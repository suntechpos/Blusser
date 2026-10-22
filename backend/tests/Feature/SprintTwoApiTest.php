<?php

namespace Tests\Feature;

use App\Modules\Access\Services\Governance;
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

final class SprintTwoApiTest extends FoundationTestCase
{
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

    public function test_real_cookie_login_requires_csrf_rotates_session_and_logout_prevents_replay(): void
    {
        $this->operator();
        $jar = [];
        $csrfResponse = $this->browser($jar, 'GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->assertStringContainsString('no-store', $csrfResponse->headers->get('Cache-Control'));
        $before = $jar[config('session.cookie')];
        $this->browser($jar, 'POST', '/api/v1/auth/login', ['login' => 'platform.one', 'password' => self::PASSWORD], false)->assertStatus(419);
        $login = $this->browser($jar, 'POST', '/api/v1/auth/login', ['login' => 'platform.one', 'password' => self::PASSWORD])->assertOk()->assertJsonPath('is_platform_admin', true);
        $this->assertNotSame($before, $jar[config('session.cookie')]);
        $this->assertStringContainsString('no-store', $login->headers->get('Cache-Control'));
        $cookie = collect($login->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('lax', $cookie->getSameSite());
        $old = $jar;
        $this->browser($jar, 'GET', '/api/v1/auth/me')->assertOk();
        $this->browser($jar, 'POST', '/api/v1/auth/logout')->assertNoContent();
        $this->browser($old, 'GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->browser($jar, 'GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->assertSame(1, DB::connection('migration')->table('audit_logs')->where('action', 'auth.logout')->count());
    }

    public function test_cross_origin_missing_origin_and_bearer_cannot_authenticate(): void
    {
        $this->operator();
        $jar = [];
        $this->login($jar, 'platform.one')->assertOk();
        $this->browser($jar, 'GET', '/api/v1/auth/me', origin: 'https://untrusted.invalid')->assertForbidden();
        $this->browser($jar, 'GET', '/api/v1/auth/me', origin: null)->assertForbidden();
        $anonymous = [];
        $this->browser($anonymous, 'GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->withToken('synthetic-bearer')->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_platform_alone_creates_ceo_and_first_login_is_limited_until_password_change(): void
    {
        $this->operator();
        $platform = [];
        $this->login($platform, 'platform.one')->assertOk();
        $created = $this->browser($platform, 'POST', '/api/v1/platform/clinics', ['name' => 'Synthetic Clinic', 'slug' => 'clinic-a', 'ceo_login' => 'ceo.a', 'temporary_password' => self::TEMPORARY])->assertCreated();
        $tenant = $created->json('tenant_id');
        $this->assertSame(1, DB::connection('migration')->table('tenant_ceos')->count());
        $this->assertStringNotContainsString(self::TEMPORARY, $created->getContent());
        $ceo = [];
        $this->login($ceo, 'ceo.a', self::TEMPORARY)->assertOk()->assertJsonPath('must_change_password', true);
        $old = $ceo;
        $this->browser($ceo, 'GET', '/api/v1/auth/clinics')->assertForbidden();
        $this->browser($ceo, 'POST', '/api/v1/auth/active-clinic', ['tenant_id' => $tenant])->assertForbidden();
        $this->browser($ceo, 'POST', '/api/v1/auth/password', ['current_password' => self::TEMPORARY, 'password' => self::PASSWORD, 'password_confirmation' => 'Mismatch-password'])->assertUnprocessable();
        $this->browser($ceo, 'POST', '/api/v1/auth/password', ['current_password' => self::TEMPORARY, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertOk()->assertJsonPath('must_change_password', false);
        $this->browser($old, 'GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->browser($ceo, 'GET', '/api/v1/auth/clinics')->assertOk()->assertJsonPath('data.0.id', $tenant);
        $this->browser($ceo, 'POST', '/api/v1/platform/clinics', ['name' => 'Forbidden', 'slug' => 'forbidden', 'ceo_login' => 'other.ceo', 'temporary_password' => self::TEMPORARY])->assertForbidden();
        $this->select($ceo, $tenant);
        $this->browser($ceo, 'POST', '/api/v1/clinic/branches', ['name' => 'Synthetic branch', 'code' => 'main'])->assertCreated();
        $this->browser($platform, 'POST', '/api/v1/auth/active-clinic', ['tenant_id' => $tenant])->assertForbidden();
        $this->browser($platform, 'GET', '/api/v1/clinic/employees')->assertStatus(409);
    }

    public function test_employee_creation_role_grants_and_foreign_resource_requests_are_isolated(): void
    {
        $operator = $this->operator();
        [$a,$ceoA] = $this->provision($operator);
        [$b,$ceoB] = $this->provision($operator, 'b');
        $clinic = app(ClinicAdministration::class);
        $foreignRole = $clinic->saveRole($ceoB, $b, null, 'staff', 'Synthetic staff', []);
        $foreignBranch = $clinic->createBranch($ceoB, $b, 'Synthetic branch B', 'main');
        $foreignMember = $clinic->createEmployee($ceoB, $b, 'staff.b', 'Synthetic staff B', self::TEMPORARY, $foreignRole);
        $jar = [];
        $this->login($jar, 'ceo.a')->assertOk();
        $this->select($jar, $a);
        $role = $this->browser($jar, 'POST', '/api/v1/clinic/roles', ['code' => 'staff', 'name' => 'Synthetic staff', 'grants' => [['code' => 'employees.view', 'scope' => 'own']]])->assertCreated()->json('id');
        $branch = $this->browser($jar, 'POST', '/api/v1/clinic/branches', ['name' => 'Synthetic A', 'code' => 'main'])->assertCreated()->json('id');
        $member = $this->browser($jar, 'POST', '/api/v1/clinic/employees', ['login' => 'staff.a', 'name' => 'Synthetic A', 'temporary_password' => self::TEMPORARY, 'role_id' => $role, 'branch_ids' => [$branch]])->assertCreated()->json('id');
        $this->browser($jar, 'GET', '/api/v1/clinic/employees/'.$member)->assertOk()->assertJsonPath('login', 'staff.a')->assertJsonMissingPath('password_hash');
        $this->browser($jar, 'GET', '/api/v1/clinic/employees/'.$foreignMember)->assertNotFound();
        $this->browser($jar, 'GET', '/api/v1/clinic/branches/'.$foreignBranch)->assertNotFound();
        $this->browser($jar, 'GET', '/api/v1/clinic/roles/'.$foreignRole)->assertNotFound();
        $this->browser($jar, 'PATCH', '/api/v1/clinic/employees/'.$foreignMember, ['active' => false])->assertForbidden();
        $this->browser($jar, 'PATCH', '/api/v1/clinic/employees/'.$member, ['role_id' => $foreignRole])->assertForbidden();
        $this->browser($jar, 'PATCH', '/api/v1/clinic/employees/'.$member, ['branch_ids' => [$foreignBranch]])->assertForbidden();
        $this->browser($jar, 'POST', '/api/v1/auth/active-clinic', ['tenant_id' => $b])->assertForbidden();
        // Client-supplied context does not override the server-side selected clinic.
        $this->browser($jar, 'GET', '/api/v1/clinic/branches?tenant_id='.$b)->assertOk()->assertJsonPath('data.0.id', $branch);
        $owner = DB::connection('migration');
        $this->assertTrue($owner->table('tenant_memberships')->where('id', $foreignMember)->value('active'));
        $this->assertSame($role, $owner->table('tenant_memberships')->where('id', $member)->value('role_id'));
    }

    public function test_ceo_and_self_role_are_protected_and_scoped_employee_cannot_escalate(): void
    {
        [$tenant,$ceo] = $this->provision($this->operator());
        $owner = DB::connection('migration');
        $designation = $owner->table('tenant_ceos')->where('tenant_id', $tenant)->first();
        $ceoRole = $owner->table('tenant_memberships')->where('id', $designation->membership_id)->value('role_id');
        $jar = [];
        $this->login($jar, 'ceo.a')->assertOk();
        $this->select($jar, $tenant);
        $this->browser($jar, 'DELETE', '/api/v1/clinic/employees/'.$designation->membership_id)->assertForbidden();
        $this->browser($jar, 'DELETE', '/api/v1/clinic/roles/'.$ceoRole)->assertForbidden();
        $this->browser($jar, 'PUT', '/api/v1/clinic/roles/'.$ceoRole, ['code' => 'ceo', 'name' => 'Changed', 'grants' => []])->assertForbidden();
        $audit = $this->browser($jar, 'GET', '/api/v1/clinic/audit')->assertOk();
        $this->assertContains('operation.denied', array_column($audit->json('data'), 'action'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $audit->json('data.0.created_at'));
        $this->assertStringNotContainsString(self::TEMPORARY, $audit->getContent());
        $role = app(ClinicAdministration::class)->saveRole($ceo, $tenant, null, 'own', 'Synthetic own', [['code' => 'employees.view', 'scope' => 'own']]);
        $member = app(ClinicAdministration::class)->createEmployee($ceo, $tenant, 'staff.own', 'Synthetic own', self::TEMPORARY, $role);
        $staff = [];
        $this->login($staff, 'staff.own', self::TEMPORARY)->assertOk();
        $this->browser($staff, 'POST', '/api/v1/auth/password', ['current_password' => self::TEMPORARY, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertOk();
        $this->select($staff, $tenant);
        $this->browser($staff, 'GET', '/api/v1/clinic/employees')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $member);
        $this->browser($staff, 'GET', '/api/v1/clinic/employees/'.$designation->membership_id)->assertNotFound();
        $this->browser($staff, 'PATCH', '/api/v1/clinic/employees/'.$member, ['role_id' => $ceoRole])->assertForbidden();
        $this->browser($staff, 'POST', '/api/v1/clinic/roles', ['code' => 'escalate', 'name' => 'Escalate', 'grants' => []])->assertForbidden();
    }

    public function test_password_reset_revokes_all_cookie_sessions_and_requires_change_again(): void
    {
        [$tenant,$ceo] = $this->provision($this->operator());
        $clinic = app(ClinicAdministration::class);
        $role = $clinic->saveRole($ceo, $tenant, null, 'staff', 'Synthetic staff', []);
        $member = $clinic->createEmployee($ceo, $tenant, 'staff.a', 'Synthetic staff', self::TEMPORARY, $role);
        $actor = app(Identity::class)->authenticate('staff.a', self::TEMPORARY, '192.0.2.3');
        app(Identity::class)->changePassword($actor, self::TEMPORARY, self::PASSWORD);
        $one = [];
        $two = [];
        $admin = [];
        $this->login($one, 'staff.a')->assertOk();
        $this->login($two, 'staff.a')->assertOk();
        $this->login($admin, 'ceo.a')->assertOk();
        $this->select($admin, $tenant);
        $this->browser($admin, 'POST', '/api/v1/clinic/employees/'.$member.'/password-reset', ['current_password' => self::PASSWORD, 'temporary_password' => 'Synthetic-reset-password-43!'])->assertNoContent();
        $this->browser($one, 'GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->browser($two, 'GET', '/api/v1/auth/me')->assertUnauthorized();
        $fresh = [];
        $this->login($fresh, 'staff.a', 'Synthetic-reset-password-43!')->assertOk()->assertJsonPath('must_change_password', true);
        $this->browser($fresh, 'GET', '/api/v1/auth/clinics')->assertForbidden();
        $this->assertStringNotContainsString('Synthetic-reset-password-43!', json_encode(DB::connection('migration')->table('audit_logs')->get()));
    }

    public function test_link_consent_and_switching_clinics_rechecks_membership_and_current_role(): void
    {
        $operator = $this->operator();
        [$a,$ceoA] = $this->provision($operator);
        [$b,$ceoB] = $this->provision($operator, 'b');
        $role = app(ClinicAdministration::class)->saveRole($ceoB, $b, null, 'reader', 'Synthetic reader', [['code' => 'branches.view', 'scope' => 'assigned_branches']]);
        $branch = app(ClinicAdministration::class)->createBranch($ceoB, $b, 'Synthetic B', 'main');
        app(ClinicAdministration::class)->createBranch($ceoB, $b, 'Synthetic hidden B', 'hidden');
        $bJar = [];
        $this->login($bJar, 'ceo.b')->assertOk();
        $this->select($bJar, $b);
        $link = $this->browser($bJar, 'POST', '/api/v1/clinic/links', ['login' => 'ceo.a', 'role_id' => $role, 'branch_ids' => [$branch]])->assertCreated()->json('id');
        $aJar = [];
        $this->login($aJar, 'ceo.a')->assertOk();
        $this->browser($aJar, 'GET', '/api/v1/auth/links')->assertOk()->assertJsonPath('data.0.id', $link);
        $member = $this->browser($aJar, 'POST', '/api/v1/auth/links/'.$link.'/accept', ['current_password' => self::PASSWORD])->assertOk()->json('id');
        $this->browser($aJar, 'GET', '/api/v1/auth/clinics')->assertOk()->assertJsonCount(2, 'data');
        $this->select($aJar, $b);
        $this->browser($aJar, 'GET', '/api/v1/clinic/branches')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $branch);
        $this->browser($aJar, 'POST', '/api/v1/clinic/branches', ['name' => 'Forbidden', 'code' => 'forbidden'])->assertForbidden();
        DB::connection('migration')->table('tenant_memberships')->where('id', $member)->update(['active' => false]);
        $this->browser($aJar, 'GET', '/api/v1/clinic/branches')->assertUnauthorized();
        $this->login($aJar, 'ceo.a')->assertOk();
        $this->select($aJar, $a);
        $this->browser($aJar, 'GET', '/api/v1/clinic/branches')->assertOk();
    }

    public function test_stale_session_writer_cannot_resurrect_after_reset_or_logout(): void
    {
        [$tenant,$ceo] = $this->provision($this->operator());
        $db = app(Governance::class);
        $db->call('session_start', ['synthetic-inflight-session'], $ceo);
        $db->call('session_logout', ['synthetic-inflight-session'], $ceo);
        $this->assertFalse($db->call('session_write', ['synthetic-inflight-session', $ceo->id, 2, 'full', 'synthetic-payload', time()]));
        $this->assertSqlState('42501', fn () => DB::table('sessions')->insert(['id' => 'synthetic-forged', 'payload' => '', 'last_activity' => time()]));
        $db->call('session_start', ['synthetic-before-password-change'], $ceo);
        app(Identity::class)->changePassword($ceo, self::PASSWORD, 'Synthetic-replacement-43!');
        $this->assertFalse($db->call('session_write', ['synthetic-before-password-change', $ceo->id, 2, 'full', 'synthetic-payload', time()]));
        $this->assertSame(0, DB::connection('migration')->table('sessions')->where('user_id', $ceo->id)->count());
    }

    public function test_bad_credentials_are_generic_rate_limited_and_do_not_create_authenticated_session(): void
    {
        $this->operator();
        $jar = [];
        $this->browser($jar, 'GET', '/sanctum/csrf-cookie');
        for ($i = 0; $i < 5; $i++) {
            $this->browser($jar, 'POST', '/api/v1/auth/login', ['login' => 'unknown.synthetic', 'password' => 'Incorrect-password'])->assertUnauthorized()->assertExactJson(['message' => 'Operation not authorized or unavailable.']);
        }
        $this->browser($jar, 'POST', '/api/v1/auth/login', ['login' => 'unknown.synthetic', 'password' => 'Incorrect-password'])->assertStatus(429);
        $this->assertSame(0, DB::connection('migration')->table('sessions')->whereNotNull('user_id')->count());
    }

    public function test_ceo_recovery_http_requires_two_operators_and_invalidates_all_ceo_sessions(): void
    {
        [$tenant,$ceo] = $this->provision($one = $this->operator());
        $this->operator('platform.two');
        $first = [];
        $second = [];
        $ceoJar = [];
        $this->login($first, 'platform.one')->assertOk();
        $this->login($second, 'platform.two')->assertOk();
        $this->login($ceoJar, 'ceo.a')->assertOk();
        $request = $this->browser($first, 'POST', '/api/v1/platform/clinics/'.$tenant.'/ceo-recovery', ['current_password' => self::PASSWORD])->assertCreated()->json('id');
        $body = ['current_password' => self::PASSWORD, 'verification_reference' => 'synthetic-independent-check-001'];
        $this->browser($first, 'POST', '/api/v1/platform/ceo-recoveries/'.$request.'/approve', $body)->assertForbidden();
        $token = $this->browser($second, 'POST', '/api/v1/platform/ceo-recoveries/'.$request.'/approve', $body)->assertOk()->json('token');
        $target = [];
        $this->browser($target, 'GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->browser($target, 'POST', '/api/v1/auth/ceo-recovery/'.$request, ['token' => $token, 'password' => 'Synthetic-recovered-43!', 'password_confirmation' => 'Synthetic-recovered-43!'])->assertNoContent();
        $this->browser($ceoJar, 'GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->login($target, 'ceo.a', 'Synthetic-recovered-43!')->assertOk()->assertJsonPath('must_change_password', false);
        $this->browser($target, 'POST', '/api/v1/auth/ceo-recovery/'.$request, ['token' => $token, 'password' => 'Synthetic-recovered-44!', 'password_confirmation' => 'Synthetic-recovered-44!'])->assertForbidden();
        $this->browser($first, 'GET', '/api/v1/platform/audit')->assertOk()->assertJsonCount(4, 'data');
        $this->assertStringNotContainsString($token, json_encode(DB::connection('migration')->table('audit_logs')->get()));
    }

    public function test_delegated_admin_api_cannot_expand_scopes_or_edit_own_grants(): void
    {
        [$tenant,$ceo] = $this->provision($this->operator());
        $grants = array_map(fn ($code) => ['code' => $code, 'scope' => 'entire_clinic'], ['roles.create', 'roles.update', 'roles.manage_permissions', 'employees.create', 'employees.assign_role']);
        $grants[] = ['code' => 'employees.view', 'scope' => 'own'];
        $clinic = app(ClinicAdministration::class);
        $role = $clinic->saveRole($ceo, $tenant, null, 'manager', 'Synthetic manager', $grants);
        $clinic->createEmployee($ceo, $tenant, 'manager.a', 'Synthetic manager', self::TEMPORARY, $role);
        $manager = [];
        $this->login($manager, 'manager.a', self::TEMPORARY)->assertOk();
        $this->browser($manager, 'POST', '/api/v1/auth/password', ['current_password' => self::TEMPORARY, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertOk();
        $this->select($manager, $tenant);
        $this->browser($manager, 'PUT', '/api/v1/clinic/roles/'.$role, ['code' => 'manager', 'name' => 'Changed', 'grants' => $grants])->assertForbidden();
        $this->browser($manager, 'POST', '/api/v1/clinic/roles', ['code' => 'broaden', 'name' => 'Forbidden', 'grants' => [['code' => 'employees.view', 'scope' => 'assigned_branches']]])->assertForbidden();
        $staffRole = $this->browser($manager, 'POST', '/api/v1/clinic/roles', ['code' => 'staff', 'name' => 'Synthetic staff', 'grants' => [['code' => 'employees.view', 'scope' => 'own']]])->assertCreated()->json('id');
        $this->browser($manager, 'POST', '/api/v1/clinic/employees', ['login' => 'delegated.staff', 'name' => 'Synthetic staff', 'role_id' => $staffRole, 'temporary_password' => self::TEMPORARY])->assertCreated();
    }

    public function test_expired_cookie_and_credentials_and_live_permission_revocation_are_rejected(): void
    {
        [$tenant,$ceo] = $this->provision($this->operator());
        $jar = [];
        $this->login($jar, 'ceo.a')->assertOk();
        $this->select($jar, $tenant);
        $this->browser($jar, 'GET', '/api/v1/clinic/branches')->assertOk();
        $owner = DB::connection('migration');
        $permission = $owner->table('permissions')->where('code', 'branches.view')->value('id');
        $owner->table('role_permissions')->where('tenant_id', $tenant)->where('permission_id', $permission)->delete();
        $this->browser($jar, 'GET', '/api/v1/clinic/branches')->assertForbidden();
        $owner->table('sessions')->where('user_id', $ceo->id)->update(['last_activity' => time() - 100000]);
        $this->browser($jar, 'GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->login($jar, 'ceo.a')->assertOk();
        $owner->table('users')->where('id', $ceo->id)->update(['active' => false]);
        $this->browser($jar, 'GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_session_issue_and_password_change_serialize_on_user_lock(): void
    {
        [$tenant,$ceo] = $this->provision($this->operator());
        config(['database.connections.competing' => config('database.connections.pgsql')]);
        $a = DB::connection('pgsql');
        $b = DB::connection('competing');
        $a->beginTransaction();
        try {
            $a->select("SELECT set_config('app.user_id',?,true),set_config('app.credential_version','2',true)", [$ceo->id]);
            $a->select('SELECT governance.session_start(?)', ['synthetic-racing-session']);
            $this->assertSqlState('55P03', fn () => $b->transaction(function () use ($b, $ceo) {
                $b->statement("SET LOCAL lock_timeout='100ms'");
                $b->select("SELECT set_config('app.user_id',?,true),set_config('app.credential_version','2',true)", [$ceo->id]);
                $b->select('SELECT governance.password_change(?)', [Hash::make('Synthetic-concurrent-43!')]);
            }));
            $a->commit();
        } finally {
            if ($a->transactionLevel() > 0) {
                $a->rollBack();
            }
        }
        app(Identity::class)->changePassword($ceo, self::PASSWORD, 'Synthetic-concurrent-43!');
        $this->assertFalse(app(Governance::class)->call('session_write', ['synthetic-racing-session', $ceo->id, 2, 'full', 'synthetic', time()]));
        $this->assertSame(0, DB::connection('migration')->table('sessions')->where('user_id', $ceo->id)->count());
    }

    public function test_branch_limited_administrator_cannot_gain_other_branches_through_accounts_or_reset(): void
    {
        [$tenant,$ceo] = $this->provision($this->operator());
        $clinic = app(ClinicAdministration::class);
        $a = $clinic->createBranch($ceo, $tenant, 'Synthetic A', 'a');
        $b = $clinic->createBranch($ceo, $tenant, 'Synthetic B', 'b');
        $scoped = [['code' => 'branches.view', 'scope' => 'assigned_branches']];
        $reader = $clinic->saveRole($ceo, $tenant, null, 'reader', 'Synthetic reader', $scoped);
        $managerGrants = array_map(fn ($code) => ['code' => $code, 'scope' => 'entire_clinic'], ['roles.create', 'roles.update', 'roles.manage_permissions', 'employees.create', 'employees.update', 'employees.assign_role', 'employees.assign_branches', 'employees.reset_password']);
        $managerRole = $clinic->saveRole($ceo, $tenant, null, 'manager', 'Synthetic branch manager', [...$managerGrants, ...$scoped]);
        $clinic->createEmployee($ceo, $tenant, 'manager.a', 'Synthetic manager', self::TEMPORARY, $managerRole, [$a]);
        $outside = $clinic->createEmployee($ceo, $tenant, 'staff.b', 'Synthetic B', self::TEMPORARY, $reader, [$b]);
        $manager = [];
        $this->login($manager, 'manager.a', self::TEMPORARY)->assertOk();
        $this->browser($manager, 'POST', '/api/v1/auth/password', ['current_password' => self::TEMPORARY, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertOk();
        $this->select($manager, $tenant);
        $body = ['login' => 'forbidden.branch', 'name' => 'Synthetic', 'temporary_password' => self::TEMPORARY, 'role_id' => $reader, 'branch_ids' => [$b]];
        $this->browser($manager, 'POST', '/api/v1/clinic/employees', $body)->assertForbidden();
        $this->assertFalse(DB::connection('migration')->table('users')->where('login', 'forbidden.branch')->exists());
        $body['login'] = 'allowed.branch';
        $body['branch_ids'] = [$a];
        $member = $this->browser($manager, 'POST', '/api/v1/clinic/employees', $body)->assertCreated()->json('id');
        $this->browser($manager, 'PATCH', '/api/v1/clinic/employees/'.$member, ['branch_ids' => [$b]])->assertForbidden();
        $before = DB::connection('migration')->table('users')->where('login', 'staff.b')->value('password_hash');
        $this->browser($manager, 'POST', '/api/v1/clinic/employees/'.$outside.'/password-reset', ['current_password' => self::PASSWORD, 'temporary_password' => 'Synthetic-replacement-43!'])->assertForbidden();
        $this->assertSame($before, DB::connection('migration')->table('users')->where('login', 'staff.b')->value('password_hash'));
        $this->browser($manager, 'PUT', '/api/v1/clinic/roles/'.$reader, ['code' => 'reader', 'name' => 'Forbidden change', 'grants' => $scoped])->assertForbidden();
        $this->assertSame('Synthetic reader', DB::connection('migration')->table('tenant_roles')->where('id', $reader)->value('name'));
    }
}
