<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Doctor queue safety migration requires owner credentials.');
        }

        // Preserve the established governance authorization, idempotency,
        // reservation and ordering logic; change only the call preconditions.
        $signature = 'governance.queue_command(text,uuid,jsonb,uuid)';
        $definition = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS sql', [$signature])->sql;
        $versionCheck = "IF q.version IS DISTINCT FROM (payload->>'expected_version')::bigint THEN RAISE EXCEPTION 'Stale version' USING ERRCODE='23505'; END IF;";

        if (substr_count($definition, $versionCheck) !== 1) {
            throw new RuntimeException('Queue command changed; review single-patient guard before migrating.');
        }

        $guard = <<<'SQL'
IF action_code='call' AND q.status='waiting' THEN
 -- The doctor user row was locked above by governance.lock_users,
 -- serializing calls even if they are sent by two different staff sessions.
 IF EXISTS(
   SELECT 1 FROM public.queue_tickets active_q
   JOIN public.visits active_v
     ON (active_v.tenant_id,active_v.id)=(active_q.tenant_id,active_q.visit_id)
   WHERE active_q.tenant_id=tid AND active_v.doctor_id=v.doctor_id
     AND active_q.id<>q.id AND active_q.status IN ('called','in_progress')
 ) THEN
   RAISE EXCEPTION 'Previous patient not finished' USING ERRCODE='23505';
 END IF;
 -- Server-side FIFO: choosing a different ticket by ID cannot skip a waiting patient.
 IF EXISTS(
   SELECT 1 FROM public.queue_tickets earlier_q
   JOIN public.visits earlier_v
     ON (earlier_v.tenant_id,earlier_v.id)=(earlier_q.tenant_id,earlier_q.visit_id)
   WHERE earlier_q.tenant_id=tid AND earlier_v.doctor_id=v.doctor_id
     AND earlier_q.branch_id=q.branch_id AND earlier_q.local_date=q.local_date
     AND earlier_q.status='waiting'
     AND (earlier_q.created_at,earlier_q.id)<(q.created_at,q.id)
 ) THEN
   RAISE EXCEPTION 'Earlier patient is waiting' USING ERRCODE='23505';
 END IF;
END IF;
SQL;
        DB::unprepared(str_replace($versionCheck, $versionCheck."\n ".$guard, $definition));

        // A branch/day filtered feed alone cannot detect an unfinished visit
        // from another branch/day. Expose only a boolean, never another patient's data.
        $feedSignature = 'governance.doctor_workspace_feed(date,uuid,uuid)';
        $feed = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS sql', [$feedSignature])->sql;
        $marker = "'has_called_today', EXISTS(";
        if (substr_count($feed, $marker) !== 1) {
            throw new RuntimeException('Doctor workspace feed changed; review active-patient indicator.');
        }
        $active = <<<'SQL'
'has_active_patient', EXISTS(
            SELECT 1 FROM public.queue_tickets active_q
            JOIN public.visits active_v
              ON (active_v.tenant_id,active_v.id)=(active_q.tenant_id,active_q.visit_id)
            WHERE active_q.tenant_id=tid AND active_v.doctor_id=did
              AND active_q.status IN ('called','in_progress')
        ),
        SQL;
        DB::unprepared(str_replace($marker, $active."\n        ".$marker, $feed));
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only queue safety migration; do not remove live patient protections.');
    }
};
