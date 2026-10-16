<?php

namespace Tests\Feature;

use App\Modules\Access\Services\GovernanceDenied;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\Identity;
use App\Modules\Platform\Services\ClinicProvisioning;
use App\Modules\Tenancy\Services\ClinicAdministration;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\FoundationTestCase;

class CeoGovernanceTest extends FoundationTestCase
{
    private const PASSWORD = 'Synthetic-permanent-42!';

    private const TEMPORARY = 'Synthetic-temporary-42!';

    private function identity(): Identity
    {
        return app(Identity::class);
    }

    private function clinic(): ClinicAdministration
    {
        return app(ClinicAdministration::class);
    }

    private function platform(): ClinicProvisioning
    {
        return app(ClinicProvisioning::class);
    }

    private function operator(string $login = 'platform.one'): User
    {
        $id = (string) Str::uuid();
        DB::connection('migration')->table('users')->insert(['id' => $id, 'login' => $login, 'name' => 'Synthetic platform operator', 'password_hash' => Hash::make(self::PASSWORD), 'must_change_password' => false, 'is_platform_admin' => true]);

        return $this->identity()->authenticate($login, self::PASSWORD, '192.0.2.1');
    }

    private function provision(User $operator, string $suffix = 'a'): array
    {
        $result = $this->platform()->createClinic($operator, 'Synthetic Clinic '.$suffix, 'clinic-'.$suffix, 'ceo.'.$suffix, self::TEMPORARY);
        $ceo = $this->identity()->authenticate('ceo.'.$suffix, self::TEMPORARY, '192.0.2.2');
        $ceo = $this->identity()->changePassword($ceo, self::TEMPORARY, self::PASSWORD);

        return [$result['tenant_id'], $ceo];
    }

    private function role(User $ceo, string $tenant, string $code = 'staff', array $grants = []): string
    {
        return $this->clinic()->saveRole($ceo, $tenant, null, $code, 'Synthetic '.$code, $grants);
    }

    private function denied(\Closure $operation, string $reason = 'forbidden'): void
    {
        try {
            $operation();
            $this->fail('Operation should be denied.');
        } catch (GovernanceDenied $exception) {
            $this->assertSame($reason, $exception->reason);
            $this->assertNull($exception->getPrevious(), 'SQL bindings must not escape in exception chains.');
        }
    }

    public function test_platform_bootstraps_exactly_one_ceo_and_temporary_identity_is_restricted(): void
    {
        $operator = $this->operator();
        $result = $this->platform()->createClinic($operator, 'Synthetic Clinic', 'clinic-a', ' Ceo.A ', self::TEMPORARY);
        $tenant = $result['tenant_id'];
        $owner = DB::connection('migration');
        $this->assertFalse($result['pending_consent']);
        $this->assertSame(1, $owner->table('tenant_memberships')->count());
        $this->assertSame(1, $owner->table('tenant_roles')->where('protected', true)->count());
        $this->assertSame(1, $owner->table('tenant_ceos')->count());
        $ceo = $this->identity()->authenticate('CEO.A', self::TEMPORARY, '192.0.2.2');
        $this->assertTrue($ceo->must_change_password);
        $this->assertNull($ceo->getAttribute('password_hash'));
        $this->denied(fn () => $this->clinic()->createBranch($ceo, $tenant, 'Synthetic branch', 'main'));
        $ceo = $this->identity()->changePassword($ceo, self::TEMPORARY, self::PASSWORD);
        $this->assertSame(2, $ceo->credential_version);
        $branch = $this->clinic()->createBranch($ceo, $tenant, 'Synthetic branch', 'main');
        $this->assertTrue(Str::isUuid($branch));
        $this->denied(fn () => $this->platform()->createClinic($operator, 'Duplicate', 'clinic-a', 'unused.ceo', self::TEMPORARY), 'conflict');
        $this->assertFalse($owner->table('users')->where('login', 'unused.ceo')->exists());
        $this->denied(fn () => $this->clinic()->createBranch($operator, $tenant, 'Forbidden', 'forbidden'));
    }

