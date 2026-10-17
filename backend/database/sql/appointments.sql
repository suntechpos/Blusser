CREATE SCHEMA IF NOT EXISTS reservation_extensions AUTHORIZATION blusser_owner;
REVOKE ALL ON SCHEMA reservation_extensions FROM PUBLIC;
GRANT USAGE ON SCHEMA reservation_extensions TO blusser_security;
CREATE EXTENSION IF NOT EXISTS btree_gist WITH SCHEMA reservation_extensions;
DO $$ BEGIN
 IF NOT EXISTS(SELECT 1 FROM pg_extension e JOIN pg_namespace n ON n.oid=e.extnamespace WHERE e.extname='btree_gist' AND n.nspname='reservation_extensions') THEN
 RAISE EXCEPTION 'btree_gist requires reviewed installation in reservation_extensions'; END IF;
END $$;
CREATE TABLE appointments (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), tenant_id uuid NOT NULL REFERENCES tenants(id), patient_id uuid NOT NULL, doctor_id uuid NOT NULL, branch_id uuid NOT NULL,
 service_id uuid NOT NULL, room_id uuid NOT NULL, reservation_id uuid NOT NULL,
 starts_at timestamptz NOT NULL, ends_at timestamptz NOT NULL CHECK(ends_at>starts_at),
 status text NOT NULL DEFAULT 'scheduled' CHECK(status IN ('scheduled','checked_in','in_progress','completed','cancelled','no_show')),
 version bigint NOT NULL DEFAULT 1 CHECK(version>0), created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,id,patient_id,doctor_id,branch_id,service_id), UNIQUE(tenant_id,id,doctor_id,branch_id,room_id,starts_at,ends_at),
 FOREIGN KEY(tenant_id,patient_id,branch_id) REFERENCES patient_branches(tenant_id,patient_id,branch_id),
 FOREIGN KEY(tenant_id,doctor_id,branch_id) REFERENCES doctor_branches(tenant_id,doctor_id,branch_id),
 FOREIGN KEY(tenant_id,doctor_id,service_id) REFERENCES doctor_services(tenant_id,doctor_id,service_id),
 FOREIGN KEY(tenant_id,branch_id,room_id) REFERENCES rooms(tenant_id,branch_id,id)
);
CREATE TABLE visits (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), tenant_id uuid NOT NULL REFERENCES tenants(id), patient_id uuid NOT NULL, doctor_id uuid NOT NULL, branch_id uuid NOT NULL,
 service_id uuid NOT NULL, appointment_id uuid, reservation_id uuid,
 status text NOT NULL DEFAULT 'arrived' CHECK(status IN ('arrived','queued','called','in_progress','completed','skipped','cancelled')),
 arrived_at timestamptz NOT NULL DEFAULT clock_timestamp(), version bigint NOT NULL DEFAULT 1 CHECK(version>0),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,appointment_id), UNIQUE(tenant_id,id,branch_id), UNIQUE(tenant_id,id,doctor_id,branch_id), UNIQUE(tenant_id,id,appointment_id),
 CHECK(appointment_id IS NULL OR reservation_id IS NOT NULL),
 FOREIGN KEY(tenant_id,patient_id,branch_id) REFERENCES patient_branches(tenant_id,patient_id,branch_id),
 FOREIGN KEY(tenant_id,doctor_id,branch_id) REFERENCES doctor_branches(tenant_id,doctor_id,branch_id),
 FOREIGN KEY(tenant_id,doctor_id,service_id) REFERENCES doctor_services(tenant_id,doctor_id,service_id),
 FOREIGN KEY(tenant_id,appointment_id,patient_id,doctor_id,branch_id,service_id) REFERENCES appointments(tenant_id,id,patient_id,doctor_id,branch_id,service_id)
);
CREATE UNIQUE INDEX visits_active_attendance ON visits(tenant_id,patient_id,branch_id,doctor_id) WHERE status NOT IN ('completed','cancelled');
CREATE TABLE resource_reservations (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), tenant_id uuid NOT NULL REFERENCES tenants(id), doctor_id uuid NOT NULL, branch_id uuid NOT NULL, room_id uuid NOT NULL,
 appointment_id uuid, visit_id uuid, starts_at timestamptz NOT NULL, ends_at timestamptz NOT NULL CHECK(ends_at>starts_at), active boolean NOT NULL DEFAULT true,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 CHECK(num_nonnulls(appointment_id,visit_id)>=1), UNIQUE(tenant_id,id), UNIQUE(tenant_id,appointment_id), UNIQUE(tenant_id,visit_id),
 UNIQUE(tenant_id,appointment_id,id), UNIQUE(tenant_id,visit_id,id),
 FOREIGN KEY(tenant_id,doctor_id,branch_id) REFERENCES doctor_branches(tenant_id,doctor_id,branch_id), FOREIGN KEY(tenant_id,branch_id,room_id) REFERENCES rooms(tenant_id,branch_id,id),
 FOREIGN KEY(tenant_id,appointment_id,doctor_id,branch_id,room_id,starts_at,ends_at) REFERENCES appointments(tenant_id,id,doctor_id,branch_id,room_id,starts_at,ends_at) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(tenant_id,visit_id,doctor_id,branch_id) REFERENCES visits(tenant_id,id,doctor_id,branch_id) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(tenant_id,visit_id,appointment_id) REFERENCES visits(tenant_id,id,appointment_id) DEFERRABLE INITIALLY DEFERRED,
 CONSTRAINT reservation_doctor_exclusion EXCLUDE USING gist (tenant_id reservation_extensions.gist_uuid_ops WITH =,doctor_id reservation_extensions.gist_uuid_ops WITH =,tstzrange(starts_at,ends_at,'[)') WITH &&) WHERE(active),
 CONSTRAINT reservation_room_exclusion EXCLUDE USING gist (tenant_id reservation_extensions.gist_uuid_ops WITH =,room_id reservation_extensions.gist_uuid_ops WITH =,tstzrange(starts_at,ends_at,'[)') WITH &&) WHERE(active)
);
ALTER TABLE appointments ADD CONSTRAINT appointment_occupancy FOREIGN KEY(tenant_id,id,reservation_id) REFERENCES resource_reservations(tenant_id,appointment_id,id) DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE visits ADD CONSTRAINT visit_occupancy FOREIGN KEY(tenant_id,id,reservation_id) REFERENCES resource_reservations(tenant_id,visit_id,id) DEFERRABLE INITIALLY DEFERRED;
CREATE TABLE queue_tickets (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), tenant_id uuid NOT NULL REFERENCES tenants(id), visit_id uuid NOT NULL, branch_id uuid NOT NULL,
 kind text NOT NULL CHECK(kind IN ('scheduled','walk_in')), local_date date NOT NULL, number bigint NOT NULL CHECK(number>0),
 status text NOT NULL DEFAULT 'waiting' CHECK(status='waiting'), version bigint NOT NULL DEFAULT 1 CHECK(version>0),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,visit_id), UNIQUE(tenant_id,branch_id,kind,local_date,number),
 FOREIGN KEY(tenant_id,visit_id,branch_id) REFERENCES visits(tenant_id,id,branch_id)
);
CREATE TABLE queue_counters (
 tenant_id uuid NOT NULL REFERENCES tenants(id), branch_id uuid NOT NULL, kind text NOT NULL CHECK(kind IN ('scheduled','walk_in')), local_date date NOT NULL, value bigint NOT NULL CHECK(value>0),
 PRIMARY KEY(tenant_id,branch_id,kind,local_date), FOREIGN KEY(tenant_id,branch_id) REFERENCES branches(tenant_id,id)
);
CREATE TABLE operation_events (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), tenant_id uuid NOT NULL REFERENCES tenants(id), appointment_id uuid, visit_id uuid, ticket_id uuid,
 doctor_id uuid NOT NULL, branch_id uuid NOT NULL, actor_membership_id uuid NOT NULL, action text NOT NULL, from_status text, to_status text NOT NULL,
 request_id uuid NOT NULL DEFAULT gen_random_uuid(), occurred_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(tenant_id,id), CHECK(num_nonnulls(appointment_id,visit_id,ticket_id)=1),
 FOREIGN KEY(tenant_id,appointment_id) REFERENCES appointments(tenant_id,id), FOREIGN KEY(tenant_id,visit_id) REFERENCES visits(tenant_id,id), FOREIGN KEY(tenant_id,ticket_id) REFERENCES queue_tickets(tenant_id,id),
 FOREIGN KEY(tenant_id,doctor_id) REFERENCES doctor_profiles(tenant_id,id), FOREIGN KEY(tenant_id,branch_id) REFERENCES branches(tenant_id,id), FOREIGN KEY(tenant_id,actor_membership_id) REFERENCES tenant_memberships(tenant_id,id)
);
CREATE TABLE operation_idempotency (
 tenant_id uuid NOT NULL REFERENCES tenants(id), actor_membership_id uuid NOT NULL, operation text NOT NULL, key uuid NOT NULL, request_digest char(64) NOT NULL,
 appointment_id uuid, visit_id uuid, expires_at timestamptz NOT NULL DEFAULT now()+interval '24 hours',
 PRIMARY KEY(tenant_id,actor_membership_id,operation,key), CHECK(num_nonnulls(appointment_id,visit_id)=1),
 FOREIGN KEY(tenant_id,actor_membership_id) REFERENCES tenant_memberships(tenant_id,id), FOREIGN KEY(tenant_id,appointment_id) REFERENCES appointments(tenant_id,id), FOREIGN KEY(tenant_id,visit_id) REFERENCES visits(tenant_id,id)
);
CREATE INDEX appointments_list ON appointments(tenant_id,starts_at,id);
CREATE INDEX appointments_patient ON appointments(tenant_id,patient_id,status,doctor_id);
CREATE INDEX visits_list ON visits(tenant_id,created_at,id);
CREATE INDEX operation_events_appointment ON operation_events(tenant_id,appointment_id,occurred_at,id);
CREATE INDEX operation_events_visit ON operation_events(tenant_id,visit_id,occurred_at,id);

