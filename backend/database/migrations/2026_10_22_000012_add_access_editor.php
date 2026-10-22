<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/access_editor.sql')));
        DB::statement('ALTER FUNCTION governance.membership_session_revoke() OWNER TO blusser_security');
        DB::statement('REVOKE ALL ON FUNCTION governance.membership_session_revoke() FROM PUBLIC, blusser_app');
        DB::statement('ALTER FUNCTION governance.link_profile_resolved() OWNER TO blusser_security');
        DB::statement('REVOKE ALL ON FUNCTION governance.link_profile_resolved() FROM PUBLIC, blusser_app');
        foreach (['staff_link(uuid,text,uuid,uuid[],uuid,bigint)', 'self_membership()', 'role_assignments(uuid)', 'staff_directory(uuid,integer)', 'access_role_command(text,uuid,jsonb,uuid,bigint)', 'staff_command(uuid,jsonb,uuid,bigint)'] as $signature) {
            DB::statement('ALTER FUNCTION governance.'.$signature.' OWNER TO blusser_security');
            DB::statement('REVOKE ALL ON FUNCTION governance.'.$signature.' FROM PUBLIC');
            DB::statement('GRANT EXECUTE ON FUNCTION governance.'.$signature.' TO blusser_app');
        }
        foreach (json_decode(file_get_contents(app_path('Modules/Access/Contracts/ui-permissions.json')), true, flags: JSON_THROW_ON_ERROR) as $p) {
            if (! DB::table('permissions')->where('code', $p['code'])->exists()) {
                DB::table('permissions')->insert(['id' => (string) Str::uuid(), 'code' => $p['code'], 'allowed_scopes' => '{own,assigned_branches,entire_clinic}']);
                // Only existing designated CEOs receive newly introduced UI grants.
                DB::statement("INSERT INTO role_permissions(tenant_id,role_id,permission_id,scope) SELECT c.tenant_id,m.role_id,p.id,'entire_clinic' FROM tenant_ceos c JOIN tenant_memberships m ON (m.tenant_id,m.id)=(c.tenant_id,c.membership_id) CROSS JOIN permissions p WHERE p.code=? ON CONFLICT DO NOTHING", [$p['code']]);
            }
        }
    }

    public function down(): void
    {
        if (DB::table('employee_profiles')->exists() || DB::table('access_editor_intents')->exists()) {
            throw new RuntimeException('Access editor data exists; use a forward migration.');
        }
        DB::statement('DROP TRIGGER IF EXISTS link_profile_resolved ON membership_link_requests');
        DB::statement('DROP FUNCTION IF EXISTS governance.link_profile_resolved(), governance.staff_link(uuid,text,uuid,uuid[],uuid,bigint)');
        DB::statement('DROP TRIGGER IF EXISTS membership_session_revoke ON tenant_memberships');
        DB::statement('DROP FUNCTION IF EXISTS governance.membership_session_revoke()');
        DB::statement('DROP FUNCTION IF EXISTS governance.self_membership(), governance.role_assignments(uuid), governance.staff_directory(uuid,integer), governance.access_role_command(text,uuid,jsonb,uuid,bigint), governance.staff_command(uuid,jsonb,uuid,bigint)');
        DB::statement('DROP TABLE access_editor_intents,employee_profiles');
        DB::statement('DROP INDEX role_name_normalized');
        DB::statement('ALTER TABLE tenant_roles DROP CONSTRAINT role_name_nonempty, DROP COLUMN note, DROP COLUMN version');
        // Permission rows may have delegated grants; retain rather than erase access data.
    }
};
