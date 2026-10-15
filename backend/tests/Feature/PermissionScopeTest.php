<?php

namespace Tests\Feature;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\FoundationTestCase;

final class PermissionScopeTest extends FoundationTestCase
{
    public function test_assigned_branch_scope_and_revocation_are_applied_by_database(): void
    {
        $ids = $this->fixture();
        $owner = DB::connection('migration');
        $extra = (string) Str::uuid();
        $owner->table('branches')->insert(['id' => $extra, 'tenant_id' => $ids['a']['tenant'], 'name' => 'Unassigned synthetic branch', 'code' => 'extra']);
        $permission = $owner->table('permissions')->where('code', 'branches.view')->value('id');
        $owner->table('role_permissions')->where('role_id', $ids['a']['role'])->where('permission_id', $permission)->update(['scope' => 'assigned_branches']);
        app(TenantContext::class)->run($this->actor($ids['a']['user']), $ids['a']['tenant'], function () use ($ids, $extra) {
            $this->assertSame([$ids['a']['branch']], DB::table('branches')->pluck('id')->all());
            $this->assertFalse(DB::table('branches')->where('id', $extra)->exists());
        });
        $owner->table('membership_branches')->where('membership_id', $ids['a']['membership'])->delete();
        app(TenantContext::class)->run($this->actor($ids['a']['user']), $ids['a']['tenant'], fn () => $this->assertSame(0, DB::table('branches')->count()));
    }

    public function test_own_audit_scope_excludes_other_actors_and_global_events(): void
    {
        $ids = $this->fixture();
        $owner = DB::connection('migration');
        $permission = $owner->table('permissions')->where('code', 'audit.view')->value('id');
        $owner->table('role_permissions')->where('role_id', $ids['a']['role'])->where('permission_id', $permission)->update(['scope' => 'own']);
        $owner->table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $ids['a']['tenant'], 'actor_user_id' => $ids['b']['user'], 'action' => 'synthetic.event', 'resource_type' => 'synthetic', 'request_id' => (string) Str::uuid()]);
        $owner->table('audit_logs')->insert(['id' => (string) Str::uuid(), 'actor_user_id' => $ids['a']['user'], 'action' => 'password.changed', 'resource_type' => 'user', 'request_id' => (string) Str::uuid()]);
        app(TenantContext::class)->run($this->actor($ids['a']['user']), $ids['a']['tenant'], fn () => $this->assertSame([$ids['a']['audit']], DB::table('audit_logs')->pluck('id')->all()));
    }

    public function test_permission_revocation_does_not_reuse_session_claims(): void
    {
        $ids = $this->fixture();
        $permission = DB::connection('migration')->table('permissions')->where('code', 'services.view')->value('id');
        app(TenantContext::class)->run($this->actor($ids['a']['user']), $ids['a']['tenant'], fn () => $this->assertSame(1, DB::table('services')->count()));
        DB::connection('migration')->table('role_permissions')->where('role_id', $ids['a']['role'])->where('permission_id', $permission)->delete();
        app(TenantContext::class)->run($this->actor($ids['a']['user']), $ids['a']['tenant'], fn () => $this->assertSame(0, DB::table('services')->count()));
    }

    public function test_same_user_has_separate_effective_permissions_in_each_clinic(): void
    {
        $ids = $this->fixture();
        $owner = DB::connection('migration');
        $owner->table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $ids['b']['tenant'], 'user_id' => $ids['a']['user'], 'role_id' => $ids['b']['role']]);
        $owner->table('role_permissions')->where('role_id', $ids['b']['role'])->delete();
        app(TenantContext::class)->run($this->actor($ids['a']['user']), $ids['a']['tenant'], fn () => $this->assertSame(1, DB::table('services')->count()));
        app(TenantContext::class)->run($this->actor($ids['a']['user']), $ids['b']['tenant'], fn () => $this->assertSame(0, DB::table('services')->count()));
    }
}