CREATE FUNCTION governance.booking_scope(permission_code text,did uuid,bid uuid) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT governance.scheduling_identity() AND permission_code IN ('appointments.view','appointments.create','appointments.update','appointments.cancel','appointments.check_in','appointments.no_show','appointments.export','visits.view','visits.create','queues.create') AND CASE public.permission_scope(permission_code)
 WHEN 'entire_clinic' THEN true WHEN 'assigned_branches' THEN public.branch_assigned(bid)
 WHEN 'own' THEN governance.doctor_branch_live(did,bid) AND EXISTS(SELECT 1 FROM public.doctor_profiles WHERE tenant_id=public.context_tenant() AND id=did AND membership_id IN (SELECT id FROM public.tenant_memberships WHERE tenant_id=public.context_tenant() AND user_id=public.context_user() AND active))
 ELSE false END
$$;
CREATE FUNCTION governance.booking_visible(kind text,rid uuid,permission_code text) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT CASE kind WHEN 'appointments' THEN permission_code='appointments.view' AND EXISTS(SELECT 1 FROM public.appointments a WHERE a.tenant_id=public.context_tenant() AND a.id=rid AND governance.booking_scope(permission_code,a.doctor_id,a.branch_id))
 WHEN 'visits' THEN permission_code='visits.view' AND EXISTS(SELECT 1 FROM public.visits v WHERE v.tenant_id=public.context_tenant() AND v.id=rid AND governance.booking_scope(permission_code,v.doctor_id,v.branch_id)) ELSE false END