    public function test_ceo_creates_employees_and_protected_ceo_cannot_be_demoted_or_reset(): void
    {
        [$tenant, $ceo] = $this->provision($operator = $this->operator());
        $role = $this->role($ceo, $tenant);
        $branch = $this->clinic()->createBranch($ceo, $tenant, 'Synthetic branch', 'main');
        $member = $this->clinic()->createEmployee($ceo, $tenant, 'staff.a', 'Synthetic employee', self::TEMPORARY, $role, [$branch]);
        $owner = DB::connection('migration');
        $employee = $this->identity()->authenticate('staff.a', self::TEMPORARY, '192.0.2.3');
        $this->denied(fn () => $this->clinic()->createBranch($employee, $tenant, 'Forbidden', 'forbidden'));
        $employee = $this->identity()->changePassword($employee, self::TEMPORARY, self::PASSWORD);
        $this->assertSame($employee->id, $owner->table('tenant_memberships')->where('id', $member)->value('user_id'));
        $this->assertSame($branch, $owner->table('membership_branches')->where('membership_id', $member)->value('branch_id'));
        $designation = $owner->table('tenant_ceos')->where('tenant_id', $tenant)->first();
        $ceoRole = $owner->table('tenant_memberships')->where('id', $designation->membership_id)->value('role_id');
        $this->denied(fn () => $this->clinic()->assignEmployee($ceo, $tenant, $designation->membership_id, $role, active: false));
        $this->denied(fn () => $this->clinic()->deleteRole($ceo, $tenant, $ceoRole));
        $this->denied(fn () => $this->clinic()->saveRole($ceo, $tenant, $ceoRole, 'ceo', 'CEO', []));
        $this->denied(fn () => $this->clinic()->resetEmployee($ceo, $tenant, $designation->membership_id, self::PASSWORD, self::TEMPORARY));
        $this->denied(fn () => $this->clinic()->createEmployee($operator, $tenant, 'platform.staff', 'Forbidden', self::TEMPORARY, $role));
        $this->denied(fn () => $this->clinic()->resetEmployee($operator, $tenant, $member, self::PASSWORD, self::TEMPORARY));
        $this->assertSame(1, $owner->table('tenant_ceos')->count());
    }

    public function test_tenant_foreign_roles_and_branches_are_rejected_atomically(): void
    {
        $operator = $this->operator();
        [$a, $ceoA] = $this->provision($operator);
        [$b, $ceoB] = $this->provision($operator, 'b');
        $roleA = $this->role($ceoA, $a);
        $roleB = $this->role($ceoB, $b);
        $branchB = $this->clinic()->createBranch($ceoB, $b, 'Synthetic B', 'main');
        $this->denied(fn () => $this->clinic()->createEmployee($ceoA, $a, 'foreign.role', 'Synthetic', self::TEMPORARY, $roleB));
        $this->denied(fn () => $this->clinic()->createEmployee($ceoA, $a, 'foreign.branch', 'Synthetic', self::TEMPORARY, $roleA, [$branchB]));
        $this->denied(fn () => $this->clinic()->createBranch($ceoA, $b, 'Forbidden', 'foreign'));
        $this->assertFalse(DB::connection('migration')->table('users')->whereIn('login', ['foreign.role', 'foreign.branch'])->exists());
        $visible = app(TenantContext::class)->run($ceoA, $a, fn () => DB::table('branches')->pluck('id')->all());
        $this->assertNotContains($branchB, $visible);
    }

    public function test_delegation_cannot_expand_permissions_scopes_or_modify_own_role(): void
    {
        [$tenant, $ceo] = $this->provision($this->operator());
        $grants = array_map(fn ($code) => ['code' => $code, 'scope' => 'entire_clinic'], ['roles.create', 'roles.update', 'roles.manage_permissions']);
        $grants[] = ['code' => 'employees.view', 'scope' => 'own'];
        $role = $this->role($ceo, $tenant, 'manager', $grants);
        $this->clinic()->createEmployee($ceo, $tenant, 'manager.a', 'Synthetic manager', self::TEMPORARY, $role);
        $manager = $this->identity()->changePassword($this->identity()->authenticate('manager.a', self::TEMPORARY, '192.0.2.3'), self::TEMPORARY, self::PASSWORD);
        $this->role($manager, $tenant, 'delegated', [['code' => 'employees.view', 'scope' => 'own']]);
        $this->denied(fn () => $this->role($manager, $tenant, 'broadened', [['code' => 'employees.view', 'scope' => 'entire_clinic']]));
        $this->denied(fn () => $this->role($manager, $tenant, 'unowned', [['code' => 'employees.create', 'scope' => 'entire_clinic']]));
        $this->denied(fn () => $this->clinic()->saveRole($manager, $tenant, $role, 'manager', 'Changed', $grants));
    }

