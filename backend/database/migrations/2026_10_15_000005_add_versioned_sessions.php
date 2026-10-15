<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $functions = ['session_identity(uuid,bigint)', 'session_start(text)', 'session_write(text,uuid,bigint,text,text,integer)', 'session_logout(text)', 'my_clinics()', 'select_clinic(uuid)', 'employee_directory(uuid,integer)', 'platform_clinics(integer)', 'platform_audit(integer)', 'link_reject(uuid)', 'operation_denied(text)', 'assert_branch_delegation(text,uuid,uuid)'];

    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/sessions.sql')));
        foreach ($this->functions as $signature) {
            DB::statement('ALTER FUNCTION governance.'.$signature.' OWNER TO blusser_security');
            DB::statement('REVOKE ALL ON FUNCTION governance.'.$signature.' FROM PUBLIC');
            DB::statement('GRANT EXECUTE ON FUNCTION governance.'.$signature.' TO blusser_app');
        }
    }

    public function down(): void
    {
        foreach ($this->functions as $signature) {
            DB::statement('DROP FUNCTION IF EXISTS governance.'.$signature);
        }
        DB::statement('REVOKE INSERT, UPDATE, SELECT ON sessions FROM blusser_security');
        DB::statement('GRANT SELECT (user_id) ON sessions TO blusser_security');
        DB::statement('REVOKE SELECT ON audit_logs FROM blusser_security');
        DB::statement('GRANT INSERT, UPDATE ON sessions TO blusser_app');
    }
};
