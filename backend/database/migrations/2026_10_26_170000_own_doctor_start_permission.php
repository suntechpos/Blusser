<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Own-doctor start migration requires owner credentials.');
        }

        // An own-scoped queue.start grant authorizes only the currently signed-in
        // doctor's own active branch. It does not grant schedule/catalog reads.
        DB::unprepared(<<<'SQL'
CREATE FUNCTION governance.own_doctor_start(did uuid,bid uuid)
RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT public.permission_scope('queues.start')='own'
    AND governance.queue_scope('queues.start',did,bid)
    AND EXISTS(
      SELECT 1 FROM public.doctor_profiles d
      JOIN public.tenant_memberships m ON (m.tenant_id,m.id)=(d.tenant_id,d.membership_id)
      WHERE (d.tenant_id,d.id)=(public.context_tenant(),did)
        AND m.user_id=public.context_user() AND m.active
    )
$$;
ALTER FUNCTION governance.own_doctor_start(uuid,uuid) OWNER TO blusser_security;
REVOKE ALL ON FUNCTION governance.own_doctor_start(uuid,uuid) FROM PUBLIC;
SQL
        );

        // The existing queue command still verifies queue, visit, doctor/branch,
        // version, state, the real booking_slot_valid() schedule, occupancy and
        // reservation within its audited transaction. Only the redundant catalog
        // and schedule *listing* permissions are waived for a doctor's own start.
        $signature = 'governance.queue_command(text,uuid,jsonb,uuid)';
        $definition = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS sql', [$signature])->sql;
        $replacements = [
            "IF NOT governance.booking_visible('visits',v.id,'visits.view') OR (v.appointment_id IS NOT NULL AND NOT governance.booking_visible('appointments',v.appointment_id,'appointments.view')) THEN" =>
                "IF NOT governance.booking_visible('visits',v.id,'visits.view') OR (v.appointment_id IS NOT NULL AND NOT governance.booking_visible('appointments',v.appointment_id,'appointments.view') AND NOT (action_code='start' AND governance.own_doctor_start(v.doctor_id,v.branch_id))) THEN",
            "IF v.appointment_id IS NOT NULL AND action_code IN ('start','complete','cancel') AND NOT governance.booking_scope(CASE WHEN action_code='cancel' THEN 'appointments.cancel' ELSE 'appointments.update' END,v.doctor_id,v.branch_id) THEN" =>
                "IF v.appointment_id IS NOT NULL AND action_code IN ('start','complete','cancel') AND NOT governance.booking_scope(CASE WHEN action_code='cancel' THEN 'appointments.cancel' ELSE 'appointments.update' END,v.doctor_id,v.branch_id) AND NOT (action_code='start' AND governance.own_doctor_start(v.doctor_id,v.branch_id)) THEN",
            "IF action_code IN ('start','requeue') AND NOT governance.patient_allowed(v.patient_id,'patients.view') THEN" =>
                "IF action_code IN ('start','requeue') AND NOT governance.patient_allowed(v.patient_id,'patients.view') AND NOT (action_code='start' AND governance.own_doctor_start(v.doctor_id,v.branch_id)) THEN",
            "IF action_code='start' AND (public.permission_scope('services.view') IS DISTINCT FROM 'entire_clinic' OR" =>
                "IF action_code='start' AND NOT governance.own_doctor_start(v.doctor_id,v.branch_id) AND (public.permission_scope('services.view') IS DISTINCT FROM 'entire_clinic' OR",
            "IF NOT governance.patient_allowed(v.patient_id,'patients.view') OR EXISTS(SELECT 1 FROM public.patients" =>
                "IF (NOT governance.patient_allowed(v.patient_id,'patients.view') AND NOT (action_code='start' AND governance.own_doctor_start(v.doctor_id,v.branch_id))) OR EXISTS(SELECT 1 FROM public.patients",
        ];
        foreach ($replacements as $before => $after) {
            if (substr_count($definition, $before) !== 1) {
                throw new RuntimeException('Queue authorization changed; review own-doctor permission patch.');
            }
            $definition = str_replace($before, $after, $definition);
        }
        DB::unprepared($definition);

        // Ticket-scoped schedule projection. It returns ONLY a valid room/slot
        // for an own called ticket, never arbitrary schedules or patient records.
        // The final POST /start rechecks everything atomically.
        DB::unprepared(<<<'SQL'
CREATE FUNCTION governance.own_doctor_start_slot(qid uuid)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE
 tid uuid:=public.context_tenant();
 q public.queue_tickets;
 v public.visits;
 a public.appointments;
 zone text;
 day_local date;
 selected_room uuid;
 selected_lo timestamptz;
 selected_hi timestamptz;
BEGIN
 IF NOT governance.scheduling_identity() OR NOT governance.queue_visible(qid) THEN
   RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002';
 END IF;
 SELECT * INTO q FROM public.queue_tickets WHERE (tenant_id,id)=(tid,qid);
 SELECT * INTO v FROM public.visits WHERE (tenant_id,id)=(tid,q.visit_id);
 IF q.status<>'called' OR NOT governance.own_doctor_start(v.doctor_id,v.branch_id)
    OR NOT governance.booking_visible('visits',v.id,'visits.view') THEN
   RAISE EXCEPTION 'Denied' USING ERRCODE='42501';
 END IF;
 SELECT coalesce(b.timezone,t.timezone) INTO zone
 FROM public.branches b JOIN public.tenants t ON t.id=b.tenant_id
 WHERE (b.tenant_id,b.id)=(tid,v.branch_id) AND b.active;
 day_local:=(clock_timestamp() AT TIME ZONE zone)::date;
 IF q.local_date<>day_local THEN RETURN NULL; END IF;
 IF v.appointment_id IS NOT NULL THEN
   SELECT * INTO a FROM public.appointments
   WHERE (tenant_id,id)=(tid,v.appointment_id);
   IF a.ends_at<=clock_timestamp() OR NOT EXISTS(
     SELECT 1 FROM public.resource_reservations rr
     WHERE (rr.tenant_id,rr.id)=(tid,v.reservation_id) AND rr.active
   ) OR NOT governance.booking_slot_valid(
     v.doctor_id,v.branch_id,v.service_id,a.room_id,a.starts_at,a.ends_at
   ) THEN RETURN NULL; END IF;
   RETURN jsonb_build_object('room_id',a.room_id,
     'starts_at',to_char(a.starts_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS"Z"'),
     'ends_at',to_char(a.ends_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS"Z"'));
 END IF;
 -- Walk-ins: current configured windows, exception replacements and service
 -- duration; appointment reservations and breaks are validated, not guessed.
 SELECT w.room_id,option_slot.lo,option_slot.hi
 INTO selected_room,selected_lo,selected_hi
 FROM (
   SELECT sr.room_id,sr.local_start,sr.local_end,
     coalesce(ds.duration_minutes,sr.slot_minutes) AS duration
   FROM public.schedule_rules sr
   JOIN public.doctor_services ds ON
     (ds.tenant_id,ds.doctor_id,ds.service_id)=(sr.tenant_id,sr.doctor_id,v.service_id)
   WHERE sr.tenant_id=tid AND sr.doctor_id=v.doctor_id AND sr.branch_id=v.branch_id
     AND sr.weekday=extract(isodow FROM day_local)::integer
     AND sr.effective_from<=day_local
     AND (sr.effective_to IS NULL OR sr.effective_to>=day_local)
     AND sr.active
     AND NOT EXISTS(
       SELECT 1 FROM public.schedule_exceptions ex
       WHERE ex.tenant_id=tid AND ex.doctor_id=v.doctor_id
         AND ex.branch_id=v.branch_id AND ex.local_date=day_local
     )
   UNION ALL
   SELECT ex.room_id,ex.local_start,ex.local_end,
     coalesce(ds.duration_minutes,ex.slot_minutes)
   FROM public.schedule_exceptions ex
   JOIN public.doctor_services ds ON
     (ds.tenant_id,ds.doctor_id,ds.service_id)=(ex.tenant_id,ex.doctor_id,v.service_id)
   WHERE ex.tenant_id=tid AND ex.doctor_id=v.doctor_id AND ex.branch_id=v.branch_id
     AND ex.local_date=day_local AND ex.kind='replace'
 ) w
 CROSS JOIN LATERAL generate_series(
   0,greatest(0,ceil(extract(epoch FROM (w.local_end-w.local_start))
      / (w.duration*60.0))::integer-1)
 ) n
 CROSS JOIN LATERAL (
   SELECT ((day_local::timestamp+w.local_start)+n*make_interval(mins=>w.duration))
      AT TIME ZONE zone AS lo,
     ((day_local::timestamp+w.local_start)+(n+1)*make_interval(mins=>w.duration))
      AT TIME ZONE zone AS hi
 ) option_slot
 JOIN public.rooms room ON
   room.tenant_id=tid AND room.id=w.room_id AND room.branch_id=v.branch_id
   AND room.active AND room.availability='available'
 WHERE option_slot.lo>clock_timestamp()+interval '2 seconds'
   AND governance.booking_slot_valid(
     v.doctor_id,v.branch_id,v.service_id,w.room_id,option_slot.lo,option_slot.hi
   )
   AND NOT EXISTS(
     SELECT 1 FROM public.resource_reservations rr
     WHERE rr.tenant_id=tid AND rr.active
       AND (rr.doctor_id=v.doctor_id OR rr.room_id=w.room_id)
       AND tstzrange(rr.starts_at,rr.ends_at,'[)')
         && tstzrange(option_slot.lo,option_slot.hi,'[)')
   )
 ORDER BY option_slot.lo,w.room_id
 LIMIT 1;
 IF selected_room IS NULL THEN RETURN NULL; END IF;
 RETURN jsonb_build_object('room_id',selected_room,
   'starts_at',to_char(selected_lo AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS"Z"'),
   'ends_at',to_char(selected_hi AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS"Z"'));
END $$;
ALTER FUNCTION governance.own_doctor_start_slot(uuid) OWNER TO blusser_security;
REVOKE ALL ON FUNCTION governance.own_doctor_start_slot(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION governance.own_doctor_start_slot(uuid) TO blusser_app;
SQL
        );
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only own-doctor consultation authorization migration.');
    }
};
