<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Doctor overdue queue migration requires owner credentials.');
        }

        // Do not weaken the existing cross-day single-patient command guards.
        // Resolve the selected doctor/branch through the same secured workspace
        // feed first, then expose only individually authorized overdue tickets.
        DB::unprepared(<<<'SQL'
CREATE FUNCTION governance.doctor_workspace_overdue(requested_doctor uuid, requested_branch uuid)
RETURNS jsonb LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE
 tid uuid:=public.context_tenant();
 workspace jsonb;
 did uuid;
 permitted jsonb:='[]'::jsonb;
BEGIN
 workspace:=governance.doctor_workspace_feed(NULL::date,requested_doctor,requested_branch);
 did:=(workspace->'doctor'->>'id')::uuid;
 IF did IS NULL THEN RETURN permitted; END IF;

 SELECT COALESCE(jsonb_agg(
   jsonb_build_object(
     'id',items.id,'visit_id',items.visit_id,'patient_id',items.patient_id,
     'name',items.name,'patient_number',items.patient_number,
     'service',items.service,'status',items.status,'version',items.version,
     'local_date',items.local_date,'branch_id',items.branch_id,
     'branch_name',items.branch_name,'appointment_id',items.appointment_id,
     'number',items.number,'kind',items.kind)
   ORDER BY items.local_date,items.created_at,items.id),'[]'::jsonb)
 INTO permitted
 FROM (
   SELECT q.id,v.id AS visit_id,p.id AS patient_id,p.name,p.patient_number,
     s.name AS service,q.status,q.version,q.local_date,q.branch_id,
     b.name AS branch_name,v.appointment_id,q.number,q.kind,q.created_at
   FROM public.queue_tickets q
   JOIN public.visits v ON (v.tenant_id,v.id)=(q.tenant_id,q.visit_id)
   JOIN public.patients p ON (p.tenant_id,p.id)=(v.tenant_id,v.patient_id)
   JOIN public.services s ON (s.tenant_id,s.id)=(v.tenant_id,v.service_id)
   JOIN public.branches b ON (b.tenant_id,b.id)=(q.tenant_id,q.branch_id)
   JOIN public.tenants t ON t.id=q.tenant_id
   WHERE q.tenant_id=tid AND v.doctor_id=did
     AND q.status IN ('called','in_progress')
     AND q.local_date < (clock_timestamp() AT TIME ZONE coalesce(b.timezone,t.timezone))::date
     AND governance.queue_scope('queues.view',v.doctor_id,q.branch_id)
     AND governance.booking_visible('visits',v.id,'visits.view')
     AND (v.appointment_id IS NULL OR governance.booking_visible('appointments',v.appointment_id,'appointments.view'))
   ORDER BY q.local_date,q.created_at,q.id
   LIMIT 100
 ) AS items;
 RETURN permitted;
END $$;
ALTER FUNCTION governance.doctor_workspace_overdue(uuid,uuid) OWNER TO blusser_security;
REVOKE ALL ON FUNCTION governance.doctor_workspace_overdue(uuid,uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION governance.doctor_workspace_overdue(uuid,uuid) TO blusser_app;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Doctor overdue queue recovery is forward-only.');
    }
};
