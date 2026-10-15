<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Foundation migrations require the PostgreSQL migration role.');
        }

        DB::unprepared(file_get_contents(database_path('sql/foundation.sql')));
    }

    public function down(): void
    {
        foreach (['auth_rate_limits', 'sessions', 'audit_logs', 'membership_link_request_branches', 'membership_link_requests', 'services', 'membership_branches', 'tenant_memberships', 'role_permissions', 'permissions', 'tenant_roles', 'branches', 'tenants', 'users'] as $table) {
            DB::statement('DROP TABLE '.$table);
        }
    }
};