    public function test_existing_ceo_login_requires_consent_without_overwriting_credentials(): void
    {
        $operator = $this->operator();
        [$a, $ceo] = $this->provision($operator);
        $hash = DB::connection('migration')->table('users')->where('id', $ceo->id)->value('password_hash');
        $proposal = $this->platform()->createClinic($operator, 'Synthetic Clinic B', 'clinic-b', 'ceo.a', null);
        $this->assertTrue($proposal['pending_consent']);
        $this->denied(fn () => $this->clinic()->createBranch($ceo, $proposal['tenant_id'], 'Forbidden', 'main'));
        $pending = $this->clinic()->pendingLinks($ceo);
        $this->assertCount(1, $pending);
        $this->assertSame('ceo', $pending[0]['purpose']);
        $this->denied(fn () => $this->clinic()->acceptLink($ceo, $pending[0]['id'], 'Incorrect-password'), 'credentials');
        $this->clinic()->acceptLink($ceo, $pending[0]['id'], self::PASSWORD);
        $this->assertSame($hash, DB::connection('migration')->table('users')->where('id', $ceo->id)->value('password_hash'));
        $this->assertSame(2, DB::connection('migration')->table('tenant_ceos')->where('user_id', $ceo->id)->count());
        $this->denied(fn () => $this->clinic()->acceptLink($ceo, $pending[0]['id'], self::PASSWORD));
        $this->clinic()->createBranch($ceo, $proposal['tenant_id'], 'Synthetic B', 'main');
    }

    public function test_employee_linking_requires_consent_and_preserves_global_identity_and_roles(): void
    {
        $operator = $this->operator();
        [$a, $ceoA] = $this->provision($operator);
        [$b, $ceoB] = $this->provision($operator, 'b');
        $roleA = $this->role($ceoA, $a);
        $roleB = $this->role($ceoB, $b);
        $memberA = $this->clinic()->createEmployee($ceoA, $a, 'shared.staff', 'Synthetic shared employee', self::TEMPORARY, $roleA);
        $employee = $this->identity()->changePassword($this->identity()->authenticate('shared.staff', self::TEMPORARY, '192.0.2.3'), self::TEMPORARY, self::PASSWORD);
        $request = $this->clinic()->requestLink($ceoB, $b, 'shared.staff', $roleB);
        $this->assertCount(0, $this->clinic()->pendingLinks($ceoA));
        $this->denied(fn () => $this->clinic()->acceptLink($ceoA, $request, self::PASSWORD));
        $memberB = $this->clinic()->acceptLink($employee, $request, self::PASSWORD);
        $owner = DB::connection('migration');
        $this->assertSame($employee->id, $owner->table('tenant_memberships')->where('id', $memberB)->value('user_id'));
        $this->assertSame($roleA, $owner->table('tenant_memberships')->where('id', $memberA)->value('role_id'));
        $this->assertSame($roleB, $owner->table('tenant_memberships')->where('id', $memberB)->value('role_id'));
        $this->denied(fn () => $this->clinic()->resetEmployee($ceoA, $a, $memberA, self::PASSWORD, self::TEMPORARY));
        $this->denied(fn () => $this->clinic()->acceptLink($employee, $request, self::PASSWORD));
        $this->assertSame(1, $owner->table('users')->where('login', 'shared.staff')->count());
    }

    public function test_link_acceptance_rechecks_initiator_credential_version(): void
    {
        $operator = $this->operator();
        [$a, $ceoA] = $this->provision($operator);
        [$b, $ceoB] = $this->provision($operator, 'b');
        $role = $this->role($ceoB, $b);
        $request = $this->clinic()->requestLink($ceoB, $b, 'ceo.a', $role);
        DB::connection('migration')->table('users')->where('id', $ceoB->id)->increment('credential_version');
        $this->denied(fn () => $this->clinic()->acceptLink($ceoA, $request, self::PASSWORD));
        $this->assertSame(0, DB::connection('migration')->table('tenant_memberships')->where('tenant_id', $b)->where('user_id', $ceoA->id)->count());
    }

