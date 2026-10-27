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

        // Doctor self-service rescheduling must not require full clinic catalog access.
        // Keep the existing restrictions unchanged for everyone else.
        $definition = DB::selectOne(
            'SELECT pg_get_functiondef(?::regprocedure) AS sql',
            ['governance.booking_command(text,uuid,jsonb,uuid)'],
        )->sql;
        $before = "IF public.permission_scope('services.view') IS DISTINCT FROM 'entire_clinic' OR NOT governance.scheduling_branch('doctors.view',bid) OR NOT governance.scheduling_branch('rooms.view',bid) OR NOT governance.scheduling_branch('schedules.view',bid) THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;";
        $after = "IF NOT (action_code='reschedule' AND a.id=rid AND did=a.doctor_id AND bid=a.branch_id AND public.permission_scope('appointments.update')='own' AND governance.booking_scope('appointments.update',did,bid)) AND (public.permission_scope('services.view') IS DISTINCT FROM 'entire_clinic' OR NOT governance.scheduling_branch('doctors.view',bid) OR NOT governance.scheduling_branch('rooms.view',bid) OR NOT governance.scheduling_branch('schedules.view',bid)) THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;";
        if (substr_count($definition, $before) !== 1) {
            throw new RuntimeException('Booking command permissions changed; review migration anchor.');
        }
        DB::unprepared(str_replace($before, $after, $definition));
        DB::statement('ALTER FUNCTION governance.appointment_doctor_rebooking_options(uuid,date) OWNER TO blusser_security');
        DB::statement('REVOKE ALL ON FUNCTION governance.appointment_doctor_rebooking_options(uuid,date) FROM PUBLIC,blusser_app');
        DB::statement('GRANT EXECUTE ON FUNCTION governance.appointment_doctor_rebooking_options(uuid,date) TO blusser_app');
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only migration; review before rollback.');
    }
};
