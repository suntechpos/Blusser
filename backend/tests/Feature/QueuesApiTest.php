<?php

namespace Tests\Feature;

use App\Modules\Access\Services\GovernanceDenied;
use App\Modules\Appointments\Services\Appointments;
use App\Modules\Patients\Services\Patients;
use App\Modules\Queues\Services\Queues;
use App\Modules\Scheduling\Services\Scheduling;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Process\Process;
use Tests\FoundationTestCase;

final class QueuesApiTest extends FoundationTestCase
{
    private function scheduleCommand(array $a, string $kind, array $payload, ?string $id = null, ?string $key = null): array
    {
        return app(Scheduling::class)->command($this->actor($a['user']), $a['tenant'], $kind, $id ? 'update' : 'create', $id, $payload, $key ?? (string) Str::uuid());
    }

    private function reference(array $a): array
    {
        $specialty = $this->scheduleCommand($a, 'specialties', ['code' => 'synthetic', 'name' => 'Synthetic specialty', 'active' => true]);
        $doctor = $this->scheduleCommand($a, 'doctors', ['membership_id' => $a['membership'], 'specialty_id' => $specialty['id'], 'branch_ids' => [$a['branch']], 'service_ids' => [$a['service']], 'active' => true]);
        $room = $this->scheduleCommand($a, 'rooms', ['branch_id' => $a['branch'], 'number' => '101', 'service_type' => 'consultation', 'active' => true, 'availability' => 'available']);

        $a = [...$a, 'specialty' => $specialty, 'doctor' => $doctor, 'room' => $room];
        $this->scheduleCommand($a, 'schedules', $this->rule($a));
        $patient = app(Patients::class)->command($this->actor($a['user']), $a['tenant'], 'create', null, ['name' => 'Synthetic patient', 'date_of_birth' => '1990-01-01', 'branch_id' => $a['branch']], (string) Str::uuid());

        return [...$a, 'patient' => $patient['patient']];
    }

    private function rule(array $a): array
    {
        return ['doctor_id' => $a['doctor']['id'], 'branch_id' => $a['branch'], 'room_id' => $a['room']['id'], 'weekday' => 1,
            'local_start' => '09:00', 'local_end' => '18:00', 'slot_minutes' => 30, 'effective_from' => '2030-01-01', 'effective_to' => '2030-12-31', 'breaks' => [['local_start' => '13:00', 'local_end' => '14:00']]];
    }

