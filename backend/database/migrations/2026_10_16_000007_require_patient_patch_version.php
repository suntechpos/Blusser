<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->requireOwner();
        // Same signature: PostgreSQL preserves owner and EXECUTE ACL on replacement.
        DB::unprepared(file_get_contents(database_path('sql/patient_optimistic_lock.sql')));
    }

    public function down(): void
    {
        $this->requireOwner();
        $original = file_get_contents(database_path('sql/patients.sql'));
        $start = strpos($original, 'CREATE FUNCTION governance.patient_command(');
        $end = strpos($original, 'GRANT DELETE ON patient_idempotency', $start);
        DB::unprepared(substr_replace(substr($original, $start, $end - $start), 'CREATE OR REPLACE FUNCTION', 0, strlen('CREATE FUNCTION')));
    }

    private function requireOwner(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Patients migration requires migration credentials.');
        }
    }
};
