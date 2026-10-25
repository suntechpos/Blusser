<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SIGNATURE = 'governance.booking_command(text,uuid,jsonb,uuid)';

    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Reception check-in migration requires owner credentials.');
        }

        $original = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS sql', [self::SIGNATURE])->sql;
        $before = 'a.starts_at<clock_timestamp() OR NOT governance.booking_slot_valid';
        // Keep the existing advance check-in behavior while allowing arrivals
        // until 60 minutes after the scheduled end. The original booked slot,
        // active reservation, patient eligibility, doctor, room, tenant, and
        // optimistic-version checks remain intact; do not reschedule the slot.
        $after = "a.ends_at + interval '60 minutes' < clock_timestamp() OR NOT governance.booking_slot_valid";
        if (substr_count($original, $before) !== 1) {
            throw new RuntimeException('Booking command changed; review the check-in rule manually.');
        }

        DB::statement('CREATE TABLE governance.reception_checkin_function_backup (signature text PRIMARY KEY, definition text NOT NULL)');
        DB::statement('REVOKE ALL ON governance.reception_checkin_function_backup FROM PUBLIC, blusser_app, blusser_security');
        DB::insert('INSERT INTO governance.reception_checkin_function_backup VALUES (?, ?)', [self::SIGNATURE, $original]);
        DB::unprepared(str_replace($before, $after, $original));
    }

    public function down(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Reception check-in rollback requires owner credentials.');
        }

        $definition = DB::table('governance.reception_checkin_function_backup')
            ->where('signature', self::SIGNATURE)->value('definition');
        if (! is_string($definition)) {
            throw new RuntimeException('Original reception booking function not found.');
        }
        DB::unprepared($definition);
        DB::statement('DROP TABLE governance.reception_checkin_function_backup');
    }
};
