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
        $before = 'AND q.local_date=day_local';
        $after = <<<'SQL'
AND (q.local_date=day_local OR EXISTS (
       SELECT 1 FROM public.appointments linked_a
       WHERE (linked_a.tenant_id,linked_a.id)=(v.tenant_id,v.appointment_id)
         AND linked_a.doctor_id=did AND linked_a.branch_id=bid
         AND (linked_a.starts_at AT TIME ZONE zone)::date=day_local
     ))
SQL;
        if (substr_count($definition, $before) !== 1) {
            throw new RuntimeException('Doctor workspace feed filter changed; review migration anchor.');
        }
        DB::unprepared(str_replace($before, $after, $definition));
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only doctor workspace feed fix.');
    }
};
