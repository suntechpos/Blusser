<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Booking migration requires migration credentials.');
        }
        DB::statement('ALTER TABLE appointments ADD COLUMN note text CHECK (length(note)<=2000)');
        // Reviewed forward-only changes to the established booking command; keep its
        // locks, digest, replay, scope checks and exclusion constraints unchanged.
        $base = file_get_contents(database_path('sql/appointments.sql'));
        foreach (['booking_validate_payload', 'booking_command', 'booking_projection'] as $name) {
            if (! preg_match('/CREATE FUNCTION governance\\.'.$name.'.*?\n(?:END )?\$\$;/s', $base, $match)) {
                throw new RuntimeException('Booking baseline changed; review migration.');
            }
            $sql = str_replace('CREATE FUNCTION', 'CREATE OR REPLACE FUNCTION', $match[0]);
            if ($name === 'booking_validate_payload') {
                $sql = str_replace("'room_id','starts_at','ends_at']", "'room_id','starts_at','ends_at','note']", $sql);
                $sql = str_replace("array_remove(fields,'reason_code')", "array_remove(array_remove(fields,'reason_code'),'note')", $sql);
                $sql = str_replace("IF k='expected_version' THEN", "IF k='note' THEN\n   IF jsonb_typeof(v) NOT IN ('null','string') OR length(p->>k)>2000 THEN RAISE EXCEPTION 'Invalid note' USING ERRCODE='22023'; END IF;\n  ELSIF k='expected_version' THEN", $sql);
            } elseif ($name === 'booking_command') {
                $sql = str_replace('reservation_id,starts_at,ends_at) VALUES(result_id,tid,pid,did,bid,sid,room,res_id,lo,hi)', "reservation_id,starts_at,ends_at,note) VALUES(result_id,tid,pid,did,bid,sid,room,res_id,lo,hi,payload->>'note')", $sql);
            } else {
                $sql = str_replace("'status',a.status,'version'", "'note',a.note,'status',a.status,'version'", $sql);
            }
            DB::unprepared($sql);
        }
        DB::unprepared(file_get_contents(database_path('sql/reception_booking_options.sql')));
    }

    public function down(): void
    {
        if (DB::table('appointments')->whereNotNull('note')->exists()) {
            throw new RuntimeException('Appointment notes exist; use a reviewed forward migration.');
        }
        DB::statement('DROP FUNCTION governance.reception_booking_options(uuid,integer,integer)');
        $base = file_get_contents(database_path('sql/appointments.sql'));
        foreach (['booking_validate_payload', 'booking_command', 'booking_projection'] as $name) {
            preg_match('/CREATE FUNCTION governance\\.'.$name.'.*?\n(?:END )?\$\$;/s', $base, $match);
            DB::unprepared(str_replace('CREATE FUNCTION', 'CREATE OR REPLACE FUNCTION', $match[0]));
        }
        DB::statement('ALTER TABLE appointments DROP COLUMN note');
    }
};
