<?php

namespace Tests\Feature;

use App\Modules\Access\Permissions;
use App\Modules\Access\PermissionScope;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\FoundationTestCase;

final class DatabaseSecurityTest extends FoundationTestCase
{
    public function test_runtime_is_not_owner_superuser_or_bypassrls_and_cannot_set_role(): void
    {
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls, rolcreaterole FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('blusser_app', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
        $this->assertFalse($role->rolcreaterole);
        foreach (['blusser_owner', 'blusser_security'] as $privileged) {
            $this->assertSqlState('42501', fn () => DB::statement('SET ROLE '.$privileged));
        }
        $this->assertSqlState('42501', fn () => DB::statement('CREATE TABLE public.forbidden(id int)'));
        $this->assertSqlState('42501', fn () => DB::statement('ALTER TABLE branches DISABLE ROW LEVEL SECURITY'));
    }

    public function test_all_business_tables_have_forced_rls_and_no_public_definer_execution(): void
    {
        $tables = DB::select("SELECT relname FROM pg_class WHERE relnamespace='public'::regnamespace AND relrowsecurity AND relforcerowsecurity");
        $names = array_column($tables, 'relname');
        foreach (['users', 'tenants', 'branches', 'tenant_roles', 'role_permissions', 'tenant_memberships', 'membership_branches', 'services', 'membership_link_requests', 'membership_link_request_branches', 'audit_logs', 'tenant_ceos', 'ceo_recovery_requests'] as $table) {
            $this->assertContains($table, $names);
        }
        $functions = DB::select("SELECT p.proname, p.prosecdef, p.proconfig, r.rolname FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace JOIN pg_roles r ON r.oid=p.proowner WHERE n.nspname IN ('public','governance') AND p.prosecdef");
        $this->assertNotEmpty($functions);
        foreach ($functions as $function) {
            $this->assertSame('blusser_security', $function->rolname);
            $this->assertStringContainsString('search_path=pg_catalog', $function->proconfig);
        }
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS total FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace CROSS JOIN LATERAL aclexplode(coalesce(p.proacl, acldefault('f',p.proowner))) a WHERE n.nspname IN ('public','governance') AND a.grantee=0 AND a.privilege_type='EXECUTE'")->total);
    }

    public function test_missing_context_exposes_no_tenant_data_or_users(): void
    {
        $this->fixture();
        foreach (['tenants', 'branches', 'tenant_roles', 'role_permissions', 'tenant_memberships', 'membership_branches', 'services', 'audit_logs'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame(0, DB::table('users')->select('id')->count());
        $this->assertSqlState('42501', fn () => DB::table('users')->select('password_hash')->get());
    }

    public function test_context_isolates_two_clinics_including_known_foreign_ids(): void
    {
        $ids = $this->fixture();
        app(TenantContext::class)->run($this->actor($ids['a']['user']), $ids['a']['tenant'], function () use ($ids) {
            foreach (['branches', 'services', 'tenant_roles', 'tenant_memberships', 'membership_branches', 'audit_logs'] as $table) {
                $this->assertSame(1, DB::table($table)->count(), $table);
                $this->assertFalse(DB::table($table)->where('tenant_id', $ids['b']['tenant'])->exists(), $table);
            }
            $this->assertFalse(DB::table('branches')->where('id', $ids['b']['branch'])->exists());
            $this->assertSame(PermissionScope::EntireClinic, app(Permissions::class)->scope('services.view'));
            $this->assertNull(app(Permissions::class)->scope('unknown.action'));
        });
    }

    public function test_forged_or_inactive_context_is_rejected_and_cleared_after_exception(): void
    {
        $ids = $this->fixture();
        try {
            app(TenantContext::class)->run($this->actor($ids['a']['user']), $ids['b']['tenant'], fn () => $this->fail('Foreign tenant was accepted'));
            $this->fail('Expected 404');
        } catch (NotFoundHttpException) {
            $this->assertSame(0, DB::table('branches')->count());
        }
        DB::connection('migration')->table('tenant_memberships')->where('id', $ids['a']['membership'])->update(['active' => false]);
        $this->expectException(NotFoundHttpException::class);
        app(TenantContext::class)->run($this->actor($ids['a']['user']), $ids['a']['tenant'], fn () => null);
    }

    public function test_context_does_not_leak_on_commit_rollback_or_reused_connection(): void
    {
        $ids = $this->fixture();
        try {
            app(TenantContext::class)->run($this->actor($ids['a']['user']), $ids['a']['tenant'], function () {
                $this->assertSame(1, DB::table('branches')->count());
                throw new \RuntimeException('Synthetic failure');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic failure', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('branches')->count());
        app(TenantContext::class)->run($this->actor($ids['b']['user']), $ids['b']['tenant'], function () use ($ids) {
            $this->assertSame([$ids['b']['branch']], DB::table('branches')->pluck('id')->all());
        });
        $this->assertSame(0, DB::table('branches')->count());
        $this->assertSame(0, DB::connection()->transactionLevel());
    }

    public function test_runtime_writes_are_denied_even_with_valid_clinic_context(): void
    {
        $ids = $this->fixture();
        foreach (['tenant_roles', 'role_permissions', 'tenant_memberships', 'membership_branches', 'services', 'branches', 'membership_link_requests', 'membership_link_request_branches', 'audit_logs', 'users', 'auth_rate_limits'] as $table) {
            $this->assertSqlState('42501', fn () => app(TenantContext::class)->run($this->actor($ids['a']['user']), $ids['a']['tenant'], fn () => DB::statement('DELETE FROM '.$table)));
        }
    }

    public function test_rls_also_denies_writes_if_a_dml_grant_is_accidentally_added(): void
    {
        $ids = $this->fixture();
        $owner = DB::connection('migration');
        $owner->statement('GRANT INSERT, UPDATE, DELETE ON branches TO blusser_app');
        try {
            $this->assertSqlState('42501', fn () => app(TenantContext::class)->run($this->actor($ids['a']['user']), $ids['a']['tenant'], fn () => DB::table('branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $ids['b']['tenant'], 'code' => 'forged', 'name' => 'Synthetic'])));
            app(TenantContext::class)->run($this->actor($ids['a']['user']), $ids['a']['tenant'], function () use ($ids) {
                $this->assertSame(0, DB::table('branches')->where('id', $ids['b']['branch'])->update(['name' => 'Denied']));
                $this->assertSame(0, DB::table('branches')->where('id', $ids['b']['branch'])->delete());
                $this->assertSame(0, DB::table('branches')->where('id', $ids['a']['branch'])->update(['tenant_id' => $ids['b']['tenant']]));
            });
        } finally {
            $owner->statement('REVOKE INSERT, UPDATE, DELETE ON branches FROM blusser_app');
        }
    }

    public function test_platform_admin_without_membership_cannot_access_tenant_resources(): void
    {
        $ids = $this->fixture();
        DB::connection('migration')->table('users')->where('id', $ids['a']['user'])->update(['is_platform_admin' => true]);
        $this->expectException(NotFoundHttpException::class);
        app(TenantContext::class)->run($this->actor($ids['a']['user']), $ids['b']['tenant'], fn () => null);
    }

    public function test_stale_credential_actor_cannot_open_context(): void
    {
        $ids = $this->fixture();
        $actor = $this->actor($ids['a']['user']);
        $actor->credential_version = 2;
        $this->expectException(NotFoundHttpException::class);
        app(TenantContext::class)->run($actor, $ids['a']['tenant'], fn () => null);
    }

    #[DataProvider('inactiveStates')]
    public function test_unusable_identity_membership_role_or_clinic_cannot_open_context(string $table, string $kind, array $changes): void
    {
        $ids = $this->fixture();
        DB::connection('migration')->table($table)->where('id', $ids['a'][$kind])->update($changes);
        $this->expectException(NotFoundHttpException::class);
        app(TenantContext::class)->run($this->actor($ids['a']['user']), $ids['a']['tenant'], fn () => null);
    }

    public static function inactiveStates(): array
    {
        return [
            'disabled user' => ['users', 'user', ['active' => false]],
            'temporary password' => ['users', 'user', ['must_change_password' => true, 'temporary_password_expires_at' => '2099-01-01 00:00:00+00']],
            'disabled role' => ['tenant_roles', 'role', ['active' => false]],
            'suspended clinic' => ['tenants', 'tenant', ['status' => 'suspended']],
        ];
    }
}