$$;
CREATE FUNCTION governance.booking_projection(kind text,rid uuid) RETURNS jsonb LANGUAGE sql STABLE SET search_path=pg_catalog AS $$
 SELECT CASE kind WHEN 'appointments' THEN (SELECT jsonb_build_object('id',a.id,'patient_id',a.patient_id,'doctor_id',a.doctor_id,'branch_id',a.branch_id,'service_id',a.service_id,'room_id',a.room_id,
 'starts_at',to_char(a.starts_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),'ends_at',to_char(a.ends_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),'status',a.status,'version',a.version,'late',a.status='scheduled' AND a.starts_at<clock_timestamp()) FROM public.appointments a WHERE a.tenant_id=public.context_tenant() AND a.id=rid)
 WHEN 'visits' THEN (SELECT jsonb_build_object('id',v.id,'patient_id',v.patient_id,'doctor_id',v.doctor_id,'branch_id',v.branch_id,'appointment_id',v.appointment_id,'status',v.status,'version',v.version) FROM public.visits v WHERE v.tenant_id=public.context_tenant() AND v.id=rid) END
$$;
CREATE FUNCTION governance.attendance_projection(vid uuid) RETURNS jsonb LANGUAGE sql STABLE SET search_path=pg_catalog AS $$
 SELECT jsonb_build_object('visit',governance.booking_projection('visits',v.id),'appointment',governance.booking_projection('appointments',v.appointment_id),
 'ticket',jsonb_build_object('id',q.id,'patient_id',v.patient_id,'doctor_id',v.doctor_id,'branch_id',v.branch_id,'appointment_id',v.appointment_id,'status',q.status,'version',q.version,'visit_id',v.id,'number',q.number,'kind',q.kind,'local_date',q.local_date))
 FROM public.visits v JOIN public.queue_tickets q ON (q.tenant_id,q.visit_id)=(v.tenant_id,v.id) WHERE v.tenant_id=public.context_tenant() AND v.id=vid
$$;
CREATE FUNCTION governance.booking_local_unique(wall timestamp,zone text) RETURNS boolean LANGUAGE sql STABLE SET search_path=pg_catalog AS $$
 SELECT count(DISTINCT candidate)=1 FROM (
 SELECT (wall AT TIME ZONE 'UTC')-((t AT TIME ZONE zone)-(t AT TIME ZONE 'UTC')) AS candidate FROM generate_series((wall AT TIME ZONE 'UTC')-interval '48 hours',(wall AT TIME ZONE 'UTC')+interval '48 hours',interval '1 hour') t
 ) s WHERE candidate AT TIME ZONE zone=wall
$$;
CREATE FUNCTION governance.booking_slot_valid(did uuid,bid uuid,sid uuid,rid uuid,lo timestamptz,hi timestamptz,check_live boolean DEFAULT true) RETURNS boolean LANGUAGE plpgsql STABLE SET search_path=pg_catalog AS $$
DECLARE zone text; day date; wall_start timestamp; wall_end timestamp; w record; ex public.schedule_exceptions; duration int; override_minutes int;
BEGIN
 IF lo IS NULL OR hi IS NULL OR NOT isfinite(lo) OR NOT isfinite(hi) OR hi<=lo OR (check_live AND NOT governance.doctor_branch_live(did,bid)) THEN RETURN false; END IF;
 SELECT ds.duration_minutes INTO override_minutes FROM public.doctor_services ds JOIN public.services s ON (s.tenant_id,s.id)=(ds.tenant_id,ds.service_id) AND (s.active OR NOT check_live)
 WHERE (ds.tenant_id,ds.doctor_id,ds.service_id)=(public.context_tenant(),did,sid); IF NOT FOUND THEN RETURN false; END IF;
 SELECT coalesce(b.timezone,t.timezone) INTO zone FROM public.branches b JOIN public.tenants t ON t.id=b.tenant_id JOIN public.rooms r ON (r.tenant_id,r.branch_id)=(b.tenant_id,b.id)
 WHERE (r.tenant_id,r.id,r.branch_id)=(public.context_tenant(),rid,bid) AND (NOT check_live OR (r.active AND r.availability='available' AND b.active));
 IF NOT FOUND THEN RETURN false; END IF;
 wall_start:=lo AT TIME ZONE zone; wall_end:=hi AT TIME ZONE zone; day:=wall_start::date;
 IF wall_start::date<>wall_end::date OR NOT governance.booking_local_unique(wall_start,zone) OR NOT governance.booking_local_unique(wall_end,zone) THEN RETURN false; END IF;
 SELECT * INTO ex FROM public.schedule_exceptions WHERE tenant_id=public.context_tenant() AND doctor_id=did AND branch_id=bid AND local_date=day;
 IF FOUND THEN
  IF ex.kind='closed' OR ex.room_id<>rid THEN RETURN false; END IF;
  duration:=coalesce(override_minutes,ex.slot_minutes);
  RETURN wall_start::time>=ex.local_start AND wall_end::time<=ex.local_end AND hi-lo=make_interval(mins=>duration) AND wall_end-wall_start=make_interval(mins=>duration)
   AND mod(extract(epoch FROM (wall_start::time-ex.local_start)),duration*60)=0
   AND NOT EXISTS(SELECT 1 FROM public.schedule_exception_breaks WHERE tenant_id=public.context_tenant() AND schedule_exception_id=ex.id AND local_start<wall_end::time AND local_end>wall_start::time);
 END IF;
 FOR w IN SELECT * FROM public.schedule_rules WHERE tenant_id=public.context_tenant() AND doctor_id=did AND branch_id=bid AND room_id=rid AND weekday=extract(isodow FROM day) AND effective_from<=day AND (effective_to IS NULL OR effective_to>=day) LOOP
  duration:=coalesce(override_minutes,w.slot_minutes);
  IF wall_start::time>=w.local_start AND wall_end::time<=w.local_end AND hi-lo=make_interval(mins=>duration) AND wall_end-wall_start=make_interval(mins=>duration)
   AND mod(extract(epoch FROM (wall_start::time-w.local_start)),duration*60)=0
   AND NOT EXISTS(SELECT 1 FROM public.schedule_breaks WHERE tenant_id=public.context_tenant() AND schedule_rule_id=w.id AND local_start<wall_end::time AND local_end>wall_start::time) THEN RETURN true; END IF;
 END LOOP;
 RETURN false;
