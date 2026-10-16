<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/governance.sql')));
        foreach (DB::select("SELECT p.oid::regprocedure AS signature FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='governance'") as $function) {
            DB::statement('ALTER FUNCTION '.$function->signature.' OWNER TO blusser_security');
        }
        DB::statement('REVOKE ALL ON ALL FUNCTIONS IN SCHEMA governance FROM PUBLIC, blusser_app');
        foreach (['identity_lookup(text)', 'rate_reserve(text,boolean)', 'rate_finish(text,boolean)', 'password_change(text)', 'provision(text,text,text,text)', 'role_save(uuid,text,text,jsonb)', 'role_delete(uuid)', 'branch_create(text,text)', 'employee_create(text,text,text,uuid,uuid[])', 'employee_assign(uuid,uuid,uuid[],boolean)', 'employee_reset(uuid,text)', 'link_request(text,uuid,uuid[])', 'pending_links()', 'link_accept(uuid)', 'recovery_open(uuid)', 'recovery_approve(uuid,text,text)', 'recovery_consume(uuid,text,text)', 'login_exists(text)', 'auth_failure(text)'] as $signature) {
            DB::statement('GRANT EXECUTE ON FUNCTION governance.'.$signature.' TO blusser_app');
        }
    }

    public function down(): void
    {
        DB::statement('DROP SCHEMA governance CASCADE');
        DB::statement('DROP TABLE ceo_recovery_requests, tenant_ceos');
        DB::statement('ALTER TABLE membership_link_requests DROP COLUMN purpose, DROP COLUMN initiator_version');
        DB::statement('ALTER TABLE tenant_memberships DROP CONSTRAINT memberships_identity_unique');
        DB::statement('ALTER TABLE tenant_roles DROP COLUMN protected');
        // A pending clinic has no CEO: do not turn it into an active legacy clinic.
        DB::statement("UPDATE tenants SET status='suspended' WHERE status='pending'");
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT tenants_status_check');
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('active','suspended'))");
        DB::statement('REVOKE INSERT, UPDATE ON users, tenants FROM blusser_security');
        DB::statement('REVOKE INSERT, UPDATE, DELETE ON tenant_roles, role_permissions, tenant_memberships, membership_branches, membership_link_requests, membership_link_request_branches FROM blusser_security');
        DB::statement('REVOKE INSERT ON branches, audit_logs FROM blusser_security');
        DB::statement('REVOKE DELETE, SELECT (user_id) ON sessions FROM blusser_security');
        DB::statement('REVOKE ALL ON auth_rate_limits FROM blusser_security');
        DB::statement('REVOKE SELECT ON membership_link_requests, membership_link_request_branches FROM blusser_security');
        $sql = file_get_contents(database_path('sql/security.sql'));
        $start = strpos($sql, 'CREATE FUNCTION public.is_active_member');
        $end = strpos($sql, 'CREATE FUNCTION public.permission_scope', $start);
        DB::unprepared(str_replace('CREATE FUNCTION', 'CREATE OR REPLACE FUNCTION', substr($sql, $start, $end - $start)));
        DB::statement('DROP POLICY audit_read ON audit_logs');
        DB::statement("CREATE POLICY audit_read ON audit_logs FOR SELECT TO blusser_app USING ((tenant_id=public.context_tenant() AND public.can_access('audit.view',actor_user_id,branch_id)) OR (tenant_id IS NULL AND public.is_platform_admin()))");
    }
};
