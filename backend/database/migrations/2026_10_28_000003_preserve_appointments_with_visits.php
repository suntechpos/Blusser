<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Doctor workspace projection migration requires owner credentials.');
        }

        // A linked visit may belong to a different local day. Never hide the
        // booked appointment solely because the visit/ticket was created.
        // The UI deduplicates rows when the corresponding ticket is visible.
        $signature = 'governance.doctor_workspace_feed(date,uuid,uuid)';
        $definition = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS sql', [$signature])->sql;
        $before = <<<'SQL'
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
            throw new RuntimeException('Doctor workspace projection differs from the expected previous migration.');
        }
        DB::unprepared(str_replace($before, '', $definition));
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only workspace projection fix.');
    }
};
