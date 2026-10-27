<?php

namespace Tests\Feature;

use App\Modules\Access\Services\Governance;
use App\Modules\Access\Services\GovernanceDenied;
use App\Modules\Appointments\Services\Appointments;
use App\Modules\Patients\Services\CeoGrantBackfill;
use App\Modules\Patients\Services\Patients;
use App\Modules\Queues\Services\Queues;
use App\Modules\Scheduling\Services\Scheduling;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\FoundationTestCase;

final class DoctorQueueScopeTest extends FoundationTestCase
{
    /** Role administration requires a real verified clinic CEO registration. */
    private function clinicFixture(): array
    {
        $a = $this->fixture()['a'];
        $db = DB::connection('migration');
        $db->table('tenant_roles')->where('id', $a['role'])->update(['code' => 'ceo', 'protected' => true]);
        $db->table('tenant_ceos')->insert(['tenant_id' => $a['tenant'], 'user_id' => $a['user'], 'membership_id' => $a['membership']]);

        return $a;
    }

    private function schedule(array $a, string $kind, array $payload): array
    {
        return app(Scheduling::class)->command($this->actor($a['user']), $a['tenant'], $kind, 'create', null, $payload, (string) Str::uuid());
    }

    private function reference(array $a): array
    {
        $db = DB::connection('migration');
        $doctorUser = (string) Str::uuid();
        $doctorMembership = (string) Str::uuid();
        $db->table('users')->insert(['id' => $doctorUser, 'login' => 'synthetic.scoped-doctor', 'name' => 'Scoped doctor', 'password_hash' => $db->table('users')->where('id', $a['user'])->value('password_hash'), 'must_change_password' => false]);
        $db->table('tenant_memberships')->insert(['id' => $doctorMembership, 'tenant_id' => $a['tenant'], 'user_id' => $doctorUser, 'role_id' => $a['role']]);
        $db->table('membership_branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $a['tenant'], 'membership_id' => $doctorMembership, 'branch_id' => $a['branch']]);
        $a = [...$a, 'doctor_user' => $doctorUser, 'doctor_membership' => $doctorMembership];
        $doctor = $this->schedule($a, 'doctors', ['membership_id' => $doctorMembership, 'specialty_id' => null, 'branch_ids' => [$a['branch']], 'service_ids' => [$a['service']], 'active' => true]);
        $room = $this->schedule($a, 'rooms', ['branch_id' => $a['branch'], 'number' => '101', 'service_type' => 'consultation', 'active' => true, 'availability' => 'available']);
        $a = [...$a, 'doctor' => $doctor, 'room' => $room];
        $this->schedule($a, 'schedules', ['doctor_id' => $doctor['id'], 'branch_id' => $a['branch'], 'room_id' => $room['id'], 'weekday' => 1, 'local_start' => '09:00', 'local_end' => '18:00', 'slot_minutes' => 30, 'effective_from' => '2030-01-01', 'effective_to' => '2030-12-31', 'breaks' => [['local_start' => '13:00', 'local_end' => '14:00']]]);

        return [...$a, 'patient' => $this->patient($a)];
    }

    private function patient(array $a): array
    {
        return app(Patients::class)->command($this->actor($a['user']), $a['tenant'], 'create', null, ['name' => 'Synthetic patient '.Str::uuid(), 'date_of_birth' => '1990-01-01', 'branch_id' => $a['branch']], (string) Str::uuid())['patient'];
    }

    private function queue(array $a, string $action = 'create', ?string $id = null, ?array $data = null, ?string $key = null): array
    {
        return app(Queues::class)->command($this->actor($a['user']), $a['tenant'], $action, $id, $data ?? ['patient_id' => $a['patient']['id'], 'doctor_id' => $a['doctor']['id'], 'branch_id' => $a['branch'], 'service_id' => $a['service']], $key ?? (string) Str::uuid());
    }

    private function slot(array $a, string $time = '09:00'): array
    {
        $start = new \DateTimeImmutable('2030-01-07T'.$time.':00+05:00');

        return ['room_id' => $a['room']['id'], 'starts_at' => $start->format('c'), 'ends_at' => $start->modify('+30 minutes')->format('c')];
    }

    private function booking(array $a, string $time = '09:00'): array
    {
        return app(Appointments::class)->command($this->actor($a['user']), $a['tenant'], 'create', null, ['patient_id' => $a['patient']['id'], 'doctor_id' => $a['doctor']['id'], 'branch_id' => $a['branch'], 'service_id' => $a['service'], ...$this->slot($a, $time)], (string) Str::uuid());
    }

    private function payload(array $a, ?string $time = null, int $version = 1): array
    {
        return ['expected_version' => $version, 'reason_code' => 'patient_request', 'note' => 'Bemor boshqa vaqtni so‘radi.', ...($time === null ? [] : ['rebook' => $this->slot($a, $time)])];
    }

    private function denied(callable $callback, string $reason): void
    {
        try {
            $callback();
            $this->fail('Expected rejection.');
        } catch (GovernanceDenied $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    private function grant(array $a, string $code, ?string $scope): void
    {
        $db = DB::connection('migration');
        $query = $db->table('role_permissions')->where('role_id', $a['role'])->where('permission_id', $db->table('permissions')->where('code', $code)->value('id'));
        $scope === null ? $query->delete() : $query->update(['scope' => $scope]);
    }

    private function request(array &$jar, string $method, string $path, array $payload = [], ?string $key = null): TestResponse
    {
        Auth::forgetGuards();
        $this->app->make('session')->forgetDrivers();
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTPS' => 'on', 'SERVER_PORT' => 443, 'REMOTE_ADDR' => '192.0.2.30', 'HTTP_ORIGIN' => 'https://localhost'];
        if (isset($jar['XSRF-TOKEN'])) {
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
        $this->request($jar, 'GET', '/sanctum/csrf-cookie')->assertNoContent();
        $login = DB::connection('migration')->table('users')->where('id', $a['user'])->value('login');
        $this->request($jar, 'POST', '/api/v1/auth/login', ['login' => $login, 'password' => 'Synthetic-password-42!'])->assertOk();
        $this->request($jar, 'POST', '/api/v1/auth/active-clinic', ['tenant_id' => $a['tenant']])->assertNoContent();

        return $jar;
    }

    private function roleBody(array $grants): array
    {
        $code = 'scoped_'.str_replace('-', '', (string) Str::uuid());

        return ['code' => $code, 'name' => 'Scoped '.Str::uuid(), 'note' => 'Scope regression', 'grants' => array_map(fn ($code, $scope) => ['code' => $code, 'scope' => $scope], array_keys($grants), $grants)];
    }

    private function ownRole(array $a, array &$admin, bool $catalogs = false, string $appointmentView = 'own'): string
    {
        $role = $this->request($admin, 'POST', '/api/v1/clinic/roles', $this->roleBody([
            'appointments.view' => $appointmentView, 'appointments.create' => 'own',
            'appointments.cancel' => 'own', 'appointments.no_show' => 'own',
            'queues.view' => 'own', 'queues.skip' => 'own', 'queues.cancel' => 'own',
            'visits.view' => 'own', 'patients.view' => 'own',
            ...($catalogs ? ['doctors.view' => 'assigned_branches', 'rooms.view' => 'assigned_branches', 'schedules.view' => 'assigned_branches', 'services.view' => 'entire_clinic'] : []),
        ]), (string) Str::uuid())->assertCreated()->json('id');
        DB::connection('migration')->table('tenant_memberships')->where('id', $a['doctor_membership'])->update(['role_id' => $role]);

        return $role;
    }

    private function ownSession(array $a): array
    {
        return $this->clinicSession([...$a, 'user' => $a['doctor_user']]);
    }

    private function bookingBody(array $a, string $time = '09:00'): array
    {
        return ['patient_id' => $a['patient']['id'], 'doctor_id' => $a['doctor']['id'], 'branch_id' => $a['branch'], 'service_id' => $a['service'], ...$this->slot($a, $time)];
    }

    public function test_real_role_api_accepts_own_actions_under_assigned_view_and_rejects_broader_or_missing_view(): void
    {
        $a = $this->clinicFixture();
        $jar = $this->clinicSession($a);
        $grants = ['appointments.view' => 'assigned_branches', 'appointments.create' => 'own', 'appointments.cancel' => 'own', 'appointments.no_show' => 'own'];
        $body = $this->roleBody($grants);
        $id = $this->request($jar, 'POST', '/api/v1/clinic/roles', $body, (string) Str::uuid())->assertCreated()->json('id');
        $detail = $this->request($jar, 'GET', '/api/v1/clinic/roles/'.$id)->assertOk()->json();
        $actual = array_column($detail['grants'], 'scope', 'code');
        ksort($actual);
        ksort($grants);
        $this->assertSame($grants, $actual);
        $this->assertSame(1, $detail['version']);
        foreach (['appointments.create', 'appointments.cancel', 'appointments.no_show'] as $action) {
            foreach ([['appointments.view' => 'own', $action => 'assigned_branches'], [$action => 'own']] as $invalid) {
                $this->request($jar, 'POST', '/api/v1/clinic/roles', $this->roleBody($invalid), (string) Str::uuid())->assertUnprocessable();
            }
        }
        $this->assertSame(2, DB::connection('migration')->table('tenant_roles')->where('tenant_id', $a['tenant'])->count());
        $catalog = $this->request($jar, 'GET', '/api/v1/clinic/permissions')->assertOk()->json('data');
        $create = collect($catalog)->firstWhere('code', 'appointments.create');
        $this->assertContains('own', $create['allowed_scopes']);
    }

    public function test_own_create_uses_existing_catalog_reads_and_ticket_rebooking_needs_no_broad_catalog_grants(): void
    {
        $a = $this->reference($this->clinicFixture());
        $ticket = $this->queue($a)['ticket']['id'];
        $admin = $this->clinicSession($a);
        $role = $this->ownRole($a, $admin, true);
        $doctor = $this->ownSession($a);
        $grants = DB::connection('migration')->table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $role)->pluck('rp.scope', 'p.code')->all();
        $this->assertSame('own', $grants['appointments.create']);
        $this->assertSame('own', $grants['appointments.view']);
        $created = $this->request($doctor, 'POST', '/api/v1/clinic/appointments', $this->bookingBody($a), (string) Str::uuid())->assertCreated()->json();
        $this->assertSame($a['doctor']['id'], $created['doctor_id']);
        // Keep generic booking's established catalog prerequisites. The
        // cabinet's ticket-scoped projection must work after removing them.
        $db = DB::connection('migration');
        foreach (['doctors.view', 'rooms.view', 'schedules.view', 'services.view'] as $code) {
            $db->table('role_permissions')->where('role_id', $role)->where('permission_id', $db->table('permissions')->where('code', $code)->value('id'))->delete();
        }
        $remaining = $db->table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $role)->pluck('p.code')->all();
        foreach (['doctors.view', 'rooms.view', 'schedules.view', 'services.view'] as $code) {
            $this->assertNotContains($code, $remaining);
        }
        $slots = $this->request($doctor, 'GET', '/api/v1/clinic/queue-tickets/'.$ticket.'/rebooking-options?action=skip&local_date=2030-01-07')->assertOk()->json('slots');
        $this->assertNotEmpty($slots);
        $this->assertNotContains('2030-01-07T04:00:00Z', array_column($slots, 'starts_at'));
        $saved = $this->request($doctor, 'POST', '/api/v1/clinic/queue-tickets/'.$ticket.'/skip', $this->payload($a, '09:30'), (string) Str::uuid())->assertOk()->assertJsonPath('ticket.status', 'skipped')->json('rebooked_appointment');
        foreach (['doctor_id' => $a['doctor']['id'], 'patient_id' => $a['patient']['id'], 'service_id' => $a['service'], 'branch_id' => $a['branch']] as $key => $value) {
            $this->assertSame($value, $saved[$key]);
        }
        $this->request($doctor, 'GET', '/api/v1/clinic/appointments/'.$saved['id'])->assertOk()->assertJsonPath('status', 'scheduled');
        $this->assertSame(2, DB::connection('migration')->table('appointments')->count());
    }

    public function test_own_create_cannot_book_another_doctor_even_with_branch_wide_appointment_view(): void
    {
        $a = $this->reference($this->clinicFixture());
        $other = $this->schedule($a, 'doctors', ['membership_id' => $a['membership'], 'specialty_id' => null, 'branch_ids' => [$a['branch']], 'service_ids' => [$a['service']], 'active' => true]);
        $patient = $this->patient($a);
        $room = $this->schedule($a, 'rooms', ['branch_id' => $a['branch'], 'number' => '102', 'service_type' => 'consultation', 'active' => true, 'availability' => 'available']);
        $this->schedule($a, 'schedules', ['doctor_id' => $other['id'], 'branch_id' => $a['branch'], 'room_id' => $room['id'], 'weekday' => 1, 'local_start' => '09:00', 'local_end' => '18:00', 'slot_minutes' => 30, 'effective_from' => '2030-01-01', 'effective_to' => '2030-12-31', 'breaks' => []]);
        $otherTicket = $this->queue([...$a, 'doctor' => $other, 'patient' => $patient])['ticket']['id'];
        $admin = $this->clinicSession($a);
        $this->ownRole($a, $admin, true, 'assigned_branches');
        $doctor = $this->ownSession($a);
        $body = $this->bookingBody([...$a, 'doctor' => $other, 'patient' => $patient, 'room' => $room]);
        $this->request($doctor, 'POST', '/api/v1/clinic/appointments', $body, (string) Str::uuid())->assertForbidden();
        $this->request($doctor, 'POST', '/api/v1/clinic/queue-tickets/'.$otherTicket.'/skip', $this->payload($a, '09:00'), (string) Str::uuid())->assertNotFound();
        $this->assertSame(0, DB::connection('migration')->table('appointments')->count());
        $this->assertSame('waiting', DB::connection('migration')->table('queue_tickets')->where('id', $otherTicket)->value('status'));
    }

    public function test_own_create_and_rebooking_deny_removed_branch_without_changing_old_ticket(): void
    {
        $a = $this->reference($this->clinicFixture());
        $db = DB::connection('migration');
        $branch = (string) Str::uuid();
        $db->table('branches')->insert(['id' => $branch, 'tenant_id' => $a['tenant'], 'name' => 'Removed doctor branch', 'code' => 'removed']);
        $db->table('membership_branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $a['tenant'], 'membership_id' => $a['doctor_membership'], 'branch_id' => $branch]);
        $profile = $a['doctor'];
        unset($profile['id']);
        $profile['branch_ids'][] = $branch;
        app(Scheduling::class)->command($this->actor($a['user']), $a['tenant'], 'doctors', 'update', $a['doctor']['id'], $profile, (string) Str::uuid());
        app(Patients::class)->command($this->actor($a['user']), $a['tenant'], 'link_branch', $a['patient']['id'], ['branch_id' => $branch], (string) Str::uuid());
        $room = $this->schedule($a, 'rooms', ['branch_id' => $branch, 'number' => '201', 'service_type' => 'consultation', 'active' => true, 'availability' => 'available']);
        $this->schedule($a, 'schedules', ['doctor_id' => $a['doctor']['id'], 'branch_id' => $branch, 'room_id' => $room['id'], 'weekday' => 2, 'local_start' => '09:00', 'local_end' => '18:00', 'slot_minutes' => 30, 'effective_from' => '2030-01-01', 'effective_to' => '2030-12-31', 'breaks' => []]);
        $ticket = $this->queue($a, data: ['patient_id' => $a['patient']['id'], 'doctor_id' => $a['doctor']['id'], 'branch_id' => $branch, 'service_id' => $a['service']])['ticket']['id'];
        $admin = $this->clinicSession($a);
        $this->ownRole($a, $admin, true);
        $doctor = $this->ownSession($a);
        $db->table('membership_branches')->where('membership_id', $a['doctor_membership'])->where('branch_id', $branch)->delete();
        $body = [...$this->bookingBody($a), 'branch_id' => $branch, 'room_id' => $room['id'], 'starts_at' => '2030-01-08T09:00:00+05:00', 'ends_at' => '2030-01-08T09:30:00+05:00'];
        $this->request($doctor, 'POST', '/api/v1/clinic/appointments', $body, (string) Str::uuid())->assertForbidden();
        $this->request($doctor, 'POST', '/api/v1/clinic/queue-tickets/'.$ticket.'/cancel', $this->payload($a, '09:00'), (string) Str::uuid())->assertNotFound();
        $this->assertSame(0, $db->table('appointments')->count());
        $stored = $db->table('queue_tickets')->where('id', $ticket)->first();
        $this->assertSame('waiting', $stored->status);
        $this->assertSame(1, $stored->version);
        $this->assertNull($stored->reason_code);
    }

    public function test_busy_slot_rebooking_with_own_create_rolls_back_ticket_and_reservation(): void
    {
        $a = $this->reference($this->clinicFixture());
        $ticket = $this->queue($a)['ticket']['id'];
        $this->booking([...$a, 'patient' => $this->patient($a)]);
        $admin = $this->clinicSession($a);
        $this->ownRole($a, $admin);
        $doctor = $this->ownSession($a);
        $db = DB::connection('migration');
        $events = $db->table('operation_events')->count();
        foreach (['skip', 'cancel'] as $action) {
            $this->request($doctor, 'POST', '/api/v1/clinic/queue-tickets/'.$ticket.'/'.$action, $this->payload($a, '09:00'), (string) Str::uuid())->assertConflict();
            $stored = $db->table('queue_tickets')->where('id', $ticket)->first();
            $this->assertSame('waiting', $stored->status);
            $this->assertSame(1, $stored->version);
            $this->assertNull($stored->reason_code);
            $this->assertSame(1, $db->table('appointments')->count());
            $this->assertTrue($db->table('resource_reservations')->first()->active);
            $this->assertSame($events, $db->table('operation_events')->count());
        }
        $this->request($doctor, 'POST', '/api/v1/clinic/queue-tickets/'.$ticket.'/cancel', $this->payload($a, '09:30'), (string) Str::uuid())->assertOk()->assertJsonPath('ticket.status', 'cancelled');
    }

    public function test_view_child_containment_does_not_allow_actor_to_delegate_an_unauthorized_scope(): void
    {
        $a = $this->clinicFixture();
        $jar = $this->clinicSession($a);
        $this->grant($a, 'appointments.view', 'assigned_branches');
        $this->grant($a, 'appointments.create', 'assigned_branches');
        // Scope containment validates a role's internal dependencies; it must
        // not change the established actor-to-grant delegation rule.
        $this->request($jar, 'POST', '/api/v1/clinic/roles', $this->roleBody(['appointments.view' => 'assigned_branches', 'appointments.create' => 'own']), (string) Str::uuid())->assertForbidden();
        $this->assertSame(1, DB::connection('migration')->table('tenant_roles')->where('tenant_id', $a['tenant'])->count());
    }

    public function test_legacy_role_save_enforces_the_same_view_child_containment_and_rolls_back_invalid_grants(): void
    {
        $a = $this->clinicFixture();
        $body = $this->roleBody(['appointments.view' => 'assigned_branches', 'appointments.create' => 'own', 'appointments.cancel' => 'own', 'appointments.no_show' => 'own']);
        $save = fn (array $body) => app(Governance::class)->call('role_save', [null, $body['code'], $body['name'], json_encode($body['grants'], JSON_THROW_ON_ERROR)], $this->actor($a['user']), $a['tenant']);
        $id = $save($body);
        $db = DB::connection('migration');
        $saved = $db->table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $id)->pluck('rp.scope', 'p.code')->all();
        $this->assertSame('assigned_branches', $saved['appointments.view']);
        foreach (['appointments.create', 'appointments.cancel', 'appointments.no_show'] as $code) {
            $this->assertSame('own', $saved[$code]);
        }
        foreach ([['appointments.view' => 'own', 'appointments.cancel' => 'assigned_branches'], ['appointments.create' => 'own']] as $invalid) {
            // The existing legacy Governance adapter maps invalid grant data
            // to forbidden; the transaction must still remain entirely empty.
            $this->denied(fn () => $save($this->roleBody($invalid)), 'forbidden');
        }
        $this->assertSame(2, $db->table('tenant_roles')->where('tenant_id', $a['tenant'])->count());
        $this->assertSame(1, $db->table('audit_logs')->where('action', 'role.saved')->count());
        $this->assertSame($saved, $db->table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $id)->pluck('rp.scope', 'p.code')->all());
    }

    public function test_employee_page_grants_intersect_role_actions_in_both_directions_without_granting_absent_actions(): void
    {
        $a = $this->reference($this->clinicFixture());
        $first = $this->queue($a)['ticket']['id'];
        $second = $this->queue([...$a, 'patient' => $this->patient($a)])['ticket']['id'];
        $otherDoctor = $this->schedule($a, 'doctors', ['membership_id' => $a['membership'], 'specialty_id' => null, 'branch_ids' => [$a['branch']], 'service_ids' => [$a['service']], 'active' => true]);
        $otherRoom = $this->schedule($a, 'rooms', ['branch_id' => $a['branch'], 'number' => '102', 'service_type' => 'consultation', 'active' => true, 'availability' => 'available']);
        $this->schedule($a, 'schedules', ['doctor_id' => $otherDoctor['id'], 'branch_id' => $a['branch'], 'room_id' => $otherRoom['id'], 'weekday' => 1, 'local_start' => '09:00', 'local_end' => '18:00', 'slot_minutes' => 30, 'effective_from' => '2030-01-01', 'effective_to' => '2030-12-31', 'breaks' => []]);
        $otherBody = $this->bookingBody([...$a, 'doctor' => $otherDoctor, 'room' => $otherRoom]);
        $admin = $this->clinicSession($a);
        $role = $this->ownRole($a, $admin, false, 'assigned_branches');
        $db = DB::connection('migration');
        $employee = (string) Str::uuid();
        $pages = [['code' => 'appointments.view', 'scope' => 'assigned_branches'], ['code' => 'patients.view', 'scope' => 'own'], ['code' => 'queues.view', 'scope' => 'own'], ['code' => 'visits.view', 'scope' => 'own']];
        $db->table('employee_profiles')->insert(['id' => $employee, 'tenant_id' => $a['tenant'], 'membership_id' => $a['doctor_membership'], 'profile' => json_encode(['first' => 'Scoped', 'last' => 'Doctor', 'phone' => '+998900001122', 'position' => 'Shifokor', 'branch' => $a['branch']]), 'active' => true, 'page_grants' => json_encode($pages)]);
        $doctor = $this->ownSession($a);
        $context = $this->request($doctor, 'GET', '/api/v1/clinic/context')->assertOk()->assertJsonPath('employee_id', $employee)->json('grants');
        $this->assertSame('assigned_branches', $context['appointments.view']);
        foreach (['appointments.create', 'appointments.cancel', 'appointments.no_show'] as $code) {
            $this->assertSame('own', $context[$code]);
        }
        foreach (['appointments.update', 'appointments.check_in', 'queues.call'] as $code) {
            $this->assertArrayNotHasKey($code, $context);
        }
        $options = $this->request($doctor, 'GET', '/api/v1/clinic/queue-tickets/'.$first.'/rebooking-options?action=skip&local_date=2030-01-07')->assertOk()->json('slots');
        $this->assertNotEmpty($options);
        $this->request($doctor, 'POST', '/api/v1/clinic/appointments', $otherBody, (string) Str::uuid())->assertForbidden();
        $this->request($doctor, 'POST', '/api/v1/clinic/queue-tickets/'.$first.'/skip', $this->payload($a, '09:00'), (string) Str::uuid())->assertOk()->assertJsonPath('ticket.status', 'skipped')->assertJsonPath('rebooked_appointment.doctor_id', $a['doctor']['id']);

        // Reverse the intersection: broader role actions remain restricted by
        // the employee's own-doctor page selection, rather than disappearing.
        foreach (['appointments.create', 'appointments.cancel', 'appointments.no_show'] as $code) {
            $db->table('role_permissions')->where('role_id', $role)->where('permission_id', $db->table('permissions')->where('code', $code)->value('id'))->update(['scope' => 'assigned_branches']);
        }
        $pages[0]['scope'] = 'own';
        $db->table('employee_profiles')->where('id', $employee)->update(['page_grants' => json_encode($pages)]);
        $context = $this->request($doctor, 'GET', '/api/v1/clinic/context')->assertOk()->json('grants');
        $this->assertSame('own', $context['appointments.view']);
        foreach (['appointments.create', 'appointments.cancel', 'appointments.no_show'] as $code) {
            $this->assertSame('own', $context[$code]);
        }
        foreach (['appointments.update', 'appointments.check_in', 'queues.call'] as $code) {
            $this->assertArrayNotHasKey($code, $context);
        }
        $this->request($doctor, 'POST', '/api/v1/clinic/appointments', $otherBody, (string) Str::uuid())->assertForbidden();
        $this->request($doctor, 'POST', '/api/v1/clinic/queue-tickets/'.$second.'/cancel', $this->payload($a, '09:30'), (string) Str::uuid())->assertOk()->assertJsonPath('ticket.status', 'cancelled')->assertJsonPath('rebooked_appointment.doctor_id', $a['doctor']['id']);
        $this->assertSame(2, $db->table('appointments')->count());
        $db->table('membership_branches')->where('membership_id', $a['doctor_membership'])->delete();
        $this->request($doctor, 'POST', '/api/v1/clinic/queue-tickets/'.$first.'/cancel', $this->payload($a, version: 2), (string) Str::uuid())->assertNotFound();
        $this->assertSame('skipped', $db->table('queue_tickets')->where('id', $first)->value('status'));
        $this->assertSame(2, $db->table('queue_tickets')->where('id', $first)->value('version'));
        $this->assertSame(2, $db->table('appointments')->where('status', 'scheduled')->count());
    }

    public function test_ceo_backfill_accepts_only_reviewed_own_scope_catalog_and_preserves_custom_role_grants(): void
    {
        $a = $this->clinicFixture();
        $db = DB::connection('migration');
        $db->table('tenant_roles')->where('id', $a['role'])->update(['code' => 'ceo', 'protected' => true]);
        $db->table('tenant_ceos')->insertOrIgnore(['tenant_id' => $a['tenant'], 'user_id' => $a['user'], 'membership_id' => $a['membership']]);
        $custom = (string) Str::uuid();
        $db->table('tenant_roles')->insert(['id' => $custom, 'tenant_id' => $a['tenant'], 'code' => 'existing_doctor', 'name' => 'Existing doctor role']);
        foreach (['appointments.view', 'appointments.create'] as $code) {
            $db->table('role_permissions')->insert(['tenant_id' => $a['tenant'], 'role_id' => $custom, 'permission_id' => $db->table('permissions')->where('code', $code)->value('id'), 'scope' => 'assigned_branches']);
        }
        $snapshot = fn () => [
            $db->table('tenant_roles')->orderBy('id')->get()->toJson(),
            $db->table('role_permissions')->orderBy('tenant_id')->orderBy('role_id')->orderBy('permission_id')->get()->toJson(),
        ];
        $before = $snapshot();
        $backfill = app(CeoGrantBackfill::class);
        $this->assertSame('{own,assigned_branches,entire_clinic}', $db->table('permissions')->where('code', 'appointments.create')->value('allowed_scopes'));
        $this->assertSame(0, $backfill->run($db));
        $this->assertSame(0, $backfill->run($db, true));
        $this->assertSame(0, $backfill->run($db, true));
        $this->assertSame($before, $snapshot());
        $this->assertSame(0, $db->table('patient_grant_provenance')->count());
        $db->beginTransaction();
        try {
            // Valid PostgreSQL scope values with an unreviewed combination
            // still indicate catalog drift and must fail closed.
            $db->statement("UPDATE permissions SET allowed_scopes=ARRAY['own','entire_clinic']::text[] WHERE code='appointments.create'");
            try {
                $backfill->run($db, true);
                $this->fail('Unexpected scope arrays must not bypass catalog validation.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Permission catalog mismatch.', $exception->getMessage());
            }
            $this->assertSame($before, $snapshot());
            $this->assertSame(0, $db->table('patient_grant_provenance')->count());
        } finally {
            $db->rollBack();
        }
    }

    public function test_scope_catalog_upgrade_is_idempotent_and_keeps_existing_roles_grants_and_assignments(): void
    {
        $a = $this->reference($this->clinicFixture());
        $db = DB::connection('migration');
        $snapshot = function () use ($db): array {
            $tables = ['tenant_roles', 'role_permissions', 'tenant_memberships', 'membership_branches', 'doctor_profiles', 'doctor_branches', 'doctor_services'];
            $result = [];
            foreach ($tables as $table) {
                $rows = $db->table($table)->get()->map(fn ($row) => (array) $row)->all();
                usort($rows, fn ($left, $right) => strcmp(json_encode($left), json_encode($right)));
                $result[$table] = $rows;
            }

            return $result;
        };
        $before = $snapshot();
        $originalConnection = DB::getDefaultConnection();
        $db->beginTransaction();
        try {
            DB::setDefaultConnection('migration');
            $db->statement("UPDATE permissions SET allowed_scopes=ARRAY['assigned_branches','entire_clinic']::text[] WHERE code='appointments.create'");
            $this->assertFalse($db->selectOne("SELECT 'own'=ANY(allowed_scopes) AS allowed FROM permissions WHERE code='appointments.create'")->allowed);
            $permission = $db->table('permissions')->where('code', 'appointments.create')->value('id');
            $this->assertSqlState('23514', fn () => $db->transaction(fn () => $db->table('role_permissions')->where('role_id', $a['role'])->where('permission_id', $permission)->update(['scope' => 'own'])));
            $migration = require database_path('migrations/2026_10_27_000003_allow_own_appointment_creation.php');
            $migration->up();
            $this->assertSame($before, $snapshot());
            $this->assertTrue($db->selectOne("SELECT 'own'=ANY(allowed_scopes) AS allowed FROM permissions WHERE code='appointments.create'")->allowed);
            $migration->up();
            $this->assertSame($before, $snapshot());
            $this->assertTrue($db->selectOne("SELECT cardinality(allowed_scopes)=3 AS valid FROM permissions WHERE code='appointments.create'")->valid);
        } finally {
            $db->rollBack();
            DB::setDefaultConnection($originalConnection);
        }
    }
}