END $$;
CREATE FUNCTION governance.booking_event(ak text,rid uuid,did uuid,bid uuid,from_value text,to_value text,action_value text,aid uuid) RETURNS void LANGUAGE sql SET search_path=pg_catalog AS $$
 INSERT INTO public.operation_events(tenant_id,appointment_id,visit_id,ticket_id,doctor_id,branch_id,actor_membership_id,action,from_status,to_status)
 VALUES(public.context_tenant(),CASE WHEN ak='appointment' THEN rid END,CASE WHEN ak='visit' THEN rid END,CASE WHEN ak='ticket' THEN rid END,did,bid,aid,action_value,from_value,to_value)
$$;
CREATE FUNCTION governance.booking_validate_payload(action_code text,p jsonb) RETURNS void LANGUAGE plpgsql SET search_path=pg_catalog AS $$
DECLARE fields text[]; required_fields text[]; k text; v jsonb;
BEGIN
 fields:=CASE action_code WHEN 'create' THEN ARRAY['patient_id','doctor_id','branch_id','service_id','room_id','starts_at','ends_at']
 WHEN 'walk_in' THEN ARRAY['patient_id','doctor_id','branch_id','service_id']
 WHEN 'reschedule' THEN ARRAY['doctor_id','branch_id','room_id','starts_at','ends_at','expected_version']
 ELSE ARRAY['expected_version','reason_code'] END;
 required_fields:=array_remove(fields,'reason_code');
 IF jsonb_typeof(p) IS DISTINCT FROM 'object' OR NOT(p?&required_fields) OR EXISTS(SELECT 1 FROM jsonb_object_keys(p) x WHERE NOT x=ANY(fields)) THEN RAISE EXCEPTION 'Invalid fields' USING ERRCODE='22023'; END IF;
 FOR k,v IN SELECT key,value FROM jsonb_each(p) LOOP
  IF k='expected_version' THEN
   IF jsonb_typeof(v)<>'number' OR v::text !~ '^[1-9][0-9]{0,18}$' OR (v::text)::numeric>9223372036854775807 THEN RAISE EXCEPTION 'Invalid version' USING ERRCODE='22023'; END IF;
  ELSE
   IF jsonb_typeof(v)<>'string' THEN RAISE EXCEPTION 'Invalid type' USING ERRCODE='22023'; END IF;
   IF k LIKE '%_id' AND p->>k !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' THEN RAISE EXCEPTION 'Invalid UUID' USING ERRCODE='22023'; END IF;
   IF k IN ('starts_at','ends_at') AND p->>k !~ '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$' THEN RAISE EXCEPTION 'Offset timestamp required' USING ERRCODE='22023'; END IF;
   IF k='reason_code' AND (length(p->>k)>64 OR length(p->>k)=0) THEN RAISE EXCEPTION 'Invalid reason' USING ERRCODE='22023'; END IF;
  END IF;
 END LOOP;
END $$;
CREATE FUNCTION governance.booking_command(action_code text,rid uuid,payload jsonb,request_key uuid) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE tid uuid:=public.context_tenant(); aid uuid; a public.appointments; v public.visits; saved public.operation_idempotency;
 pid uuid; did uuid; bid uuid; sid uuid; room uuid; lo timestamptz; hi timestamptz; target_user uuid; old_user uuid;
 permission_code text; digest_value text; result_id uuid; res_id uuid; ticket_id uuid; counter_value bigint; local_day date; zone text;
