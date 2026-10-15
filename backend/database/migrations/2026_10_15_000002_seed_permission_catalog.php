<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $all = ['own', 'assigned_branches', 'entire_clinic'];
        $catalog = ['dashboard.view' => $all, 'audit.view' => $all];
        foreach (['branches', 'employees', 'services', 'roles'] as $section) {
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                $catalog["$section.$action"] = match (true) {
                    $section === 'employees' && $action === 'view' => $all,
                    $section === 'branches' && $action !== 'create' => ['assigned_branches', 'entire_clinic'],
                    default => ['entire_clinic'],
                };
            }
        }
        foreach (['employees.assign_role', 'employees.assign_branches', 'employees.lookup_identity', 'employees.reset_password', 'roles.manage_permissions'] as $code) {
            $catalog[$code] = ['entire_clinic'];
        }
        foreach ($catalog as $code => $scopes) {
            DB::table('permissions')->insert(['id' => (string) Str::uuid(), 'code' => $code, 'allowed_scopes' => '{'.implode(',', $scopes).'}']);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->delete();
        DB::table('permissions')->delete();
    }
};