    public function test_employee_reset_revokes_sessions_and_old_actor_credentials(): void
    {
        [$tenant, $ceo] = $this->provision($this->operator());
        $role = $this->role($ceo, $tenant);
        $member = $this->clinic()->createEmployee($ceo, $tenant, 'staff.a', 'Synthetic staff', self::TEMPORARY, $role);
        $staff = $this->identity()->changePassword($this->identity()->authenticate('staff.a', self::TEMPORARY, '192.0.2.3'), self::TEMPORARY, self::PASSWORD);
        $owner = DB::connection('migration');
        $owner->table('sessions')->insert(['id' => 'synthetic-session', 'user_id' => $staff->id, 'credential_version' => 2, 'auth_stage' => 'full', 'payload' => '', 'last_activity' => time()]);
        $this->clinic()->resetEmployee($ceo, $tenant, $member, self::PASSWORD, self::TEMPORARY);
        $this->assertFalse($owner->table('sessions')->where('user_id', $staff->id)->exists());
        $this->denied(fn () => $this->identity()->authenticate('staff.a', self::PASSWORD, '192.0.2.3'), 'credentials');
        $fresh = $this->identity()->authenticate('staff.a', self::TEMPORARY, '192.0.2.3');
        $this->assertTrue($fresh->must_change_password);
        $this->assertSame(3, $fresh->credential_version);
        $this->denied(fn () => $this->clinic()->pendingLinks($staff));
        $audit = json_encode($owner->table('audit_logs')->get());
        $this->assertStringNotContainsString(self::TEMPORARY, $audit);
        $this->assertStringNotContainsString('$argon2id$', $audit);
    }

    public function test_ceo_recovery_requires_two_operators_and_one_time_token_and_revokes_sessions(): void
    {
        [$tenant, $ceo] = $this->provision($one = $this->operator());
        $two = $this->operator('platform.two');
        $request = $this->platform()->requestRecovery($one, $tenant, self::PASSWORD);
        $this->denied(fn () => $this->platform()->approveRecovery($one, $request, 'synthetic-verification-001', self::PASSWORD));
        $token = $this->platform()->approveRecovery($two, $request, 'synthetic-verification-001', self::PASSWORD);
        $raw = $token->revealOnce();
        $this->assertStringNotContainsString($raw, json_encode($token));
        $owner = DB::connection('migration');
        $this->assertSame(hash('sha256', $raw), $owner->table('ceo_recovery_requests')->where('id', $request)->value('token_digest'));
        $owner->table('sessions')->insert(['id' => 'ceo-session', 'user_id' => $ceo->id, 'credential_version' => 2, 'auth_stage' => 'full', 'payload' => '', 'last_activity' => time()]);
        $this->denied(fn () => $this->platform()->recover($request, 'incorrect-token', 'Synthetic-new-password-43!', '192.0.2.4'));
        $this->platform()->recover($request, $raw, 'Synthetic-new-password-43!', '192.0.2.4');
        $this->assertFalse($owner->table('sessions')->where('user_id', $ceo->id)->exists());
        $fresh = $this->identity()->authenticate('ceo.a', 'Synthetic-new-password-43!', '192.0.2.2');
        $this->assertFalse($fresh->must_change_password);
        $this->assertSame(3, $fresh->credential_version);
        $this->assertSame($ceo->id, $owner->table('tenant_ceos')->where('tenant_id', $tenant)->value('user_id'));
        $this->denied(fn () => $this->platform()->recover($request, $raw, 'Synthetic-new-password-44!', '192.0.2.4'));
        $audit = json_encode($owner->table('audit_logs')->get());
        $this->assertStringNotContainsString($raw, $audit);
        $this->assertStringNotContainsString('Synthetic-new-password-43!', $audit);
    }

