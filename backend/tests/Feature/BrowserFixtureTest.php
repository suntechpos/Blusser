<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\FoundationTestCase;

/** Synthetic browser fixtures only; FoundationTestCase guards actual/configured blusser_test. */
final class BrowserFixtureTest extends FoundationTestCase
{
    public function test_prepare_synthetic_patients_browser_fixture(): void
    {
        $ids = $this->fixture();
        $db = DB::connection('migration');
        $a = $ids['a'];
        $b = $ids['b'];
        $db->table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $b['tenant'], 'user_id' => $a['user'], 'role_id' => $b['role']]);
        $membership = $db->table('tenant_memberships')->where('tenant_id', $b['tenant'])->where('user_id', $a['user'])->value('id');
        $db->table('membership_branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $b['tenant'], 'membership_id' => $membership, 'branch_id' => $b['branch']]);
        $second = (string) Str::uuid();
        $db->table('branches')->insert(['id' => $second, 'tenant_id' => $a['tenant'], 'name' => 'Synthetic second branch', 'code' => 'second']);
        $db->table('membership_branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $a['tenant'], 'membership_id' => $a['membership'], 'branch_id' => $second]);
        foreach (['reader', 'temporary', 'platform'] as $label) {
            $uid = (string) Str::uuid();
            $role = (string) Str::uuid();
            $mid = (string) Str::uuid();
            $db->table('users')->insert(['id' => $uid, 'login' => 'synthetic.'.$label, 'name' => 'Synthetic '.$label, 'password_hash' => Hash::make('Synthetic-password-42!'), 'must_change_password' => $label === 'temporary', 'temporary_password_expires_at' => $label === 'temporary' ? now()->addDay() : null, 'is_platform_admin' => $label === 'platform']);
            if ($label === 'platform') {
                continue;
            }
            $db->table('tenant_roles')->insert(['id' => $role, 'tenant_id' => $a['tenant'], 'code' => $label, 'name' => 'Synthetic '.$label]);
            $db->table('tenant_memberships')->insert(['id' => $mid, 'tenant_id' => $a['tenant'], 'user_id' => $uid, 'role_id' => $role]);
            $db->table('membership_branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $a['tenant'], 'membership_id' => $mid, 'branch_id' => $a['branch']]);
            $db->table('role_permissions')->insert(['tenant_id' => $a['tenant'], 'role_id' => $role, 'permission_id' => $db->table('permissions')->where('code', 'patients.view')->value('id'), 'scope' => 'assigned_branches']);
        }
        $this->assertSame('blusser_test', $db->selectOne('SELECT current_database() AS name')->name);
        $this->assertSame(3, $db->table('branches')->count());
    }
}
