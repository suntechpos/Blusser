<?php

use App\Modules\ClinicOperations\Contracts\OperationsContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = ['specialties', 'doctor_profiles', 'doctor_services', 'doctor_branches', 'rooms', 'schedule_rules', 'schedule_breaks', 'schedule_exceptions', 'schedule_exception_breaks', 'scheduling_idempotency', 'scheduling_changes'];

    private const FUNCTIONS = ['valid_iana_timezone(text)', 'scheduling_identity()', 'doctor_branch_live(uuid,uuid)', 'scheduling_branch(text,uuid)', 'scheduling_visible(text,uuid)', 'scheduling_doctor_branch_visible(uuid,uuid)', 'scheduling_breaks_valid(jsonb,time without time zone,time without time zone)', 'scheduling_interval_conflict(jsonb,uuid,date)', 'scheduling_projection(text,uuid)', 'scheduling_validate_payload(text,jsonb)', 'scheduling_command(text,text,uuid,jsonb,uuid)', 'scheduling_availability_context(uuid,uuid,uuid)'];

    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Scheduling migration requires migration credentials.');
        }
        // Stage 2 already seeded and provenance-backfilled the exact Stage-1 catalog.
        foreach (OperationsContract::permissions() as $code => $scopes) {
            if (DB::table('permissions')->where('code', $code)->value('allowed_scopes') !== '{'.implode(',', $scopes).'}') {
                throw new RuntimeException('Permission catalog mismatch.');
            }
        }
        DB::unprepared(file_get_contents(database_path('sql/scheduling.sql')));
        DB::statement("INSERT INTO audit_logs(id,tenant_id,action,resource_type,metadata,request_id)
            SELECT gen_random_uuid(),id,'timezone.backfilled','deployment','{\"migration\":\"scheduling-stage-3\",\"timezone\":\"Asia/Tashkent\"}'::jsonb,gen_random_uuid() FROM tenants");
        foreach (self::TABLES as $table) {
            DB::statement("REVOKE ALL ON $table FROM PUBLIC, blusser_app");
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY migration_owner ON $table TO blusser_owner USING(true) WITH CHECK(true)");
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON $table TO blusser_security");
        }
        DB::statement('REVOKE UPDATE, DELETE ON scheduling_changes FROM blusser_security');
        foreach (['specialties' => 'specialties', 'doctor_profiles' => 'doctors', 'rooms' => 'rooms', 'schedule_rules' => 'schedules', 'schedule_exceptions' => 'schedule-exceptions'] as $table => $kind) {
            DB::statement("CREATE POLICY scheduling_read ON $table FOR SELECT TO blusser_app USING(tenant_id=public.context_tenant() AND governance.scheduling_visible('$kind',id))");
        }
        DB::statement('CREATE POLICY scheduling_read ON doctor_branches FOR SELECT TO blusser_app USING(tenant_id=public.context_tenant() AND governance.scheduling_doctor_branch_visible(doctor_id,branch_id))');
        DB::statement("CREATE POLICY scheduling_read ON doctor_services FOR SELECT TO blusser_app USING(tenant_id=public.context_tenant() AND governance.scheduling_visible('doctors',doctor_id))");
        DB::statement("CREATE POLICY scheduling_read ON schedule_breaks FOR SELECT TO blusser_app USING(tenant_id=public.context_tenant() AND governance.scheduling_visible('schedules',schedule_rule_id))");
        DB::statement("CREATE POLICY scheduling_read ON schedule_exception_breaks FOR SELECT TO blusser_app USING(tenant_id=public.context_tenant() AND governance.scheduling_visible('schedule-exceptions',schedule_exception_id))");
        DB::statement('GRANT SELECT ON specialties, doctor_profiles, doctor_branches, doctor_services, rooms, schedule_rules, schedule_breaks, schedule_exceptions, schedule_exception_breaks TO blusser_app');
        foreach (self::FUNCTIONS as $signature) {
            DB::statement('ALTER FUNCTION governance.'.$signature.' OWNER TO blusser_security');
            DB::statement('REVOKE ALL ON FUNCTION governance.'.$signature.' FROM PUBLIC, blusser_app');
        }
        foreach (['scheduling_identity()', 'scheduling_doctor_branch_visible(uuid,uuid)', 'scheduling_branch(text,uuid)', 'scheduling_visible(text,uuid)', 'scheduling_projection(text,uuid)', 'scheduling_command(text,text,uuid,jsonb,uuid)', 'scheduling_availability_context(uuid,uuid,uuid)'] as $signature) {
            DB::statement('GRANT EXECUTE ON FUNCTION governance.'.$signature.' TO blusser_app');
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Scheduling data exists; use a reviewed forward migration.');
            }
        }
        // Never discard configured timezone information during rollback.
        if (DB::table('tenants')->where('timezone', '<>', 'Asia/Tashkent')->exists() || DB::table('branches')->whereNotNull('timezone')->exists()) {
            throw new RuntimeException('Timezone configuration exists; use a reviewed forward migration.');
        }
        foreach (array_reverse(self::TABLES) as $table) {
            DB::statement('DROP TABLE '.$table);
        }
        DB::statement('REVOKE SELECT ON services FROM blusser_security');
        DB::statement('ALTER TABLE branches DROP COLUMN timezone');
        DB::statement('ALTER TABLE tenants DROP COLUMN timezone');
        foreach (array_reverse(self::FUNCTIONS) as $signature) {
            DB::statement('DROP FUNCTION governance.'.$signature);
        }
    }
};
