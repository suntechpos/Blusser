<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Doctor start guard requires owner credentials.');
        }

        // Queue operations already lock the doctor user, so a second start
        // serializes with the first even when staff use separate sessions.
        $signature = 'governance.queue_command(text,uuid,jsonb,uuid)';
        $definition = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS sql', [$signature])->sql;
        $marker = "IF action_code='start' THEN\n  room:=(payload->>'room_id')::uuid;";
        if (substr_count($definition, $marker) !== 1) {
            throw new RuntimeException('Queue start command changed; review active consultation guard.');
        }
        $guard = <<<'SQL'
IF action_code='start' AND EXISTS(
  SELECT 1 FROM public.queue_tickets ongoing_q
  JOIN public.visits ongoing_v
    ON (ongoing_v.tenant_id,ongoing_v.id)=(ongoing_q.tenant_id,ongoing_q.visit_id)
  WHERE ongoing_q.tenant_id=tid AND ongoing_v.doctor_id=v.doctor_id
    AND ongoing_q.id<>q.id AND ongoing_q.status='in_progress'
) THEN
  RAISE EXCEPTION 'Another consultation is in progress' USING ERRCODE='23505';
END IF;
SQL;
        DB::unprepared(str_replace($marker, $guard."\n ".$marker, $definition));
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only consultation safety migration.');
    }
};
