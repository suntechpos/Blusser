<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\Process;
use Tests\FoundationTestCase;

final class AccessReviewRegressionTest extends FoundationTestCase
{
    private const PASSWORD = 'Synthetic-password-42!';

    private array $commandHeaders = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'versioned', 'session.secure' => true, 'session.encrypt' => true, 'session.lottery' => [0, 100]]);
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

    private function clinic(): array
    {
        $a = $this->fixture()['a'];
        $db = DB::connection('migration');
        $db->table('tenant_roles')->where('id', $a['role'])->update(['protected' => true, 'code' => 'ceo']);
        $db->table('tenant_ceos')->insert(['tenant_id' => $a['tenant'], 'user_id' => $a['user'], 'membership_id' => $a['membership']]);
        $a['branch_b'] = (string) Str::uuid();
        $db->table('branches')->insert(['id' => $a['branch_b'], 'tenant_id' => $a['tenant'], 'name' => 'Review B', 'code' => 'review-b']);

        return $a;
    }

    private function role(array $a, string $code, array $grants): string
    {
        $db = DB::connection('migration');
        $id = (string) Str::uuid();
        $db->table('tenant_roles')->insert(['id' => $id, 'tenant_id' => $a['tenant'], 'code' => $code, 'name' => 'Review '.$code]);
        foreach ($grants as $permission => $scope) {
            $db->table('role_permissions')->insert(['tenant_id' => $a['tenant'], 'role_id' => $id, 'permission_id' => $db->table('permissions')->where('code', $permission)->value('id'), 'scope' => $scope]);
        }

        return $id;
    }

    private function member(array $a, string $login, string $role, array $branches, bool $profile = true): array
    {
        $db = DB::connection('migration');
        $u = (string) Str::uuid();
        $m = (string) Str::uuid();
        $p = (string) Str::uuid();
        $db->table('users')->insert(['id' => $u, 'login' => $login, 'name' => 'Review Staff', 'password_hash' => Hash::make(self::PASSWORD), 'must_change_password' => false]);
        $db->table('tenant_memberships')->insert(['id' => $m, 'tenant_id' => $a['tenant'], 'user_id' => $u, 'role_id' => $role]);
        foreach ($branches as $b) {
            $db->table('membership_branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $a['tenant'], 'membership_id' => $m, 'branch_id' => $b]);
        }
        $data = ['first' => 'Review', 'last' => 'Staff', 'phone' => '+998900001122', 'position' => 'Kassir', 'branch' => $branches[0] ?? $a['branch']];
        if ($profile) {
            $db->table('employee_profiles')->insert(['id' => $p, 'tenant_id' => $a['tenant'], 'membership_id' => $m, 'profile' => json_encode($data), 'active' => true, 'version' => 1]);
        }

        return ['user' => $u, 'member' => $m, 'id' => $profile ? $p : $m, 'role' => $role, 'login' => $login, 'profile' => $data, 'branches' => $branches];
    }

    private function signed(string $login, string $tenant): array
    {
        $jar = [];
        $this->login($jar, $login)->assertOk();
        $this->browser($jar, 'POST', '/api/v1/auth/active-clinic', ['tenant_id' => $tenant])->assertNoContent();

        return $jar;
    }

    private function detail(array &$jar, array $e): array
    {
        return $this->browser($jar, 'GET', '/api/v1/clinic/employees/'.$e['id'].'?format=profile')->assertOk()->json();
    }

    private function payload(array $e): array
    {
        $p = $e['profile'];
        unset($p['branch']);

        return ['profile' => $p, 'active' => true, 'access' => true, 'login' => $e['login'], 'role_id' => $e['role'], 'branch_id' => $e['profile']['branch'], 'branch_ids' => $e['branches']];
    }

    private function patchProfile(array &$jar, array $e, array $body, int $v): TestResponse
    {
        $this->commandHeaders = ['HTTP_IF_MATCH' => '"'.$v.'"', 'HTTP_IDEMPOTENCY_KEY' => (string) Str::uuid()];

        return $this->browser($jar, 'PATCH', '/api/v1/clinic/employees/'.$e['id'], $body);
    }

    private function state(array $e): array
    {
        $db = DB::connection('migration');

        return ['profile' => (array) $db->table('employee_profiles')->where('id', $e['id'])->first(), 'member' => (array) $db->table('tenant_memberships')->where('id', $e['member'])->first(), 'credentials' => $db->table('users')->where('id', $e['user'])->value('credential_version'), 'sessions' => $db->table('sessions')->where('user_id', $e['user'])->orderBy('id')->get()->toArray()];
    }

    public function test_profile_disable_uses_branch_guard_and_rolls_back_profile_membership_and_sessions(): void
    {
        $a = $this->clinic();
        $managerRole = $this->role($a, 'manager', ['employees.view' => 'entire_clinic', 'employees.update' => 'entire_clinic', 'employees.delete' => 'entire_clinic', 'patients.view' => 'assigned_branches']);
        $this->member($a, 'review.manager', $managerRole, [$a['branch']]);
        $targetRole = $this->role($a, 'target', ['patients.view' => 'assigned_branches']);
        $e = $this->member($a, 'review.target', $targetRole, [$a['branch_b']]);
        $jar = $this->signed('review.manager', $a['tenant']);
        $staff = $this->signed('review.target', $a['tenant']);
        $before = $this->state($e);
        $v = $this->detail($jar, $e)['version'];
        $this->browser($jar, 'PATCH', '/api/v1/clinic/employees/'.$e['member'], ['active' => false])->assertForbidden();
        $body = $this->payload($e);
        $body['access'] = false;
        $body['branch_id'] = $a['branch'];
        $body['profile']['phone'] = '+998909999999';
        $this->patchProfile($jar, $e, $body, $v)->assertForbidden();
        $this->assertEquals($before, $this->state($e));
        $this->browser($staff, 'GET', '/api/v1/auth/me')->assertOk();
        $db = DB::connection('migration');
        $db->table('membership_branches')->where('membership_id', $e['member'])->update(['branch_id' => $a['branch']]);
        $body['branch_ids'] = [$a['branch']];
        $v = $this->detail($jar, $e)['version'];
        $this->patchProfile($jar, $e, $body, $v)->assertOk();
        $this->assertFalse($db->table('tenant_memberships')->where('id', $e['member'])->value('active'));
        $this->browser($staff, 'GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_legacy_disable_role_and_branch_writes_invalidate_stale_profiles(): void
    {
        $a = $this->clinic();
        $jar = $this->signed('synthetic.a', $a['tenant']);
        $r1 = $this->role($a, 'one', ['patients.view' => 'entire_clinic']);
        $r2 = $this->role($a, 'two', ['patients.view' => 'entire_clinic']);
        foreach (['disable', 'role', 'branch', 'without-profile'] as $mode) {
            $e = $this->member($a, 'review.'.$mode, $r1, [$a['branch']], $mode !== 'without-profile');
            $v = $this->detail($jar, $e)['version'];
            $body = $this->payload($e);
            $change = match ($mode) {
                'role' => ['role_id' => $r2],'branch' => ['branch_ids' => [$a['branch_b']]],default => ['active' => false]
            };
            $this->browser($jar, 'PATCH', '/api/v1/clinic/employees/'.$e['member'], $change)->assertNoContent();
            $current = $this->detail($jar, $e);
            $this->assertGreaterThan($v, $current['version'], $mode);
            $this->patchProfile($jar, $e, $body, $v)->assertConflict();
            $db = DB::connection('migration');
            $m = $db->table('tenant_memberships')->where('id', $e['member'])->first();
            $this->assertSame($mode === 'role' ? $r2 : $r1, $m->role_id);
            $this->assertSame(! in_array($mode, ['disable', 'without-profile']), $m->active);
            if ($mode === 'branch') {
                $this->assertSame([$a['branch_b']], $db->table('membership_branches')->where('membership_id', $e['member'])->pluck('branch_id')->all());
            }
            $body['access'] = $m->active;
            $body['role_id'] = $m->role_id;
            $body['branch_ids'] = $mode === 'branch' ? [$a['branch_b']] : [$a['branch']];
            $body['profile']['phone'] = '+998908888888';
            $this->patchProfile($jar, $e, $body, $current['version'])->assertOk();
        }
    }

    public function test_plain_profile_edit_preserves_all_branches_without_assignment_permission(): void
    {
        $a = $this->clinic();
        $r = $this->role($a, 'manager', ['employees.view' => 'entire_clinic', 'employees.update' => 'entire_clinic', 'patients.view' => 'entire_clinic']);
        $this->member($a, 'review.manager', $r, [$a['branch']]);
        $targetRole = $this->role($a, 'target', ['patients.view' => 'entire_clinic']);
        $e = $this->member($a, 'review.target', $targetRole, [$a['branch'], $a['branch_b']]);
        $jar = $this->signed('review.manager', $a['tenant']);
        $v = $this->detail($jar, $e)['version'];
        $body = $this->payload($e);
        unset($body['branch_ids']);
        $body['profile']['phone'] = '+998907777777';
        $this->patchProfile($jar, $e, $body, $v)->assertOk();
        $current = $this->detail($jar, $e);
        $this->assertEqualsCanonicalizing($e['branches'], $current['branch_ids']);
        $this->assertSame('+998907777777', $current['profile']['phone']);
        $body['branch_ids'] = [$a['branch']];
        $this->patchProfile($jar, $e, $body, $current['version'])->assertForbidden();
        $this->assertEqualsCanonicalizing($e['branches'], $this->detail($jar, $e)['branch_ids']);
        $ceo = $this->signed('synthetic.a', $a['tenant']);
        $this->patchProfile($ceo, $e, $body, $current['version'])->assertOk();
        $this->assertSame([$a['branch']], $this->detail($ceo, $e)['branch_ids']);
    }

    public function test_parallel_legacy_disable_invalidates_a_profile_waiting_on_the_same_locks(): void
    {
        $a = $this->clinic();
        $role = $this->role($a, 'parallel', ['patients.view' => 'entire_clinic']);
        $e = $this->member($a, 'review.parallel', $role, [$a['branch']]);
        $jar = $this->signed('synthetic.a', $a['tenant']);
        $v = $this->detail($jar, $e)['version'];
        $body = $this->payload($e);
        $code = <<<'WORKER'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$i=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
Illuminate\Support\Facades\DB::select("SELECT set_config('application_name',?,false)",[$i['tag']]);
$actor=(new App\Modules\Identity\Models\User)->forceFill(['id'=>$i['user'],'credential_version'=>1]);
try { $g=app(App\Modules\Access\Services\Governance::class);if($i['legacy'])$g->call('employee_assign',[$i['member'],null,null,false],$actor,$i['tenant']);else $g->call('staff_command',[$i['profile'],json_encode($i['body'],JSON_THROW_ON_ERROR),$i['key'],$i['version']],$actor,$i['tenant']);echo json_encode(['status'=>200]); }
catch(App\Modules\Access\Services\GovernanceDenied $e){echo json_encode(['status'=>$e->reason==='conflict'?409:403]);}
WORKER;
        $db = DB::connection('migration');
        $db->beginTransaction();
        $db->table('tenants')->where('id', $a['tenant'])->lockForUpdate()->first();
        $workers = [];
        try {
            foreach ([true, false] as $legacy) {
                $tag = 'review-race-'.Str::uuid();
                $worker = new Process([PHP_BINARY, '-r', $code], base_path(), ['APP_ENV' => 'testing']);
                $worker->setInput(json_encode(['legacy' => $legacy, 'tag' => $tag, 'user' => $a['user'], 'tenant' => $a['tenant'], 'member' => $e['member'], 'profile' => $e['id'], 'body' => $body, 'version' => $v, 'key' => (string) Str::uuid()]));
                $worker->setTimeout(30);
                $worker->start();
                $workers[] = $worker;
                $deadline = microtime(true) + 10;
                do {
                    $blocked = (int) DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'", [$tag])->n;
                    if ($blocked === 1) {
                        break;
                    }usleep(30000);
                } while (microtime(true) < $deadline);
                $this->assertSame(1, $blocked, 'Both real runtime commands must reach database locks.');
            }
            $db->commit();
            $statuses = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $statuses[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'];
            }$this->assertSame([200, 409], $statuses);
            $this->assertFalse($db->table('tenant_memberships')->where('id', $e['member'])->value('active'));
        } finally {
            if ($db->transactionLevel()) {
                $db->rollBack();
            }foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }

    public function test_upgrade_invalidates_held_old_forms_after_legacy_access_revocation(): void
    {
        $db = DB::connection('migration');
        $steps = $db->table('migrations')->where('migration', '>=', '2026_10_22_000013')->count();
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'migration', '--step' => $steps, '--force' => true]));
        try {
            $a = $this->clinic();
            $role = $this->role($a, 'installed', ['patients.view' => 'entire_clinic']);
            $jar = $this->signed('synthetic.a', $a['tenant']);
            $forms = [];
            foreach ([true, false] as $profile) {
                $e = $this->member($a, 'review.installed.'.($profile ? 'profile' : 'legacy'), $role, [$a['branch'], $a['branch_b']], $profile);
                $old = $this->detail($jar, $e);
                $this->assertSame(1, $old['version']);
                $forms[] = [$e, $this->payload($e), $old['version']];
                $this->browser($jar, 'DELETE', '/api/v1/clinic/employees/'.$e['member'])->assertNoContent();
                $this->assertSame(1, $this->detail($jar, $e)['version']);
            }
            $members = $db->table('tenant_memberships')->orderBy('id')->get()->toArray();
            $branches = $db->table('membership_branches')->orderBy('id')->get()->toArray();
            $roles = $db->table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toArray();
            $profiles = $db->table('employee_profiles')->orderBy('id')->get(['id', 'tenant_id', 'membership_id', 'profile', 'active'])->toArray();
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'migration', '--force' => true]));
            $after = $db->table('tenant_memberships')->orderBy('id')->get()->toArray();
            foreach ($after as $m) {
                unset($m->employee_version);
            }
            $this->assertEquals($members, $after);
            $this->assertEquals($branches, $db->table('membership_branches')->orderBy('id')->get()->toArray());
            $this->assertEquals($roles, $db->table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toArray());
            $this->assertEquals($profiles, $db->table('employee_profiles')->orderBy('id')->get(['id', 'tenant_id', 'membership_id', 'profile', 'active'])->toArray());
            foreach ($forms as [$e, $body, $oldVersion]) {
                $this->patchProfile($jar, $e, $body, $oldVersion)->assertConflict();
                $current = $this->detail($jar, $e);
                $this->assertFalse($current['access']);
                $this->assertFalse($db->table('tenant_memberships')->where('id', $e['member'])->value('active'));
                $this->assertGreaterThan($oldVersion, $current['version']);
                $version = $current['version'];
                $this->assertSame(0, Artisan::call('migrate', ['--database' => 'migration', '--force' => true]));
                $this->assertSame($version, $this->detail($jar, $e)['version']);
                $body['access'] = false;
                $body['profile']['phone'] = '+998907777777';
                $this->patchProfile($jar, $e, $body, $version)->assertOk();
                $this->assertFalse($this->detail($jar, $e)['access']);
            }
        } finally {
            $db->statement('TRUNCATE tenants, users CASCADE');
            Artisan::call('migrate', ['--database' => 'migration', '--force' => true]);
        }
    }

    public function test_invalidation_upgrades_an_installed_000013_and_never_revalidates_tokens_on_rollback(): void
    {
        $db = DB::connection('migration');
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'migration', '--step' => 1, '--force' => true]));
        try {
            $a = $this->clinic();
            $role = $this->role($a, 'already13', ['patients.view' => 'entire_clinic']);
            $e = $this->member($a, 'review.already13', $role, [$a['branch']]);
            $db->table('employee_profiles')->where('id', $e['id'])->update(['version' => 7]);
            $db->table('tenant_memberships')->where('id', $e['member'])->update(['employee_version' => 9]);
            $loginless = (string) Str::uuid();
            $db->table('employee_profiles')->insert(['id' => $loginless, 'tenant_id' => $a['tenant'], 'profile' => json_encode($e['profile']), 'version' => 12]);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'migration', '--force' => true]));
            $jar = $this->signed('synthetic.a', $a['tenant']);
            $this->assertSame(10, $this->detail($jar, $e)['version']);
            $this->assertSame(10, $db->table('employee_profiles')->where('id', $e['id'])->value('version'));
            $this->assertSame(13, $this->detail($jar, ['id' => $loginless])['version']);
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'migration', '--step' => 1, '--force' => true]));
            $this->assertSame(10, $this->detail($jar, $e)['version']);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'migration', '--force' => true]));
            $this->assertSame(11, $this->detail($jar, $e)['version']);
            $this->assertSame(14, $this->detail($jar, ['id' => $loginless])['version']);
        } finally {
            $db->statement('TRUNCATE tenants, users CASCADE');
            Artisan::call('migrate', ['--database' => 'migration', '--force' => true]);
        }
    }

    public function test_migration_handles_legacy_duplicate_and_generated_suffix_names_without_reassigning_roles(): void
    {
        $db = DB::connection('migration');
        $count = $db->table('migrations')->where('migration', '>=', '2026_10_21_')->count();
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'migration', '--step' => $count, '--force' => true]));
        try {
            $a = $this->fixture()['a'];
            $ids = ['00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002', '00000000-0000-4000-8000-000000000003'];
            $names = ['Staff Review', 'staff review', 'staff review [role '.$ids[1].']'];
            foreach ($ids as $i => $id) {
                $db->table('tenant_roles')->insert(['id' => $id, 'tenant_id' => $a['tenant'], 'code' => 'collision'.$i, 'name' => $names[$i]]);
                $db->table('role_permissions')->insert(['tenant_id' => $a['tenant'], 'role_id' => $id, 'permission_id' => $db->table('permissions')->where('code', 'patients.view')->value('id'), 'scope' => 'entire_clinic']);
            }
            $db->table('tenant_memberships')->where('id', $a['membership'])->update(['role_id' => $ids[1]]);
            $beforeRoles = $db->table('tenant_roles')->orderBy('id')->get(['id', 'tenant_id', 'code', 'active', 'protected'])->toArray();
            $beforeGrants = $db->table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toArray();
            $beforeMemberships = $db->table('tenant_memberships')->orderBy('id')->get(['id', 'tenant_id', 'user_id', 'role_id', 'active'])->toArray();
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'migration', '--force' => true]));
            $this->assertEquals($beforeRoles, $db->table('tenant_roles')->orderBy('id')->get(['id', 'tenant_id', 'code', 'active', 'protected'])->toArray());
            $this->assertEquals($beforeGrants, $db->table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toArray());
            $this->assertEquals($beforeMemberships, $db->table('tenant_memberships')->orderBy('id')->get(['id', 'tenant_id', 'user_id', 'role_id', 'active'])->toArray());
            $this->assertSame($names[0], $db->table('tenant_roles')->where('id', $ids[0])->value('name'));
            $this->assertSame($names[2], $db->table('tenant_roles')->where('id', $ids[2])->value('name'));
            $this->assertSame('staff review [role '.$ids[1].' #1]', $db->table('tenant_roles')->where('id', $ids[1])->value('name'));
            $this->assertSame(1, $db->table('role_name_upgrades')->count());
            $snapshot = $db->table('tenant_roles')->orderBy('id')->pluck('name', 'id')->all();
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'migration', '--force' => true]));
            $this->assertSame($snapshot, $db->table('tenant_roles')->orderBy('id')->pluck('name', 'id')->all());
        } finally {
            $db->statement('TRUNCATE tenants, users CASCADE');
            Artisan::call('migrate', ['--database' => 'migration', '--force' => true]);
        }
    }
}
