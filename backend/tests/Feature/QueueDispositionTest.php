<?php

namespace Tests\Feature;

use App\Modules\Access\Services\GovernanceDenied;
use App\Modules\Appointments\Services\Appointments;
use App\Modules\Patients\Services\Patients;
use App\Modules\Queues\Services\Queues;
use App\Modules\Scheduling\Services\Scheduling;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\Process;
use Tests\FoundationTestCase;

final class QueueDispositionTest extends FoundationTestCase
{
    private function schedule(array $a, string $kind, array $payload): array
    {
        return app(Scheduling::class)->command($this->actor($a['user']), $a['tenant'], $kind, 'create', null, $payload, (string) Str::uuid());
    }

    private function reference(array $a): array
    {
        $doctor = $this->schedule($a, 'doctors', ['membership_id' => $a['membership'], 'specialty_id' => null, 'branch_ids' => [$a['branch']], 'service_ids' => [$a['service']], 'active' => true]);
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

    public function test_skip_and_cancel_require_reason_in_http_and_runtime_and_persist_optional_note(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $jar = $this->clinicSession($a);
        foreach (['skip' => 'skipped', 'cancel' => 'cancelled'] as $action => $status) {
            $a['patient'] = $this->patient($a);
            $id = $this->queue($a)['ticket']['id'];
            $path = '/api/v1/clinic/queue-tickets/'.$id.'/'.$action;
            foreach ([[], ['reason_code' => ''], ['reason_code' => '   '], ['reason_code' => str_repeat('x', 65)], ['reason_code' => 'valid', 'note' => str_repeat('x', 2001)]] as $invalid) {
                $this->request($jar, 'POST', $path, ['expected_version' => 1, ...$invalid], (string) Str::uuid())->assertUnprocessable();
            }
            $this->denied(fn () => $this->queue($a, $action, $id, ['expected_version' => 1]), 'invalid_input');
            $this->assertSame('waiting', DB::connection('migration')->table('queue_tickets')->where('id', $id)->value('status'));
            $result = $this->request($jar, 'POST', $path, $this->payload($a), (string) Str::uuid())->assertOk()->assertJsonPath('ticket.status', $status)->assertJsonPath('ticket.reason_code', 'patient_request')->assertJsonPath('ticket.note', 'Bemor boshqa vaqtni so‘radi.')->assertJsonPath('rebooked_appointment', null)->json();
            $stored = DB::connection('migration')->table('queue_tickets')->where('id', $id)->first();
            $this->assertSame($result['ticket']['reason_code'], $stored->reason_code);
            $this->assertSame($result['ticket']['note'], $stored->note);
            $this->assertNull($stored->rebooked_appointment_id);
        }
        $this->assertSame(0, DB::connection('migration')->table('appointments')->count());
    }

    public function test_optional_note_can_be_omitted_and_both_actions_rebook_same_patient_doctor_and_service(): void
    {
        $a = $this->reference($this->fixture()['a']);
        foreach (['skip' => '09:00', 'cancel' => '09:30'] as $action => $time) {
            $a['patient'] = $this->patient($a);
            $id = $this->queue($a)['ticket']['id'];
            $data = $this->payload($a, $time);
            unset($data['note']);
            $result = $this->queue($a, $action, $id, $data);
            $this->assertSame($action === 'skip' ? 'skipped' : 'cancelled', $result['ticket']['status']);
            $this->assertNull($result['ticket']['note']);
            $appointment = $result['rebooked_appointment'];
            foreach (['patient_id' => $a['patient']['id'], 'doctor_id' => $a['doctor']['id'], 'branch_id' => $a['branch'], 'service_id' => $a['service'], 'status' => 'scheduled'] as $field => $value) {
                $this->assertSame($value, $appointment[$field]);
            }
            $this->assertSame($appointment['id'], $result['ticket']['rebooked_appointment_id']);
            $this->assertSame($appointment['id'], DB::connection('migration')->table('queue_tickets')->where('id', $id)->value('rebooked_appointment_id'));
            $this->assertSame($appointment['id'], app(Queues::class)->show($this->actor($a['user']), $a['tenant'], $id)['rebooked_appointment_id']);
        }
        $this->assertSame(2, DB::connection('migration')->table('appointments')->count());
        $this->assertSame(2, DB::connection('migration')->table('resource_reservations')->where('active', true)->count());
    }

    public function test_busy_or_unscheduled_slot_rolls_back_queue_visit_note_and_booking_for_both_actions(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $this->booking([...$a, 'patient' => $this->patient($a)]);
        $db = DB::connection('migration');
        foreach (['skip', 'cancel'] as $action) {
            $a['patient'] = $this->patient($a);
            $ticket = $this->queue($a);
            $beforeEvents = $db->table('operation_events')->count();
            foreach (['09:00', '13:00', '18:00'] as $time) {
                $this->denied(fn () => $this->queue($a, $action, $ticket['ticket']['id'], $this->payload($a, $time)), 'conflict');
                $queue = $db->table('queue_tickets')->where('id', $ticket['ticket']['id'])->first();
                $this->assertSame('waiting', $queue->status);
                $this->assertSame(1, $queue->version);
                $this->assertNull($queue->reason_code);
                $this->assertNull($queue->note);
                $this->assertNull($queue->rebooked_appointment_id);
                $this->assertSame('queued', $db->table('visits')->where('id', $ticket['visit']['id'])->value('status'));
                $this->assertSame(1, $db->table('appointments')->count());
                $this->assertSame(1, $db->table('resource_reservations')->count());
                $this->assertSame($beforeEvents, $db->table('operation_events')->count());
            }
        }
    }

    public function test_ticket_options_use_real_schedule_breaks_occupancy_and_branch_timezone(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $this->booking([...$a, 'patient' => $this->patient($a)]);
        $ticket = $this->queue($a)['ticket']['id'];
        $jar = $this->clinicSession($a);
        foreach (['skip', 'cancel'] as $action) {
            $url = '/api/v1/clinic/queue-tickets/'.$ticket.'/rebooking-options?action='.$action.'&local_date=';
            $result = $this->request($jar, 'GET', $url.'2030-01-07')->assertOk()->assertJsonPath('local_date', '2030-01-07')->assertJsonPath('timezone', 'Asia/Tashkent')->json();
            $this->assertNotEmpty($result['slots']);
            $times = array_map(fn ($slot) => (new \DateTimeImmutable($slot['starts_at']))->setTimezone(new \DateTimeZone('Asia/Tashkent'))->format('H:i'), $result['slots']);
            $this->assertNotContains('09:00', $times);
            $this->assertContains('09:30', $times);
            $busyTimes = array_map(fn ($slot) => (new \DateTimeImmutable($slot['starts_at']))->setTimezone(new \DateTimeZone('Asia/Tashkent'))->format('H:i'), $result['busy_slots']);
            $this->assertContains('09:00', $busyTimes);
            $this->assertNotContains('09:30', $busyTimes);
            $this->assertNotContains('13:00', $times);
            $this->assertNotContains('13:30', $times);
            $this->assertSame([$a['room']['id']], array_values(array_unique(array_column($result['slots'], 'room_id'))));
            foreach ($result['slots'] as $slot) {
                $this->assertSame(1800, strtotime($slot['ends_at']) - strtotime($slot['starts_at']));
            }
            $this->request($jar, 'GET', $url.'2030-01-08')->assertOk()->assertJsonPath('slots', []);
            $this->request($jar, 'GET', $url.'bad-date')->assertUnprocessable();
        }
    }

    public function test_ticket_options_and_mutation_honor_real_closed_and_replacement_exceptions(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $ticket = $this->queue($a)['ticket']['id'];
        $jar = $this->clinicSession($a);
        $url = '/api/v1/clinic/queue-tickets/'.$ticket.'/rebooking-options?action=skip&local_date=2030-01-07';
        $exceptionBody = ['doctor_id' => $a['doctor']['id'], 'branch_id' => $a['branch'], 'local_date' => '2030-01-07', 'kind' => 'closed', 'replacement' => null];
        $exception = $this->schedule($a, 'schedule-exceptions', $exceptionBody);
        $this->request($jar, 'GET', $url)->assertOk()->assertJsonPath('slots', []);
        $this->denied(fn () => $this->queue($a, 'skip', $ticket, $this->payload($a, '09:00')), 'conflict');
        $replacement = ['doctor_id' => $a['doctor']['id'], 'branch_id' => $a['branch'], 'room_id' => $a['room']['id'], 'weekday' => 1, 'local_start' => '10:00', 'local_end' => '11:00', 'slot_minutes' => 30, 'effective_from' => '2030-01-01', 'effective_to' => '2030-12-31', 'breaks' => []];
        app(Scheduling::class)->command($this->actor($a['user']), $a['tenant'], 'schedule-exceptions', 'update', $exception['id'], [...$exceptionBody, 'kind' => 'replace', 'replacement' => $replacement], (string) Str::uuid());
        $slots = $this->request($jar, 'GET', $url)->assertOk()->json('slots');
        $this->assertCount(2, $slots);
        $this->assertSame('2030-01-07T05:00:00Z', $slots[0]['starts_at']);
        $this->denied(fn () => $this->queue($a, 'skip', $ticket, $this->payload($a, '09:00')), 'conflict');
        $this->assertSame('scheduled', $this->queue($a, 'skip', $ticket, $this->payload($a, '10:00'))['rebooked_appointment']['status']);
    }

    public function test_stale_version_and_permission_denial_do_not_create_booking_or_modify_old_ticket(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $ticket = $this->queue($a)['ticket']['id'];
        $this->queue($a, 'call', $ticket, ['expected_version' => 1]);
        $this->denied(fn () => $this->queue($a, 'skip', $ticket, $this->payload($a, '09:00')), 'conflict');
        $this->grant($a, 'appointments.create', null);
        $this->denied(fn () => $this->queue($a, 'cancel', $ticket, $this->payload($a, '09:00', 2)), 'forbidden');
        $this->assertSame('called', DB::connection('migration')->table('queue_tickets')->where('id', $ticket)->value('status'));
        $this->assertSame(2, DB::connection('migration')->table('queue_tickets')->where('id', $ticket)->value('version'));
        $this->assertSame(0, DB::connection('migration')->table('appointments')->count());
        // Removing booking permission must not forbid the permitted operation
        // when the user explicitly chooses not to rebook.
        $this->assertSame('cancelled', $this->queue($a, 'cancel', $ticket, $this->payload($a, version: 2))['ticket']['status']);
    }

    public function test_replay_does_not_duplicate_booking_and_reauthorizes_permissions(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $ticket = $this->queue($a)['ticket']['id'];
        $key = (string) Str::uuid();
        $body = $this->payload($a, '09:00');
        $saved = $this->queue($a, 'skip', $ticket, $body, $key);
        $replay = $this->queue($a, 'skip', $ticket, $body, $key);
        $this->assertSame($saved['rebooked_appointment']['id'], $replay['rebooked_appointment']['id']);
        $this->assertSame(2, $replay['ticket']['version']);
        $this->denied(fn () => $this->queue($a, 'skip', $ticket, [...$body, 'note' => 'Changed'], $key), 'conflict');
        $this->denied(fn () => $this->queue($a, 'skip', $ticket, [...$body, 'rebook' => $this->slot($a, '09:30')], $key), 'conflict');
        // A later cancel may close the old skipped ticket, but it cannot
        // silently create a second booking or discard the existing linkage.
        $this->denied(fn () => $this->queue($a, 'cancel', $ticket, $this->payload($a, '09:30', 2)), 'conflict');
        $cancelled = $this->queue($a, 'cancel', $ticket, $this->payload($a, version: 2));
        $this->assertSame($saved['rebooked_appointment']['id'], $cancelled['ticket']['rebooked_appointment_id']);
        $this->assertSame('scheduled', $cancelled['rebooked_appointment']['status']);
        $this->grant($a, 'appointments.create', null);
        // Replays must hide a linked booking once its required authorization
        // is revoked, using the existing unavailable-resource convention.
        $this->denied(fn () => $this->queue($a, 'skip', $ticket, $body, $key), 'not_found');
        $this->grant($a, 'queues.skip', null);
        $this->denied(fn () => $this->queue($a, 'skip', $ticket, $body, $key), 'forbidden');
        $this->assertSame(1, DB::connection('migration')->table('appointments')->count());
        $this->assertSame(1, DB::connection('migration')->table('operation_events')->where('action', 'skip')->where('ticket_id', $ticket)->count());
    }

    public function test_skipped_rebooked_patient_can_check_in_new_appointment_without_erasing_history_or_requeueing_old_visit(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $ticket = $this->queue($a)['ticket']['id'];
        $saved = $this->queue($a, 'skip', $ticket, $this->payload($a, '09:00'));
        $appointment = $saved['rebooked_appointment'];
        $checkedIn = app(Appointments::class)->command($this->actor($a['user']), $a['tenant'], 'check_in', $appointment['id'], ['expected_version' => 1], (string) Str::uuid());
        $this->assertSame('waiting', $checkedIn['ticket']['status']);
        $this->assertSame($a['patient']['id'], $checkedIn['ticket']['patient_id']);
        $this->assertNotSame($ticket, $checkedIn['ticket']['id']);
        $db = DB::connection('migration');
        $this->assertSame('skipped', $db->table('queue_tickets')->where('id', $ticket)->value('status'));
        $this->assertSame(2, $db->table('visits')->count());
        $this->assertSame(2, $db->table('queue_tickets')->count());
        $this->denied(fn () => $this->queue($a, 'requeue', $ticket, ['expected_version' => 2]), 'conflict');
        $this->assertSame('waiting', $db->table('queue_tickets')->where('id', $checkedIn['ticket']['id'])->value('status'));
        $this->assertSame(2, $db->table('queue_tickets')->where('id', $ticket)->value('version'));
    }

    public function test_linked_appointment_cancel_and_rebooking_commit_together_and_old_reservation_is_released(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $original = $this->booking($a);
        $attendance = app(Appointments::class)->command($this->actor($a['user']), $a['tenant'], 'check_in', $original['id'], ['expected_version' => 1], (string) Str::uuid());
        $id = $attendance['ticket']['id'];
        $saved = $this->queue($a, 'cancel', $id, $this->payload($a, '09:30'));
        $this->assertSame('cancelled', $saved['appointment']['status']);
        $this->assertSame('cancelled', $saved['visit']['status']);
        $this->assertNotSame($original['id'], $saved['rebooked_appointment']['id']);
        $db = DB::connection('migration');
        $this->assertFalse($db->table('resource_reservations')->where('appointment_id', $original['id'])->value('active'));
        $this->assertTrue($db->table('resource_reservations')->where('appointment_id', $saved['rebooked_appointment']['id'])->value('active'));
        $this->assertSame(2, $db->table('appointments')->count());
    }

    public function test_failed_linked_cancel_rebooking_restores_old_appointment_and_active_reservation(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $original = $this->booking($a);
        $attendance = app(Appointments::class)->command($this->actor($a['user']), $a['tenant'], 'check_in', $original['id'], ['expected_version' => 1], (string) Str::uuid());
        $this->booking([...$a, 'patient' => $this->patient($a)], '09:30');
        $ticket = $attendance['ticket']['id'];
        $db = DB::connection('migration');
        $events = $db->table('operation_events')->count();
        $this->denied(fn () => $this->queue($a, 'cancel', $ticket, $this->payload($a, '09:30')), 'conflict');
        $this->assertSame('waiting', $db->table('queue_tickets')->where('id', $ticket)->value('status'));
        $this->assertSame(1, $db->table('queue_tickets')->where('id', $ticket)->value('version'));
        $this->assertNull($db->table('queue_tickets')->where('id', $ticket)->value('reason_code'));
        $this->assertSame('queued', $db->table('visits')->where('id', $attendance['visit']['id'])->value('status'));
        $this->assertSame('checked_in', $db->table('appointments')->where('id', $original['id'])->value('status'));
        $this->assertSame(2, $db->table('appointments')->where('id', $original['id'])->value('version'));
        $this->assertTrue($db->table('resource_reservations')->where('appointment_id', $original['id'])->value('active'));
        $this->assertSame(2, $db->table('appointments')->count());
        $this->assertSame(2, $db->table('resource_reservations')->count());
        $this->assertSame($events, $db->table('operation_events')->count());
        $retry = $this->queue($a, 'cancel', $ticket, $this->payload($a, '10:00'));
        $this->assertSame('cancelled', $retry['appointment']['status']);
        $this->assertSame('scheduled', $retry['rebooked_appointment']['status']);
    }

    public function test_foreign_tenant_own_doctor_and_unassigned_branch_are_denied_for_options_and_mutations(): void
    {
        $fixtures = $this->fixture();
        $a = $this->reference($fixtures['a']);
        $b = $this->reference($fixtures['b']);
        $foreign = $this->queue($b)['ticket']['id'];
        $jar = $this->clinicSession($a);
        $url = '/api/v1/clinic/queue-tickets/';
        $this->request($jar, 'GET', $url.$foreign.'/rebooking-options?action=skip&local_date=2030-01-07')->assertNotFound();
        $this->request($jar, 'POST', $url.$foreign.'/skip', $this->payload($a, '09:00'), (string) Str::uuid())->assertNotFound();

        $db = DB::connection('migration');
        $otherUser = (string) Str::uuid();
        $otherMembership = (string) Str::uuid();
        $db->table('users')->insert(['id' => $otherUser, 'login' => 'synthetic.other', 'name' => 'Other doctor', 'password_hash' => $db->table('users')->where('id', $a['user'])->value('password_hash'), 'must_change_password' => false]);
        $db->table('tenant_memberships')->insert(['id' => $otherMembership, 'tenant_id' => $a['tenant'], 'user_id' => $otherUser, 'role_id' => $a['role']]);
        $db->table('membership_branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $a['tenant'], 'membership_id' => $otherMembership, 'branch_id' => $a['branch']]);
        $otherDoctor = $this->schedule($a, 'doctors', ['membership_id' => $otherMembership, 'specialty_id' => null, 'branch_ids' => [$a['branch']], 'service_ids' => [$a['service']], 'active' => true]);
        $other = $this->queue([...$a, 'doctor' => $otherDoctor, 'patient' => $this->patient($a)])['ticket']['id'];
        $own = $this->queue($a)['ticket']['id'];
        $ownRebook = $this->queue([...$a, 'patient' => $this->patient($a)])['ticket']['id'];
        foreach (['queues.view', 'queues.skip', 'queues.cancel', 'visits.view', 'appointments.view', 'patients.view'] as $code) {
            $this->grant($a, $code, 'own');
        }
        // Appointment creation supports branch scopes, whereas viewing and
        // queue mutations also support own-doctor scopes in the real catalog.
        $this->grant($a, 'appointments.create', 'assigned_branches');
        $this->request($jar, 'GET', $url.$other.'/rebooking-options?action=skip&local_date=2030-01-07')->assertNotFound();
        $this->request($jar, 'POST', $url.$other.'/skip', $this->payload($a, '09:00'), (string) Str::uuid())->assertNotFound();
        // Own ticket + own branch remains a supported path without broad
        // doctor/schedule/room catalogue listing privileges.
        foreach (['doctors.view', 'schedules.view', 'rooms.view', 'services.view'] as $code) {
            $this->grant($a, $code, null);
        }
        $this->request($jar, 'GET', $url.$own.'/rebooking-options?action=skip&local_date=2030-01-07')->assertOk();
        $this->request($jar, 'POST', $url.$ownRebook.'/skip', $this->payload($a, '09:00'), (string) Str::uuid())->assertOk()->assertJsonPath('rebooked_appointment.doctor_id', $a['doctor']['id']);
        $db->table('membership_branches')->where('membership_id', $a['membership'])->delete();
        $this->request($jar, 'GET', $url.$own.'/rebooking-options?action=skip&local_date=2030-01-07')->assertNotFound();
        $this->request($jar, 'POST', $url.$own.'/skip', $this->payload($a, '09:00'), (string) Str::uuid())->assertNotFound();
        $this->assertSame('waiting', $db->table('queue_tickets')->where('id', $own)->value('status'));
        $this->assertSame(1, $db->table('appointments')->count());
    }

    public function test_nested_rebooking_cannot_supply_patient_doctor_branch_or_service_ids(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $ticket = $this->queue($a)['ticket']['id'];
        $jar = $this->clinicSession($a);
        foreach (['patient_id', 'doctor_id', 'branch_id', 'service_id', 'tenant_id'] as $field) {
            $body = $this->payload($a, '09:00');
            $body['rebook'][$field] = (string) Str::uuid();
            $this->request($jar, 'POST', '/api/v1/clinic/queue-tickets/'.$ticket.'/skip', $body, (string) Str::uuid())->assertUnprocessable();
            $this->denied(fn () => $this->queue($a, 'skip', $ticket, $body), 'invalid_input');
        }
        $this->assertSame('waiting', DB::connection('migration')->table('queue_tickets')->where('id', $ticket)->value('status'));
        $this->assertSame(0, DB::connection('migration')->table('appointments')->count());
    }

    public function test_parallel_rebooking_commands_with_distinct_keys_change_old_ticket_and_create_booking_once(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $ticket = $this->queue($a)['ticket']['id'];
        $requests = [
            ['action' => 'skip', 'id' => $ticket, 'data' => $this->payload($a, '09:00'), 'key' => (string) Str::uuid()],
            ['action' => 'cancel', 'id' => $ticket, 'data' => $this->payload($a, '09:30'), 'key' => (string) Str::uuid()],
        ];
        $responses = $this->race($a, $requests);
        $codes = array_column($responses, 'status');
        sort($codes);
        $this->assertSame([200, 409], $codes);
        $db = DB::connection('migration');
        $this->assertSame(1, $db->table('appointments')->count());
        $this->assertSame(1, $db->table('resource_reservations')->count());
        $stored = $db->table('queue_tickets')->where('id', $ticket)->first();
        $this->assertSame(2, $stored->version);
        $this->assertNotNull($stored->rebooked_appointment_id);
        $this->assertSame(1, $db->table('operation_events')->where('ticket_id', $ticket)->whereIn('action', ['skip', 'queue_cancel'])->count());
    }

    private function race(array $a, array $requests): array
    {
        $code = <<<'WORKER'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$i=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
Illuminate\Support\Facades\DB::select("SELECT set_config('application_name',?,false)",[$i['tag']]);
$actor=(new App\Modules\Identity\Models\User)->forceFill(['id'=>$i['user'],'credential_version'=>1]);
try{$r=app(App\Modules\Queues\Services\Queues::class)->command($actor,$i['tenant'],$i['action'],$i['id'],$i['data'],$i['key']);echo json_encode(['status'=>200,'data'=>$r]);}
catch(App\Modules\Access\Services\GovernanceDenied $e){echo json_encode(['status'=>match($e->reason){'conflict'=>409,'not_found'=>404,'invalid_input'=>422,default=>403}]);}
WORKER;
        $owner = DB::connection('migration');
        $workers = [];
        $tags = [];
        $owner->beginTransaction();
        $owner->table('tenants')->where('id', $a['tenant'])->lockForUpdate()->first();
        try {
            foreach ($requests as $request) {
                $tag = 'queue-disposition-race-'.Str::uuid();
                $tags[] = $tag;
                $process = new Process([PHP_BINARY, '-r', $code], base_path(), ['APP_ENV' => 'testing']);
                $process->setInput(json_encode(['tag' => $tag, 'user' => $a['user'], 'tenant' => $a['tenant'], ...$request]));
                $process->setTimeout(20);
                $process->start();
                $workers[] = $process;
            }
            $deadline = microtime(true) + 10;
            do {
                $blocked = (int) DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE application_name IN (?,?) AND wait_event_type='Lock'", $tags)->n;
                if ($blocked === 2) {
                    break;
                }
                usleep(30000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked, 'Both independent runtime transactions must contend on real PostgreSQL locks.');
            $owner->commit();
            $out = [];
            foreach ($workers as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $out[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return $out;
        } finally {
            if ($owner->transactionLevel()) {
                $owner->rollBack();
            }
            foreach ($workers as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }
}
