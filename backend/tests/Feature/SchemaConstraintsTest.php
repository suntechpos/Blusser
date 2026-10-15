<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\FoundationTestCase;

final class SchemaConstraintsTest extends FoundationTestCase
{
    public function test_global_login_unique_and_canonical_and_hash_only_credentials(): void
    {
        $ids = $this->fixture();
        $user = (array) DB::connection('migration')->table('users')->where('id', $ids['a']['user'])->first();
        $user['id'] = (string) Str::uuid();
        $this->assertSqlState('23505', fn () => DB::connection('migration')->table('users')->insert($user));
        $user['login'] = ' Synthetic.A ';
        $this->assertSqlState('23514', fn () => DB::connection('migration')->table('users')->insert($user));
        $user['login'] = 'synthetic.c';
        $user['password_hash'] = 'plaintext-is-not-a-hash';
        $this->assertSqlState('23514', fn () => DB::connection('migration')->table('users')->insert($user));
    }

    public function test_cross_tenant_role_and_branch_foreign_keys_are_rejected(): void
    {
        $ids = $this->fixture();
        $owner = DB::connection('migration');
        $this->assertSqlState('23503', fn () => $owner->table('tenant_memberships')->where('id', $ids['a']['membership'])->update(['role_id' => $ids['b']['role']]));
        $this->assertSqlState('23503', fn () => $owner->table('membership_branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $ids['a']['tenant'], 'membership_id' => $ids['a']['membership'], 'branch_id' => $ids['b']['branch']]));
        $this->assertSqlState('23503', fn () => $owner->table('role_permissions')->where('tenant_id', $ids['a']['tenant'])->update(['role_id' => $ids['b']['role']]));
    }

    public function test_permission_scope_is_validated_by_database_not_only_application(): void
    {
        $ids = $this->fixture();
        $permission = DB::connection('migration')->table('permissions')->where('code', 'services.view')->value('id');
        $this->assertSqlState('23514', fn () => DB::connection('migration')->table('role_permissions')->where('role_id', $ids['a']['role'])->where('permission_id', $permission)->update(['scope' => 'own']));
    }

    public function test_temporary_credential_and_session_state_constraints(): void
    {
        $ids = $this->fixture();
        $owner = DB::connection('migration');
        $this->assertSqlState('23514', fn () => $owner->table('users')->where('id', $ids['a']['user'])->update(['must_change_password' => true]));
        $this->assertSqlState('23514', fn () => $owner->table('users')->where('id', $ids['a']['user'])->update(['credential_version' => 0]));
        $this->assertSqlState('23514', fn () => DB::table('sessions')->insert(['id' => 'synthetic', 'user_id' => $ids['a']['user'], 'payload' => '', 'last_activity' => time()]));
    }

    public function test_one_user_can_have_different_roles_in_two_clinics_without_duplicates(): void
    {
        $ids = $this->fixture();
        $owner = DB::connection('migration');
        $membership = ['id' => (string) Str::uuid(), 'tenant_id' => $ids['b']['tenant'], 'user_id' => $ids['a']['user'], 'role_id' => $ids['b']['role']];
        $owner->table('tenant_memberships')->insert($membership);
        $this->assertSame(2, $owner->table('tenant_memberships')->where('user_id', $ids['a']['user'])->count());
        $membership['id'] = (string) Str::uuid();
        $this->assertSqlState('23505', fn () => $owner->table('tenant_memberships')->insert($membership));
    }

    public function test_pending_links_and_composite_branch_relations_are_constrained(): void
    {
        $ids = $this->fixture();
        $owner = DB::connection('migration');
        $link = ['id' => (string) Str::uuid(), 'tenant_id' => $ids['a']['tenant'], 'target_user_id' => $ids['b']['user'], 'initiated_by_user_id' => $ids['a']['user'], 'role_id' => $ids['a']['role'], 'expires_at' => now()->addDay()];
        $owner->table('membership_link_requests')->insert($link);
        $this->assertSqlState('23503', fn () => $owner->table('membership_link_request_branches')->insert(['tenant_id' => $ids['a']['tenant'], 'request_id' => $link['id'], 'branch_id' => $ids['b']['branch']]));
        $link['id'] = (string) Str::uuid();
        $this->assertSqlState('23505', fn () => $owner->table('membership_link_requests')->insert($link));
    }
}