    public function test_recovery_invalidates_when_target_credentials_change_or_verifier_is_disabled(): void
    {
        [$tenant, $ceo] = $this->provision($one = $this->operator());
        $two = $this->operator('platform.two');
        $request = $this->platform()->requestRecovery($one, $tenant, self::PASSWORD);
        $token = $this->platform()->approveRecovery($two, $request, 'synthetic-verification-002', self::PASSWORD)->revealOnce();
        DB::connection('migration')->table('users')->where('id', $two->id)->update(['active' => false]);
        $this->denied(fn () => $this->platform()->recover($request, $token, 'Synthetic-new-password-43!', '192.0.2.4'));
        DB::connection('migration')->table('users')->where('id', $two->id)->update(['active' => true]);
        $this->identity()->changePassword($ceo, self::PASSWORD, 'Synthetic-new-password-44!');
        $this->denied(fn () => $this->platform()->recover($request, $token, 'Synthetic-new-password-43!', '192.0.2.4'));
    }

    public function test_password_guessing_is_limited_without_exposing_unknown_identities(): void
    {
        $this->operator();
        for ($i = 0; $i < 5; $i++) {
            $this->denied(fn () => $this->identity()->authenticate('unknown.login', 'Incorrect-password', '192.0.2.9'), 'credentials');
        }
        $this->denied(fn () => $this->identity()->authenticate('unknown.login', 'Incorrect-password', '192.0.2.9'), 'rate_limited');
        $this->assertSame(6, DB::connection('migration')->table('audit_logs')->where('action', 'auth.failed')->count());
        $this->assertStringNotContainsString('unknown.login', json_encode(DB::connection('migration')->table('auth_rate_limits')->get()));
    }

