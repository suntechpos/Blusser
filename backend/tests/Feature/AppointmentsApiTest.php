<?php

namespace Tests\Feature;

use App\Modules\Access\Services\GovernanceDenied;
use App\Modules\Appointments\Services\Appointments;
use App\Modules\Patients\Services\Patients;
use App\Modules\Queues\Services\Queues;
use App\Modules\Scheduling\Services\Availability;
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

final class AppointmentsApiTest extends FoundationTestCase
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

    private function filters(array $a, string $from = '2030-01-07T00:00:00+05:00', string $to = '2030-01-08T00:00:00+05:00'): array
    {
        return ['branch_id' => $a['branch'], 'service_id' => $a['service'], 'from' => $from, 'to' => $to];
    }

    private function slots(array $a, ?array $filters = null): array
    {
        return app(Availability::class)->get($this->actor($a['user']), $a['tenant'], $a['doctor']['id'], $filters ?? $this->filters($a));
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

    private function reschedule(array $a, string $time = '10:00', int $version = 1): array
    {
        $d = $this->data($a, $time);
        unset($d['patient_id'],$d['service_id']);

        return [...$d, 'expected_version' => $version];
    }

    private function walk(array $a): array
    {
        $d = $this->data($a);
        unset($d['room_id'],$d['starts_at'],$d['ends_at']);

        return $d;
    }

    public function test_real_http_booking_reschedule_checkin_attendance_history_and_csrf(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $jar = $this->clinicSession($a);
        $url = '/api/v1/clinic/appointments';
        $key = (string) Str::uuid();
        $this->browser($jar, 'POST', $url, $this->data($a), false, $key)->assertStatus(419);
        $this->browser($jar, 'POST', $url, $this->data($a))->assertUnprocessable();
        $created = $this->browser($jar, 'POST', $url, $this->data($a), key: $key)->assertCreated()->json();
        $id = $created['id'];
        $this->browser($jar, 'POST', $url, $this->data($a), key: $key)->assertCreated()->assertJsonPath('id', $id);
        $this->browser($jar, 'POST', $url, $this->data($a, '10:00'), key: $key)->assertConflict();
        $this->browser($jar, 'GET', $url.'/'.$id)->assertOk()->assertJsonPath('late', false);
        $this->browser($jar, 'GET', $url)->assertOk()->assertJsonPath('meta.total', 1);
        $this->browser($jar, 'GET', '/api/v1/clinic/patients/'.$a['patient']['id'].'/appointments')->assertOk()->assertJsonPath('meta.total', 1);
        $this->browser($jar, 'POST', $url.'/'.$id.'/reschedule', $this->reschedule($a), key: (string) Str::uuid())->assertOk()->assertJsonPath('version', 2);
        $this->browser($jar, 'POST', $url.'/'.$id.'/reschedule', $this->reschedule($a, '11:00'), key: (string) Str::uuid())->assertConflict();
        $checkKey = (string) Str::uuid();
        $att = $this->browser($jar, 'POST', $url.'/'.$id.'/check-in', ['expected_version' => 2], key: $checkKey)->assertOk()->assertJsonPath('appointment.status', 'checked_in')->assertJsonPath('visit.status', 'queued')->assertJsonPath('ticket.status', 'waiting')->json();
        $this->browser($jar, 'POST', $url.'/'.$id.'/check-in', ['expected_version' => 2], key: $checkKey)->assertOk()->assertJsonPath('visit.id', $att['visit']['id']);
        $this->browser($jar, 'POST', $url.'/'.$id.'/check-in', ['expected_version' => 3], key: (string) Str::uuid())->assertConflict();
        $this->browser($jar, 'POST', $url.'/'.$id.'/cancel', ['expected_version' => 3], key: (string) Str::uuid())->assertConflict();
        $this->browser($jar, 'GET', $url.'/'.$id.'/history')->assertOk()->assertJsonPath('meta.total', 3);
        $this->browser($jar, 'GET', '/api/v1/clinic/visits/'.$att['visit']['id'].'/history')->assertOk()->assertJsonPath('meta.total', 2);
        $this->browser($jar, 'GET', '/api/v1/clinic/visits/'.$att['visit']['id'])->assertOk()->assertJsonPath('appointment_id', $id);
        $this->browser($jar, 'GET', '/api/v1/clinic/patients/'.$a['patient']['id'].'/visits')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame(1, DB::connection('migration')->table('resource_reservations')->count());
        $row = DB::connection('migration')->table('resource_reservations')->first();
        $this->assertSame($att['visit']['id'], $row->visit_id);
        $this->assertSame($id, $row->appointment_id);
        $this->assertArrayNotHasKey('reservation_id', $created);
        $this->browser($jar, 'GET', '/api/v1/clinic/queues')->assertNotFound();
        $this->browser($jar, 'GET', $url.'?untrusted=1')->assertUnprocessable();
        $this->browser($jar, 'POST', $url, [...$this->data($a), 'tenant_id' => $a['tenant']], key: (string) Str::uuid())->assertUnprocessable();
        $this->browser($jar, 'POST', '/api/v1/auth/logout')->assertNoContent();
        $this->browser($jar, 'GET', $url)->assertUnauthorized();
    }

    public function test_cancel_frees_occupancy_adjacent_intervals_and_version_conflicts(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $first = $this->command($a);
        $this->command($a, data: $this->data($a, '09:30'));
        $this->denied(fn () => $this->command($a), 'conflict');
        $this->denied(fn () => $this->command($a, 'cancel', $first['id'], ['expected_version' => 2]), 'conflict');
        $c = $this->command($a, 'cancel', $first['id'], ['expected_version' => 1]);
        $this->assertSame('cancelled', $c['status']);
        $this->assertNotEmpty($this->command($a)['id']);
        foreach (['check_in', 'cancel', 'no_show', 'reschedule'] as $action) {
            $this->denied(fn () => $this->command($a, $action, $first['id'], $action === 'reschedule' ? $this->reschedule($a, version: 2) : ['expected_version' => 2]), 'conflict');
        }
        $this->assertSame(1, DB::connection('migration')->table('resource_reservations')->where('active', false)->count());
    }

    public function test_walkin_atomic_queue_counter_idempotency_duplicate_and_no_occupancy(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $key = (string) Str::uuid();
        $att = $this->command($a, 'walk_in', data: $this->walk($a), key: $key);
        $this->assertNull($att['appointment']);
        $this->assertSame('walk_in', $att['ticket']['kind']);
        $this->assertSame(1, $att['ticket']['number']);
        $this->assertSame($att['visit']['id'], $this->command($a, 'walk_in', data: $this->walk($a), key: $key)['visit']['id']);
        $this->denied(fn () => $this->command($a, 'walk_in', data: $this->walk($a)), 'conflict');
        $this->assertSame(1, DB::connection('migration')->table('queue_counters')->value('value'));
        $this->assertSame(0, DB::connection('migration')->table('resource_reservations')->count());
        $this->grant($a, 'queues.create', null);
        $this->denied(fn () => $this->command($a, 'walk_in', data: $this->walk($a), key: $key), 'forbidden');
        $this->assertSame(1, DB::connection('migration')->table('queue_tickets')->count());
    }

    public function test_checkin_partial_failure_rolls_back_visit_reservation_counter_history_and_ledger(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $appt = $this->command($a);
        $db = DB::connection('migration');
        // Controlled database failure at the LAST child insert, not a simulated PHP check.
        $db->unprepared("CREATE FUNCTION public.test_reject_ticket() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'Synthetic final insert failure' USING ERRCODE='23514'; END \$\$; CREATE TRIGGER test_reject_ticket BEFORE INSERT ON queue_tickets FOR EACH ROW EXECUTE FUNCTION public.test_reject_ticket();");
        try {
            $this->denied(fn () => $this->command($a, 'check_in', $appt['id'], ['expected_version' => 1]), 'invalid_input');
        } finally {
            $db->unprepared('DROP TRIGGER test_reject_ticket ON queue_tickets; DROP FUNCTION public.test_reject_ticket();');
        }
        foreach (['visits', 'queue_tickets', 'queue_counters'] as $table) {
            $this->assertSame(0, $db->table($table)->count());
        }
        $this->assertSame('scheduled', $db->table('appointments')->value('status'));
        $this->assertNull($db->table('resource_reservations')->value('visit_id'));
        $this->assertSame(1, $db->table('operation_events')->count());
        $this->assertSame(1, $db->table('operation_idempotency')->count());
        $att = $this->command($a, 'check_in', $appt['id'], ['expected_version' => 1]);
        $this->assertSame(1, $att['ticket']['number']);
    }

    public function test_booking_schedule_break_exception_service_room_timezone_rules(): void
    {
        $a = $this->reference($this->fixture()['a']);
        foreach ([['13:00', '13:30'], ['08:00', '08:30'], ['09:15', '09:45'], ['09:00', '10:00']] as [$lo,$hi]) {
            $this->denied(fn () => $this->command($a, data: [...$this->data($a, $lo), 'ends_at' => '2030-01-07T'.$hi.':00+05:00']), 'conflict');
        }
        $ex = ['doctor_id' => $a['doctor']['id'], 'branch_id' => $a['branch'], 'local_date' => '2030-01-07', 'kind' => 'closed', 'replacement' => null];
        $e = $this->scheduleCommand($a, 'schedule-exceptions', $ex);
        $this->denied(fn () => $this->command($a), 'conflict');
        $this->scheduleCommand($a, 'schedule-exceptions', [...$ex, 'kind' => 'replace', 'replacement' => [...$this->rule($a), 'local_start' => '10:00', 'local_end' => '11:00', 'breaks' => []]], $e['id']);
        $this->command($a, data: $this->data($a, '10:00'));
        // Existing booking cannot be silently invalidated by changing exception hours.
        $this->denied(fn () => $this->scheduleCommand($a, 'schedule-exceptions', $ex, $e['id']), 'conflict');
        $this->assertSame('replace', DB::connection('migration')->table('schedule_exceptions')->value('kind'));
    }

    public function test_archive_active_attendance_and_real_own_patient_relationship_expiry(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $appt = $this->command($a);
        $p = $a['patient']['id'];
        $patients = app(Patients::class);
        $actor = $this->actor($a['user']);
        $this->denied(fn () => $patients->command($actor, $a['tenant'], 'archive', $p, ['expected_version' => 1], (string) Str::uuid()), 'conflict');
        $this->grant($a, 'patients.view', 'own');
        $this->grant($a, 'patients.update', 'own');
        $this->assertSame(1, $patients->listing($actor, $a['tenant'], [])['meta']['total']);
        $this->assertSame(2, $patients->command($actor, $a['tenant'], 'update', $p, ['name' => 'Synthetic updated', 'expected_version' => 1], (string) Str::uuid())['version']);
        $this->command($a, 'cancel', $appt['id'], ['expected_version' => 1]);
        $this->assertSame(0, $patients->listing($actor, $a['tenant'], [])['meta']['total']);
        $this->grant($a, 'patients.view', 'entire_clinic');
        $this->command($a, 'walk_in', data: $this->walk($a));
        $this->denied(fn () => $patients->command($actor, $a['tenant'], 'archive', $p, ['expected_version' => 2], (string) Str::uuid()), 'conflict');
        $db = DB::connection('migration');
        foreach ($db->table('queue_tickets')->get() as $ticket) {
            app(Queues::class)->command($actor, $a['tenant'], 'cancel', $ticket->id, ['expected_version' => $ticket->version], (string) Str::uuid());
        }
        $this->grant($a, 'patients.view', 'own');
        $this->assertSame(0, $patients->listing($actor, $a['tenant'], [])['meta']['total']);
        $this->grant($a, 'patients.view', 'entire_clinic');
        $patients->command($actor, $a['tenant'], 'archive', $p, ['expected_version' => 2], (string) Str::uuid());
        $this->denied(fn () => $this->command($a), 'conflict');
    }

    public function test_late_no_show_and_reschedule_before_checkin(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $appt = $this->command($a);
        $db = DB::connection('migration');
        $db->transaction(function () use ($db, $appt) {
            $lo = now()->subHour();
            $hi = now()->subMinutes(30);
            $db->table('appointments')->where('id', $appt['id'])->update(['starts_at' => $lo, 'ends_at' => $hi]);
            $db->table('resource_reservations')->where('appointment_id', $appt['id'])->update(['starts_at' => $lo, 'ends_at' => $hi]);
        });
        $this->assertTrue(app(Appointments::class)->show($this->actor($a['user']), $a['tenant'], 'appointments', $appt['id'])['late']);
        $this->denied(fn () => $this->command($a, 'check_in', $appt['id'], ['expected_version' => 1]), 'conflict');
        $updated = $this->command($a, 'reschedule', $appt['id'], $this->reschedule($a));
        $this->assertFalse($updated['late']);
        $this->denied(fn () => $this->command($a, 'no_show', $appt['id'], ['expected_version' => 2]), 'conflict');
        $this->command($a, 'check_in', $appt['id'], ['expected_version' => 2]);
        $other = $this->command($a, data: $this->data($a, '11:00'));
        $db->transaction(function () use ($db, $other) {
            $lo = now()->subHours(3);
            $hi = now()->subHours(2);
            $db->table('appointments')->where('id', $other['id'])->update(['starts_at' => $lo, 'ends_at' => $hi]);
            $db->table('resource_reservations')->where('appointment_id', $other['id'])->update(['starts_at' => $lo, 'ends_at' => $hi]);
        });
        $this->assertSame('no_show', $this->command($a, 'no_show', $other['id'], ['expected_version' => 1])['status']);
    }

    public function test_runtime_rls_idor_cross_tenant_composites_and_internal_queue_privileges(): void
    {
        $ids = $this->fixture();
        $a = $this->reference($ids['a']);
        $b = $this->reference($ids['b']);
        $aa = $this->command($a);
        $bb = $this->command($b);
        $att = $this->command($a, 'check_in', $aa['id'], ['expected_version' => 1]);
        app(Scheduling::class)->context($this->actor($a['user']), $a['tenant'], function () use ($b) {
            foreach (['appointments', 'visits', 'operation_events', 'queue_tickets'] as $t) {
                $this->assertSame(0, DB::table($t)->where('tenant_id', $b['tenant'])->count());
            }
            $this->assertSame(1, DB::table('appointments')->count());
        });
        foreach (['appointments', 'visits', 'resource_reservations', 'queue_tickets', 'queue_counters', 'operation_events', 'operation_idempotency'] as $table) {
            $r = DB::selectOne('SELECT relrowsecurity,relforcerowsecurity FROM pg_class WHERE oid=?::regclass', [$table]);
            $this->assertTrue($r->relrowsecurity && $r->relforcerowsecurity);
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $priv) {
                $this->assertFalse(DB::selectOne('SELECT has_table_privilege(current_user,?,?) AS allowed', [$table, $priv])->allowed);
            }
        }
        foreach (['resource_reservations', 'queue_counters', 'operation_idempotency'] as $t) {
            $this->assertSqlState('42501', fn () => DB::table($t)->get());
        }
        $this->assertSqlState('42501', fn () => DB::select("SELECT governance.booking_slot_valid(?,?,?,?,'2030-01-07T04:00:00Z','2030-01-07T04:30:00Z',true)", [$a['doctor']['id'], $a['branch'], $a['service'], $a['room']['id']]));
        $jar = $this->clinicSession($a);
        foreach (['/api/v1/clinic/appointments/'.$bb['id'], '/api/v1/clinic/appointments/'.$bb['id'].'/history', '/api/v1/clinic/patients/'.$b['patient']['id'].'/appointments'] as $url) {
            $this->browser($jar, 'GET', $url)->assertNotFound();
        }
        foreach (['patient_id' => $b['patient']['id'], 'doctor_id' => $b['doctor']['id'], 'branch_id' => $b['branch'], 'service_id' => $b['service'], 'room_id' => $b['room']['id']] as $key => $id) {
            $this->denied(fn () => $this->command($a, data: [...$this->data($a, '10:00'), $key => $id]), 'not_found');
        }
        $db = DB::connection('migration');
        $this->assertSqlState('23503', fn () => $db->table('queue_tickets')->insert(['tenant_id' => $b['tenant'], 'visit_id' => $att['visit']['id'], 'branch_id' => $b['branch'], 'kind' => 'scheduled', 'local_date' => '2030-01-01', 'number' => 1]));
        $this->assertSqlState('23503', fn () => $db->table('visits')->insert(['tenant_id' => $a['tenant'], 'patient_id' => $b['patient']['id'], 'doctor_id' => $a['doctor']['id'], 'branch_id' => $a['branch'], 'service_id' => $a['service']]));
        $this->assertSqlState('23503', fn () => $db->table('operation_events')->insert(['tenant_id' => $a['tenant'], 'appointment_id' => $bb['id'], 'doctor_id' => $a['doctor']['id'], 'branch_id' => $a['branch'], 'actor_membership_id' => $a['membership'], 'action' => 'invalid', 'to_status' => 'scheduled']));
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace CROSS JOIN LATERAL aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a WHERE n.nspname='governance' AND p.proname LIKE 'booking_%' AND a.grantee=0")->n);
    }

    public function test_assigned_branch_counts_history_filters_and_replay_grant_revocation(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $id = $this->command($a)['id'];
        $second = $this->branch($a);
        $db = DB::connection('migration');
        app(Patients::class)->command($this->actor($a['user']), $a['tenant'], 'link_branch', $a['patient']['id'], ['branch_id' => $second], (string) Str::uuid());
        $d = $a['doctor'];
        unset($d['id']);
        $d['branch_ids'][] = $second;
        $this->scheduleCommand($a, 'doctors', $d, $a['doctor']['id']);
        $room = $this->scheduleCommand($a, 'rooms', ['branch_id' => $second, 'number' => '101', 'service_type' => 'consultation', 'active' => true, 'availability' => 'available']);
        $this->scheduleCommand($a, 'schedules', [...$this->rule($a), 'branch_id' => $second, 'room_id' => $room['id'], 'local_start' => '18:00', 'local_end' => '19:00', 'breaks' => []]);
        $new = [...$this->reschedule($a, '18:00'), 'branch_id' => $second, 'room_id' => $room['id']];
        $this->command($a, 'reschedule', $id, $new);
        $this->grant($a, 'appointments.view', 'assigned_branches');
        $this->grant($a, 'appointments.cancel', 'assigned_branches');
        $db->table('membership_branches')->where('membership_id', $a['membership'])->where('branch_id', $a['branch'])->delete();
        $svc = app(Appointments::class);
        $this->assertSame(1, $svc->listing($this->actor($a['user']), $a['tenant'], 'appointments', [])['meta']['total']);
        $this->assertSame(1, $svc->show($this->actor($a['user']), $a['tenant'], 'appointments', $id, [])['meta']['total']);
        $this->assertSame(0, $svc->listing($this->actor($a['user']), $a['tenant'], 'appointments', ['branch_id' => $a['branch']])['meta']['total']);
        $key = (string) Str::uuid();
        $this->command($a, 'cancel', $id, ['expected_version' => 2], $key);
        $this->grant($a, 'appointments.cancel', null);
        $this->denied(fn () => $this->command($a, 'cancel', $id, ['expected_version' => 2], $key), 'forbidden');
        $this->assertSame(1, $db->table('operation_idempotency')->where('operation', 'cancel')->count());
    }

    public function test_revoked_doctor_service_room_membership_and_actor_credentials_platform_denial(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $db = DB::connection('migration');
        foreach ([['doctor_profiles', $a['doctor']['id'], 'active'], ['services', $a['service'], 'active'], ['rooms', $a['room']['id'], 'active']] as [$t,$id,$field]) {
            $db->table($t)->where('id', $id)->update([$field => false]);
            $this->denied(fn () => $this->command($a), 'not_found');
            $db->table($t)->where('id', $id)->update([$field => true]);
        }
        $db->table('membership_branches')->where('membership_id', $a['membership'])->delete();
        $this->denied(fn () => $this->command($a), 'not_found');
        $db->table('users')->where('id', $a['user'])->update(['credential_version' => 2]);
        $this->denied(fn () => $this->command($a), 'forbidden');
        $db->table('users')->where('id', $a['user'])->update(['credential_version' => 1, 'is_platform_admin' => true]);
        $this->denied(fn () => $this->command($a), 'forbidden');
        try {
            app(Appointments::class)->listing($this->actor($a['user']), $a['tenant'], 'appointments', []);
            $this->fail('Platform denied.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }
        $this->assertSame(0, $db->table('appointments')->count());
    }

    public function test_availability_excludes_real_occupancy_and_export_is_scoped_and_audited(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $this->assertSame(16, $this->slots($a)['meta']['total']);
        $this->command($a);
        $this->assertSame(15, $this->slots($a)['meta']['total']);
        $jar = $this->clinicSession($a);
        $res = $this->browser($jar, 'GET', '/api/v1/clinic/appointments/export')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('patient_id', $res->getContent());
        $this->assertStringNotContainsString('Synthetic patient', $res->getContent());
        $this->assertSame(1, DB::connection('migration')->table('audit_logs')->where('action', 'appointment.export')->count());
        $this->grant($a, 'appointments.export', null);
        $this->browser($jar, 'GET', '/api/v1/clinic/appointments/export')->assertForbidden();
    }

    public function test_dst_gap_fold_and_service_duration_are_rechecked_in_database(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $db = DB::connection('migration');
        $db->table('branches')->where('id', $a['branch'])->update(['timezone' => 'America/New_York']);
        $rules = $db->table('schedule_rules')->where('doctor_id', $a['doctor']['id'])->value('id');
        $this->scheduleCommand($a, 'schedules', [...$this->rule($a), 'weekday' => 7, 'local_start' => '01:00', 'local_end' => '04:00', 'breaks' => []], $rules);
        foreach ([['2030-03-10T02:00:00-05:00', '2030-03-10T02:30:00-05:00'], ['2030-11-03T01:00:00-04:00', '2030-11-03T01:30:00-04:00']] as [$lo,$hi]) {
            $this->denied(fn () => $this->command($a, data: [...$this->data($a), 'starts_at' => $lo, 'ends_at' => $hi]), 'conflict');
        }
        $this->scheduleCommand($a, 'schedules', [...$this->rule($a), 'weekday' => 7, 'local_start' => '09:00', 'local_end' => '12:00', 'breaks' => []], $rules);
        $db->table('doctor_services')->where('doctor_id', $a['doctor']['id'])->update(['duration_minutes' => 60]);
        $this->command($a, data: [...$this->data($a), 'starts_at' => '2030-03-10T09:00:00-04:00', 'ends_at' => '2030-03-10T10:00:00-04:00']);
        $this->denied(fn () => $this->command($a, data: [...$this->data($a), 'starts_at' => '2030-03-10T10:00:00-04:00', 'ends_at' => '2030-03-10T10:30:00-04:00']), 'conflict');
        $this->assertSame(1, $db->table('appointments')->count());
    }

    public function test_migration_empty_roundtrip_nonempty_refusal_and_exclusion_catalog(): void
    {
        $ids = $this->fixture();
        $db = DB::connection('migration');
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'migration', '--step' => 3, '--force' => true]));
        $this->assertNull($db->selectOne("SELECT to_regclass('public.appointments') AS name")->name);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'migration', '--force' => true]));
        $this->assertSame(2, (int) $db->selectOne("SELECT count(*) AS n FROM pg_constraint WHERE conrelid='public.resource_reservations'::regclass AND contype='x'")->n);
        $a = $this->reference($ids['a']);
        $this->command($a);
        try {
            Artisan::call('migrate:rollback', ['--database' => 'migration', '--step' => 3, '--force' => true]);
            $this->fail('No destructive down.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('forward migration', $e->getMessage());
        } finally {
            Artisan::call('migrate', ['--database' => 'migration', '--force' => true]);
        }
        $this->assertSame(1, $db->table('appointments')->count());
    }

    private function race(array $a, array $requests): array
    {
        $code = <<<'WORKER'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$i=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
Illuminate\Support\Facades\DB::select("SELECT set_config('application_name',?,false)",[$i['tag']]);
$actor=(new App\Modules\Identity\Models\User)->forceFill(['id'=>$i['user'],'credential_version'=>1]);
try{$r=app(App\Modules\Appointments\Services\Appointments::class)->command($actor,$i['tenant'],$i['action'],$i['id'],$i['data'],$i['key']);echo json_encode(['status'=>in_array($i['action'],['create','walk_in'])?201:200,'data'=>$r]);}
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

    public function test_parallel_runtime_booking_replay_reschedule_and_checkin(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $request = ['action' => 'create', 'id' => null, 'data' => $this->data($a), 'key' => (string) Str::uuid()];
        $r = $this->race($a, [$request, [...$request, 'key' => (string) Str::uuid()]]);
        $codes = array_column($r, 'status');
        sort($codes);
        $this->assertSame([201, 409], $codes);
        $first = array_values(array_filter($r, fn ($x) => $x['status'] === 201))[0]['data'];
        $request['data'] = $this->data($a, '10:00');
        $request['key'] = (string) Str::uuid();
        $r = $this->race($a, [$request, $request]);
        $this->assertSame([201, 201], array_column($r, 'status'));
        $this->assertSame($r[0]['data']['id'], $r[1]['data']['id']);
        $req = ['action' => 'reschedule', 'id' => $first['id'], 'data' => $this->reschedule($a, '11:00'), 'key' => (string) Str::uuid()];
        $r = $this->race($a, [$req, [...$req, 'data' => $this->reschedule($a, '12:00'), 'key' => (string) Str::uuid()]]);
        $codes = array_column($r, 'status');
        sort($codes);
        $this->assertSame([200, 409], $codes);
        $this->assertSame(2, DB::connection('migration')->table('appointments')->where('id', $first['id'])->value('version'));
        $req = ['action' => 'check_in', 'id' => $first['id'], 'data' => ['expected_version' => 2], 'key' => (string) Str::uuid()];
        $r = $this->race($a, [$req, $req]);
        $this->assertSame([200, 200], array_column($r, 'status'));
        $this->assertSame($r[0]['data']['visit']['id'], $r[1]['data']['visit']['id']);
        $db = DB::connection('migration');
        $this->assertSame(2, $db->table('resource_reservations')->count());
        $this->assertSame(1, $db->table('visits')->count());
        $this->assertSame(1, $db->table('queue_tickets')->count());
        $this->assertSame(1, $db->table('operation_events')->where('action', 'reschedule')->count());
    }

    public function test_parallel_same_doctor_across_branches_is_excluded_even_with_misconfigured_schedule_fixture(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $second = $this->branch($a);
        $d = $a['doctor'];
        unset($d['id']);
        $d['branch_ids'][] = $second;
        $this->scheduleCommand($a, 'doctors', $d, $a['doctor']['id']);
        $room = $this->scheduleCommand($a, 'rooms', ['branch_id' => $second, 'number' => '101', 'service_type' => 'consultation', 'active' => true, 'availability' => 'available']);
        app(Patients::class)->command($this->actor($a['user']), $a['tenant'], 'link_branch', $a['patient']['id'], ['branch_id' => $second], (string) Str::uuid());
        // Deliberately malformed *test-only owner fixture* isolates the booking
        // exclusion from the already-tested schedule-command overlap rejection.
        $rule = $this->rule($a);
        unset($rule['breaks']);
        DB::connection('migration')->table('schedule_rules')->insert(['tenant_id' => $a['tenant'], ...$rule, 'branch_id' => $second, 'room_id' => $room['id']]);
        $r1 = ['action' => 'create', 'id' => null, 'data' => $this->data($a), 'key' => (string) Str::uuid()];
        $r2 = [...$r1, 'key' => (string) Str::uuid(), 'data' => [...$this->data($a), 'branch_id' => $second, 'room_id' => $room['id']]];
        $r = $this->race($a, [$r1, $r2]);
        $codes = array_column($r, 'status');
        sort($codes);
        $this->assertSame([201, 409], $codes);
        $this->assertSame(1, DB::connection('migration')->table('resource_reservations')->count());
    }

    private function rawPair($db, array $a, string $lo): array
    {
        $id = (string) Str::uuid();
        $res = (string) Str::uuid();
        $hi = (new \DateTimeImmutable($lo))->modify('+30 minutes')->format('c');
        $db->table('appointments')->insert(['id' => $id, 'tenant_id' => $a['tenant'], 'patient_id' => $a['patient']['id'], 'doctor_id' => $a['doctor']['id'], 'branch_id' => $a['branch'], 'service_id' => $a['service'], 'room_id' => $a['room']['id'], 'reservation_id' => $res, 'starts_at' => $lo, 'ends_at' => $hi]);
        $db->table('resource_reservations')->insert(['id' => $res, 'tenant_id' => $a['tenant'], 'doctor_id' => $a['doctor']['id'], 'branch_id' => $a['branch'], 'room_id' => $a['room']['id'], 'appointment_id' => $id, 'starts_at' => $lo, 'ends_at' => $hi]);

        return ['id' => $id, 'res' => $res];
    }

    public function test_database_exclusions_block_concurrent_inserts_and_updates_below_command_serialization(): void
    {
        $ids = $this->fixture();
        $a = $this->reference($ids['a']);
        $db = DB::connection('migration');
        $membership = (string) Str::uuid();
        $db->table('tenant_memberships')->insert(['id' => $membership, 'tenant_id' => $a['tenant'], 'user_id' => $ids['b']['user'], 'role_id' => $a['role']]);
        $db->table('membership_branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $a['tenant'], 'membership_id' => $membership, 'branch_id' => $a['branch']]);
        $d = $a['doctor'];
        unset($d['id']);
        $d['membership_id'] = $membership;
        $otherDoctor = $this->scheduleCommand($a, 'doctors', $d);
        $room = $this->scheduleCommand($a, 'rooms', ['branch_id' => $a['branch'], 'number' => '102', 'service_type' => 'consultation', 'active' => true, 'availability' => 'available']);
        $worker = <<<'WORKER'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$i=json_decode(stream_get_contents(STDIN),true);$db=Illuminate\Support\Facades\DB::connection('migration');
if($db->selectOne('SELECT current_database() AS n')->n!=='blusser_test')throw new RuntimeException('Test database required');
$db->select("SELECT set_config('application_name',?,false)",[$i['tag']]);
try{$db->transaction(function()use($db,$i){
 if($i['mode']==='insert'){$db->table('appointments')->insert($i['appointment']);$db->table('resource_reservations')->insert($i['reservation']);}
 else{$db->table('appointments')->where('id',$i['id'])->update(['starts_at'=>$i['lo'],'ends_at'=>$i['hi']]);$db->table('resource_reservations')->where('id',$i['res'])->update(['starts_at'=>$i['lo'],'ends_at'=>$i['hi']]);}
 });echo json_encode(['state'=>'committed']);}
catch(PDOException $e){echo json_encode(['state'=>$e->errorInfo[0]??'', 'constraint'=>str_contains($e->errorInfo[2]??'','reservation_room_exclusion')?'room':'doctor']);}
WORKER;
        $pairs = [];
        foreach (['doctor', 'room', 'update'] as $mode) {
            $tag = 'exclusion-race-'.Str::uuid();
            $db->beginTransaction();
            $p = null;
            try {
                if ($mode !== 'update') {
                    $lo = $mode === 'doctor' ? '2030-01-07T04:00:00Z' : '2030-01-07T05:00:00Z';
                    $hi = (new \DateTimeImmutable($lo))->modify('+30 minutes')->format('c');
                    $pairs[] = $this->rawPair($db, $a, $lo);
                    $did = $mode === 'room' ? $otherDoctor['id'] : $a['doctor']['id'];
                    $rid = $mode === 'room' ? $a['room']['id'] : $room['id'];
                    $id = (string) Str::uuid();
                    $res = (string) Str::uuid();
                    $input = ['mode' => 'insert', 'appointment' => ['id' => $id, 'tenant_id' => $a['tenant'], 'patient_id' => $a['patient']['id'], 'doctor_id' => $did, 'branch_id' => $a['branch'], 'service_id' => $a['service'], 'room_id' => $rid, 'reservation_id' => $res, 'starts_at' => $lo, 'ends_at' => $hi],
                        'reservation' => ['id' => $res, 'tenant_id' => $a['tenant'], 'doctor_id' => $did, 'branch_id' => $a['branch'], 'room_id' => $rid, 'appointment_id' => $id, 'starts_at' => $lo, 'ends_at' => $hi]];
                } else {
                    $lo = '2030-01-07T06:00:00Z';
                    $hi = '2030-01-07T06:30:00Z';
                    $db->table('appointments')->where('id', $pairs[0]['id'])->update(['starts_at' => $lo, 'ends_at' => $hi]);
                    $db->table('resource_reservations')->where('id', $pairs[0]['res'])->update(['starts_at' => $lo, 'ends_at' => $hi]);
                    $input = ['mode' => 'update', ...$pairs[1], 'lo' => $lo, 'hi' => $hi];
                }
                $p = new Process([PHP_BINARY, '-r', $worker], base_path(), ['APP_ENV' => 'testing']);
                $p->setInput(json_encode([...$input, 'tag' => $tag]));
                $p->setTimeout(20);
                $p->start();
                $deadline = microtime(true) + 10;
                $waiting = false;
                do {
                    $db->select('SELECT pg_stat_clear_snapshot()');
                    $waiting = $db->selectOne("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock' AND query ILIKE '%resource_reservations%') AS yes", [$tag])->yes;
                    if ($waiting) {
                        break;
                    }usleep(30000);
                } while (microtime(true) < $deadline);
                $this->assertTrue($waiting, 'Independent transaction must block inside the reservation GiST write, not on the tenant command mutex.');
                $db->commit();
                $p->wait();
                $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
                $r = json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('23P01', $r['state']);
                if ($mode !== 'update') {
                    $this->assertSame($mode, $r['constraint']);
                }
            } finally {
                if ($db->transactionLevel()) {
                    $db->rollBack();
                }if ($p?->isRunning()) {
                    $p->stop();
                }
            }
        }
        $this->assertSame(2, $db->table('appointments')->count());
        $this->assertSame(2, $db->table('resource_reservations')->count());
        $this->assertSqlState('23514', fn () => $db->table('resource_reservations')->where('id', $pairs[0]['res'])->update(['ends_at' => '2030-01-07T06:00:00Z']));
    }

    public function test_doctor_own_appointments_visits_patient_access_and_branch_revocation(): void
    {
        $ids = $this->fixture();
        $a = $this->reference($ids['a']);
        $first = $this->command($a);
        $db = DB::connection('migration');
        $mid = (string) Str::uuid();
        $db->table('tenant_memberships')->insert(['id' => $mid, 'tenant_id' => $a['tenant'], 'user_id' => $ids['b']['user'], 'role_id' => $a['role']]);
        $db->table('membership_branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $a['tenant'], 'membership_id' => $mid, 'branch_id' => $a['branch']]);
        $d = $a['doctor'];
        unset($d['id']);
        $d['membership_id'] = $mid;
        $doc = $this->scheduleCommand($a, 'doctors', $d);
        $room = $this->scheduleCommand($a, 'rooms', ['branch_id' => $a['branch'], 'number' => '102', 'service_type' => 'consultation', 'active' => true, 'availability' => 'available']);
        $this->scheduleCommand($a, 'schedules', [...$this->rule($a), 'doctor_id' => $doc['id'], 'room_id' => $room['id']]);
        $p = app(Patients::class)->command($this->actor($a['user']), $a['tenant'], 'create', null, ['name' => 'Synthetic second patient', 'date_of_birth' => '1991-01-01', 'branch_id' => $a['branch']], (string) Str::uuid());
        $second = $this->command($a, data: [...$this->data($a), 'patient_id' => $p['patient']['id'], 'doctor_id' => $doc['id'], 'room_id' => $room['id']]);
        foreach (['patients.view', 'appointments.view', 'visits.view', 'appointments.update'] as $code) {
            $this->grant($a, $code, 'own');
        }
        $svc = app(Appointments::class);
        $actor = $this->actor($a['user']);
        $this->assertSame(1, $svc->listing($actor, $a['tenant'], 'appointments', [])['meta']['total']);
        $this->assertSame(1, app(Patients::class)->listing($actor, $a['tenant'], [])['meta']['total']);
        try {
            $svc->show($actor, $a['tenant'], 'appointments', $second['id']);
            $this->fail('Another doctor is hidden.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }
        $this->denied(fn () => $this->command($a, 'reschedule', $first['id'], [...$this->reschedule($a), 'doctor_id' => $doc['id'], 'room_id' => $room['id']]), 'forbidden');
        $att = $this->command($a, 'walk_in', data: $this->walk($a));
        $this->command($a, 'cancel', $first['id'], ['expected_version' => 1]);
        $this->assertSame(1, app(Patients::class)->listing($actor, $a['tenant'], [])['meta']['total']);
        $this->assertSame(1, $svc->listing($actor, $a['tenant'], 'visits', [])['meta']['total']);
        app(Queues::class)->command($actor, $a['tenant'], 'cancel', $att['ticket']['id'], ['expected_version' => 1], (string) Str::uuid());
        $this->assertSame(0, app(Patients::class)->listing($actor, $a['tenant'], [])['meta']['total']);
        $db->table('membership_branches')->where('membership_id', $a['membership'])->delete();
        $this->assertSame(0, $svc->listing($actor, $a['tenant'], 'appointments', [])['meta']['total']);
        $this->assertSame(0, $svc->listing($actor, $a['tenant'], 'visits', [])['meta']['total']);
        $this->grant($a, 'patients.view', null);
        try {
            app(Patients::class)->listing($actor, $a['tenant'], []);
            $this->fail('Revoked view must fail.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_parallel_walkin_duplicate_and_counter_allocation(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $req = ['action' => 'walk_in', 'id' => null, 'data' => $this->walk($a), 'key' => (string) Str::uuid()];
        $r = $this->race($a, [$req, [...$req, 'key' => (string) Str::uuid()]]);
        $codes = array_column($r, 'status');
        sort($codes);
        $this->assertSame([201, 409], $codes);
        $others = [];
        foreach ([2, 3] as $n) {
            $p = app(Patients::class)->command($this->actor($a['user']), $a['tenant'], 'create', null, ['name' => 'Synthetic patient '.$n, 'date_of_birth' => '1990-01-01', 'branch_id' => $a['branch']], (string) Str::uuid());
            $others[] = [...$req, 'key' => (string) Str::uuid(), 'data' => [...$this->walk($a), 'patient_id' => $p['patient']['id']]];
        }
        $r = $this->race($a, $others);
        $this->assertSame([201, 201], array_column($r, 'status'));
        $numbers = array_map(fn ($v) => $v['data']['ticket']['number'], $r);
        sort($numbers);
        $this->assertSame([2, 3], $numbers);
        $db = DB::connection('migration');
        $this->assertSame(3, $db->table('queue_counters')->value('value'));
        $this->assertSame(3, $db->table('visits')->count());
        $this->assertSame(0, $db->table('resource_reservations')->count());
    }

    public function test_optimistic_version_is_rechecked_after_appointment_row_lock(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $appt = $this->command($a);
        $db = DB::connection('migration');
        $db->beginTransaction();
        $db->table('appointments')->where('id', $appt['id'])->lockForUpdate()->first();
        $code = <<<'WORKER'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$i=json_decode(stream_get_contents(STDIN),true);Illuminate\Support\Facades\DB::select("SELECT set_config('application_name','appointment-row-lock-test',false)");
$actor=(new App\Modules\Identity\Models\User)->forceFill(['id'=>$i['user'],'credential_version'=>1]);try{app(App\Modules\Appointments\Services\Appointments::class)->command($actor,$i['tenant'],'reschedule',$i['id'],$i['data'],$i['key']);echo 'updated';}catch(App\Modules\Access\Services\GovernanceDenied $e){echo $e->reason;}
WORKER;
        $p = new Process([PHP_BINARY, '-r', $code], base_path(), ['APP_ENV' => 'testing']);
        $p->setInput(json_encode(['user' => $a['user'], 'tenant' => $a['tenant'], 'id' => $appt['id'], 'data' => $this->reschedule($a), 'key' => (string) Str::uuid()]));
        $p->setTimeout(20);
        $p->start();
        try {
            $blocked = false;
            $deadline = microtime(true) + 10;
            do {
                $blocked = DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE application_name='appointment-row-lock-test' AND wait_event_type='Lock') AS yes")->yes;
                if ($blocked) {
                    break;
                }usleep(30000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($blocked);
            $db->table('appointments')->where('id', $appt['id'])->update(['version' => 2]);
            $db->commit();
            $p->wait();
            $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
            $this->assertSame('conflict', $p->getOutput());
            $this->assertSame(1, $db->table('operation_idempotency')->count());
            $this->assertSame(0, $db->table('operation_events')->where('action', 'reschedule')->count());
            $this->assertSame(2, $db->table('appointments')->value('version'));
        } finally {
            if ($db->transactionLevel()) {
                $db->rollBack();
            }if ($p->isRunning()) {
                $p->stop();
            }
        }
    }
}
