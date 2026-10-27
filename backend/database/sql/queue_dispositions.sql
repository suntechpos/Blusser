-- The operational reason is stored with the existing RLS-protected ticket.
-- Existing tickets keep NULL values; no historical states are rewritten.
ALTER TABLE public.queue_tickets
 ADD COLUMN reason_code text CHECK(reason_code IS NULL OR length(btrim(reason_code)) BETWEEN 1 AND 64),
 ADD COLUMN note text CHECK(note IS NULL OR length(note)<=2000),
 ADD COLUMN rebooked_appointment_id uuid,
 ADD CONSTRAINT queue_rebooked_appointment_fk FOREIGN KEY(tenant_id,rebooked_appointment_id)
   REFERENCES public.appointments(tenant_id,id);

-- A skipped attendance normally remains requeueable and prevents duplicates.
-- Only an explicitly superseded attendance may yield to its new appointment.
ALTER TABLE public.visits ADD COLUMN rebooked_appointment_id uuid,
 ADD CONSTRAINT visit_rebooked_appointment_fk FOREIGN KEY(tenant_id,rebooked_appointment_id)
   REFERENCES public.appointments(tenant_id,id);
DROP INDEX public.visits_active_attendance;
CREATE UNIQUE INDEX visits_active_attendance ON public.visits(tenant_id,patient_id,branch_id,doctor_id)
 WHERE status NOT IN ('completed','cancelled')
   AND NOT (status='skipped' AND rebooked_appointment_id IS NOT NULL);

CREATE FUNCTION governance.queue_disposition_validate(body jsonb)
RETURNS void LANGUAGE plpgsql SET search_path=pg_catalog AS $$
BEGIN
 IF jsonb_typeof(body) IS DISTINCT FROM 'object'
   OR NOT (body ?& ARRAY['expected_version','reason_code'])
   OR EXISTS(SELECT 1 FROM jsonb_object_keys(body) k WHERE k NOT IN ('expected_version','reason_code','note','rebook'))
   OR jsonb_typeof(body->'reason_code') IS DISTINCT FROM 'string'
   OR length(btrim(body->>'reason_code')) NOT BETWEEN 1 AND 64
   OR body->>'reason_code' !~ '[^[:space:]]'
   OR (body ? 'note' AND body->'note'<>'null'::jsonb AND
       (jsonb_typeof(body->'note') IS DISTINCT FROM 'string' OR length(body->>'note')>2000)) THEN
   RAISE EXCEPTION 'Reason is required' USING ERRCODE='22023';
 END IF;
 PERFORM governance.booking_validate_payload('queue',body-ARRAY['note','rebook']);
 IF body ? 'rebook' THEN
   IF jsonb_typeof(body->'rebook') IS DISTINCT FROM 'object'
     OR NOT (body->'rebook' ?& ARRAY['room_id','starts_at','ends_at'])
     OR EXISTS(SELECT 1 FROM jsonb_object_keys(body->'rebook') k WHERE k NOT IN ('room_id','starts_at','ends_at')) THEN
     RAISE EXCEPTION 'Invalid rebooking fields' USING ERRCODE='22023';
   END IF;
   -- Reuse the strict timestamp/UUID shape validation of appointment creation.
   PERFORM governance.booking_validate_payload('reschedule',body->'rebook' || jsonb_build_object(
     'doctor_id','00000000-0000-0000-0000-000000000000',
     'branch_id','00000000-0000-0000-0000-000000000000',
     'expected_version',body->'expected_version'));
 END IF;
END $$;

