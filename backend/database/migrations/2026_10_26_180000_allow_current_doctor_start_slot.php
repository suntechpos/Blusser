<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Doctor start-slot fix requires owner credentials.');
        }

        $signature = 'governance.own_doctor_start_slot(uuid)';
        $definition = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS sql', [$signature])->sql;
        $before = "option_slot.lo>clock_timestamp()+interval '2 seconds'";
        $after = "option_slot.hi>clock_timestamp()+interval '2 seconds'";

        if (substr_count($definition, $before) !== 1) {
            throw new RuntimeException('Own-doctor start-slot SQL changed; review the current-slot fix.');
        }

        // A doctor must be able to start within a currently active, valid
        // 30-minute window. The prior >= future-start filter skipped that
        // window and incorrectly returned 409 near the end of a schedule.
        // The final queue command still checks schedule and reservation.
        DB::unprepared(str_replace($before, $after, $definition));
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only fix: preserve live consultation scheduling.');
    }
};
