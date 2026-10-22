<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->owner();
        DB::statement('ALTER TABLE employee_profiles ADD COLUMN page_grants jsonb CHECK(page_grants IS NULL OR jsonb_typeof(page_grants)=\'array\')');
        DB::unprepared('CREATE TABLE employee_cashier_secrets(tenant_id uuid NOT NULL, employee_id uuid NOT NULL, pin_hash text NOT NULL, PRIMARY KEY(tenant_id,employee_id), FOREIGN KEY(tenant_id,employee_id) REFERENCES employee_profiles(tenant_id,id) ON DELETE CASCADE);
ALTER TABLE employee_cashier_secrets ENABLE ROW LEVEL SECURITY;
ALTER TABLE employee_cashier_secrets FORCE ROW LEVEL SECURITY;
CREATE POLICY migration_owner ON employee_cashier_secrets TO blusser_owner USING(true) WITH CHECK(true);
GRANT SELECT,INSERT,UPDATE,DELETE ON employee_cashier_secrets TO blusser_security;');
        DB::unprepared(file_get_contents(database_path('sql/employee_page_access.sql')));
    }

    public function down(): void
    {
        $this->owner();
        if (DB::table('employee_profiles')->whereNotNull('page_grants')->exists() || DB::table('employee_cashier_secrets')->exists()) {
            throw new RuntimeException('Employee access data exists; use a forward migration.');
        }
        $integrity = file_get_contents(database_path('sql/access_editor_integrity.sql'));
        foreach (['staff_command', 'staff_directory'] as $name) {
            $start = strpos($integrity, 'CREATE OR REPLACE FUNCTION governance.'.$name.'(');
            $end = strpos($integrity, 'END $$;', $start) + strlen('END $$;');
            DB::unprepared(substr($integrity, $start, $end - $start));
        }
        foreach (['security.sql' => 'CREATE FUNCTION public.permission_scope(', 'governance.sql' => 'CREATE FUNCTION governance.scope_for('] as $file => $marker) {
            $sql = file_get_contents(database_path('sql/'.$file));
            $start = strpos($sql, $marker);
            $end = strpos($sql, '$$;', strpos($sql, 'AS $$', $start)) + 3;
            DB::unprepared(str_replace('CREATE FUNCTION', 'CREATE OR REPLACE FUNCTION', substr($sql, $start, $end - $start)));
        }
        $sql = file_get_contents(database_path('sql/sessions.sql'));
        $start = strpos($sql, 'CREATE FUNCTION governance.assert_branch_delegation(');
        $end = strpos($sql, 'END $$;', $start) + strlen('END $$;');
        DB::unprepared(str_replace('CREATE FUNCTION', 'CREATE OR REPLACE FUNCTION', substr($sql, $start, $end - $start)));
        DB::statement('DROP TABLE employee_cashier_secrets');
        DB::statement('ALTER TABLE employee_profiles DROP COLUMN page_grants');
    }

    private function owner(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Employee page migration requires migration credentials.');
        }
    }
};