BEGIN
 IF action_code NOT IN ('create','reschedule','cancel','no_show','check_in','walk_in') OR request_key IS NULL OR (action_code IN ('create','walk_in') AND rid IS NOT NULL) OR (action_code NOT IN ('create','walk_in') AND rid IS NULL) THEN RAISE EXCEPTION 'Invalid command' USING ERRCODE='22023'; END IF;
 PERFORM governance.booking_validate_payload(action_code,payload);
 SELECT m.user_id INTO target_user FROM public.doctor_profiles d JOIN public.tenant_memberships m ON (m.tenant_id,m.id)=(d.tenant_id,d.membership_id) WHERE d.tenant_id=tid AND d.id=(payload->>'doctor_id')::uuid;
 SELECT m.user_id INTO old_user FROM public.appointments ap JOIN public.doctor_profiles d ON (d.tenant_id,d.id)=(ap.tenant_id,ap.doctor_id) JOIN public.tenant_memberships m ON (m.tenant_id,m.id)=(d.tenant_id,d.membership_id) WHERE ap.tenant_id=tid AND ap.id=rid;
 PERFORM governance.lock_users(ARRAY[public.context_user(),target_user,old_user]); PERFORM governance.actor();
 PERFORM id FROM public.tenants WHERE id=tid AND status='active' FOR UPDATE;
 IF NOT FOUND OR NOT governance.scheduling_identity() THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 SELECT id INTO aid FROM public.tenant_memberships WHERE tenant_id=tid AND user_id=public.context_user() AND active;
 permission_code:=CASE WHEN action_code='walk_in' THEN 'visits.create' WHEN action_code='reschedule' THEN 'appointments.update' ELSE 'appointments.'||action_code END;
 IF public.permission_scope(permission_code) IS NULL THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 IF action_code NOT IN ('create','walk_in') THEN
  IF NOT governance.booking_visible('appointments',rid,'appointments.view') THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
  SELECT * INTO a FROM public.appointments WHERE (tenant_id,id)=(tid,rid);
  IF NOT governance.booking_scope(permission_code,a.doctor_id,a.branch_id) THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
  pid:=a.patient_id; did:=a.doctor_id; bid:=a.branch_id; sid:=a.service_id;
 END IF;
 IF action_code IN ('create','walk_in','reschedule') THEN
  pid:=coalesce(pid,(payload->>'patient_id')::uuid); did:=(payload->>'doctor_id')::uuid; bid:=(payload->>'branch_id')::uuid; sid:=coalesce(sid,(payload->>'service_id')::uuid);
  IF NOT governance.booking_scope(permission_code,did,bid) OR (action_code<>'walk_in' AND NOT governance.booking_scope('appointments.view',did,bid)) THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
  IF NOT governance.patient_allowed(pid,'patients.view') OR NOT EXISTS(SELECT 1 FROM public.patient_branches WHERE (tenant_id,patient_id,branch_id)=(tid,pid,bid))
   OR NOT governance.doctor_branch_live(did,bid) OR NOT EXISTS(SELECT 1 FROM public.doctor_services ds JOIN public.services s ON (s.tenant_id,s.id)=(ds.tenant_id,ds.service_id) AND s.active WHERE (ds.tenant_id,ds.doctor_id,ds.service_id)=(tid,did,sid)) THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
  IF EXISTS(SELECT 1 FROM public.patients WHERE (tenant_id,id)=(tid,pid) AND archived_at IS NOT NULL) THEN RAISE EXCEPTION 'Archived' USING ERRCODE='23505'; END IF;
 END IF;
 IF action_code='check_in' OR action_code='walk_in' THEN
  IF NOT governance.booking_scope('visits.create',did,bid) OR NOT governance.booking_scope('queues.create',did,bid) THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
  IF NOT governance.patient_allowed(pid,'patients.view') THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
 END IF;
 digest_value:=encode(sha256(convert_to(jsonb_build_object('id',rid,'payload',payload)::text,'UTF8')),'hex');
 SELECT * INTO saved FROM public.operation_idempotency WHERE tenant_id=tid AND actor_membership_id=aid AND operation=action_code AND key=request_key;
 IF FOUND AND saved.expires_at>now() THEN
  IF saved.request_digest<>digest_value THEN RAISE EXCEPTION 'Conflict' USING ERRCODE='23505'; END IF;
  IF saved.appointment_id IS NOT NULL THEN
   SELECT * INTO a FROM public.appointments WHERE (tenant_id,id)=(tid,saved.appointment_id);
   IF NOT governance.booking_scope(permission_code,a.doctor_id,a.branch_id) OR NOT governance.booking_visible('appointments',a.id,'appointments.view') THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
   RETURN governance.booking_projection('appointments',a.id);
  END IF;
  SELECT * INTO v FROM public.visits WHERE (tenant_id,id)=(tid,saved.visit_id);
  IF NOT governance.booking_scope('visits.create',v.doctor_id,v.branch_id) OR NOT governance.booking_scope('queues.create',v.doctor_id,v.branch_id) OR NOT governance.patient_allowed(v.patient_id,'patients.view') THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
  RETURN governance.attendance_projection(v.id);
 END IF;
 -- Compare the version only after acquiring the actual aggregate row lock.
 IF action_code NOT IN ('create','walk_in') THEN
  PERFORM id FROM public.rooms WHERE tenant_id=tid AND id IN (a.room_id,(payload->>'room_id')::uuid) ORDER BY id FOR UPDATE;
  SELECT * INTO a FROM public.appointments WHERE (tenant_id,id)=(tid,rid) FOR UPDATE;
  IF NOT governance.booking_visible('appointments',rid,'appointments.view') OR NOT governance.booking_scope(permission_code,a.doctor_id,a.branch_id) THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
  IF a.version IS DISTINCT FROM (payload->>'expected_version')::bigint OR a.status<>'scheduled' THEN RAISE EXCEPTION 'State conflict' USING ERRCODE='23505'; END IF;
 END IF;
 IF action_code IN ('create','reschedule') THEN
  IF public.permission_scope('services.view') IS DISTINCT FROM 'entire_clinic' OR NOT governance.scheduling_branch('doctors.view',bid) OR NOT governance.scheduling_branch('rooms.view',bid) OR NOT governance.scheduling_branch('schedules.view',bid) THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
  room:=(payload->>'room_id')::uuid;lo:=(payload->>'starts_at')::timestamptz;hi:=(payload->>'ends_at')::timestamptz;
  SELECT coalesce(b.timezone,t.timezone) INTO zone FROM public.branches b JOIN public.tenants t ON t.id=b.tenant_id WHERE (b.tenant_id,b.id)=(tid,bid);
  -- Accept UTC instants or the actual branch offset. Reject a nonexistent wall
  -- label with a made-up/stale offset instead of normalizing it to another slot.
  IF ((payload->>'starts_at') !~ '(Z|[+-]00:00)$' AND regexp_replace(payload->>'starts_at','[+-]\d{2}:\d{2}$','')::timestamp IS DISTINCT FROM lo AT TIME ZONE zone)
  OR ((payload->>'ends_at') !~ '(Z|[+-]00:00)$' AND regexp_replace(payload->>'ends_at','[+-]\d{2}:\d{2}$','')::timestamp IS DISTINCT FROM hi AT TIME ZONE zone) THEN RAISE EXCEPTION 'Invalid timezone offset' USING ERRCODE='23505'; END IF;
  PERFORM id FROM public.rooms WHERE (tenant_id,id,branch_id)=(tid,room,bid) AND active AND availability='available' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
  IF lo<=clock_timestamp() OR NOT governance.booking_slot_valid(did,bid,sid,room,lo,hi) THEN RAISE EXCEPTION 'Invalid slot' USING ERRCODE='23505'; END IF;
  IF action_code='create' THEN
   result_id:=gen_random_uuid();res_id:=gen_random_uuid();
   INSERT INTO public.appointments(id,tenant_id,patient_id,doctor_id,branch_id,service_id,room_id,reservation_id,starts_at,ends_at) VALUES(result_id,tid,pid,did,bid,sid,room,res_id,lo,hi);
   INSERT INTO public.resource_reservations(id,tenant_id,doctor_id,branch_id,room_id,appointment_id,starts_at,ends_at) VALUES(res_id,tid,did,bid,room,result_id,lo,hi);
   PERFORM governance.booking_event('appointment',result_id,did,bid,NULL,'scheduled','create',aid);
  ELSE
   result_id:=rid;
   UPDATE public.appointments SET doctor_id=did,branch_id=bid,room_id=room,starts_at=lo,ends_at=hi,version=version+1,updated_at=now() WHERE (tenant_id,id)=(tid,rid);
   UPDATE public.resource_reservations SET doctor_id=did,branch_id=bid,room_id=room,starts_at=lo,ends_at=hi,updated_at=now() WHERE (tenant_id,id)=(tid,a.reservation_id);
   PERFORM governance.booking_event('appointment',rid,did,bid,'scheduled','scheduled','reschedule',aid);
  END IF;
 ELSIF action_code IN ('cancel','no_show') THEN
  IF action_code='no_show' AND a.starts_at>clock_timestamp() THEN RAISE EXCEPTION 'Too early' USING ERRCODE='23505'; END IF;
  result_id:=rid;
  UPDATE public.appointments SET status=CASE WHEN action_code='cancel' THEN 'cancelled' ELSE 'no_show' END,version=version+1,updated_at=now() WHERE (tenant_id,id)=(tid,rid);
  UPDATE public.resource_reservations SET active=false,updated_at=now() WHERE (tenant_id,id)=(tid,a.reservation_id);
  PERFORM governance.booking_event('appointment',rid,a.doctor_id,a.branch_id,'scheduled',CASE WHEN action_code='cancel' THEN 'cancelled' ELSE 'no_show' END,action_code,aid);
 ELSE
  IF action_code='check_in' THEN
   IF a.starts_at<clock_timestamp() OR NOT governance.booking_slot_valid(a.doctor_id,a.branch_id,a.service_id,a.room_id,a.starts_at,a.ends_at) OR NOT EXISTS(SELECT 1 FROM public.resource_reservations WHERE (tenant_id,id)=(tid,a.reservation_id) AND active)
   OR EXISTS(SELECT 1 FROM public.patients WHERE (tenant_id,id)=(tid,a.patient_id) AND archived_at IS NOT NULL) THEN RAISE EXCEPTION 'Reschedule or eligibility review required' USING ERRCODE='23505'; END IF;
  END IF;
  result_id:=gen_random_uuid();ticket_id:=gen_random_uuid();
  INSERT INTO public.visits(id,tenant_id,patient_id,doctor_id,branch_id,service_id,appointment_id,reservation_id) VALUES(result_id,tid,pid,did,bid,sid,CASE WHEN action_code='check_in' THEN rid END,CASE WHEN action_code='check_in' THEN a.reservation_id END);
  PERFORM governance.booking_event('visit',result_id,did,bid,NULL,'arrived','arrive',aid);
  IF action_code='check_in' THEN
   UPDATE public.resource_reservations SET visit_id=result_id,updated_at=now() WHERE (tenant_id,id)=(tid,a.reservation_id);
   UPDATE public.appointments SET status='checked_in',version=version+1,updated_at=now() WHERE (tenant_id,id)=(tid,rid);
   PERFORM governance.booking_event('appointment',rid,did,bid,'scheduled','checked_in','check_in',aid);
  END IF;
  SELECT (arrival.arrived_at AT TIME ZONE coalesce(b.timezone,t.timezone))::date INTO local_day FROM public.branches b JOIN public.tenants t ON t.id=b.tenant_id JOIN public.visits arrival ON (arrival.tenant_id,arrival.branch_id)=(b.tenant_id,b.id) WHERE (b.tenant_id,b.id,arrival.id)=(tid,bid,result_id);
  INSERT INTO public.queue_counters(tenant_id,branch_id,kind,local_date,value) VALUES(tid,bid,CASE WHEN action_code='check_in' THEN 'scheduled' ELSE 'walk_in' END,local_day,1)
  ON CONFLICT(tenant_id,branch_id,kind,local_date) DO UPDATE SET value=public.queue_counters.value+1 RETURNING value INTO counter_value;
  INSERT INTO public.queue_tickets(id,tenant_id,visit_id,branch_id,kind,local_date,number) VALUES(ticket_id,tid,result_id,bid,CASE WHEN action_code='check_in' THEN 'scheduled' ELSE 'walk_in' END,local_day,counter_value);
  UPDATE public.visits SET status='queued',version=version+1,updated_at=now() WHERE (tenant_id,id)=(tid,result_id);
  PERFORM governance.booking_event('visit',result_id,did,bid,'arrived','queued','enqueue',aid);
  PERFORM governance.booking_event('ticket',ticket_id,did,bid,NULL,'waiting','enqueue',aid);
 END IF;
 DELETE FROM public.operation_idempotency WHERE tenant_id=tid AND actor_membership_id=aid AND operation=action_code AND key=request_key;
 INSERT INTO public.operation_idempotency(tenant_id,actor_membership_id,operation,key,request_digest,appointment_id,visit_id) VALUES(tid,aid,action_code,request_key,digest_value,CASE WHEN action_code NOT IN ('walk_in','check_in') THEN result_id END,CASE WHEN action_code IN ('walk_in','check_in') THEN result_id END);
 PERFORM governance.event(CASE WHEN action_code='walk_in' THEN 'visit.create' ELSE 'appointment.'||action_code END,CASE WHEN action_code='walk_in' THEN 'visit' ELSE 'appointment' END,coalesce(rid,result_id),tid);
 RETURN CASE WHEN action_code IN ('walk_in','check_in') THEN governance.attendance_projection(result_id) ELSE governance.booking_projection('appointments',result_id) END;
