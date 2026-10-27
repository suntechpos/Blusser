<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Rebooking availability migration requires owner credentials.');
        }

        DB::unprepared(file_get_contents(database_path('sql/queue_rebooking_availability.sql')));
        DB::statement('ALTER FUNCTION governance.queue_rebooking_options(uuid,text,date) OWNER TO blusser_security');
        DB::statement('REVOKE ALL ON FUNCTION governance.queue_rebooking_options(uuid,text,date) FROM PUBLIC,blusser_app');
        DB::statement('GRANT EXECUTE ON FUNCTION governance.queue_rebooking_options(uuid,text,date) TO blusser_app');
    }

    public function down(): void
    {
        throw new RuntimeException('Queue availability is forward-only; review before rollback.');
    }
};
