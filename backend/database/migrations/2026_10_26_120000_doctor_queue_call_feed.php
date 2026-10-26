<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Doctor queue feed migration requires owner credentials.');
        }

        // This is an additive projection change. The original security-definer
        // function retains its existing tenant, doctor and branch authorization.
        $signature = 'governance.doctor_workspace_feed(date,uuid,uuid)';
        $definition = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS sql', [$signature])->sql;
        $changes = [
            "'status',q.status,'appointment_id',v.appointment_id,'created_at',q.created_at" =>
                "'status',q.status,'version',q.version,'called_at',q.called_at,'appointment_id',v.appointment_id,'created_at',q.created_at",
            'ORDER BY q.number,q.created_at,q.id' => 'ORDER BY q.created_at,q.id',
            "'scheduled',scheduled_rows,'tickets',ticket_rows);" =>
                "'scheduled',scheduled_rows,'tickets',ticket_rows,\n".
                "        'has_called_today', EXISTS(\n".
                "            SELECT 1 FROM public.queue_tickets called_q\n".
                "            JOIN public.visits called_v ON (called_v.tenant_id,called_v.id)=(called_q.tenant_id,called_q.visit_id)\n".
                "            WHERE called_q.tenant_id=tid AND called_v.doctor_id=did\n".
                "              AND called_q.branch_id=bid AND called_q.local_date=day_local\n".
                "              AND called_q.called_at IS NOT NULL));",
        ];
        foreach ($changes as $before => $after) {
            if (substr_count($definition, $before) !== 1) {
                throw new RuntimeException('Doctor workspace feed changed; review forward migration.');
            }
            $definition = str_replace($before, $after, $definition);
        }

        DB::unprepared($definition);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only doctor workspace feed migration; review before rollback.');
    }
};
