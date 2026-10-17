<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\FoundationTestCase;

final class SelfContextApiTest extends FoundationTestCase
{
    private function browser(array &$jar, string $method, string $path, array $payload = [], bool $csrf = true, ?string $key = null): TestResponse
    {
        Auth::forgetGuards();
        $this->app->make('session')->forgetDrivers();
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTPS' => 'on', 'SERVER_PORT' => 443, 'REMOTE_ADDR' => '192.0.2.15', 'HTTP_ORIGIN' => 'https://localhost'];
        if ($csrf && isset($jar['XSRF-TOKEN'])) {
            $server['HTTP_X_XSRF_TOKEN'] = $jar['XSRF-TOKEN'];
        }
        if ($key !== null) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $key;
        }
        $response = $this->call($method, $path, [], $jar, [], $server, json_encode($payload));
        foreach ($response->headers->getCookies() as $cookie) {
            $jar[$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }

    private function clinicSession(array $a): array
    {
        config(['session.driver' => 'versioned', 'session.secure' => true, 'session.encrypt' => true, 'session.lottery' => [0, 100]]);
        $this->app->detectEnvironment(fn () => 'local');
        $jar = [];
        $this->browser($jar, 'GET', '/sanctum/csrf-cookie')->assertNoContent();
        $login = DB::connection('migration')->table('users')->where('id', $a['user'])->value('login');
        $this->browser($jar, 'POST', '/api/v1/auth/login', ['login' => $login, 'password' => 'Synthetic-password-42!'])->assertOk();
        $this->browser($jar, 'POST', '/api/v1/auth/active-clinic', ['tenant_id' => $a['tenant']])->assertNoContent();

        return $jar;
    }

    public function test_self_context_returns_effective_grants_without_role_or_employee_directory_access(): void
    {
        $ids = $this->fixture();
        $a = $ids['a'];
        $db = DB::connection('migration');
        $db->table('role_permissions')->where('role_id', $a['role'])->whereIn('permission_id', $db->table('permissions')->whereIn('code', ['roles.view', 'employees.view', 'branches.view'])->pluck('id'))->delete();
        $jar = $this->clinicSession($a);
        $r = $this->browser($jar, 'GET', '/api/v1/clinic/context')->assertOk()->assertJsonPath('membership_id', $a['membership'])->assertJsonPath('branches.0.id', $a['branch'])->assertJsonPath('timezone', 'Asia/Tashkent')->json();
        $this->assertSame('entire_clinic', $r['grants']['patients.view']);
        $this->assertArrayNotHasKey('roles.view', $r['grants']);
        $this->assertArrayNotHasKey('employees.view', $r['grants']);
        $this->assertSame(1, count($r['branches']));
        $this->assertNotContains($ids['b']['branch'], array_column($r['branches'], 'id'));
        $db->table('role_permissions')->where('role_id', $a['role'])->where('permission_id', $db->table('permissions')->where('code', 'patients.update')->value('id'))->delete();
        $next = $this->browser($jar, 'GET', '/api/v1/clinic/context')->assertOk()->json();
        $this->assertArrayNotHasKey('patients.update', $next['grants']);
        $db->table('membership_branches')->where('membership_id', $a['membership'])->delete();
        $this->browser($jar, 'GET', '/api/v1/clinic/context')->assertOk()->assertJsonPath('branches', []);
        $this->browser($jar, 'GET', '/api/v1/clinic/context?tenant_id='.$ids['b']['tenant'])->assertUnprocessable();
    }

    public function test_self_context_rejects_anonymous_platform_disabled_and_foreign_clinic_selection(): void
    {
        $ids = $this->fixture();
        $a = $ids['a'];
        $jar = [];
        $this->browser($jar, 'GET', '/api/v1/clinic/context')->assertUnauthorized();
        $jar = $this->clinicSession($a);
        $this->browser($jar, 'POST', '/api/v1/auth/active-clinic', ['tenant_id' => $ids['b']['tenant']])->assertForbidden();
        $this->browser($jar, 'GET', '/api/v1/clinic/context')->assertOk()->assertJsonPath('membership_id', $a['membership']);
        DB::connection('migration')->table('users')->where('id', $a['user'])->update(['is_platform_admin' => true]);
        $this->browser($jar, 'GET', '/api/v1/clinic/context')->assertNotFound();
        DB::connection('migration')->table('users')->where('id', $a['user'])->update(['is_platform_admin' => false, 'active' => false]);
        $this->browser($jar, 'GET', '/api/v1/clinic/context')->assertUnauthorized();
    }

    public function test_self_branch_helper_has_no_public_execute_or_missing_context_data(): void
    {
        $this->fixture();
        $this->assertSame('[]', DB::selectOne('SELECT governance.self_branches()::text AS value')->value);
        $row = DB::selectOne("SELECT r.rolname,p.prosecdef,p.proconfig FROM pg_proc p JOIN pg_roles r ON r.oid=p.proowner WHERE p.oid='governance.self_branches()'::regprocedure");
        $this->assertSame('blusser_security', $row->rolname);
        $this->assertTrue($row->prosecdef);
        $this->assertStringContainsString('search_path=pg_catalog', $row->proconfig);
        $this->assertFalse(DB::selectOne("SELECT has_function_privilege('public','governance.self_branches()','EXECUTE') AS allowed")->allowed);
    }
}
