<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Doctor appointment rebooking requires owner credentials.');
        }
        DB::unprepared(file_get_contents(database_path('sql/doctor_appointment_rebooking.sql')));
        DB::statement('ALTER FUNCTION governance.appointment_doctor_rebooking_options(uuid,date) OWNER TO blusser_security');
        DB::statement('REVOKE ALL ON FUNCTION governance.appointment_doctor_rebooking_options(uuid,date) FROM PUBLIC,blusser_app');
        DB::statement('GRANT EXECUTE ON FUNCTION governance.appointment_doctor_rebooking_options(uuid,date) TO blusser_app');
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only migration; review before rollback.');
    }
};
