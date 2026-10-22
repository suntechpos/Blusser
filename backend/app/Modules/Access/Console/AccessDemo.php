<?php

namespace App\Modules\Access\Console;

use App\Modules\Access\Services\Governance;
use App\Modules\Identity\Services\Identity;
use App\Modules\Platform\Services\ClinicProvisioning;
use App\Modules\Tenancy\Services\ClinicAdministration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Explicit opt-in fixture command; never touches production/development clinical databases. */
final class AccessDemo extends Command
{
    protected $signature = 'blusser:access-demo {--operator=accessdemo.platform}';

    protected $description = 'Idempotent isolated blusser_test access accounts; passwords supplied only via environment';

    public function handle(): int
    {
        if (! in_array(app()->environment(), ['local', 'testing'], true) || config('database.connections.migration.database') !== 'blusser_test' || config('database.connections.pgsql.database') !== 'blusser_test') {
            $this->error('Only local/testing blusser_test is allowed.');

            return self::FAILURE;
        }
        $db = DB::connection('migration');
        if ($db->selectOne('SELECT current_database() AS name')->name !== 'blusser_test') {
            return self::FAILURE;
        }
        $operatorPassword = getenv('ACCESS_DEMO_OPERATOR_PASSWORD') ?: '';
        $password = getenv('ACCESS_DEMO_PASSWORD') ?: '';
        try {
            Governance::password($password);
            $identity = app(Identity::class);
            $operator = $identity->authenticate((string) $this->option('operator'), $operatorPassword, 'access-demo-cli');
            if (! $operator->is_platform_admin) {
                throw new \RuntimeException;
            }
            $tenant = $db->table('tenants')->where('slug', 'access-demo')->value('id');
            if (! $tenant) {
                $temporary = Str::random(40);
                $result = app(ClinicProvisioning::class)->createClinic($operator, 'Access demo (test only)', 'access-demo', 'accessdemo.admin', $temporary);
                $tenant = $result['tenant_id'];
                $ceo = $identity->authenticate('accessdemo.admin', $temporary, 'access-demo-cli');
                $ceo = $identity->changePassword($ceo, $temporary, $password);
                unset($temporary);
            } else {
                $ceo = $identity->authenticate('accessdemo.admin', $password, 'access-demo-cli');
                if (! $db->table('tenant_ceos')->where('tenant_id', $tenant)->where('user_id', $ceo->id)->exists()) {
                    throw new \RuntimeException;
                }
            }
            $admin = app(ClinicAdministration::class);
            $branch = $db->table('branches')->where('tenant_id', $tenant)->where('code', 'main')->value('id') ?? $admin->createBranch($ceo, $tenant, 'Asosiy filial', 'main');
            $roles = [
                'registrator' => ['Registrator', ['reception.view', 'visits.view', 'visits.create', 'patients.view', 'patients.create', 'patients.update', 'appointments.view', 'appointments.create', 'appointments.update', 'appointments.check_in', 'queues.view', 'queues.call']],
                'doctor' => ['Shifokor', ['workspace.view', 'workspace.consult', 'workspace.prescribe', 'workspace.refer', 'workspace.upload', 'workspace.complete', 'workspace.export', 'patients.view', 'appointments.view']],
                'cashier' => ['Kassir', ['cashier.view', 'cashier.shift', 'cashier.pay', 'cashier.history']],
            ];
            foreach ($roles as $code => [$name,$codes]) {
                $grants = [];
                foreach ($codes as $c) {
                    $allowed = $db->table('permissions')->where('code', $c)->value('allowed_scopes');
                    $prefer = $code === 'doctor' && in_array($c, ['patients.view', 'appointments.view'], true) ? 'own' : ($code === 'registrator' ? 'assigned_branches' : 'entire_clinic');
                    $grants[] = ['code' => $c, 'scope' => str_contains($allowed, $prefer) ? $prefer : 'entire_clinic'];
                }
                $rid = $db->table('tenant_roles')->where('tenant_id', $tenant)->where('code', 'access_'.$code)->value('id') ?? $admin->saveRole($ceo, $tenant, null, 'access_'.$code, $name, $grants);
                $login = 'accessdemo.'.$code;
                $existing = $db->table('users')->where('login', $login)->value('id');
                if ($existing) {
                    if (! $db->table('tenant_memberships')->where('tenant_id', $tenant)->where('user_id', $existing)->exists()) {
                        throw new \RuntimeException;
                    }

continue;
                }
                $temporary = Str::random(40);
                $body = ['profile' => ['first' => 'Demo', 'last' => $name, 'position' => $name, 'phone' => '+998900001122', 'hired' => date('Y-m-d'), 'duration' => 30], 'active' => true, 'access' => true, 'login' => $login, 'name' => 'Demo '.$name, 'branch_id' => $branch, 'branch_ids' => [$branch], 'role_id' => $rid, 'password_hash' => Hash::make($temporary)];
                app(Governance::class)->call('staff_command', [null, json_encode($body, JSON_THROW_ON_ERROR), (string) Str::uuid(), null], $ceo, $tenant);
                $user = $identity->authenticate($login, $temporary, 'access-demo-cli');
                $identity->changePassword($user, $temporary, $password);
                unset($temporary);
            }
            $this->info('Ready: accessdemo.admin, accessdemo.registrator, accessdemo.doctor, accessdemo.cashier. Existing accounts/passwords were not overwritten.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Fixture setup failed. Check supplied credentials and dedicated test database; no password is printed.');

            return self::FAILURE;
        } finally {
            unset($operatorPassword,$password);
        }
    }
}
