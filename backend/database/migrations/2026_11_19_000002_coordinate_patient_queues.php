<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Queue coordination requires owner credentials.');
        }
        DB::unprepared(<<<'SQL'
CREATE FUNCTION governance.patient_queue_block(tid uuid,qid uuid) RETURNS text
LANGUAGE sql STABLE SET search_path=pg_catalog AS $$
 SELECT CASE
 WHEN EXISTS(SELECT 1 FROM public.visits ov JOIN public.queue_tickets oq ON (oq.tenant_id,oq.visit_id)=(ov.tenant_id,ov.id)
 WHERE ov.tenant_id=tid AND ov.patient_id=v.patient_id AND oq.id<>q.id AND oq.status IN ('called','in_progress')) THEN 'patient_busy'
 WHEN a.id IS NOT NULL AND EXISTS(
 SELECT 1 FROM public.appointments earlier
 JOIN public.branches b ON (b.tenant_id,b.id)=(q.tenant_id,q.branch_id)
 JOIN public.tenants t ON t.id=q.tenant_id
 WHERE earlier.tenant_id=tid AND earlier.patient_id=v.patient_id AND earlier.id<>a.id
 AND earlier.status IN ('scheduled','checked_in')
 AND earlier.starts_at<a.starts_at
 AND (earlier.starts_at AT TIME ZONE coalesce(b.timezone,t.timezone))::date=q.local_date
 AND NOT EXISTS(SELECT 1 FROM public.visits ev JOIN public.queue_tickets eq ON (eq.tenant_id,eq.visit_id)=(ev.tenant_id,ev.id)
 WHERE ev.tenant_id=tid AND ev.appointment_id=earlier.id AND eq.status IN ('skipped','cancelled','completed'))
 ) THEN 'earlier_appointment'
 ELSE NULL END
 FROM public.queue_tickets q JOIN public.visits v ON (v.tenant_id,v.id)=(q.tenant_id,q.visit_id)
 LEFT JOIN public.appointments a ON (a.tenant_id,a.id)=(v.tenant_id,v.appointment_id)
 WHERE q.tenant_id=tid AND q.id=qid
$$;
ALTER FUNCTION governance.patient_queue_block(uuid,uuid) OWNER TO blusser_security;
REVOKE ALL ON FUNCTION governance.patient_queue_block(uuid,uuid) FROM PUBLIC,blusser_app;
SQL);
        $replace = static function (string $signature, array $changes): void {
            $sql = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS sql', [$signature])->sql;
            foreach ($changes as $before => $after) {
                if (substr_count($sql, $before) !== 1) {
                    throw new RuntimeException('Queue coordination anchor changed: '.$signature);
                }
                $sql = str_replace($before, $after, $sql);
            }
            DB::unprepared($sql);
        };
        $marker = "IF q.version IS DISTINCT FROM (payload->>'expected_version')::bigint THEN RAISE EXCEPTION 'Stale version' USING ERRCODE='23505'; END IF;";
        $replace('governance.queue_command(text,uuid,jsonb,uuid)', [
            $marker => $marker."\n IF action_code IN ('call','start','recall') AND governance.patient_queue_block(tid,q.id) IS NOT NULL THEN RAISE EXCEPTION 'Patient has another active or earlier appointment' USING ERRCODE='23505'; END IF;",
            "AND earlier_q.status='waiting'" => "AND earlier_q.status='waiting' AND governance.patient_queue_block(tid,earlier_q.id) IS NULL",
        ]);
        $replace('governance.doctor_workspace_feed(date,uuid,uuid)', [
            "'status',q.status,'version',q.version" => "'status',q.status,'blocked_reason',CASE WHEN q.status='waiting' THEN governance.patient_queue_block(tid,q.id) ELSE NULL END,'starts_at',(SELECT a.starts_at FROM public.appointments a WHERE (a.tenant_id,a.id)=(v.tenant_id,v.appointment_id)),'version',q.version",
        ]);
        $replace('governance.display_feed(uuid,uuid)', [
            "FROM rows WHERE status='waiting'" => "FROM rows WHERE status='waiting' AND governance.patient_queue_block(tid,id) IS NULL",
        ]);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only patient queue safety migration.');
    }
};