    public function test_runtime_rls_and_privilege_boundaries_survive_identity_lookup(): void
    {
        [$tenant, $ceo] = $this->provision($operator = $this->operator());
        $this->assertSqlState('42501', fn () => DB::select('SELECT password_hash FROM users'));
        $this->assertSame([], DB::table('users')->select('id')->get()->all());
        $this->assertSqlState('42501', fn () => DB::select('SELECT governance.actor()'));
        $this->assertSqlState('42501', fn () => DB::statement('SET ROLE blusser_security'));
        $this->assertSqlState('42501', fn () => DB::table('tenant_ceos')->get());
        $owner = DB::connection('migration');
        $designation = $owner->table('tenant_ceos')->where('tenant_id', $tenant)->first();
        $ceoRole = $owner->table('tenant_memberships')->where('id', $designation->membership_id)->value('role_id');
        $owner->table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'user_id' => $operator->id, 'role_id' => $ceoRole]);
        try {
            app(TenantContext::class)->run($operator, $tenant, fn () => DB::table('branches')->get());
            $this->fail('Platform identity must not enter tenant context.');
        } catch (NotFoundHttpException) {
            $this->addToAssertionCount(1);
        }
        $owner->table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'actor_user_id' => $ceo->id, 'action' => 'synthetic.private', 'resource_type' => 'synthetic', 'request_id' => (string) Str::uuid()]);
        $actions = DB::transaction(function () use ($operator, $tenant) {
            DB::select("SELECT set_config('app.user_id',?,true),set_config('app.tenant_id',?,true)", [$operator->id, $tenant]);

            return DB::table('audit_logs')->pluck('action')->all();
        });
        $this->assertContains('clinic.provisioned', $actions);
        $this->assertNotContains('synthetic.private', $actions);
        $this->assertNotContains('account.created', $actions);
    }

    public function test_link_acceptance_rechecks_revoked_permissions_and_expiry(): void
    {
        $operator = $this->operator();
        [$a, $ceoA] = $this->provision($operator);
        [$b, $ceoB] = $this->provision($operator, 'b');
        $role = $this->role($ceoB, $b);
        $request = $this->clinic()->requestLink($ceoB, $b, 'ceo.a', $role);
        $owner = DB::connection('migration');
        $permission = $owner->table('permissions')->where('code', 'employees.assign_role')->value('id');
        $owner->table('role_permissions')->where('tenant_id', $b)->where('permission_id', $permission)->delete();
        $this->denied(fn () => $this->clinic()->acceptLink($ceoA, $request, self::PASSWORD));
        $owner->table('membership_link_requests')->where('id', $request)->update(['expires_at' => now()->subMinute()]);
        $this->denied(fn () => $this->clinic()->acceptLink($ceoA, $request, self::PASSWORD));
        $this->assertCount(0, $this->clinic()->pendingLinks($ceoA));
    }

    public function test_expired_temporary_password_and_expired_recovery_cannot_be_used(): void
    {
        [$tenant, $ceo] = $this->provision($one = $this->operator());
        $two = $this->operator('platform.two');
        $role = $this->role($ceo, $tenant);
        $this->clinic()->createEmployee($ceo, $tenant, 'expired.staff', 'Synthetic', self::TEMPORARY, $role);
        $owner = DB::connection('migration');
        $owner->table('users')->where('login', 'expired.staff')->update(['temporary_password_expires_at' => now()->subMinute()]);
        $this->denied(fn () => $this->identity()->authenticate('expired.staff', self::TEMPORARY, '192.0.2.3'), 'credentials');
        $request = $this->platform()->requestRecovery($one, $tenant, self::PASSWORD);
        $token = $this->platform()->approveRecovery($two, $request, 'synthetic-verification-expiry', self::PASSWORD);
        $raw = $token->revealOnce();
        try {
            $token->revealOnce();
            $this->fail('Token was revealed twice.');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
        $owner->table('ceo_recovery_requests')->where('id', $request)->update(['token_expires_at' => now()->subMinute()]);
        $this->denied(fn () => $this->platform()->recover($request, $raw, 'Synthetic-new-password-43!', '192.0.2.4'));
        $this->assertSame(2, $owner->table('users')->where('id', $ceo->id)->value('credential_version'));
    }

    public function test_ceo_can_delegate_employee_management_but_delegate_cannot_assign_self(): void
    {
        [$tenant, $ceo] = $this->provision($this->operator());
        $codes = ['employees.create', 'employees.update', 'employees.assign_role', 'employees.assign_branches', 'employees.reset_password', 'employees.delete'];
        $role = $this->role($ceo, $tenant, 'manager', array_map(fn ($code) => ['code' => $code, 'scope' => 'entire_clinic'], $codes));
        $staffRole = $this->role($ceo, $tenant);
        $managerMember = $this->clinic()->createEmployee($ceo, $tenant, 'manager.a', 'Synthetic manager', self::TEMPORARY, $role);
        $manager = $this->identity()->changePassword($this->identity()->authenticate('manager.a', self::TEMPORARY, '192.0.2.3'), self::TEMPORARY, self::PASSWORD);
        $member = $this->clinic()->createEmployee($manager, $tenant, 'delegated.staff', 'Synthetic staff', self::TEMPORARY, $staffRole);
        $this->clinic()->resetEmployee($manager, $tenant, $member, self::PASSWORD, 'Synthetic-replacement-43!');
        $this->denied(fn () => $this->clinic()->assignEmployee($manager, $tenant, $managerMember, $staffRole));
        $this->assertTrue($this->identity()->authenticate('delegated.staff', 'Synthetic-replacement-43!', '192.0.2.4')->must_change_password);
    }

    public function test_lookup_requires_explicit_permission_and_create_does_not_overwrite_global_login(): void
    {
        [$tenant, $ceo] = $this->provision($this->operator());
        $role = $this->role($ceo, $tenant);
        $this->clinic()->createEmployee($ceo, $tenant, 'staff.a', 'Synthetic', self::TEMPORARY, $role);
        $this->assertTrue($this->clinic()->loginExists($ceo, $tenant, 'STAFF.A'));
        $this->assertFalse($this->clinic()->loginExists($ceo, $tenant, 'absent.staff'));
        $staff = $this->identity()->changePassword($this->identity()->authenticate('staff.a', self::TEMPORARY, '192.0.2.3'), self::TEMPORARY, self::PASSWORD);
        $this->denied(fn () => $this->clinic()->loginExists($staff, $tenant, 'ceo.a'));
        $this->denied(fn () => $this->clinic()->createEmployee($ceo, $tenant, 'staff.a', 'Overwrite', self::TEMPORARY, $role), 'conflict');
        $this->assertSame($staff->id, $this->identity()->authenticate('staff.a', self::PASSWORD, '192.0.2.3')->id);
    }
}