-- A narrow projection tied to a real visible ticket. Client identifiers cannot
-- select another doctor, patient, service or branch. Replays use the same checks.
CREATE FUNCTION governance.queue_disposition_context(qid uuid,operation text,require_booking boolean)
RETURNS jsonb LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE tid uuid:=public.context_tenant(); q public.queue_tickets; v public.visits; zone text; is_own boolean;
BEGIN
 IF operation NOT IN ('skip','cancel') OR NOT governance.scheduling_identity()
   OR NOT governance.queue_visible(qid) THEN
   RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002';
 END IF;
 SELECT * INTO q FROM public.queue_tickets WHERE (tenant_id,id)=(tid,qid);
 SELECT * INTO v FROM public.visits WHERE (tenant_id,id)=(tid,q.visit_id);
 IF NOT governance.queue_scope('queues.'||operation,v.doctor_id,v.branch_id) THEN
   RAISE EXCEPTION 'Denied' USING ERRCODE='42501';
 END IF;
 IF NOT governance.booking_visible('visits',v.id,'visits.view')
   OR (v.appointment_id IS NOT NULL AND NOT governance.booking_visible('appointments',v.appointment_id,'appointments.view')) THEN
   RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002';
 END IF;
 IF operation='cancel' AND v.appointment_id IS NOT NULL
   AND NOT governance.booking_scope('appointments.cancel',v.doctor_id,v.branch_id) THEN
   RAISE EXCEPTION 'Denied' USING ERRCODE='42501';
 END IF;
 IF require_booking THEN
   IF q.rebooked_appointment_id IS NOT NULL AND NOT EXISTS(
     SELECT 1 FROM public.appointments rebooked
     WHERE (rebooked.tenant_id,rebooked.id)=(tid,q.rebooked_appointment_id)
       AND governance.booking_visible('appointments',rebooked.id,'appointments.view')
       AND governance.booking_scope('appointments.create',rebooked.doctor_id,rebooked.branch_id)
   ) THEN
     RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002';
   END IF;
   IF NOT governance.booking_scope('appointments.create',v.doctor_id,v.branch_id)
     OR NOT governance.booking_scope('appointments.view',v.doctor_id,v.branch_id) THEN
     RAISE EXCEPTION 'Denied' USING ERRCODE='42501';
   END IF;
   IF NOT governance.patient_allowed(v.patient_id,'patients.view')
     OR NOT governance.doctor_branch_live(v.doctor_id,v.branch_id)
     OR NOT EXISTS(SELECT 1 FROM public.patient_branches pb
       JOIN public.patients p ON (p.tenant_id,p.id)=(pb.tenant_id,pb.patient_id)
       WHERE (pb.tenant_id,pb.patient_id,pb.branch_id)=(tid,v.patient_id,v.branch_id) AND p.archived_at IS NULL)
     OR NOT EXISTS(SELECT 1 FROM public.doctor_services ds
       JOIN public.services s ON (s.tenant_id,s.id)=(ds.tenant_id,ds.service_id) AND s.active
       WHERE (ds.tenant_id,ds.doctor_id,ds.service_id)=(tid,v.doctor_id,v.service_id)) THEN
     RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002';
   END IF;
   is_own:=EXISTS(SELECT 1 FROM public.doctor_profiles d
     JOIN public.tenant_memberships m ON (m.tenant_id,m.id)=(d.tenant_id,d.membership_id)
     WHERE (d.tenant_id,d.id)=(tid,v.doctor_id) AND m.user_id=public.context_user() AND m.active);
   -- Own doctors receive only their ticket's valid slots, never catalog lists.
   -- Staff retain the established appointment catalog read prerequisites.
   IF NOT is_own AND (public.permission_scope('services.view') IS DISTINCT FROM 'entire_clinic'
     OR governance.scheduling_branch('doctors.view',v.branch_id) IS NOT TRUE
     OR governance.scheduling_branch('rooms.view',v.branch_id) IS NOT TRUE
     OR governance.scheduling_branch('schedules.view',v.branch_id) IS NOT TRUE) THEN
     RAISE EXCEPTION 'Denied' USING ERRCODE='42501';
   END IF;
 END IF;
 SELECT coalesce(b.timezone,t.timezone) INTO zone FROM public.branches b
 JOIN public.tenants t ON t.id=b.tenant_id WHERE (b.tenant_id,b.id)=(tid,v.branch_id) AND b.active;
 RETURN jsonb_build_object('doctor_id',v.doctor_id,'patient_id',v.patient_id,
   'service_id',v.service_id,'branch_id',v.branch_id,'timezone',zone);
END $$;