END $$;
CREATE FUNCTION governance.booking_event_visible(eid uuid) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT EXISTS(SELECT 1 FROM public.operation_events e WHERE e.tenant_id=public.context_tenant() AND e.id=eid AND
 ((e.appointment_id IS NOT NULL AND governance.booking_visible('appointments',e.appointment_id,'appointments.view') AND governance.booking_scope('appointments.view',e.doctor_id,e.branch_id))
 OR (e.visit_id IS NOT NULL AND governance.booking_visible('visits',e.visit_id,'visits.view') AND governance.booking_scope('visits.view',e.doctor_id,e.branch_id))))
$$;
CREATE FUNCTION governance.booking_slot_free(did uuid,rid uuid,lo timestamptz,hi timestamptz) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT EXISTS(SELECT 1 FROM public.rooms r WHERE r.tenant_id=public.context_tenant() AND r.id=rid AND governance.doctor_branch_live(did,r.branch_id)
 AND governance.scheduling_branch('doctors.view',r.branch_id) AND governance.scheduling_branch('rooms.view',r.branch_id) AND governance.scheduling_branch('schedules.view',r.branch_id))
 AND NOT EXISTS(SELECT 1 FROM public.resource_reservations rr WHERE rr.tenant_id=public.context_tenant() AND rr.active AND (rr.doctor_id=did OR rr.room_id=rid) AND tstzrange(rr.starts_at,rr.ends_at,'[)')&&tstzrange(lo,hi,'[)'))