    private function denied(callable $callback, string $reason): void
    {
        try {
            $callback();
            $this->fail('Expected command rejection.');
        } catch (GovernanceDenied $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    private function grant(array $a, string $permission, ?string $scope): void
    {
        $q = DB::connection('migration')->table('role_permissions')->where('role_id', $a['role'])->where('permission_id', DB::connection('migration')->table('permissions')->where('code', $permission)->value('id'));
        $scope === null ? $q->delete() : $q->update(['scope' => $scope]);
    }

    private function branch(array $a): string
    {
        $id = (string) Str::uuid();
        DB::connection('migration')->table('branches')->insert(['id' => $id, 'tenant_id' => $a['tenant'], 'name' => 'Synthetic second branch', 'code' => $id]);
        DB::connection('migration')->table('membership_branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $a['tenant'], 'membership_id' => $a['membership'], 'branch_id' => $id]);

        return $id;
    }

    private function browser(array &$jar, string $method, string $path, array $payload = [], bool $csrf = true, ?string $key = null): TestResponse
    {
        Auth::forgetGuards();
        $this->app->make('session')->forgetDrivers();
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTPS' => 'on', 'SERVER_PORT' => 443, 'REMOTE_ADDR' => '192.0.2.15', 'HTTP_ORIGIN' => 'https://localhost'];
        if ($csrf && isset($jar['XSRF-TOKEN'])) {
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
        $this->browser($jar, 'GET', '/sanctum/csrf-cookie')->assertNoContent();
        $login = DB::connection('migration')->table('users')->where('id', $a['user'])->value('login');
        $this->browser($jar, 'POST', '/api/v1/auth/login', ['login' => $login, 'password' => 'Synthetic-password-42!'])->assertOk();
        $this->browser($jar, 'POST', '/api/v1/auth/active-clinic', ['tenant_id' => $a['tenant']])->assertNoContent();

        return $jar;
    }

    private function data(array $a, string $time = '09:00'): array
    {
        $end = (new \DateTimeImmutable('2030-01-07T'.$time.':00+05:00'))->modify('+30 minutes')->format('c');

        return ['patient_id' => $a['patient']['id'], 'doctor_id' => $a['doctor']['id'], 'branch_id' => $a['branch'], 'service_id' => $a['service'], 'room_id' => $a['room']['id'], 'starts_at' => '2030-01-07T'.$time.':00+05:00', 'ends_at' => $end];
    }

    private function command(array $a, string $action = 'create', ?string $id = null, ?array $data = null, ?string $key = null): array
    {
        return app(Appointments::class)->command($this->actor($a['user']), $a['tenant'], $action, $id, $data ?? $this->data($a), $key ?? (string) Str::uuid());
    }

    private function walk(array $a): array
    {
        $d = $this->data($a);
        unset($d['room_id'],$d['starts_at'],$d['ends_at']);

        return $d;
    }

    private function queue(array $a, string $action = 'create', ?string $id = null, ?array $data = null, ?string $key = null): array
    {
        return app(Queues::class)->command($this->actor($a['user']), $a['tenant'], $action, $id, $data ?? $this->walk($a), $key ?? (string) Str::uuid());
    }

    private function startData(array $a, int $version, string $time = '09:00'): array
    {
        $data = $this->data($a, $time);

        return ['expected_version' => $version, 'room_id' => $data['room_id'], 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at']];
    }

    public function test_real_visit_diagnosis_is_versioned_audited_and_scoped_to_own_doctor(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $ticket = $this->queue($a);
        $visit = $ticket['visit']['id'];
        $id = $ticket['ticket']['id'];
        $this->queue($a, 'call', $id, ['expected_version' => 1]);
        $this->queue($a, 'start', $id, $this->startData($a, 2));

        $jar = $this->clinicSession($a);
        $path = '/api/v1/clinic/visits/'.$visit.'/diagnosis';

        $this->browser($jar, 'GET', $path)->assertOk()->assertJsonPath('data', null);
        $new = ['expected_version' => 0, 'diagnosis_text' => 'Dentin kariyesi, klinik belgilar bilan',
            'diagnosis_type' => 'preliminary', 'icd_code' => 'K02.1'];
        $this->browser($jar, 'PUT', $path, $new, false)->assertStatus(419);
        $this->browser($jar, 'PUT', $path, $new)->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.diagnosis_type', 'preliminary')
            ->assertJsonPath('data.icd_code', 'K02.1')
            ->assertJsonPath('data.diagnosis_text', $new['diagnosis_text']);
        $saved = $this->browser($jar, 'GET', $path)->assertOk()->json('data');
        $this->assertStringStartsWith('DX-', $saved['number']);
        $this->assertSame($visit, $saved['visit_id']);
        $this->assertSame($a['patient']['id'], $saved['patient_id']);
        $this->assertSame(1, $saved['version']);
        $this->assertSame(1, DB::connection('migration')->table('audit_logs')
            ->where('action', 'diagnosis.save')->count());

        // A stale tab may not silently overwrite the clinician's newer entry.
        $this->browser($jar, 'PUT', $path, $new)->assertStatus(409);
        $updated = [...$new, 'expected_version' => 1,
            'diagnosis_text' => 'Yakuni: dentin kariyesi', 'diagnosis_type' => 'final',
            'icd_code' => null];
        $this->browser($jar, 'PUT', $path, $updated)->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.diagnosis_type', 'final')
            ->assertJsonPath('data.icd_code', null);
        $this->browser($jar, 'GET', $path)->assertJsonPath('data.diagnosis_text', $updated['diagnosis_text']);
        $this->assertSame(2, DB::connection('migration')->table('audit_logs')
            ->where('action', 'diagnosis.save')->count());

        foreach ([
            [...$updated, 'expected_version' => 2, 'icd_code' => 'not-a-code'],
            [...$updated, 'expected_version' => 2, 'patient_id' => $a['patient']['id']],
            [...$updated, 'expected_version' => 2, 'diagnosis_text' => '  '],
        ] as $invalid) {
            $this->browser($jar, 'PUT', $path, $invalid)->assertStatus(422);
        }

        // Real authorization must enforce doctor identity; broad catalogue
        // or generic admin scopes alone never authorize writing an encounter.
        $this->grant($a, 'workspace.consult', null);
        $this->browser($jar, 'PUT', $path, [...$updated, 'expected_version' => 2])->assertForbidden();
        $this->grant($a, 'workspace.consult', 'own');

        // Completed visits are read-only even for the same doctor.
        DB::connection('migration')->table('queue_tickets')->where('id', $id)
            ->update(['status' => 'completed']);
        DB::connection('migration')->table('visits')->where('id', $visit)
            ->update(['status' => 'completed']);
        $this->browser($jar, 'PUT', $path, [...$updated, 'expected_version' => 2])->assertForbidden();
        $this->browser($jar, 'GET', $path)->assertOk()->assertJsonPath('data.version', 2);
        $this->assertSame(2, DB::connection('migration')->table('audit_logs')
            ->where('action', 'diagnosis.save')->count());
    }

    public function test_real_cookie_http_all_eleven_operations_and_synchronized_lifecycle(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $jar = $this->clinicSession($a);
        $url = '/api/v1/clinic/queue-tickets';
        $key = (string) Str::uuid();
        $this->browser($jar, 'POST', $url, $this->walk($a), false, $key)->assertStatus(419);
        $this->browser($jar, 'POST', $url, $this->walk($a))->assertUnprocessable();
        $att = $this->browser($jar, 'POST', $url, $this->walk($a), key: $key)->assertCreated()->json();
        $id = $att['ticket']['id'];
        $this->browser($jar, 'POST', $url, $this->walk($a), key: $key)->assertCreated()->assertJsonPath('ticket.id', $id);
        $this->browser($jar, 'GET', $url)->assertOk()->assertJsonPath('meta.total', 1);
        $this->browser($jar, 'GET', $url.'/'.$id)->assertOk()->assertJsonPath('id', $id)->assertJsonPath('number', 1);
        $this->browser($jar, 'GET', '/api/v1/clinic/reception/overview')->assertOk()->assertJsonPath('counts.waiting', 1)->assertJsonPath('counts.arrived', 1);
        foreach ([['call', 1, 'called'], ['recall', 2, 'called'], ['skip', 3, 'skipped'], ['requeue', 4, 'waiting'], ['call', 5, 'called']] as [$action,$version,$state]) {
            $this->browser($jar, 'POST', $url.'/'.$id.'/'.$action, ['expected_version' => $version], key: (string) Str::uuid())->assertOk()->assertJsonPath('ticket.status', $state)->assertJsonPath('ticket.version', $version + 1)->assertJsonPath('ticket.number', 1)->assertJsonPath('visit.status', $state === 'waiting' ? 'queued' : $state);
        }
        $this->browser($jar, 'POST', $url.'/'.$id.'/start', $this->startData($a, 6), key: (string) Str::uuid())->assertOk()->assertJsonPath('visit.status', 'in_progress');
        $this->browser($jar, 'POST', $url.'/'.$id.'/complete', ['expected_version' => 7], key: (string) Str::uuid())->assertOk()->assertJsonPath('ticket.status', 'completed')->assertJsonPath('visit.status', 'completed');
        $att2 = $this->browser($jar, 'POST', $url, $this->walk($a), key: (string) Str::uuid())->assertCreated()->json();
        $this->browser($jar, 'POST', $url.'/'.$att2['ticket']['id'].'/cancel', ['expected_version' => 1], key: (string) Str::uuid())->assertOk()->assertJsonPath('visit.status', 'cancelled');
        $this->assertFalse(DB::connection('migration')->table('resource_reservations')->first()->active);
        $this->browser($jar, 'POST', $url.'/'.$id.'/requeue', ['expected_version' => 8], key: (string) Str::uuid())->assertConflict();
        $this->browser($jar, 'POST', '/api/v1/auth/logout')->assertNoContent();
        $this->browser($jar, 'GET', $url)->assertUnauthorized();
    }

    public function test_call_is_fifo_and_blocked_until_previous_patient_completes(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $first = $this->queue($a);
        $secondPatient = app(Patients::class)->command($this->actor($a['user']), $a['tenant'],
            'create', null, ['name' => 'Second queue patient', 'date_of_birth' => '1992-03-04',
                'branch_id' => $a['branch']], (string) Str::uuid())['patient'];
        $second = $this->queue([...$a, 'patient' => $secondPatient]);
        $firstId = $first['ticket']['id'];
        $secondId = $second['ticket']['id'];

        // Even a direct API call with a valid ticket ID cannot skip FIFO.
        $this->denied(fn () => $this->queue($a, 'call', $secondId,
            ['expected_version' => 1]), 'conflict');
        $this->assertSame('waiting', DB::connection('migration')->table('queue_tickets')
            ->where('id', $secondId)->value('status'));

        $called = $this->queue($a, 'call', $firstId, ['expected_version' => 1]);
        $this->assertSame('called', $called['ticket']['status']);
        $this->denied(fn () => $this->queue($a, 'call', $secondId,
            ['expected_version' => 1]), 'conflict');

        $this->queue($a, 'start', $firstId, $this->startData($a, 2));
        $this->denied(fn () => $this->queue($a, 'call', $secondId,
            ['expected_version' => 1]), 'conflict');

        $this->queue($a, 'complete', $firstId, ['expected_version' => 3]);
        $after = $this->queue($a, 'call', $secondId, ['expected_version' => 1]);
        $this->assertSame('called', $after['ticket']['status']);
        $this->assertSame(2, $after['ticket']['number']);
    }

    public function test_preexisting_multiple_called_tickets_cannot_start_two_consultations(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $first = $this->queue($a);
        $secondPatient = app(Patients::class)->command($this->actor($a['user']), $a['tenant'],
            'create', null, ['name' => 'Legacy called patient', 'date_of_birth' => '1992-03-04',
                'branch_id' => $a['branch']], (string) Str::uuid())['patient'];
        $second = $this->queue([...$a, 'patient' => $secondPatient]);
        $firstId = $first['ticket']['id'];
        $secondId = $second['ticket']['id'];

        $this->queue($a, 'call', $firstId, ['expected_version' => 1]);
        // Reproduce historical data from before the one-active-patient guard.
        // Keep the synchronized visit/ticket integrity trigger valid.
        $db = DB::connection('migration');
        $db->transaction(function () use ($db, $secondId, $second): void {
            $db->table('queue_tickets')->where('id', $secondId)->update([
                'status' => 'called', 'version' => 2, 'called_at' => now(),
            ]);
            $db->table('visits')->where('id', $second['visit']['id'])->update([
                'status' => 'called', 'version' => 2,
            ]);
        });

        $this->queue($a, 'start', $firstId, $this->startData($a, 2, '09:00'));
        $this->denied(fn () => $this->queue($a, 'start', $secondId,
            $this->startData($a, 2, '09:30')), 'conflict');
        $this->assertSame('called', $db->table('queue_tickets')->where('id', $secondId)->value('status'));

        $this->queue($a, 'complete', $firstId, ['expected_version' => 3]);
        $started = $this->queue($a, 'start', $secondId, $this->startData($a, 2, '09:30'));
        $this->assertSame('in_progress', $started['ticket']['status']);
    }

    public function test_linked_appointment_reuses_reservation_and_completion_releases_it(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $appt = $this->command($a);
        $att = $this->command($a, 'check_in', $appt['id'], ['expected_version' => 1]);
        $id = $att['ticket']['id'];
        $db = DB::connection('migration');
        $reservation = $db->table('resource_reservations')->first()->id;
        $this->queue($a, 'skip', $id, ['expected_version' => 1]);
        $this->assertSame('checked_in', $db->table('appointments')->first()->status);
        $this->queue($a, 'requeue', $id, ['expected_version' => 2]);
        $this->queue($a, 'call', $id, ['expected_version' => 3]);
        $this->denied(fn () => $this->queue($a, 'start', $id, $this->startData($a, 4, '10:00')), 'conflict');
        $started = $this->queue($a, 'start', $id, $this->startData($a, 4));
        $this->assertSame('in_progress', $started['appointment']['status']);
        $this->assertSame($reservation, $db->table('visits')->first()->reservation_id);
        $this->assertSame(1, $db->table('resource_reservations')->count());
        $done = $this->queue($a, 'complete', $id, ['expected_version' => 5]);
        $this->assertSame('completed', $done['appointment']['status']);
        $this->assertFalse($db->table('resource_reservations')->first()->active);
        $this->assertSame('completed', $done['visit']['status']);
        $this->denied(fn () => $this->queue($a, 'call', $id, ['expected_version' => 6]), 'conflict');
    }

    public function test_linked_cancel_requires_separate_permission_and_is_atomic(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $appt = $this->command($a);
        $att = $this->command($a, 'check_in', $appt['id'], ['expected_version' => 1]);
        $id = $att['ticket']['id'];
        $this->grant($a, 'appointments.cancel', null);
        $this->denied(fn () => $this->queue($a, 'cancel', $id, ['expected_version' => 1]), 'forbidden');
        $this->assertSame('waiting', DB::connection('migration')->table('queue_tickets')->first()->status);
        DB::connection('migration')->table('role_permissions')->insert(['tenant_id' => $a['tenant'], 'role_id' => $a['role'], 'permission_id' => DB::connection('migration')->table('permissions')->where('code', 'appointments.cancel')->value('id'), 'scope' => 'entire_clinic']);
        $result = $this->queue($a, 'cancel', $id, ['expected_version' => 1]);
        $this->assertSame('cancelled', $result['appointment']['status']);
        $this->assertSame('cancelled', $result['visit']['status']);
        $this->assertFalse(DB::connection('migration')->table('resource_reservations')->first()->active);
        $this->assertSame(3, DB::connection('migration')->table('operation_events')->where('action', 'queue_cancel')->count());
    }

    public function test_stale_version_replay_payload_and_permission_revocation(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $id = $this->queue($a)['ticket']['id'];
        $key = (string) Str::uuid();
        $this->queue($a, 'call', $id, ['expected_version' => 1], $key);
        $this->assertSame(2, $this->queue($a, 'call', $id, ['expected_version' => 1], $key)['ticket']['version']);
        $this->denied(fn () => $this->queue($a, 'call', $id, ['expected_version' => 2], $key), 'conflict');
        $this->denied(fn () => $this->queue($a, 'skip', $id, ['expected_version' => 1]), 'conflict');
        $this->grant($a, 'queues.call', null);
        $this->denied(fn () => $this->queue($a, 'call', $id, ['expected_version' => 1], $key), 'forbidden');
        $this->assertSame(1, DB::connection('migration')->table('operation_events')->where('action', 'call')->whereNotNull('ticket_id')->count());
        $this->assertGreaterThan(0, DB::connection('migration')->table('audit_logs')->where('action', 'queue.call')->count());
    }

    public function test_tenant_rls_raw_reads_permissions_and_platform_denial(): void
    {
        $ids = $this->fixture();
        $a = $this->reference($ids['a']);
        $b = $this->reference($ids['b']);
        $qa = $this->queue($a)['ticket']['id'];
        $qb = $this->queue($b)['ticket']['id'];
        $jar = $this->clinicSession($a);
        $url = '/api/v1/clinic/queue-tickets';
        $this->browser($jar, 'GET', $url.'/'.$qb)->assertNotFound();
        $this->browser($jar, 'POST', $url.'/'.$qb.'/call', ['expected_version' => 1], key: (string) Str::uuid())->assertNotFound();
        $this->browser($jar, 'GET', $url.'?branch_id='.$b['branch'])->assertOk()->assertJsonPath('meta.total', 0);
        app(Scheduling::class)->context($this->actor($a['user']), $a['tenant'], function () use ($b) {
            $this->assertSame(1, DB::table('queue_tickets')->count());
            $this->assertSame(0, DB::table('queue_tickets')->where('tenant_id', $b['tenant'])->count());
        });
        $this->assertSame(0, DB::table('queue_tickets')->count());
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $priv) {
            $this->assertFalse(DB::selectOne("SELECT has_table_privilege(current_user,'queue_tickets',?) AS ok", [$priv])->ok);
        }
        foreach (['resource_reservations', 'queue_counters', 'operation_idempotency'] as $t) {
            $this->assertSqlState('42501', fn () => DB::table($t)->get());
        }
        $this->assertSqlState('42501', fn () => DB::select('SELECT governance.queue_attendance(?)', [$qa]));
        DB::connection('migration')->table('users')->where('id', $a['user'])->update(['is_platform_admin' => true]);
        $this->browser($jar, 'GET', $url)->assertNotFound();
        $this->denied(fn () => $this->queue($a, 'call', $qa, ['expected_version' => 1]), 'forbidden');
    }

    public function test_branch_and_own_scopes_apply_to_queue_lists_actions_and_overview(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $id = $this->queue($a)['ticket']['id'];
        foreach (['queues.view', 'queues.call', 'visits.view'] as $code) {
            $this->grant($a, $code, 'own');
        }
        $svc = app(Queues::class);
        $actor = $this->actor($a['user']);
        $this->assertSame(1, $svc->listing($actor, $a['tenant'], [])['meta']['total']);
        $this->queue($a, 'call', $id, ['expected_version' => 1]);
        DB::connection('migration')->table('membership_branches')->where('membership_id', $a['membership'])->delete();
        $this->assertSame(0, $svc->listing($actor, $a['tenant'], [])['meta']['total']);
        $this->denied(fn () => $this->queue($a, 'recall', $id, ['expected_version' => 2]), 'not_found');
        $this->grant($a, 'reception.view', 'assigned_branches');
        $this->assertSame([], $svc->overview($actor, $a['tenant'], [])['tickets']);
    }

    public function test_overview_omits_unauthorized_sections_and_counts_and_intersects_reception_scope(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $this->queue($a);
        foreach (['appointments.view', 'visits.view'] as $code) {
            $this->grant($a, $code, null);
        }
        $out = app(Queues::class)->overview($this->actor($a['user']), $a['tenant'], []);
        $this->assertArrayNotHasKey('appointments', $out);
        $this->assertArrayNotHasKey('visits', $out);
        $this->assertArrayNotHasKey('late', (array) $out['counts']);
        $this->assertArrayNotHasKey('arrived', (array) $out['counts']);
        $this->assertSame(1, ((array) $out['counts'])['waiting']);
        $this->grant($a, 'queues.view', null);
        $out = app(Queues::class)->overview($this->actor($a['user']), $a['tenant'], []);
        $this->assertArrayNotHasKey('tickets', $out);
        $this->assertSame([], (array) $out['counts']);
        $this->grant($a, 'reception.view', null);
        try {
            app(Queues::class)->overview($this->actor($a['user']), $a['tenant'], []);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_start_rechecks_schedule_occupancy_and_live_eligibility(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $id = $this->queue($a)['ticket']['id'];
        $this->queue($a, 'call', $id, ['expected_version' => 1]);
        $this->denied(fn () => $this->queue($a, 'start', $id, $this->startData($a, 2, '13:00')), 'conflict');
        $appt = $this->command($a);
        $this->denied(fn () => $this->queue($a, 'start', $id, $this->startData($a, 2)), 'conflict');
        $this->assertNull(DB::connection('migration')->table('visits')->first()->reservation_id);
        $this->command($a, 'cancel', $appt['id'], ['expected_version' => 1]);
        DB::connection('migration')->table('services')->where('id', $a['service'])->update(['active' => false]);
        $this->denied(fn () => $this->queue($a, 'start', $id, $this->startData($a, 2)), 'not_found');
        $this->assertSame('called', DB::connection('migration')->table('queue_tickets')->first()->status);
    }

    public function test_final_audit_failure_rolls_back_states_reservation_events_and_ledger(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $id = $this->queue($a)['ticket']['id'];
        $this->queue($a, 'call', $id, ['expected_version' => 1]);
        $db = DB::connection('migration');
        $n = $db->table('operation_events')->count();
        $keys = $db->table('operation_idempotency')->count();
        $db->unprepared("CREATE FUNCTION public.queue_test_fail() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.action='queue.start' THEN RAISE EXCEPTION 'Injected failure' USING ERRCODE='23505'; END IF; RETURN NEW; END \$\$; CREATE TRIGGER queue_test_fail BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION public.queue_test_fail()");
        try {
            $this->denied(fn () => $this->queue($a, 'start', $id, $this->startData($a, 2)), 'conflict');
        } finally {
            $db->unprepared('DROP TRIGGER queue_test_fail ON audit_logs; DROP FUNCTION public.queue_test_fail()');
        }
        $this->assertSame('called', $db->table('queue_tickets')->first()->status);
        $this->assertSame(0, $db->table('resource_reservations')->count());
        $this->assertSame($n, $db->table('operation_events')->count());
        $this->assertSame($keys, $db->table('operation_idempotency')->count());
        $this->queue($a, 'start', $id, $this->startData($a, 2));
    }

    public function test_validation_rejects_spoofed_context_and_malformed_commands(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $jar = $this->clinicSession($a);
        $id = $this->queue($a)['ticket']['id'];
        $url = '/api/v1/clinic/queue-tickets';
        foreach ([[], ['expected_version' => 0], ['expected_version' => 1, 'tenant_id' => $a['tenant']], ['expected_version' => 'x']] as $data) {
            $this->browser($jar, 'POST', $url.'/'.$id.'/call', $data, key: (string) Str::uuid())->assertUnprocessable();
        }
        $this->browser($jar, 'GET', $url.'?status=scheduled')->assertUnprocessable();
        $this->browser($jar, 'GET', '/api/v1/clinic/reception/overview?local_date=invalid')->assertUnprocessable();
        $this->browser($jar, 'POST', $url.'/'.$id.'/start', $this->startData($a, 1), key: (string) Str::uuid())->assertConflict();
        $this->denied(fn () => $this->queue($a, 'call', $id, ['expected_version' => 1, 'tenant_id' => $a['tenant']]), 'invalid_input');
    }

    public function test_migration_roundtrip_and_protected_nonempty_rollback(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $id = $this->command($a, 'walk_in', data: $this->walk($a))['ticket']['id'];
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'migration', '--step' => $this->migrationStepsFrom('2026_10_17_000010'), '--force' => true]));
        $this->assertFalse(DB::selectOne("SELECT has_table_privilege(current_user,'queue_tickets','SELECT') AS ok")->ok);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'migration', '--force' => true]));
        $this->queue($a, 'call', $id, ['expected_version' => 1]);
        try {
            Artisan::call('migrate:rollback', ['--database' => 'migration', '--step' => $this->migrationStepsFrom('2026_10_17_000010'), '--force' => true]);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('forward migration', $e->getMessage());
        } finally {
            Artisan::call('migrate', ['--database' => 'migration', '--force' => true]);
        }
        $this->assertSame('called', DB::connection('migration')->table('queue_tickets')->first()->status);
    }

