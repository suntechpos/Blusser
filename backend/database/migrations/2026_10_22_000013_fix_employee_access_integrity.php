<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->requireOwner();
        DB::statement('ALTER TABLE tenant_memberships ADD COLUMN employee_version bigint NOT NULL DEFAULT 1 CHECK(employee_version>0)');
        // Preserve already issued profile ETags when upgrading an installed 000012.
        DB::statement('UPDATE tenant_memberships m SET employee_version=p.version FROM employee_profiles p WHERE p.tenant_id=m.tenant_id AND p.membership_id=m.id');
        DB::unprepared(file_get_contents(database_path('sql/access_editor_integrity.sql')));
        foreach (['employee_membership_version()', 'employee_branch_version()'] as $signature) {
            DB::statement('ALTER FUNCTION governance.'.$signature.' OWNER TO blusser_security');
            DB::statement('REVOKE ALL ON FUNCTION governance.'.$signature.' FROM PUBLIC,blusser_app');
        }
        DB::statement('DROP INDEX IF EXISTS role_name_upgrade_guard');
    }

    public function down(): void
    {
        $this->requireOwner();
        if (DB::table('employee_profiles')->exists() || DB::table('access_editor_intents')->exists()) {
            throw new RuntimeException('Access editor data exists; use a forward migration.');
        }
        $original = file_get_contents(database_path('sql/access_editor.sql'));
        foreach (['staff_command', 'staff_directory', 'link_profile_resolved'] as $name) {
            $start = strpos($original, 'CREATE FUNCTION governance.'.$name.'(');
            $end = strpos($original, 'END $$;', $start) + strlen('END $$;');
            DB::unprepared(substr_replace(substr($original, $start, $end - $start), 'CREATE OR REPLACE FUNCTION', 0, strlen('CREATE FUNCTION')));
        }
        DB::statement('DROP TRIGGER employee_membership_version ON tenant_memberships');
        DB::statement('DROP TRIGGER employee_branch_version ON membership_branches');
        DB::statement('DROP FUNCTION governance.employee_membership_version(),governance.employee_branch_version()');
        DB::statement('ALTER TABLE tenant_memberships DROP COLUMN employee_version');
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS role_name_upgrade_guard ON tenant_roles(tenant_id,lower(regexp_replace(btrim(name),'\\s+',' ','g')))");
    }

    private function requireOwner(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Access integrity migration requires migration credentials.');
        }
    }
};