$$;
CREATE FUNCTION governance.booking_export_audit() RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN
 PERFORM governance.lock_users(ARRAY[public.context_user()]);PERFORM governance.actor();
 PERFORM id FROM public.tenants WHERE id=public.context_tenant() FOR UPDATE;
 IF NOT governance.scheduling_identity() OR public.permission_scope('appointments.export') IS DISTINCT FROM 'entire_clinic' OR public.permission_scope('appointments.view') IS DISTINCT FROM 'entire_clinic' THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 PERFORM governance.event('appointment.export','appointment',NULL,public.context_tenant());
END $$;
CREATE OR REPLACE FUNCTION governance.patient_allowed(pid uuid,permission_code text) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT EXISTS(SELECT 1 FROM public.patients p WHERE p.id=pid AND p.tenant_id=public.context_tenant()
 AND permission_code IN ('patients.view','patients.create','patients.update','patients.link_branch','patients.archive','patients.restore','patients.export')
 AND governance.scheduling_identity() AND EXISTS(SELECT 1 FROM public.users u WHERE u.id=public.context_user() AND NOT u.must_change_password)
 AND (public.permission_scope(permission_code)='entire_clinic' OR
 (public.permission_scope(permission_code)='assigned_branches' AND EXISTS(SELECT 1 FROM public.patient_branches pb WHERE (pb.tenant_id,pb.patient_id)=(p.tenant_id,p.id) AND public.branch_assigned(pb.branch_id))) OR
 (public.permission_scope(permission_code)='own' AND EXISTS(SELECT 1 FROM public.doctor_profiles d JOIN public.tenant_memberships m ON (m.tenant_id,m.id)=(d.tenant_id,d.membership_id)
 WHERE d.tenant_id=p.tenant_id AND m.user_id=public.context_user() AND m.active AND
 (EXISTS(SELECT 1 FROM public.appointments a WHERE a.tenant_id=p.tenant_id AND a.patient_id=p.id AND a.doctor_id=d.id AND a.status IN ('scheduled','checked_in','in_progress') AND governance.doctor_branch_live(d.id,a.branch_id)) OR
 EXISTS(SELECT 1 FROM public.visits v WHERE v.tenant_id=p.tenant_id AND v.patient_id=p.id AND v.doctor_id=d.id AND v.status NOT IN ('completed','cancelled') AND governance.doctor_branch_live(d.id,v.branch_id)))))))
