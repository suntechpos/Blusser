<?php

namespace Tests\Feature;

use App\Modules\Access\Services\DefaultClinicRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\FoundationTestCase;

final class DefaultClinicRolesTest extends FoundationTestCase
{
    private function ceo(): array
    {
        $a = $this->fixture()['a'];
        $db = DB::connection('migration');
        $db->table('tenant_roles')->where('id', $a['role'])->update(['protected' => true, 'code' => 'ceo']);
        $db->table('tenant_ceos')->insert(['tenant_id' => $a['tenant'], 'user_id' => $a['user'], 'membership_id' => $a['membership']]);

        return $a;
    }

    public function test_explicit_provisioning_adds_six_roles_with_dependencies_and_preserves_them_on_repeat(): void
    {
        $a = $this->ceo();
        $actor = $this->actor($a['user']);
        $service = app(DefaultClinicRoles::class);
        $this->assertCount(1, $service->inspect($actor, $a['tenant']));
        $result = $service->provision($actor, $a['tenant']);
        $this->assertCount(6, array_filter($result, fn ($r) => $r['status'] === 'created'));
        $db = DB::connection('migration');
        $before = $db->table('tenant_roles')->where('tenant_id', $a['tenant'])->orderBy('id')->get()->toArray();
        $grants = $db->table('role_permissions')->where('tenant_id', $a['tenant'])->orderBy('role_id')->orderBy('permission_id')->get()->toArray();
        $again = $service->provision($actor, $a['tenant']);
        $this->assertCount(6, array_filter($again, fn ($r) => $r['status'] === 'preserved'));
        $this->assertEquals($before, $db->table('tenant_roles')->where('tenant_id', $a['tenant'])->orderBy('id')->get()->toArray());
        $this->assertEquals($grants, $db->table('role_permissions')->where('tenant_id', $a['tenant'])->orderBy('role_id')->orderBy('permission_id')->get()->toArray());
        $rid = $db->table('tenant_roles')->where('tenant_id', $a['tenant'])->where('code', 'registrator')->value('id');
        $codes = $db->table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $rid)->pluck('p.code')->all();
        foreach (['appointments.create', 'appointments.check_in', 'patients.create', 'visits.create', 'queues.create'] as $code) {
            $this->assertContains($code, $codes);
        }
        $this->assertCount(1, $db->table('tenant_roles')->where('tenant_id', '<>', $a['tenant'])->get());
    }

    public function test_existing_custom_code_and_normalized_name_collisions_keep_ids_grants_and_names(): void
    {
        $a = $this->ceo();
        $db = DB::connection('migration');
        $id = (string) Str::uuid();
        $db->table('tenant_roles')->insert(['id' => $id, 'tenant_id' => $a['tenant'], 'code' => 'custom_lab', 'name' => 'LABORANT']);
        $db->table('role_permissions')->insert(['tenant_id' => $a['tenant'], 'role_id' => $id, 'permission_id' => $db->table('permissions')->where('code', 'patients.view')->value('id'), 'scope' => 'own']);
        $cash = (string) Str::uuid();
        $db->table('tenant_roles')->insert(['id' => $cash, 'tenant_id' => $a['tenant'], 'code' => 'cashier', 'name' => 'Custom limited cashier']);
        $uid = (string) Str::uuid();
        $mid = (string) Str::uuid();
        $db->table('users')->insert(['id' => $uid, 'login' => 'custom.staff', 'name' => 'Custom staff', 'password_hash' => '$argon2id$synthetic', 'must_change_password' => false]);
        $db->table('tenant_memberships')->insert(['id' => $mid, 'tenant_id' => $a['tenant'], 'user_id' => $uid, 'role_id' => $id]);
        $before = $db->table('role_permissions')->where('role_id', $id)->get()->toArray();
        $service = app(DefaultClinicRoles::class);
        $service->provision($this->actor($a['user']), $a['tenant']);
        $this->assertSame('LABORANT', $db->table('tenant_roles')->where('id', $id)->value('name'));
        $this->assertSame('custom_lab', $db->table('tenant_roles')->where('id', $id)->value('code'));
        $this->assertEquals($before, $db->table('role_permissions')->where('role_id', $id)->get()->toArray());
        $this->assertFalse($db->table('tenant_roles')->where('tenant_id', $a['tenant'])->where('code', 'laborant')->exists());
        $this->assertSame('Custom limited cashier', $db->table('tenant_roles')->where('id', $cash)->value('name'));
        $this->assertSame(0, $db->table('role_permissions')->where('role_id', $cash)->count());
        $this->assertSame($id, $db->table('tenant_memberships')->where('id', $mid)->value('role_id'));
    }

    public function test_selected_missing_templates_never_touch_user_cashier_and_doctor_workspace_roles(): void
    {
        $a = $this->ceo();
        $db = DB::connection('migration');
        $ids = [];
        foreach (['Kassir', 'Shifokor kabineti'] as $i => $name) {
            $id = (string) Str::uuid();
            $ids[] = $id;
            $db->table('tenant_roles')->insert(['id' => $id, 'tenant_id' => $a['tenant'], 'code' => 'user_custom_'.$i, 'name' => $name]);
            $db->table('role_permissions')->insert(['tenant_id' => $a['tenant'], 'role_id' => $id, 'permission_id' => $db->table('permissions')->where('code', 'patients.view')->value('id'), 'scope' => 'own']);
        }
        $before = $db->table('tenant_roles')->whereIn('id', [$a['role'], ...$ids])->orderBy('id')->get()->toArray();
        $grants = $db->table('role_permissions')->whereIn('role_id', [$a['role'], ...$ids])->orderBy('role_id')->orderBy('permission_id')->get()->toArray();
        $service = app(DefaultClinicRoles::class);
        $actor = $this->actor($a['user']);
        $result = $service->provision($actor, $a['tenant'], ['registrator', 'laborant', 'uzi_specialist']);
        $this->assertSame(['Registrator', 'Laborant', 'UZI mutaxassisi'], array_column($result, 'name'));
        $this->assertSame(6, $db->table('tenant_roles')->where('tenant_id', $a['tenant'])->count());
        $existing = $service->provision($actor, $a['tenant'], ['doctor', 'cashier']);
        $this->assertSame(['preserved', 'preserved'], array_column($existing, 'status'));
        $this->assertEquals($before, $db->table('tenant_roles')->whereIn('id', [$a['role'], ...$ids])->orderBy('id')->get()->toArray());
        $this->assertEquals($grants, $db->table('role_permissions')->whereIn('role_id', [$a['role'], ...$ids])->orderBy('role_id')->orderBy('permission_id')->get()->toArray());
    }

    public function test_cli_listing_is_read_only_and_shows_real_clinic_ids(): void
    {
        $a = $this->ceo();
        $before = DB::connection('migration')->table('tenant_roles')->orderBy('id')->get()->toArray();
        $this->artisan('blusser:clinic-roles', ['--actor' => 'synthetic.a'])->expectsQuestion('Joriy parol', 'Synthetic-password-42!')->expectsOutputToContain($a['tenant'])->assertExitCode(0);
        $this->assertEquals($before, DB::connection('migration')->table('tenant_roles')->orderBy('id')->get()->toArray());
    }

    public function test_non_ceo_and_foreign_tenant_cannot_provision_initial_roles(): void
    {
        $f = $this->fixture();
        $a = $f['a'];
        $service = app(DefaultClinicRoles::class);
        foreach ([$a['tenant'], $f['b']['tenant']] as $tenant) {
            try {
                $service->provision($this->actor($a['user']), $tenant);
                $this->fail('Unauthorized provisioning');
            } catch (AuthorizationException|NotFoundHttpException) {
                $this->assertSame(2, DB::connection('migration')->table('tenant_roles')->count());
            }
        }
    }
}
