CREATE OR REPLACE FUNCTION governance.appointment_doctor_rebooking_options(appointment_id uuid,requested_day date)
RETURNS jsonb LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE a public.appointments; tid uuid:=public.context_tenant(); did uuid; bid uuid; sid uuid; zone text; slots jsonb; busy_slots jsonb;
BEGIN
 IF NOT governance.scheduling_identity() OR NOT governance.booking_visible('appointments',appointment_id,'appointments.view') THEN
   RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002';
 END IF;
 SELECT * INTO a FROM public.appointments WHERE (tenant_id,id)=(tid,appointment_id);
 IF a.status <> 'scheduled' THEN RAISE EXCEPTION 'State conflict' USING ERRCODE='23505'; END IF;
 IF NOT governance.booking_scope('appointments.update',a.doctor_id,a.branch_id)
    OR NOT governance.patient_allowed(a.patient_id,'patients.view') THEN
   RAISE EXCEPTION 'Denied' USING ERRCODE='42501';
 END IF;
 did:=a.doctor_id; bid:=a.branch_id; sid:=a.service_id;
 SELECT coalesce(b.timezone,t.timezone) INTO zone FROM public.branches b
 JOIN public.tenants t ON t.id=b.tenant_id
 WHERE (b.tenant_id,b.id)=(tid,bid) AND b.active;
 IF zone IS NULL THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
 IF requested_day IS NULL OR NOT isfinite(requested_day) THEN
   RAISE EXCEPTION 'Invalid date' USING ERRCODE='22023';
 END IF;
 SELECT coalesce(jsonb_agg(jsonb_build_object('room_id',s.room_id,
   'starts_at',to_char(s.lo AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS"Z"'),
   'ends_at',to_char(s.hi AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS"Z"')) ORDER BY s.lo,s.room_id)
   FILTER (WHERE NOT s.occupied),'[]'::jsonb),
   coalesce(jsonb_agg(jsonb_build_object('room_id',s.room_id,
   'starts_at',to_char(s.lo AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS"Z"'),
   'ends_at',to_char(s.hi AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS"Z"')) ORDER BY s.lo,s.room_id)
   FILTER (WHERE s.occupied),'[]'::jsonb)
 INTO slots,busy_slots FROM (
   SELECT DISTINCT w.room_id,option_slot.lo,option_slot.hi,
      EXISTS(SELECT 1 FROM public.resource_reservations rr
       WHERE rr.tenant_id=tid AND rr.active AND (rr.doctor_id=did OR rr.room_id=w.room_id)
       AND rr.appointment_id IS DISTINCT FROM $1
       AND tstzrange(rr.starts_at,rr.ends_at,'[)')&&tstzrange(option_slot.lo,option_slot.hi,'[)')) AS occupied
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
 ) s;
 RETURN jsonb_build_object('local_date',requested_day,'timezone',zone,'slots',slots,'busy_slots',busy_slots);
END $$;