    private function race(array $a, array $requests): array
    {
        $code = <<<'WORKER'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$i=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
Illuminate\Support\Facades\DB::select("SELECT set_config('application_name',?,false)",[$i['tag']]);
$actor=(new App\Modules\Identity\Models\User)->forceFill(['id'=>$i['user'],'credential_version'=>1]);
try{$r=app(App\Modules\Queues\Services\Queues::class)->command($actor,$i['tenant'],$i['action'],$i['id'],$i['data'],$i['key']);echo json_encode(['status'=>in_array($i['action'],['create'])?201:200,'data'=>$r]);}
catch(App\Modules\Access\Services\GovernanceDenied $e){echo json_encode(['status'=>match($e->reason){'conflict'=>409,'not_found'=>404,'invalid_input'=>422,default=>403}]);}
WORKER;
        $owner = DB::connection('migration');
        $owner->beginTransaction();
        $owner->table('tenants')->where('id', $a['tenant'])->lockForUpdate()->first();
        $workers = [];
        $tags = [];
        try {
            foreach ($requests as $r) {
                $tag = 'booking-race-'.Str::uuid();
                $tags[] = $tag;
                $p = new Process([PHP_BINARY, '-r', $code], base_path(), ['APP_ENV' => 'testing']);
                $p->setInput(json_encode(['tag' => $tag, 'user' => $a['user'], 'tenant' => $a['tenant'], ...$r]));
                $p->setTimeout(20);
                $p->start();
                $workers[] = $p;
            }
            $deadline = microtime(true) + 10;
            $blocked = 0;
            do {
                $blocked = (int) DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE application_name IN (?,?) AND wait_event_type='Lock'", $tags)->n;
                if ($blocked === 2) {
                    break;
                }usleep(30000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked, 'Both real runtime transactions must reach PostgreSQL locks.');
            $owner->commit();
            $out = [];
            foreach ($workers as $p) {
                $p->wait();
                $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
                $out[] = json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return $out;
        } finally {
            if ($owner->transactionLevel()) {
                $owner->rollBack();
            }foreach ($workers as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }
        }
    }

    public function test_parallel_call_recall_and_skip_use_row_versions_and_replay_once(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $id = $this->queue($a)['ticket']['id'];
        $req = ['action' => 'call', 'id' => $id, 'data' => ['expected_version' => 1], 'key' => (string) Str::uuid()];
        $r = $this->race($a, [$req, [...$req, 'key' => (string) Str::uuid()]]);
        $codes = array_column($r, 'status');
        sort($codes);
        $this->assertSame([200, 409], $codes);
        $req = ['action' => 'recall', 'id' => $id, 'data' => ['expected_version' => 2], 'key' => (string) Str::uuid()];
        $r = $this->race($a, [$req, $req]);
        $this->assertSame([200, 200], array_column($r, 'status'));
        $this->assertSame(3, DB::connection('migration')->table('queue_tickets')->first()->version);
        $req = ['action' => 'skip', 'id' => $id, 'data' => ['expected_version' => 3], 'key' => (string) Str::uuid()];
        $r = $this->race($a, [$req, [...$req, 'action' => 'cancel', 'key' => (string) Str::uuid()]]);
        $codes = array_column($r, 'status');
        sort($codes);
        $this->assertSame([200, 409], $codes);
    }

    public function test_parallel_creation_number_allocation_and_duplicate_rejection(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $req = ['action' => 'create', 'id' => null, 'data' => $this->walk($a), 'key' => (string) Str::uuid()];
        $r = $this->race($a, [$req, [...$req, 'key' => (string) Str::uuid()]]);
        $codes = array_column($r, 'status');
        sort($codes);
        $this->assertSame([201, 409], $codes);
        $other = [];
        foreach ([2, 3] as $n) {
            $p = app(Patients::class)->command($this->actor($a['user']), $a['tenant'], 'create', null, ['name' => 'Synthetic '.$n, 'date_of_birth' => '1990-01-01', 'branch_id' => $a['branch']], (string) Str::uuid());
            $other[] = [...$req, 'key' => (string) Str::uuid(), 'data' => [...$req['data'], 'patient_id' => $p['patient']['id']]];
        }
        $r = $this->race($a, $other);
        $this->assertSame([201, 201], array_column($r, 'status'));
        $numbers = array_column(array_column(array_column($r, 'data'), 'ticket'), 'number');
        sort($numbers);
        $this->assertSame([2, 3], $numbers);
        $this->assertSame(3, DB::connection('migration')->table('queue_counters')->first()->value);
    }

    public function test_each_queue_action_requires_its_own_permission_and_full_attendance_views(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $id = $this->queue($a)['ticket']['id'];
        $db = DB::connection('migration');
        foreach (['queues.call', 'queues.recall', 'queues.start', 'queues.complete', 'queues.skip', 'queues.cancel', 'queues.requeue', 'visits.view', 'queues.view'] as $code) {
            $permission = $db->table('permissions')->where('code', $code)->value('id');
            $this->grant($a, $code, null);
            $action = str_starts_with($code, 'queues.') && ! str_ends_with($code, '.view') ? substr($code, 7) : 'call';
            $this->denied(fn () => $this->queue($a, $action, $id, $action === 'start' ? $this->startData($a, 1) : ['expected_version' => 1]), in_array($code, ['visits.view', 'queues.view']) ? 'not_found' : 'forbidden');
            $db->table('role_permissions')->insert(['tenant_id' => $a['tenant'], 'role_id' => $a['role'], 'permission_id' => $permission, 'scope' => 'entire_clinic']);
        }
        $this->assertSame(1, $db->table('queue_tickets')->first()->version);
        $this->assertSame(0, $db->table('operation_idempotency')->where('operation', 'queue.call')->count());
    }

    public function test_queue_read_does_not_require_unrelated_visit_view_and_replay_rechecks_credential_version(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $key = (string) Str::uuid();
        $att = $this->queue($a, key: $key);
        $this->grant($a, 'visits.view', null);
        $this->assertSame($att['ticket']['id'], app(Queues::class)->show($this->actor($a['user']), $a['tenant'], $att['ticket']['id'])['id']);
        $this->assertSame(1, app(Queues::class)->listing($this->actor($a['user']), $a['tenant'], ['doctor_id' => $a['doctor']['id']])['meta']['total']);
        DB::connection('migration')->table('users')->where('id', $a['user'])->update(['credential_version' => 2]);
        $this->denied(fn () => $this->queue($a, key: $key), 'forbidden');
        $this->assertSame(1, DB::connection('migration')->table('queue_counters')->first()->value);
    }

    public function test_reception_scope_intersection_and_branch_timezone_and_date_filters(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $other = $this->branch($a);
        $doctor = $a['doctor'];
        unset($doctor['id']);
        $doctor['branch_ids'][] = $other;
        $this->scheduleCommand($a, 'doctors', $doctor, $a['doctor']['id']);
        app(Patients::class)->command($this->actor($a['user']), $a['tenant'], 'link_branch', $a['patient']['id'], ['branch_id' => $other], (string) Str::uuid());
        $db = DB::connection('migration');
        $db->table('branches')->where('id', $other)->update(['timezone' => 'Pacific/Kiritimati']);
        $first = $this->queue($a);
        $second = $this->queue($a, data: [...$this->walk($a), 'branch_id' => $other]);
        $svc = app(Queues::class);
        $actor = $this->actor($a['user']);
        $out = $svc->overview($actor, $a['tenant'], ['branch_id' => $other, 'local_date' => $second['ticket']['local_date']]);
        $this->assertSame('Pacific/Kiritimati', $out['timezone']);
        $this->assertSame(1, ((array) $out['counts'])['waiting']);
        $this->assertSame($second['ticket']['id'], $out['tickets'][0]['id']);
        $db->table('membership_branches')->where('membership_id', $a['membership'])->where('branch_id', $other)->delete();
        $this->grant($a, 'reception.view', 'assigned_branches');
        $out = $svc->overview($actor, $a['tenant'], ['local_date' => $first['ticket']['local_date']]);
        $this->assertSame([$first['ticket']['id']], array_column($out['tickets'], 'id'));
        try {
            $svc->overview($actor, $a['tenant'], ['branch_id' => $other]);
            $this->fail();
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }
        $this->grant($a, 'queues.view', 'assigned_branches');
        $this->assertSame(1, $svc->listing($actor, $a['tenant'], [])['meta']['total']);
        $this->denied(fn () => $this->queue($a, 'call', $second['ticket']['id'], ['expected_version' => 1]), 'not_found');
    }

    public function test_parallel_walkin_starts_and_completes_preserve_exclusion_and_single_events(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $one = $this->queue($a);
        $patient = app(Patients::class)->command($this->actor($a['user']), $a['tenant'], 'create', null, ['name' => 'Synthetic concurrent', 'date_of_birth' => '1990-01-01', 'branch_id' => $a['branch']], (string) Str::uuid());
        $two = $this->queue($a, data: [...$this->walk($a), 'patient_id' => $patient['patient']['id']]);
        foreach ([$one, $two] as $att) {
            $this->queue($a, 'call', $att['ticket']['id'], ['expected_version' => 1]);
        }
        $req = ['action' => 'start', 'id' => $one['ticket']['id'], 'data' => $this->startData($a, 2), 'key' => (string) Str::uuid()];
        $r = $this->race($a, [$req, [...$req, 'id' => $two['ticket']['id'], 'key' => (string) Str::uuid()]]);
        $codes = array_column($r, 'status');
        sort($codes);
        $this->assertSame([200, 409], $codes);
        $db = DB::connection('migration');
        $this->assertSame(1, $db->table('resource_reservations')->where('active', true)->count());
        $winner = $db->table('queue_tickets')->where('status', 'in_progress')->first();
        $req = ['action' => 'complete', 'id' => $winner->id, 'data' => ['expected_version' => 3], 'key' => (string) Str::uuid()];
        $r = $this->race($a, [$req, $req]);
        $this->assertSame([200, 200], array_column($r, 'status'));
        $this->assertSame(1, $db->table('operation_events')->where('action', 'complete')->whereNotNull('ticket_id')->count());
        $this->assertSame(0, $db->table('resource_reservations')->where('active', true)->count());
    }

    public function test_schedule_edit_cannot_invalidate_active_walkin_reservation(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $id = $this->queue($a)['ticket']['id'];
        $this->queue($a, 'call', $id, ['expected_version' => 1]);
        $this->queue($a, 'start', $id, $this->startData($a, 2));
        $rule = DB::connection('migration')->table('schedule_rules')->first();
        $this->denied(fn () => $this->scheduleCommand($a, 'schedules', [...$this->rule($a), 'local_start' => '10:00'], $rule->id), 'conflict');
        $this->assertSame('09:00:00', DB::connection('migration')->table('schedule_rules')->first()->local_start);
        $this->queue($a, 'complete', $id, ['expected_version' => 3]);
        $this->scheduleCommand($a, 'schedules', [...$this->rule($a), 'local_start' => '10:00'], $rule->id);
    }

    public function test_actual_ticket_row_lock_precedes_expected_version_check(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $id = $this->queue($a)['ticket']['id'];
        $db = DB::connection('migration');
        $db->beginTransaction();
        $db->table('queue_tickets')->where('id', $id)->lockForUpdate()->first();
        $code = <<<'WORKER'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$i=json_decode(stream_get_contents(STDIN),true);Illuminate\Support\Facades\DB::select("SELECT set_config('application_name','queue-row-lock-test',false)");
$actor=(new App\Modules\Identity\Models\User)->forceFill(['id'=>$i['user'],'credential_version'=>1]);try{app(App\Modules\Queues\Services\Queues::class)->command($actor,$i['tenant'],'call',$i['id'],['expected_version'=>1],$i['key']);echo 'updated';}catch(App\Modules\Access\Services\GovernanceDenied $e){echo $e->reason;}
WORKER;
        $p = new Process([PHP_BINARY, '-r', $code], base_path(), ['APP_ENV' => 'testing']);
        $p->setInput(json_encode(['user' => $a['user'], 'tenant' => $a['tenant'], 'id' => $id, 'key' => (string) Str::uuid()]));
        $p->setTimeout(20);
        $p->start();
        try {
            $deadline = microtime(true) + 10;
            $blocked = false;
            do {
                $blocked = DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE application_name='queue-row-lock-test' AND wait_event_type='Lock') AS yes")->yes;
                if ($blocked) {
                    break;
                }usleep(30000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($blocked);
            $db->table('queue_tickets')->where('id', $id)->update(['version' => 2]);
            $db->commit();
            $p->wait();
            $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
            $this->assertSame('conflict', $p->getOutput());
            $this->assertSame('waiting', $db->table('queue_tickets')->first()->status);
            $this->assertSame(0, $db->table('operation_events')->where('action', 'call')->count());
        } finally {
            if ($db->transactionLevel()) {
                $db->rollBack();
            }if ($p->isRunning()) {
                $p->stop();
            }
        }
    }

    public function test_own_scope_hides_another_doctors_ticket_in_the_same_branch(): void
    {
        $ids = $this->fixture();
        $a = $this->reference($ids['a']);
        $own = $this->queue($a);
        $db = DB::connection('migration');
        $membership = (string) Str::uuid();
        $db->table('tenant_memberships')->insert(['id' => $membership, 'tenant_id' => $a['tenant'], 'user_id' => $ids['b']['user'], 'role_id' => $a['role']]);
        $db->table('membership_branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $a['tenant'], 'membership_id' => $membership, 'branch_id' => $a['branch']]);
        $doctor = $this->scheduleCommand($a, 'doctors', ['membership_id' => $membership, 'specialty_id' => $a['specialty']['id'], 'branch_ids' => [$a['branch']], 'service_ids' => [$a['service']], 'active' => true]);
        $other = $this->queue($a, data: [...$this->walk($a), 'doctor_id' => $doctor['id']]);
        foreach (['queues.view', 'queues.call', 'visits.view'] as $permission) {
            $this->grant($a, $permission, 'own');
        }
        $svc = app(Queues::class);
        $actor = $this->actor($a['user']);
        $this->assertSame([$own['ticket']['id']], array_column($svc->listing($actor, $a['tenant'], [])['data'], 'id'));
        try {
            $svc->show($actor, $a['tenant'], $other['ticket']['id']);
            $this->fail();
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }
        $this->denied(fn () => $this->queue($a, 'call', $other['ticket']['id'], ['expected_version' => 1]), 'not_found');
        $this->assertSame([$own['ticket']['id']], array_column($svc->overview($actor, $a['tenant'], [])['tickets'], 'id'));
    }

    public function test_all_terminal_transitions_are_rejected_without_new_events_or_numbers(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $id = $this->queue($a)['ticket']['id'];
        $this->queue($a, 'call', $id, ['expected_version' => 1]);
        $this->denied(fn () => $this->queue($a, 'start', $id, [...$this->startData($a, 2), 'starts_at' => '2030-01-07T09:00:00+04:00', 'ends_at' => '2030-01-07T09:30:00+04:00']), 'conflict');
        $this->queue($a, 'start', $id, $this->startData($a, 2));
        $this->queue($a, 'complete', $id, ['expected_version' => 3]);
        $cancelled = $this->queue($a)['ticket']['id'];
        $this->queue($a, 'cancel', $cancelled, ['expected_version' => 1]);
        $db = DB::connection('migration');
        $events = $db->table('operation_events')->count();
        foreach ([[$id, 4], [$cancelled, 2]] as [$ticket,$version]) {
            foreach (['call', 'recall', 'start', 'complete', 'skip', 'requeue', 'cancel'] as $action) {
                $this->denied(fn () => $this->queue($a, $action, $ticket, $action === 'start' ? $this->startData($a, $version) : ['expected_version' => $version]), 'conflict');
            }
        }
        $this->assertSame($events, $db->table('operation_events')->count());
        $this->assertSame(2, $db->table('queue_counters')->first()->value);
    }

    public function test_late_scheduled_requeue_conflicts_without_shifting_or_cancelling(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $appointment = $this->command($a);
        $att = $this->command($a, 'check_in', $appointment['id'], ['expected_version' => 1]);
        $id = $att['ticket']['id'];
        $this->queue($a, 'skip', $id, ['expected_version' => 1]);
        $db = DB::connection('migration');
        // Owner-only test fixture simulates elapsed time, not a runtime mutation capability.
        $db->transaction(function () use ($db, $appointment) {
            $db->table('appointments')->where('id', $appointment['id'])->update(['starts_at' => '2000-01-03T04:00:00Z', 'ends_at' => '2000-01-03T04:30:00Z']);
            $db->table('resource_reservations')->where('appointment_id', $appointment['id'])->update(['starts_at' => '2000-01-03T04:00:00Z', 'ends_at' => '2000-01-03T04:30:00Z']);
        });
        $this->denied(fn () => $this->queue($a, 'requeue', $id, ['expected_version' => 2]), 'conflict');
        $this->assertSame('checked_in', $db->table('appointments')->first()->status);
        $this->assertSame('skipped', $db->table('visits')->first()->status);
        $this->assertSame(1, $db->table('queue_counters')->first()->value);
        $this->assertSame(0, $db->table('operation_events')->where('action', 'requeue')->count());
    }

    public function test_own_doctor_start_does_not_require_clinic_wide_catalogue_or_scheduling_list_rights(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $ticket = $this->queue($a)['ticket']['id'];
        $this->queue($a, 'call', $ticket, ['expected_version' => 1]);

        $this->grant($a, 'queues.start', 'own');
        $this->grant($a, 'queues.view', 'own');
        foreach (['services.view', 'rooms.view', 'doctors.view',
            'schedules.view', 'patients.view', 'appointments.update'] as $code) {
            $this->grant($a, $code, null);
        }

        $started = $this->queue($a, 'start', $ticket, $this->startData($a, 2));
        $this->assertSame('in_progress', $started['ticket']['status']);
        $this->assertSame('in_progress', $started['visit']['status']);
        $this->assertSame(1, DB::connection('migration')->table('resource_reservations')->count());

        // Replays preserve authorization: revoking the operation itself is never bypassed.
        $this->grant($a, 'queues.start', null);
        $this->denied(fn () => $this->queue($a, 'start', $ticket,
            $this->startData($a, 2)), 'forbidden');
    }

    public function test_start_replay_rechecks_reference_permissions_and_call_rechecks_doctor_eligibility(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $id = $this->queue($a)['ticket']['id'];
        $db = DB::connection('migration');
        $db->table('doctor_profiles')->where('id', $a['doctor']['id'])->update(['active' => false]);
        $this->denied(fn () => $this->queue($a, 'call', $id, ['expected_version' => 1]), 'not_found');
        $db->table('doctor_profiles')->where('id', $a['doctor']['id'])->update(['active' => true]);
        $this->queue($a, 'call', $id, ['expected_version' => 1]);
        $key = (string) Str::uuid();
        $data = $this->startData($a, 2);
        $this->queue($a, 'start', $id, $data, $key);
        foreach (['rooms.view', 'doctors.view', 'schedules.view', 'services.view'] as $permission) {
            $this->grant($a, $permission, null);
            $this->denied(fn () => $this->queue($a, 'start', $id, $data, $key), 'forbidden');
            $db->table('role_permissions')->insert(['tenant_id' => $a['tenant'], 'role_id' => $a['role'], 'permission_id' => $db->table('permissions')->where('code', $permission)->value('id'), 'scope' => 'entire_clinic']);
        }
        $this->assertSame(1, $db->table('resource_reservations')->count());
        $this->assertSame(1, $db->table('operation_events')->where('action', 'start')->whereNotNull('ticket_id')->count());
    }

    public function test_queue_list_is_consistent_when_branch_access_is_revoked_between_queries(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $ticket = $this->queue($a)['ticket']['id'];
        $fired = false;
        DB::listen(function ($event) use ($a, &$fired) {
            if ($fired || $event->connectionName !== 'pgsql' || ! str_contains($event->sql, 'from "queue_tickets"')) {
                return;
            }
            $fired = true;
            // A separate committed owner fixture emulates a concurrent RBAC change.
            $this->grant($a, 'queues.view', 'assigned_branches');
            DB::connection('migration')->table('membership_branches')->where('membership_id', $a['membership'])->delete();
        });
        $svc = app(Queues::class);
        $actor = $this->actor($a['user']);
        $out = $svc->listing($actor, $a['tenant'], []);
        $this->assertTrue($fired);
        $this->assertSame(1, $out['meta']['total']);
        $this->assertSame([$ticket], array_column($out['data'], 'id'));
        $this->assertSame(0, $svc->listing($actor, $a['tenant'], [])['meta']['total']);
        $this->assertSame('read committed', DB::selectOne('SHOW transaction_isolation')->transaction_isolation);
    }

    public function test_overview_uses_one_snapshot_when_a_queue_call_commits_between_sections(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $att = $this->queue($a);
        $fired = false;
        DB::listen(function ($event) use ($a, $att, &$fired) {
            if ($fired || $event->connectionName !== 'pgsql' || ! str_contains($event->sql, 'governance.booking_projection')) {
                return;
            }
            $fired = true;
            $code = <<<'WORKER'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$i=json_decode(stream_get_contents(STDIN),true);$actor=(new App\Modules\Identity\Models\User)->forceFill(['id'=>$i['user'],'credential_version'=>1]);app(App\Modules\Queues\Services\Queues::class)->command($actor,$i['tenant'],'call',$i['ticket'],['expected_version'=>1],$i['key']);
WORKER;
            $p = new Process([PHP_BINARY, '-r', $code], base_path(), ['APP_ENV' => 'testing']);
            $p->setInput(json_encode(['user' => $a['user'], 'tenant' => $a['tenant'], 'ticket' => $att['ticket']['id'], 'key' => (string) Str::uuid()]));
            $p->setTimeout(15);
            $p->run();
            $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
        });
        $svc = app(Queues::class);
        $actor = $this->actor($a['user']);
        $out = $svc->overview($actor, $a['tenant'], []);
        $this->assertTrue($fired);
        $this->assertSame('queued', $out['visits'][0]['status']);
        $this->assertSame('waiting', $out['tickets'][0]['status']);
        $this->assertSame(1, ((array) $out['counts'])['waiting']);
        $next = $svc->overview($actor, $a['tenant'], []);
        $this->assertSame('called', $next['visits'][0]['status']);
        $this->assertSame('called', $next['tickets'][0]['status']);
    }

    public function test_hidden_ticket_rolls_back_snapshot_modes_and_tenant_context_before_next_command(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $att = $this->queue($a);
        try {
            app(Queues::class)->show($this->actor($a['user']), $a['tenant'], (string) Str::uuid());
            $this->fail('Hidden ticket must fail closed.');
        } catch (NotFoundHttpException) {
            $this->assertSame(0, DB::connection()->transactionLevel());
        }
        $this->assertSame('read committed', DB::selectOne('SHOW transaction_isolation')->transaction_isolation);
        $this->assertSame('off', DB::selectOne('SHOW transaction_read_only')->transaction_read_only);
        $this->assertSame(0, DB::table('queue_tickets')->count());
        $this->assertSame('called', $this->queue($a, 'call', $att['ticket']['id'], ['expected_version' => 1])['ticket']['status']);
    }
}
