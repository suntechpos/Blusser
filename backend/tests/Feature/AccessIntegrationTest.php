<?php

namespace Tests\Feature;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\Identity;
use App\Modules\Platform\Services\ClinicProvisioning;
use App\Modules\Tenancy\Services\ClinicAdministration;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\FoundationTestCase;

final class AccessIntegrationTest extends FoundationTestCase
{
    private array $commandHeaders = [];

    private const PASSWORD = 'Synthetic-permanent-42!';

    private const TEMPORARY = 'Synthetic-temporary-42!';

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'versioned', 'session.secure' => true, 'session.encrypt' => true, 'session.lottery' => [0, 100]]);
        // Exercise actual CSRF middleware, encrypted cookies and PostgreSQL session storage.
        $this->app->detectEnvironment(fn () => 'local');
    }

    private function browser(array &$jar, string $method, string $uri, array $body = [], bool $csrf = true, ?string $origin = 'https://localhost'): TestResponse
    {
        Auth::forgetGuards();
        $this->app->make('session')->forgetDrivers();
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTPS' => 'on', 'SERVER_PORT' => 443, 'REMOTE_ADDR' => '192.0.2.10'];
        if ($origin !== null) {
            $server['HTTP_ORIGIN'] = $origin;
        }
        if ($csrf && isset($jar['XSRF-TOKEN'])) {
            $server['HTTP_X_XSRF_TOKEN'] = $jar['XSRF-TOKEN'];
        }
        $server = array_merge($server, $this->commandHeaders);
        $this->commandHeaders = [];
        $response = $this->call($method, $uri, [], $jar, [], $server, json_encode($body));
        foreach ($response->headers->getCookies() as $cookie) {
            $jar[$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }

    private function login(array &$jar, string $login, string $password = self::PASSWORD): TestResponse
    {
        $this->browser($jar, 'GET', '/sanctum/csrf-cookie')->assertNoContent();

        return $this->browser($jar, 'POST', '/api/v1/auth/login', ['login' => $login, 'password' => $password]);
    }

    private function operator(string $login = 'platform.one'): User
    {
        $id = (string) Str::uuid();
        DB::connection('migration')->table('users')->insert(['id' => $id, 'login' => $login, 'name' => 'Synthetic operator', 'password_hash' => Hash::make(self::PASSWORD), 'must_change_password' => false, 'is_platform_admin' => true]);

        return app(Identity::class)->authenticate($login, self::PASSWORD, '192.0.2.1');
    }

    private function provision(User $operator, string $suffix = 'a'): array
    {
        $result = app(ClinicProvisioning::class)->createClinic($operator, 'Synthetic Clinic '.$suffix, 'clinic-'.$suffix, 'ceo.'.$suffix, self::TEMPORARY);
        $ceo = app(Identity::class)->authenticate('ceo.'.$suffix, self::TEMPORARY, '192.0.2.2');
        $ceo = app(Identity::class)->changePassword($ceo, self::TEMPORARY, self::PASSWORD);

        return [$result['tenant_id'], $ceo];
    }

    private function select(array &$jar, string $tenant): void
    {
        $this->browser($jar, 'POST', '/api/v1/auth/active-clinic', ['tenant_id' => $tenant])->assertNoContent();
    }

    private function command(array &$jar, string $method, string $url, array $body = [], ?int $version = null, ?string $key = null): TestResponse
    {
        $this->commandHeaders = ['HTTP_IDEMPOTENCY_KEY' => $key ?? (string) Str::uuid()];
        if ($version !== null) {
            $this->commandHeaders['HTTP_IF_MATCH'] = '"'.$version.'"';
        }

        return $this->browser($jar, $method, $url, $body);
    }

    private function setupClinic(): array
    {
        [$tid,$ceo] = $this->provision($this->operator());
        $branch = app(ClinicAdministration::class)->createBranch($ceo, $tid, 'Main', 'main');
        $jar = [];
        $this->login($jar, 'ceo.a')->assertOk();
        $this->select($jar, $tid);

        return [$tid, $ceo, $branch, $jar];
    }

    private function role(array &$jar, string $code = 'cashier', ?array $codes = null): string
    {
        return $this->command($jar, 'POST', '/api/v1/clinic/roles', ['code' => $code, 'name' => 'Synthetic '.$code, 'note' => 'Role note', 'grants' => array_map(fn ($code) => ['code' => $code, 'scope' => 'entire_clinic'], $codes ?? ['cashier.view', 'cashier.shift', 'cashier.pay', 'cashier.history'])])->assertCreated()->json('id');
    }

    private function profile(string $branch, ?string $role = null, bool $access = true): array
    {
        return ['profile' => ['first' => 'New', 'last' => 'Employee', 'phone' => '+998900001122', 'position' => 'Kassir', 'department' => 'Moliya', 'hired' => '2026-10-01', 'duration' => 30], 'active' => true, 'access' => $access, 'branch_id' => $branch, 'branch_ids' => $access ? [$branch] : [], 'role_id' => $role, 'login' => $access ? 'new.cashier' : null, ...($access ? ['temporary_password' => self::TEMPORARY] : [])];
    }

    public function test_real_account_role_login_password_change_scope_and_session_revocation(): void
    {
        [$tid,$ceo,$branch,$jar] = $this->setupClinic();
        $rid = $this->role($jar);
        $key = (string) Str::uuid();
        $body = $this->profile($branch, $rid);
        $id = $this->command($jar, 'POST', '/api/v1/clinic/employees', $body, null, $key)->assertCreated()->json('id');
        $this->command($jar, 'POST', '/api/v1/clinic/employees', $body, null, $key)->assertCreated()->assertJsonPath('id', $id);
        $this->assertSame(1, DB::connection('migration')->table('users')->where('login', 'new.cashier')->count());
        $this->browser($jar, 'GET', '/api/v1/clinic/roles/'.$rid)->assertOk()->assertJsonPath('employee_count', 1)->assertHeader('ETag', '"1"');
        $detail = $this->browser($jar, 'GET', '/api/v1/clinic/employees/'.$id.'?format=profile')->assertOk()->assertJsonPath('profile.first', 'New')->assertJsonMissingPath('password_hash');
        $staff = [];
        $this->login($staff, 'new.cashier', self::TEMPORARY)->assertOk()->assertJsonPath('must_change_password', true);
        $this->browser($staff, 'GET', '/api/v1/clinic/roles')->assertForbidden();
        $this->browser($staff, 'POST', '/api/v1/auth/password', ['current_password' => self::TEMPORARY, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertOk();
        $this->select($staff, $tid);
        $this->browser($staff, 'GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('role.id', $rid)->assertJsonPath('grants', fn ($g) => ($g['cashier.pay'] ?? null) === 'entire_clinic');
        $this->browser($staff, 'GET', '/api/v1/clinic/patients')->assertForbidden();
        $this->browser($staff, 'GET', '/api/v1/clinic/roles')->assertForbidden();
        $body['access'] = false;
        unset($body['temporary_password']);
        $body['login'] = null;
        $body['role_id'] = null;
        $body['branch_ids'] = [];
        $this->command($jar, 'PATCH', '/api/v1/clinic/employees/'.$id, $body, 1)->assertOk();
        $this->browser($staff, 'GET', '/api/v1/auth/me')->assertUnauthorized();
        $audit = json_encode(DB::connection('migration')->table('audit_logs')->get());
        $this->assertStringNotContainsString(self::TEMPORARY, $audit);
        $this->assertStringNotContainsString('$argon2id$', $audit);
    }

    public function test_loginless_profile_enable_is_atomic_idempotent_and_versioned(): void
    {
        [$tid,$ceo,$branch,$jar] = $this->setupClinic();
        $rid = $this->role($jar);
        $users = DB::connection('migration')->table('users')->count();
        $body = $this->profile($branch, null, false);
        $id = $this->command($jar, 'POST', '/api/v1/clinic/employees', $body)->assertCreated()->json('id');
        $this->assertSame($users, DB::connection('migration')->table('users')->count());
        $this->browser($jar, 'GET', '/api/v1/clinic/employees/'.$id.'?format=profile')->assertOk()->assertJsonPath('access', false)->assertJsonPath('profile.branch', $branch);
        $body = $this->profile($branch, (string) Str::uuid());
        $this->command($jar, 'PATCH', '/api/v1/clinic/employees/'.$id, $body, 1)->assertForbidden();
        $this->assertSame($users, DB::connection('migration')->table('users')->count());
        $body['role_id'] = $rid;
        $key = (string) Str::uuid();
        $this->command($jar, 'PATCH', '/api/v1/clinic/employees/'.$id, $body, 1, $key)->assertOk()->assertJsonPath('version', 2);
        $this->command($jar, 'PATCH', '/api/v1/clinic/employees/'.$id, $body, 1, $key)->assertOk();
        unset($body['temporary_password']);
        $this->command($jar, 'PATCH', '/api/v1/clinic/employees/'.$id, $body, 1)->assertConflict();
        $this->command($jar, 'PATCH', '/api/v1/clinic/employees/'.$id, $body)->assertStatus(428);
        $this->assertSame($users + 1, DB::connection('migration')->table('users')->count());
    }

    public function test_roles_names_dependencies_etags_protection_and_assigned_deletion(): void
    {
        [$tid,$ceo,$branch,$jar] = $this->setupClinic();
        $rid = $this->role($jar);
        $body = ['code' => 'dup', 'name' => '  sYnThEtIc   CaShIeR  ', 'grants' => [['code' => 'cashier.view', 'scope' => 'entire_clinic']]];
        $this->command($jar, 'POST', '/api/v1/clinic/roles', $body)->assertConflict();
        $body['name'] = ' ';
        $this->command($jar, 'POST', '/api/v1/clinic/roles', $body)->assertUnprocessable();
        $body['name'] = 'Dependency';
        $body['grants'] = [['code' => 'patients.update', 'scope' => 'entire_clinic']];
        $this->command($jar, 'POST', '/api/v1/clinic/roles', $body)->assertUnprocessable();
        $body['grants'] = [['code' => 'unknown.view', 'scope' => 'entire_clinic']];
        $this->command($jar, 'POST', '/api/v1/clinic/roles', $body)->assertForbidden();
        $body = ['code' => 'cashier', 'name' => 'Renamed', 'note' => 'Updated', 'grants' => [['code' => 'cashier.view', 'scope' => 'entire_clinic']]];
        $this->command($jar, 'PUT', '/api/v1/clinic/roles/'.$rid, $body, 1)->assertOk()->assertHeader('ETag', '"2"');
        $this->command($jar, 'PUT', '/api/v1/clinic/roles/'.$rid, $body, 1)->assertConflict();
        $id = $this->command($jar, 'POST', '/api/v1/clinic/employees', $this->profile($branch, $rid))->assertCreated()->json('id');
        $this->command($jar, 'DELETE', '/api/v1/clinic/roles/'.$rid, [], 2)->assertForbidden();
        $protected = DB::connection('migration')->table('tenant_roles')->where('tenant_id', $tid)->where('protected', true)->value('id');
        $this->command($jar, 'DELETE', '/api/v1/clinic/roles/'.$protected, [], 1)->assertForbidden();
        $other = $this->role($jar, 'unused');
        $this->command($jar, 'DELETE', '/api/v1/clinic/roles/'.$other, [], 1)->assertNoContent();
        $this->browser($jar, 'GET', '/api/v1/clinic/employees/'.$id.'?format=profile')->assertOk()->assertJsonPath('role_id', $rid);
    }

    public function test_open_session_loses_a_revoked_api_permission_immediately(): void
    {
        [$tid,$ceo,$branch,$jar] = $this->setupClinic();
        $rid = $this->role($jar, 'reception', ['patients.view', 'patients.create']);
        $this->command($jar, 'POST', '/api/v1/clinic/employees', $this->profile($branch, $rid))->assertCreated();
        $staff = [];
        $this->login($staff, 'new.cashier', self::TEMPORARY)->assertOk();
        $this->browser($staff, 'POST', '/api/v1/auth/password', ['current_password' => self::TEMPORARY, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertOk();
        $this->select($staff, $tid);
        $this->browser($staff, 'GET', '/api/v1/clinic/patients')->assertOk();
        $this->command($jar, 'PUT', '/api/v1/clinic/roles/'.$rid, ['code' => 'reception', 'name' => 'Read only', 'grants' => [['code' => 'patients.view', 'scope' => 'entire_clinic']]], 1)->assertOk();
        $this->command($staff, 'POST', '/api/v1/clinic/patients', ['name' => 'Denied', 'phone' => '+998900001122', 'date_of_birth' => '1990-01-01', 'gender' => 'female', 'branch_id' => $branch])->assertForbidden();
        $this->browser($staff, 'GET', '/api/v1/clinic/context')->assertOk()->assertJsonPath('grants', fn ($g) => ! array_key_exists('patients.create', $g));
    }

    public function test_existing_identity_consent_links_a_loginless_profile_without_new_account(): void
    {
        [$tid,$ceo,$branch,$jar] = $this->setupClinic();
        $rid = $this->role($jar);
        $id = $this->command($jar, 'POST', '/api/v1/clinic/employees', $this->profile($branch, null, false))->assertCreated()->json('id');
        $uid = (string) Str::uuid();
        DB::connection('migration')->table('users')->insert(['id' => $uid, 'login' => 'existing.identity', 'name' => 'Existing person', 'password_hash' => Hash::make(self::PASSWORD), 'must_change_password' => false]);
        $users = DB::connection('migration')->table('users')->count();
        $key = (string) Str::uuid();
        $body = ['employee_id' => $id, 'login' => 'existing.identity', 'role_id' => $rid, 'branch_ids' => [$branch]];
        $request = $this->command($jar, 'POST', '/api/v1/clinic/links', $body, 1, $key)->assertCreated()->json('id');
        $this->command($jar, 'POST', '/api/v1/clinic/links', $body, 1, $key)->assertCreated()->assertJsonPath('id', $request);
        $this->browser($jar, 'GET', '/api/v1/clinic/employees/'.$id.'?format=profile')->assertOk()->assertJsonPath('access', false);
        $target = [];
        $this->login($target, 'existing.identity')->assertOk();
        $this->browser($target, 'GET', '/api/v1/auth/links')->assertOk()->assertJsonPath('data.0.id', $request);
        $this->browser($target, 'POST', '/api/v1/auth/links/'.$request.'/accept', ['current_password' => self::PASSWORD])->assertOk();
        $this->browser($jar, 'GET', '/api/v1/clinic/employees/'.$id.'?format=profile')->assertOk()->assertJsonPath('access', true)->assertJsonPath('user_id', $uid)->assertJsonPath('profile.first', 'New');
        $this->assertSame($users, DB::connection('migration')->table('users')->count());
    }

    public function test_access_editor_rollback_refuses_persisted_profiles(): void
    {
        [$tid, $ceo, $branch, $jar] = $this->setupClinic();
        $this->command($jar, 'POST', '/api/v1/clinic/employees', $this->profile($branch, null, false))->assertCreated();
        $migration = require database_path('migrations/2026_10_22_000012_add_access_editor.php');
        try {
            DB::connection('migration')->transaction(function () use ($migration) {
                $previous = DB::getDefaultConnection();
                DB::setDefaultConnection('migration');
                try {
                    $migration->down();
                } finally {
                    DB::setDefaultConnection($previous);
                }
            });
            $this->fail('Persisted employee profiles must block rollback.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('forward migration', $e->getMessage());
        }
        $this->assertSame(1, DB::connection('migration')->table('employee_profiles')->count());
    }
}
