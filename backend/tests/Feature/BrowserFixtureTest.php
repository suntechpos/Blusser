<?php

namespace Tests\Feature;

use App\Modules\Patients\Services\Patients;
use App\Modules\Scheduling\Services\Scheduling;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\FoundationTestCase;

/** Synthetic browser fixtures only; FoundationTestCase guards actual/configured blusser_test. */
final class BrowserFixtureTest extends FoundationTestCase
{
    public function test_prepare_synthetic_patients_browser_fixture(): void
    {
        $ids = $this->fixture();
        $db = DB::connection('migration');
        $a = $ids['a'];
        $b = $ids['b'];
        $db->table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $b['tenant'], 'user_id' => $a['user'], 'role_id' => $b['role']]);
        $membership = $db->table('tenant_memberships')->where('tenant_id', $b['tenant'])->where('user_id', $a['user'])->value('id');
        $db->table('membership_branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $b['tenant'], 'membership_id' => $membership, 'branch_id' => $b['branch']]);
        $second = (string) Str::uuid();
        $db->table('branches')->insert(['id' => $second, 'tenant_id' => $a['tenant'], 'name' => 'Synthetic second branch', 'code' => 'second']);
        $db->table('membership_branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $a['tenant'], 'membership_id' => $a['membership'], 'branch_id' => $second]);
        foreach (['reader', 'temporary', 'platform', 'scopedexport'] as $label) {
            $uid = (string) Str::uuid();
            $role = (string) Str::uuid();
            $mid = (string) Str::uuid();
            $db->table('users')->insert(['id' => $uid, 'login' => 'synthetic.'.$label, 'name' => 'Synthetic '.$label, 'password_hash' => Hash::make('Synthetic-password-42!'), 'must_change_password' => $label === 'temporary', 'temporary_password_expires_at' => $label === 'temporary' ? now()->addDay() : null, 'is_platform_admin' => $label === 'platform']);
            if ($label === 'platform') {
                continue;
            }
            $db->table('tenant_roles')->insert(['id' => $role, 'tenant_id' => $a['tenant'], 'code' => $label, 'name' => 'Synthetic '.$label]);
            $db->table('tenant_memberships')->insert(['id' => $mid, 'tenant_id' => $a['tenant'], 'user_id' => $uid, 'role_id' => $role]);
            $db->table('membership_branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $a['tenant'], 'membership_id' => $mid, 'branch_id' => $a['branch']]);
            $db->table('role_permissions')->insert(['tenant_id' => $a['tenant'], 'role_id' => $role, 'permission_id' => $db->table('permissions')->where('code', 'patients.view')->value('id'), 'scope' => 'assigned_branches']);
            if ($label === 'scopedexport') {
                $db->table('role_permissions')->insert(['tenant_id' => $a['tenant'], 'role_id' => $role, 'permission_id' => $db->table('permissions')->where('code', 'patients.export')->value('id'), 'scope' => 'entire_clinic']);
            }
        }
        $this->assertSame('blusser_test', $db->selectOne('SELECT current_database() AS name')->name);
        $this->assertSame(3, $db->table('branches')->count());
        $this->prepareReceptionBookingFixture();
    }

    private function prepareReceptionBookingFixture(): void
    {
        $db = DB::connection('migration');
        $tenant = (string) Str::uuid();
        $branch = (string) Str::uuid();
        $role = (string) Str::uuid();
        $service = (string) Str::uuid();
        $db->table('tenants')->insert(['id' => $tenant, 'name' => 'Reception booking test clinic', 'slug' => 'reception-booking-test']);
        $db->table('branches')->insert(['id' => $branch, 'tenant_id' => $tenant, 'name' => 'Booking test branch', 'code' => 'booking']);
        $db->table('tenant_roles')->insert(['id' => $role, 'tenant_id' => $tenant, 'code' => 'fixture_setup', 'name' => 'Synthetic booking setup']);
        foreach ($db->table('permissions')->get() as $permission) {
            $db->table('role_permissions')->insert(['tenant_id' => $tenant, 'role_id' => $role, 'permission_id' => $permission->id, 'scope' => 'entire_clinic']);
        }
        $doctorUser = (string) Str::uuid();
        $membership = (string) Str::uuid();
        $db->table('users')->insert(['id' => $doctorUser, 'login' => 'booking.doctor', 'name' => 'Booking test doctor', 'password_hash' => Hash::make('Synthetic-password-42!'), 'must_change_password' => false]);
        $db->table('tenant_memberships')->insert(['id' => $membership, 'tenant_id' => $tenant, 'user_id' => $doctorUser, 'role_id' => $role]);
        $db->table('membership_branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'membership_id' => $membership, 'branch_id' => $branch]);
        $db->table('services')->insert(['id' => $service, 'tenant_id' => $tenant, 'code' => 'booking_consultation', 'name' => 'Booking consultation']);
        $scheduling = app(Scheduling::class);
        $actor = $this->actor($doctorUser);
        $command = fn ($kind, $body) => $scheduling->command($actor, $tenant, $kind, 'create', null, $body, (string) Str::uuid());
        $specialty = $command('specialties', ['code' => 'booking', 'name' => 'Booking specialty', 'active' => true]);
        $doctor = $command('doctors', ['membership_id' => $membership, 'specialty_id' => $specialty['id'], 'branch_ids' => [$branch], 'service_ids' => [$service], 'active' => true]);
        $room = $command('rooms', ['branch_id' => $branch, 'number' => 'B101', 'service_type' => 'consultation', 'active' => true, 'availability' => 'available']);
        $db->table('doctor_services')->where('doctor_id', $doctor['id'])->update(['duration_minutes' => 30]);
        foreach (range(1, 7) as $weekday) {
            $command('schedules', ['doctor_id' => $doctor['id'], 'branch_id' => $branch, 'room_id' => $room['id'], 'weekday' => $weekday, 'local_start' => '09:00', 'local_end' => '18:00', 'slot_minutes' => 30, 'effective_from' => '2026-01-01', 'effective_to' => '2040-12-31', 'breaks' => []]);
        }
        app(Patients::class)->command($actor, $tenant, 'create', null, ['name' => 'Booking test patient', 'phone' => '+998901234567', 'date_of_birth' => '1992-03-12', 'branch_id' => $branch], (string) Str::uuid());
        $registratorRole = (string) Str::uuid();
        $registrator = (string) Str::uuid();
        $registratorMembership = (string) Str::uuid();
        $db->table('tenant_roles')->insert(['id' => $registratorRole, 'tenant_id' => $tenant, 'code' => 'booking_registrator', 'name' => 'Registrator']);
        foreach (['reception.view', 'patients.view', 'appointments.view', 'appointments.create', 'doctors.view', 'rooms.view', 'services.view', 'schedules.view'] as $permission) {
            $db->table('role_permissions')->insert(['tenant_id' => $tenant, 'role_id' => $registratorRole, 'permission_id' => $db->table('permissions')->where('code', $permission)->value('id'), 'scope' => $permission === 'services.view' ? 'entire_clinic' : 'assigned_branches']);
        }
        $db->table('users')->insert(['id' => $registrator, 'login' => 'booking.registrator', 'name' => 'Booking test registrator', 'password_hash' => Hash::make('Synthetic-password-42!'), 'must_change_password' => false]);
        $db->table('tenant_memberships')->insert(['id' => $registratorMembership, 'tenant_id' => $tenant, 'user_id' => $registrator, 'role_id' => $registratorRole]);
        $db->table('membership_branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'membership_id' => $registratorMembership, 'branch_id' => $branch]);
    }
}