$$;
-- Patient branch links must not expose other branches just because a care relation
-- exists elsewhere. Called under the same non-recursive private read boundary.
CREATE OR REPLACE FUNCTION governance.patient_branch_visible(pid uuid,bid uuid) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT governance.patient_allowed(pid,'patients.view') AND CASE public.permission_scope('patients.view')
 WHEN 'entire_clinic' THEN true WHEN 'assigned_branches' THEN public.branch_assigned(bid)
 WHEN 'own' THEN EXISTS(SELECT 1 FROM public.doctor_profiles d JOIN public.tenant_memberships m ON (m.tenant_id,m.id)=(d.tenant_id,d.membership_id)
 WHERE d.tenant_id=public.context_tenant() AND m.user_id=public.context_user() AND governance.doctor_branch_live(d.id,bid)
 AND (EXISTS(SELECT 1 FROM public.appointments a WHERE (a.tenant_id,a.patient_id,a.doctor_id,a.branch_id)=(d.tenant_id,pid,d.id,bid) AND a.status IN ('scheduled','checked_in','in_progress'))
 OR EXISTS(SELECT 1 FROM public.visits v WHERE (v.tenant_id,v.patient_id,v.doctor_id,v.branch_id)=(d.tenant_id,pid,d.id,bid) AND v.status NOT IN ('completed','cancelled')))) ELSE false END
$$;
CREATE FUNCTION governance.patient_archive_booking_guard() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN
 IF NEW.archived_at IS NOT NULL AND OLD.archived_at IS NULL AND
 (EXISTS(SELECT 1 FROM public.appointments WHERE tenant_id=NEW.tenant_id AND patient_id=NEW.id AND status IN ('scheduled','checked_in','in_progress')) OR
 EXISTS(SELECT 1 FROM public.visits WHERE tenant_id=NEW.tenant_id AND patient_id=NEW.id AND status NOT IN ('completed','cancelled'))) THEN RAISE EXCEPTION 'Active attendance' USING ERRCODE='23505'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER patient_archive_booking_guard BEFORE UPDATE OF archived_at ON patients FOR EACH ROW EXECUTE FUNCTION governance.patient_archive_booking_guard();
CREATE FUNCTION governance.booking_reservation_integrity() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE j jsonb:=to_jsonb(NEW); aid uuid; vid uuid; res_id uuid;
BEGIN
 aid:=CASE WHEN TG_TABLE_NAME='appointments' THEN (j->>'id')::uuid ELSE (j->>'appointment_id')::uuid END;
 vid:=CASE WHEN TG_TABLE_NAME='visits' THEN (j->>'id')::uuid ELSE (j->>'visit_id')::uuid END;
 res_id:=CASE WHEN TG_TABLE_NAME='resource_reservations' THEN (j->>'id')::uuid ELSE (j->>'reservation_id')::uuid END;
 IF EXISTS(SELECT 1 FROM public.resource_reservations r JOIN public.appointments a ON (a.tenant_id,a.id)=(r.tenant_id,r.appointment_id) WHERE r.tenant_id=NEW.tenant_id AND (r.id=res_id OR r.appointment_id=aid OR r.visit_id=vid) AND r.active IS DISTINCT FROM (a.status IN ('scheduled','checked_in','in_progress')))
 OR EXISTS(SELECT 1 FROM public.resource_reservations r JOIN public.visits v ON (v.tenant_id,v.id)=(r.tenant_id,r.visit_id) WHERE r.tenant_id=NEW.tenant_id AND (r.id=res_id OR r.appointment_id=aid OR r.visit_id=vid) AND (r.appointment_id IS DISTINCT FROM v.appointment_id OR r.id IS DISTINCT FROM v.reservation_id
 OR (r.appointment_id IS NULL AND r.active IS DISTINCT FROM (v.status='in_progress'))))
 OR EXISTS(SELECT 1 FROM public.visits v JOIN public.appointments a ON (a.tenant_id,a.id)=(v.tenant_id,v.appointment_id) WHERE v.tenant_id=NEW.tenant_id AND (v.id=vid OR v.appointment_id=aid) AND NOT
 ((a.status='checked_in' AND v.status IN ('queued','called','skipped')) OR (a.status='in_progress' AND v.status='in_progress') OR (a.status='completed' AND v.status='completed') OR (a.status='cancelled' AND v.status='cancelled'))) THEN RAISE EXCEPTION 'Reservation consistency' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER reservation_integrity AFTER INSERT OR UPDATE ON resource_reservations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION governance.booking_reservation_integrity();
CREATE CONSTRAINT TRIGGER appointment_integrity AFTER INSERT OR UPDATE ON appointments DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION governance.booking_reservation_integrity();
CREATE CONSTRAINT TRIGGER visit_integrity AFTER INSERT OR UPDATE ON visits DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION governance.booking_reservation_integrity();
CREATE FUNCTION governance.booking_schedule_guard() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE tid uuid:=coalesce(NEW.tenant_id,OLD.tenant_id);
BEGIN
 IF EXISTS(SELECT 1 FROM public.appointments a WHERE a.tenant_id=tid AND a.status IN ('scheduled','checked_in','in_progress') AND NOT governance.booking_slot_valid(a.doctor_id,a.branch_id,a.service_id,a.room_id,a.starts_at,a.ends_at,false)) THEN
 RAISE EXCEPTION 'Existing booking would be invalidated' USING ERRCODE='23505'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER booking_schedule_guard AFTER INSERT OR UPDATE OR DELETE ON schedule_rules DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION governance.booking_schedule_guard();
CREATE CONSTRAINT TRIGGER booking_schedule_guard AFTER INSERT OR UPDATE OR DELETE ON schedule_breaks DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION governance.booking_schedule_guard();
CREATE CONSTRAINT TRIGGER booking_schedule_guard AFTER INSERT OR UPDATE OR DELETE ON schedule_exceptions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION governance.booking_schedule_guard();
CREATE CONSTRAINT TRIGGER booking_schedule_guard AFTER INSERT OR UPDATE OR DELETE ON schedule_exception_breaks DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION governance.booking_schedule_guard();
CREATE CONSTRAINT TRIGGER booking_schedule_guard AFTER INSERT OR UPDATE OR DELETE ON doctor_services DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION governance.booking_schedule_guard();