CREATE FUNCTION governance.queue_rebooking_options(qid uuid,operation text,requested_day date)
RETURNS jsonb LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE context jsonb; q public.queue_tickets; tid uuid:=public.context_tenant(); did uuid; bid uuid; sid uuid; zone text; slots jsonb;
BEGIN
 context:=governance.queue_disposition_context(qid,operation,true);
 SELECT * INTO q FROM public.queue_tickets WHERE (tenant_id,id)=(tid,qid);
 IF (operation='skip' AND q.status NOT IN ('waiting','called'))
   OR (operation='cancel' AND q.status NOT IN ('waiting','called','skipped')) THEN
   RAISE EXCEPTION 'State conflict' USING ERRCODE='23505';
 END IF;
 did:=(context->>'doctor_id')::uuid; bid:=(context->>'branch_id')::uuid;
 sid:=(context->>'service_id')::uuid; zone:=context->>'timezone';
 IF requested_day IS NULL OR NOT isfinite(requested_day) THEN
   RAISE EXCEPTION 'Invalid date' USING ERRCODE='22023';
 END IF;
 SELECT coalesce(jsonb_agg(jsonb_build_object('room_id',s.room_id,
   'starts_at',to_char(s.lo AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS"Z"'),
   'ends_at',to_char(s.hi AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS"Z"')) ORDER BY s.lo,s.room_id),'[]'::jsonb)
 INTO slots FROM (
   SELECT DISTINCT w.room_id,option_slot.lo,option_slot.hi
   FROM (
     SELECT sr.room_id,sr.local_start,sr.local_end,coalesce(ds.duration_minutes,sr.slot_minutes) AS duration
     FROM public.schedule_rules sr
     JOIN public.doctor_services ds ON (ds.tenant_id,ds.doctor_id,ds.service_id)=(sr.tenant_id,sr.doctor_id,sid)
     WHERE sr.tenant_id=tid AND sr.doctor_id=did AND sr.branch_id=bid
       AND sr.weekday=extract(isodow FROM requested_day)::integer AND sr.active
       AND sr.effective_from<=requested_day AND (sr.effective_to IS NULL OR sr.effective_to>=requested_day)
       AND NOT EXISTS(SELECT 1 FROM public.schedule_exceptions ex
         WHERE (ex.tenant_id,ex.doctor_id,ex.branch_id,ex.local_date)=(tid,did,bid,requested_day))
     UNION ALL
     SELECT ex.room_id,ex.local_start,ex.local_end,coalesce(ds.duration_minutes,ex.slot_minutes)
     FROM public.schedule_exceptions ex
     JOIN public.doctor_services ds ON (ds.tenant_id,ds.doctor_id,ds.service_id)=(ex.tenant_id,ex.doctor_id,sid)
     WHERE (ex.tenant_id,ex.doctor_id,ex.branch_id,ex.local_date)=(tid,did,bid,requested_day) AND ex.kind='replace'
   ) w
   CROSS JOIN LATERAL generate_series(0,greatest(0,ceil(extract(epoch FROM (w.local_end-w.local_start))/(w.duration*60.0))::integer-1)) n
   CROSS JOIN LATERAL (SELECT
     ((requested_day::timestamp+w.local_start)+n*make_interval(mins=>w.duration)) AT TIME ZONE zone AS lo,
     ((requested_day::timestamp+w.local_start)+(n+1)*make_interval(mins=>w.duration)) AT TIME ZONE zone AS hi) option_slot
   WHERE option_slot.lo>clock_timestamp()
     AND governance.booking_slot_valid(did,bid,sid,w.room_id,option_slot.lo,option_slot.hi)
     AND NOT EXISTS(SELECT 1 FROM public.resource_reservations rr
       WHERE rr.tenant_id=tid AND rr.active AND (rr.doctor_id=did OR rr.room_id=w.room_id)
       AND tstzrange(rr.starts_at,rr.ends_at,'[)')&&tstzrange(option_slot.lo,option_slot.hi,'[)'))
 ) s;
 RETURN jsonb_build_object('local_date',requested_day,'timezone',zone,'slots',slots);
END $$;

-- Private helper called only after queue_command has acquired the standard
-- actor/doctor -> tenant -> rooms -> attendance locks and checked the version.
CREATE FUNCTION governance.queue_rebooking_insert(context jsonb,slot jsonb,actor_id uuid)
RETURNS uuid LANGUAGE plpgsql SET search_path=pg_catalog AS $$
DECLARE tid uuid:=public.context_tenant(); did uuid:=(context->>'doctor_id')::uuid;
 bid uuid:=(context->>'branch_id')::uuid; sid uuid:=(context->>'service_id')::uuid;
 pid uuid:=(context->>'patient_id')::uuid; room uuid:=(slot->>'room_id')::uuid;
 lo timestamptz:=(slot->>'starts_at')::timestamptz; hi timestamptz:=(slot->>'ends_at')::timestamptz;
 zone text:=context->>'timezone'; new_id uuid:=gen_random_uuid(); reservation uuid:=gen_random_uuid();
BEGIN
 IF ((slot->>'starts_at') !~ '(Z|[+-]00:00)$' AND regexp_replace(slot->>'starts_at','[+-]\d{2}:\d{2}$','')::timestamp IS DISTINCT FROM lo AT TIME ZONE zone)
   OR ((slot->>'ends_at') !~ '(Z|[+-]00:00)$' AND regexp_replace(slot->>'ends_at','[+-]\d{2}:\d{2}$','')::timestamp IS DISTINCT FROM hi AT TIME ZONE zone)
   OR lo<=clock_timestamp() OR NOT governance.booking_slot_valid(did,bid,sid,room,lo,hi) THEN
   RAISE EXCEPTION 'Invalid slot' USING ERRCODE='23505';
 END IF;
 -- The existing doctor/room GiST exclusions are the final race-safe authority.
 INSERT INTO public.appointments(id,tenant_id,patient_id,doctor_id,branch_id,service_id,room_id,reservation_id,starts_at,ends_at)
 VALUES(new_id,tid,pid,did,bid,sid,room,reservation,lo,hi);
 INSERT INTO public.resource_reservations(id,tenant_id,doctor_id,branch_id,room_id,appointment_id,starts_at,ends_at)
 VALUES(reservation,tid,did,bid,room,new_id,lo,hi);
 PERFORM governance.booking_event('appointment',new_id,did,bid,NULL,'scheduled','create',actor_id);
 PERFORM governance.event('appointment.create','appointment',new_id,tid);
 RETURN new_id;
END $$;

CREATE OR REPLACE FUNCTION governance.queue_attendance(qid uuid)
RETURNS jsonb LANGUAGE sql STABLE SET search_path=pg_catalog AS $$
 SELECT jsonb_set(governance.attendance_projection(q.visit_id),'{ticket}',
   governance.attendance_projection(q.visit_id)->'ticket' || jsonb_build_object(
     'reason_code',q.reason_code,'note',q.note,'rebooked_appointment_id',q.rebooked_appointment_id))
   || jsonb_build_object('rebooked_appointment',CASE
     WHEN governance.booking_visible('appointments',q.rebooked_appointment_id,'appointments.view')
     THEN governance.booking_projection('appointments',q.rebooked_appointment_id) END)
 FROM public.queue_tickets q WHERE (q.tenant_id,q.id)=(public.context_tenant(),qid)
$$;

CREATE OR REPLACE FUNCTION governance.queue_projection(qid uuid)
RETURNS jsonb LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT jsonb_build_object('id',q.id,'visit_id',q.visit_id,'patient_id',v.patient_id,
   'doctor_id',v.doctor_id,'branch_id',q.branch_id,'appointment_id',v.appointment_id,
   'status',q.status,'version',q.version,'number',q.number,'kind',q.kind,'local_date',q.local_date,
   'reason_code',q.reason_code,'note',q.note,'rebooked_appointment_id',q.rebooked_appointment_id)
 FROM public.queue_tickets q JOIN public.visits v ON (v.tenant_id,v.id)=(q.tenant_id,q.visit_id)
 WHERE q.tenant_id=public.context_tenant() AND q.id=qid AND governance.queue_visible(q.id)
$$;
