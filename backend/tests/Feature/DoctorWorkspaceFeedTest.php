<?php

namespace Tests\Feature;

use App\Modules\Patients\Services\Patients;
use App\Modules\Queues\Services\Queues;
use App\Modules\Scheduling\Services\Scheduling;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\FoundationTestCase;

final class DoctorWorkspaceFeedTest extends FoundationTestCase
{
    private function command(array $a, string $kind, array $payload): array
    {
        return app(Scheduling::class)->command(
            $this->actor($a['user']), $a['tenant'], $kind, 'create', null, $payload, (string) Str::uuid(),
        );
    }

    private function patient(array $a, string $name): array
    {
        return app(Patients::class)->command(
            $this->actor($a['user']), $a['tenant'], 'create', null,
            ['name' => $name, 'date_of_birth' => '1991-01-01', 'branch_id' => $a['branch']],
            (string) Str::uuid(),
        )['patient'];
    }

    private function feed(array $a, ?string $doctor = null): array
    {
        return app(TenantContext::class)->run(
            $this->actor($a['user']), $a['tenant'],
            fn () => json_decode(DB::selectOne(
                'SELECT governance.doctor_workspace_feed(?::date,?::uuid,?::uuid) AS value',
                ['2026-10-26', $doctor, $a['branch']],
            )->value, true, flags: JSON_THROW_ON_ERROR),
            consistentSnapshot: true,
        );
    }

    public function test_own_doctor_feed_never_returns_another_doctors_patients(): void
    {
        $a = $this->fixture()['a'];
        $db = DB::connection('migration');
        $otherUser = (string) Str::uuid();
        $otherMembership = (string) Str::uuid();
        $db->table('users')->insert([
            'id' => $otherUser, 'login' => 'synthetic.other-doctor',
            'name' => 'Other doctor', 'password_hash' => $db->table('users')->where('id', $a['user'])->value('password_hash'),
            'must_change_password' => false,
        ]);
        $db->table('tenant_memberships')->insert([
            'id' => $otherMembership, 'tenant_id' => $a['tenant'],
            'user_id' => $otherUser, 'role_id' => $a['role'],
        ]);
        $db->table('membership_branches')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $a['tenant'],
            'membership_id' => $otherMembership, 'branch_id' => $a['branch'],
        ]);

        $specialty = $this->command($a, 'specialties', [
            'code' => 'test-doctor', 'name' => 'Test specialty', 'active' => true,
        ]);
        $first = $this->command($a, 'doctors', [
            'membership_id' => $a['membership'], 'specialty_id' => $specialty['id'],
            'branch_ids' => [$a['branch']], 'service_ids' => [$a['service']], 'active' => true,
        ]);
        $second = $this->command($a, 'doctors', [
            'membership_id' => $otherMembership, 'specialty_id' => $specialty['id'],
            'branch_ids' => [$a['branch']], 'service_ids' => [$a['service']], 'active' => true,
        ]);

        $ownPatient = $this->patient($a, 'Only my patient');
        $otherPatient = $this->patient($a, 'Other doctors patient');
        foreach ([[$first, $ownPatient], [$second, $otherPatient]] as [$doctor, $patient]) {
            app(Queues::class)->command($this->actor($a['user']), $a['tenant'], 'create', null, [
                'doctor_id' => $doctor['id'], 'branch_id' => $a['branch'],
                'service_id' => $a['service'], 'patient_id' => $patient['id'],
            ], (string) Str::uuid());
        }

        // A signed-in doctor is not a clinic-wide queue administrator.
        foreach (['queues.view', 'appointments.view', 'patients.view'] as $code) {
            $db->table('role_permissions')
                ->where('role_id', $a['role'])
                ->where('permission_id', $db->table('permissions')->where('code', $code)->value('id'))
                ->update(['scope' => 'own']);
        }
        $mine = $this->feed($a);
        $this->assertFalse($mine['manager']);
        $this->assertSame($first['id'], $mine['doctor']['id']);
        $this->assertCount(1, $mine['tickets']);
        $this->assertSame('Only my patient', $mine['tickets'][0]['name']);
        $this->assertSame(1, $mine['tickets'][0]['version']);
        $this->assertNull($mine['tickets'][0]['called_at']);
        $this->assertFalse($mine['has_called_today']);
        $this->assertFalse($mine['has_active_patient']);
        $this->assertSame([], $mine['doctors']);

        app(Queues::class)->command($this->actor($a['user']), $a['tenant'], 'call',
            $mine['tickets'][0]['id'], ['expected_version' => $mine['tickets'][0]['version']],
            (string) Str::uuid());
        $afterCall = $this->feed($a);
        $this->assertTrue($afterCall['has_called_today']);
        $this->assertTrue($afterCall['has_active_patient']);
        $this->assertSame('called', $afterCall['tickets'][0]['status']);
        $this->assertSame(2, $afterCall['tickets'][0]['version']);
        $this->assertNotNull($afterCall['tickets'][0]['called_at']);

        $this->assertSqlState('42501', fn () => $this->feed($a, $second['id']));

        // A clinic-wide authorized manager can explicitly select the other doctor.
        foreach (['queues.view', 'appointments.view', 'patients.view'] as $code) {
            $db->table('role_permissions')
                ->where('role_id', $a['role'])
                ->where('permission_id', $db->table('permissions')->where('code', $code)->value('id'))
                ->update(['scope' => 'entire_clinic']);
        }
        $manager = $this->feed($a, $second['id']);
        $this->assertTrue($manager['manager']);
        $this->assertCount(2, $manager['doctors']);
        $this->assertCount(1, $manager['tickets']);
        $this->assertSame('Other doctors patient', $manager['tickets'][0]['name']);
        $this->assertFalse($manager['has_active_patient']);
    }
}
