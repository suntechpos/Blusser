<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\FoundationTestCase;

final class CashierPinTest extends FoundationTestCase
{
    private string $ip = '192.0.2.80';

    private const PASSWORD = 'Synthetic-cashier-unlock-42!';

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'versioned', 'session.secure' => true, 'session.encrypt' => true, 'session.lottery' => [0, 100]]);
        $this->app->detectEnvironment(fn () => 'local');
    }

    private function request(array &$jar, string $method, string $path, array $body = [], bool $csrf = true): TestResponse
    {
        Auth::forgetGuards();
        $this->app->make('session')->forgetDrivers();
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTPS' => 'on', 'SERVER_PORT' => 443, 'REMOTE_ADDR' => $this->ip, 'HTTP_ORIGIN' => 'https://localhost'];
        if ($csrf && isset($jar['XSRF-TOKEN'])) {
            $server['HTTP_X_XSRF_TOKEN'] = $jar['XSRF-TOKEN'];
        }
        $response = $this->call($method, $path, [], $jar, [], $server, json_encode($body));
        foreach ($response->headers->getCookies() as $cookie) {
            $jar[$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }

    private function employee(array $a, string $login, string $primaryRole, ?string $pin, bool $cashier = true): array
    {
        $db = DB::connection('migration');
        $user = (string) Str::uuid();
        $role = (string) Str::uuid();
        $member = (string) Str::uuid();
        $profile = (string) Str::uuid();
        $db->table('users')->insert(['id' => $user, 'login' => $login, 'name' => 'Synthetic '.$primaryRole, 'password_hash' => Hash::make(self::PASSWORD), 'must_change_password' => false]);
        $db->table('tenant_roles')->insert(['id' => $role, 'tenant_id' => $a['tenant'], 'code' => $primaryRole, 'name' => $primaryRole]);
        $db->table('tenant_memberships')->insert(['id' => $member, 'tenant_id' => $a['tenant'], 'user_id' => $user, 'role_id' => $role]);
        $db->table('membership_branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $a['tenant'], 'membership_id' => $member, 'branch_id' => $a['branch']]);
        // No cashier grant on the shared role: access comes from the per-employee view selection.
        $db->table('employee_profiles')->insert(['id' => $profile, 'tenant_id' => $a['tenant'], 'membership_id' => $member, 'profile' => '{}', 'page_grants' => json_encode($cashier ? [['code' => 'cashier.view', 'scope' => 'assigned_branches']] : [])]);
        if ($pin !== null) {
            $db->table('employee_cashier_secrets')->insert(['tenant_id' => $a['tenant'], 'employee_id' => $profile, 'pin_hash' => Hash::driver('argon2id')->make($pin)]);
        }

        return compact('user', 'role', 'member', 'profile', 'login');
    }

    private function login(array $employee, string $tenant): array
    {
        $jar = [];
        $this->request($jar, 'GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->request($jar, 'POST', '/api/v1/auth/login', ['login' => $employee['login'], 'password' => self::PASSWORD])->assertOk();
        $this->request($jar, 'POST', '/api/v1/auth/active-clinic', ['tenant_id' => $tenant])->assertNoContent();

        return $jar;
    }

    private function unlock(array &$jar, string $pin, array $extra = []): TestResponse
    {
        return $this->request($jar, 'POST', '/api/v1/clinic/cashier/unlock', ['cashier_pin' => $pin, ...$extra]);
    }

    public function test_doctor_and_registrator_unlock_only_with_their_own_pin_without_cashier_role(): void
    {
        $a = $this->fixture()['a'];
        $doctor = $this->employee($a, 'unlock.doctor', 'doctor', '4826');
        $registrator = $this->employee($a, 'unlock.registrator', 'registrator', '7391');
        $one = $this->login($doctor, $a['tenant']);
        $two = $this->login($registrator, $a['tenant']);
        $this->unlock($one, '7391')->assertUnprocessable()->assertJsonPath('code', 'invalid_pin');
        $this->unlock($one, '1122')->assertUnprocessable();
        $this->unlock($one, '4826')->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');
        $this->unlock($two, '4826')->assertUnprocessable();
        $this->unlock($two, '7391')->assertNoContent();
        $this->unlock($one, '7391', ['employee_id' => $registrator['profile']])->assertUnprocessable();
        $this->request($one, 'GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('role.code', 'doctor')->assertJsonMissingPath('pin_hash')->assertJsonMissingPath('cashier_pin');
        $audit = DB::connection('migration')->table('audit_logs')->get()->toJson();
        foreach (['4826', '7391', '$argon2id$'] as $secret) {
            $this->assertStringNotContainsString($secret === '$argon2id$' ? $secret : '"'.$secret.'"', $audit);
        }
    }

    public function test_current_effective_permission_missing_pin_and_membership_revocation_fail_closed(): void
    {
        $a = $this->fixture()['a'];
        $staff = $this->employee($a, 'unlock.denied', 'doctor', '4826', false);
        $jar = $this->login($staff, $a['tenant']);
        $this->unlock($jar, '4826')->assertForbidden();
        $db = DB::connection('migration');
        $db->table('employee_profiles')->where('id', $staff['profile'])->update(['page_grants' => json_encode([['code' => 'cashier.view', 'scope' => 'assigned_branches']])]);
        $this->unlock($jar, '4826')->assertNoContent();
        $db->table('employee_cashier_secrets')->where('employee_id', $staff['profile'])->delete();
        $this->unlock($jar, '4826')->assertUnprocessable();
        $db->table('tenant_memberships')->where('id', $staff['member'])->update(['active' => false]);
        // The deleted session loses its CSRF token before POST authentication runs.
        $this->unlock($jar, '4826')->assertStatus(419);
        $this->request($jar, 'GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_pin_is_scoped_to_the_active_clinic_and_not_another_membership(): void
    {
        $ids = $this->fixture();
        $a = $ids['a'];
        $b = $ids['b'];
        $staff = $this->employee($a, 'unlock.cross', 'doctor', '4826');
        $db = DB::connection('migration');
        $mid = (string) Str::uuid();
        $pid = (string) Str::uuid();
        $db->table('tenant_memberships')->insert(['id' => $mid, 'tenant_id' => $b['tenant'], 'user_id' => $staff['user'], 'role_id' => $b['role']]);
        $db->table('employee_profiles')->insert(['id' => $pid, 'tenant_id' => $b['tenant'], 'membership_id' => $mid, 'profile' => '{}', 'page_grants' => json_encode([['code' => 'cashier.view', 'scope' => 'entire_clinic']])]);
        $db->table('employee_cashier_secrets')->insert(['tenant_id' => $b['tenant'], 'employee_id' => $pid, 'pin_hash' => Hash::driver('argon2id')->make('7391')]);
        $jar = $this->login($staff, $a['tenant']);
        $this->unlock($jar, '7391')->assertUnprocessable();
        $this->unlock($jar, '4826')->assertNoContent();
        $this->request($jar, 'POST', '/api/v1/auth/active-clinic', ['tenant_id' => $b['tenant']])->assertNoContent();
        $this->unlock($jar, '4826')->assertUnprocessable();
        $this->unlock($jar, '7391')->assertNoContent();
    }

    public function test_five_wrong_attempts_block_even_a_correct_pin_across_sessions_and_ips_until_expiry(): void
    {
        $a = $this->fixture()['a'];
        $staff = $this->employee($a, 'unlock.limited', 'doctor', '4826');
        $jar = $this->login($staff, $a['tenant']);
        for ($i = 0; $i < 5; $i++) {
            $this->unlock($jar, '0000')->assertUnprocessable();
        }
        $this->unlock($jar, '4826')->assertStatus(429);
        $this->ip = '192.0.2.81';
        $fresh = $this->login($staff, $a['tenant']);
        $this->unlock($fresh, '4826')->assertStatus(429);
        $digest = hash_hmac('sha256', 'cashier-pin:'.$a['tenant'].':'.$staff['user'], config('app.key'));
        DB::connection('migration')->table('auth_rate_limits')->where('key_digest', $digest)->update(['expires_at' => now()->subMinute()]);
        $this->unlock($fresh, '4826')->assertNoContent();
    }

    public function test_anonymous_csrf_bad_payload_and_runtime_table_access_are_rejected(): void
    {
        $a = $this->fixture()['a'];
        $staff = $this->employee($a, 'unlock.validation', 'doctor', '4826');
        $anon = [];
        $this->request($anon, 'GET', '/sanctum/csrf-cookie');
        $this->unlock($anon, '4826')->assertUnauthorized();
        $jar = $this->login($staff, $a['tenant']);
        $this->request($jar, 'POST', '/api/v1/clinic/cashier/unlock', ['cashier_pin' => '4826'], false)->assertStatus(419);
        foreach (['123', '12345', '1a22'] as $bad) {
            $this->unlock($jar, $bad)->assertUnprocessable();
        }
        $this->request($jar, 'POST', '/api/v1/clinic/cashier/unlock?cashier_pin=4826')->assertUnprocessable();
        $this->assertSqlState('42501', fn () => DB::connection('pgsql')->table('employee_cashier_secrets')->get());
        $this->assertSqlState('42501', fn () => DB::connection('pgsql')->select('SELECT governance.cashier_pin_record()'));
    }

    public function test_ip_bucket_also_limits_correct_attempts_and_pin_replacement_is_live(): void
    {
        $a = $this->fixture()['a'];
        $staff = $this->employee($a, 'unlock.ip', 'registrator', '4826');
        $jar = $this->login($staff, $a['tenant']);
        for ($i = 0; $i < 30; $i++) {
            $this->unlock($jar, '4826')->assertNoContent();
        }
        $this->unlock($jar, '4826')->assertStatus(429);
        $this->ip = '192.0.2.89';
        $db = DB::connection('migration');
        $db->table('employee_cashier_secrets')->where('employee_id', $staff['profile'])->update(['pin_hash' => Hash::driver('argon2id')->make('7391')]);
        $this->unlock($jar, '4826')->assertUnprocessable();
        $this->unlock($jar, '7391')->assertNoContent();
        $db->table('employee_profiles')->where('id', $staff['profile'])->update(['page_grants' => '[]']);
        $this->unlock($jar, '7391')->assertForbidden();
    }
}
