<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SCOPED = ['branches', 'tenant_roles', 'role_permissions', 'tenant_memberships', 'membership_branches', 'services', 'membership_link_requests', 'membership_link_request_branches', 'audit_logs'];

    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/security.sql')));
        foreach ([...self::SCOPED, 'users', 'tenants'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            // Migration/provisioning owner is privileged; never granted to runtime.
            DB::statement("CREATE POLICY migration_owner ON $table TO blusser_owner USING (true) WITH CHECK (true)");
        }
    }

    public function down(): void
    {
        // Table-level REVOKE does not remove PostgreSQL column-level grants.
        DB::statement('REVOKE SELECT (id, login, name, email, active, must_change_password, credential_version, is_platform_admin) ON users FROM blusser_app');
        DB::statement('REVOKE ALL ON sessions FROM blusser_app');
        foreach ([...self::SCOPED, 'users', 'tenants'] as $table) {
            $policies = DB::select('SELECT policyname FROM pg_policies WHERE schemaname = ? AND tablename = ?', ['public', $table]);
            foreach ($policies as $policy) {
                DB::statement('DROP POLICY "'.$policy->policyname.'" ON '.$table);
            }
            DB::statement("ALTER TABLE $table DISABLE ROW LEVEL SECURITY");
            DB::statement("REVOKE ALL ON $table FROM blusser_app");
        }
        DB::statement('DROP TRIGGER validate_permission_scope ON role_permissions');
        foreach (['can_view_member(uuid)', 'can_access(text,uuid,uuid)', 'branch_assigned(uuid)', 'permission_scope(text)', 'is_active_member(uuid)', 'is_platform_admin()', 'context_user()', 'context_tenant()', 'validate_permission_scope()'] as $function) {
            DB::statement('DROP FUNCTION public.'.$function);
        }
    }
};
