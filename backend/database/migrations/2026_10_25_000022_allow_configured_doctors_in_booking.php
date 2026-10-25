<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DEFINITIONS = [
        'governance.doctor_branch_live(uuid,uuid)',
        'governance.reception_booking_options(uuid,integer,integer)',
    ];

    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Booking eligibility migration requires owner credentials.');
        }

        DB::statement('CREATE TABLE governance.booking_eligibility_function_backup (signature text PRIMARY KEY, definition text NOT NULL)');
        DB::statement('REVOKE ALL ON governance.booking_eligibility_function_backup FROM PUBLIC, blusser_app, blusser_security');

        foreach (self::DEFINITIONS as $signature) {
            $definition = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS sql', [$signature])->sql;
            DB::insert('INSERT INTO governance.booking_eligibility_function_backup VALUES (?,?)', [$signature, $definition]);

            if (str_contains($signature, 'doctor_branch_live')) {
                // The target doctor is bookable when configured by an authorised
                // receptionist. The doctor must still rotate their own password
                // before logging in: scheduling_identity() remains unchanged.
                $definition = $this->replace($definition, 'JOIN public.specialties s ON', 'LEFT JOIN public.specialties s ON');
                $definition = $this->replace($definition, ' AND NOT u.must_change_password', '');
            } else {
                // A free-text employee specialty does not require a separate
                // active catalog specialty for a valid doctor + service booking.
                $definition = $this->replace($definition, 'JOIN public.specialties s ON', 'LEFT JOIN public.specialties s ON');
            }

            DB::unprepared($definition);
        }
    }

    private function replace(string $sql, string $old, string $new): string
    {
        if (substr_count($sql, $old) !== 1) {
            throw new RuntimeException('Booking eligibility function changed; review migration.');
        }

        return str_replace($old, $new, $sql);
    }

    public function down(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Booking eligibility rollback requires owner credentials.');
        }

        foreach (DB::table('governance.booking_eligibility_function_backup')->get() as $row) {
            DB::unprepared($row->definition);
        }
        DB::statement('DROP TABLE governance.booking_eligibility_function_backup');
    }
};
