<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Doctor workspace feed migration requires owner credentials.');
        }

        $signature = 'governance.doctor_workspace_feed(date,uuid,uuid)';
        $definition = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS sql', [$signature])->sql;
        // A visit alone must not hide an appointment. Only hide it when the
        // corresponding ticket actually belongs to the selected-day projection.
        $before = <<<'SQL'
AND NOT EXISTS (SELECT 1 FROM public.visits v
                      WHERE v.tenant_id=a.tenant_id AND v.appointment_id=a.id)
SQL;
        $after = <<<'SQL'
AND NOT EXISTS (
        SELECT 1 FROM public.visits linked_v
        JOIN public.queue_tickets linked_q
          ON (linked_q.tenant_id,linked_q.visit_id)=(linked_v.tenant_id,linked_v.id)
        WHERE linked_v.tenant_id=a.tenant_id
          AND linked_v.appointment_id=a.id
          AND linked_q.status<>'cancelled'
          AND (linked_q.local_date=day_local
            OR (a.starts_at AT TIME ZONE zone)::date=day_local)
      )
SQL;
        if (substr_count($definition, $before) !== 1) {
            throw new RuntimeException('Feed SQL changed: appointment/ticket visibility anchor not found.');
        }

        DB::unprepared(str_replace($before, $after, $definition));
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only doctor workspace projection repair.');
    }
};
