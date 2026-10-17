<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = ['appointments', 'visits', 'resource_reservations', 'queue_tickets', 'queue_counters', 'operation_events', 'operation_idempotency'];

    private const FUNCTIONS = ['booking_scope(text,uuid,uuid)', 'booking_visible(text,uuid,text)', 'booking_projection(text,uuid)', 'attendance_projection(uuid)', 'booking_local_unique(timestamp without time zone,text)', 'booking_slot_valid(uuid,uuid,uuid,uuid,timestamp with time zone,timestamp with time zone,boolean)', 'booking_event(text,uuid,uuid,uuid,text,text,text,uuid)', 'booking_validate_payload(text,jsonb)', 'booking_command(text,uuid,jsonb,uuid)', 'booking_event_visible(uuid)', 'booking_slot_free(uuid,uuid,timestamp with time zone,timestamp with time zone)', 'booking_export_audit()', 'patient_archive_booking_guard()', 'booking_reservation_integrity()', 'booking_schedule_guard()'];

    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Migration credentials required.');
        }
        DB::unprepared(file_get_contents(database_path('sql/appointments.sql')));
        // Narrow changes to existing patient functions; ETag/version/replay logic stays byte-identical.
        $sql = file_get_contents(database_path('sql/patient_optimistic_lock.sql'));
        $guard = "IF scope_value='own' THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;";
        if (substr_count($sql, $guard) !== 1) {
            throw new RuntimeException('Patient command baseline changed; review migration.');
        }
        DB::unprepared(str_replace($guard, "IF scope_value='own' AND action_code<>'update' THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;", $sql));
        $base = file_get_contents(database_path('sql/patients.sql'));
        preg_match('/CREATE FUNCTION governance\.patient_projection.*?\n\$\$;/s', $base, $m);
        DB::unprepared(str_replace(['CREATE FUNCTION', "(public.permission_scope('patients.view')='entire_clinic' OR public.branch_assigned(pb.branch_id))"], ['CREATE OR REPLACE FUNCTION', 'governance.patient_branch_visible(pid,pb.branch_id)'], $m[0]));
        foreach (self::TABLES as $table) {
            DB::statement("REVOKE ALL ON $table FROM PUBLIC,blusser_app");
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY migration_owner ON $table TO blusser_owner USING(true) WITH CHECK(true)");
            DB::statement("GRANT SELECT,INSERT,UPDATE ON $table TO blusser_security");
        }
        DB::statement('GRANT DELETE ON operation_idempotency TO blusser_security');
        DB::statement('REVOKE UPDATE ON operation_events FROM blusser_security');
        foreach (['appointments', 'visits'] as $kind) {
            DB::statement("CREATE POLICY operations_read ON $kind FOR SELECT TO blusser_app USING(tenant_id=public.context_tenant() AND governance.booking_visible('$kind',id,'$kind.view'))");
        }
        DB::statement('CREATE POLICY operations_read ON operation_events FOR SELECT TO blusser_app USING(tenant_id=public.context_tenant() AND governance.booking_event_visible(id))');
        DB::statement('GRANT SELECT ON appointments,visits,operation_events TO blusser_app');
        foreach (self::FUNCTIONS as $signature) {
            DB::statement('ALTER FUNCTION governance.'.$signature.' OWNER TO blusser_security');
            DB::statement('REVOKE ALL ON FUNCTION governance.'.$signature.' FROM PUBLIC,blusser_app');
        }
        foreach (['booking_visible(text,uuid,text)', 'booking_projection(text,uuid)', 'booking_event_visible(uuid)', 'booking_slot_free(uuid,uuid,timestamp with time zone,timestamp with time zone)', 'booking_command(text,uuid,jsonb,uuid)', 'booking_export_audit()'] as $signature) {
            DB::statement('GRANT EXECUTE ON FUNCTION governance.'.$signature.' TO blusser_app');
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Clinical data exists; reviewed forward migration required.');
            }
        }
        DB::statement('DROP TRIGGER patient_archive_booking_guard ON patients');
        foreach (['schedule_rules', 'schedule_breaks', 'schedule_exceptions', 'schedule_exception_breaks', 'doctor_services'] as $table) {
            DB::statement('DROP TRIGGER booking_schedule_guard ON '.$table);
        }
        $base = file_get_contents(database_path('sql/patients.sql'));
        foreach (['patient_allowed', 'patient_branch_visible', 'patient_projection'] as $name) {
            preg_match('/CREATE FUNCTION governance\.'.$name.'.*?\n\$\$;/s', $base, $m);
            DB::unprepared(str_replace('CREATE FUNCTION', 'CREATE OR REPLACE FUNCTION', $m[0]));
        }
        DB::unprepared(file_get_contents(database_path('sql/patient_optimistic_lock.sql')));
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT appointment_occupancy');
        DB::statement('ALTER TABLE visits DROP CONSTRAINT visit_occupancy');
        foreach (array_reverse(self::TABLES) as $table) {
            DB::statement('DROP TABLE '.$table);
        }
        foreach (array_reverse(self::FUNCTIONS) as $signature) {
            DB::statement('DROP FUNCTION governance.'.$signature);
        }
        // Keep btree_gist installed: shared extension may have other reviewed dependents.
    }
};
