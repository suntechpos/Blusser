<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FUNCTIONS = ['queue_scope(text,uuid,uuid)', 'queue_visible(uuid)', 'queue_projection(uuid)', 'queue_attendance(uuid)', 'queue_command(text,uuid,jsonb,uuid)', 'queue_integrity()'];

    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Migration credentials required.');
        }
        DB::unprepared(file_get_contents(database_path('sql/queues.sql')));
        foreach (self::FUNCTIONS as $signature) {
            DB::statement('ALTER FUNCTION governance.'.$signature.' OWNER TO blusser_security');
            DB::statement('REVOKE ALL ON FUNCTION governance.'.$signature.' FROM PUBLIC,blusser_app');
        }
        foreach (['queue_visible(uuid)', 'queue_projection(uuid)', 'queue_command(text,uuid,jsonb,uuid)'] as $signature) {
            DB::statement('GRANT EXECUTE ON FUNCTION governance.'.$signature.' TO blusser_app');
        }
        DB::statement('CREATE POLICY queue_read ON queue_tickets FOR SELECT TO blusser_app USING(tenant_id=public.context_tenant() AND governance.queue_visible(id))');
        DB::statement('GRANT SELECT ON queue_tickets TO blusser_app');
    }

    public function down(): void
    {
        // Events/ledger are append-only deployment evidence. Never erase operational history.
        if (DB::table('queue_tickets')->where('status', '<>', 'waiting')->exists()
            || DB::table('operation_events')->whereIn('action', ['call', 'recall', 'start', 'complete', 'skip', 'requeue', 'queue_cancel'])->exists()
            || DB::table('operation_idempotency')->where('operation', 'like', 'queue.%')->exists()) {
            throw new RuntimeException('Queue operations exist; reviewed forward migration required.');
        }
        DB::statement('REVOKE SELECT ON queue_tickets FROM blusser_app');
        DB::statement('DROP POLICY queue_read ON queue_tickets');
        DB::statement('DROP TRIGGER queue_integrity ON queue_tickets');
        DB::statement('DROP TRIGGER queue_integrity ON visits');
        foreach (array_reverse(self::FUNCTIONS) as $signature) {
            DB::statement('DROP FUNCTION governance.'.$signature);
        }
        preg_match('/CREATE FUNCTION governance\.booking_schedule_guard.*?END \$\$;/s', file_get_contents(database_path('sql/appointments.sql')), $baseline);
        DB::unprepared(str_replace('CREATE FUNCTION', 'CREATE OR REPLACE FUNCTION', $baseline[0]));
        DB::statement('DROP INDEX queue_tickets_list');
        DB::statement('ALTER TABLE queue_tickets DROP CONSTRAINT queue_tickets_status_check');
        DB::statement("ALTER TABLE queue_tickets ADD CONSTRAINT queue_tickets_status_check CHECK(status='waiting')");
        DB::statement('ALTER TABLE queue_tickets DROP COLUMN called_at, DROP COLUMN started_at, DROP COLUMN completed_at');
    }
};
