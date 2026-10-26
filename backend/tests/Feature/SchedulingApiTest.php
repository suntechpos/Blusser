<?php

namespace Tests\Feature;

use App\Modules\Access\Services\GovernanceDenied;
use App\Modules\Patients\Services\Patients;
use App\Modules\Scheduling\Services\Availability;
use App\Modules\Scheduling\Services\Scheduling;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Process\Process;
use Tests\FoundationTestCase;

final class SchedulingApiTest extends FoundationTestCase
{
    private function command(array $a, string $kind, array $payload, ?string $id = null, ?string $key = null): array
    {
        return app(Scheduling::class)->command($this->actor($a['user']), $a['tenant'], $kind, $id ? 'update' : 'create', $id, $payload, $key ?? (string) Str::uuid());
    }

    private function reference(array $a): array
    {
        $specialty = $this->command($a, 'specialties', ['code' => 'synthetic', 'name' => 'Synthetic specialty', 'active' => true]);
        $doctor = $this->command($a, 'doctors', ['membership_id' => $a['membership'], 'specialty_id' => $specialty['id'], 'branch_ids' => [$a['branch']], 'service_ids' => [$a['service']], 'active' => true]);
        $room = $this->command($a, 'rooms', ['branch_id' => $a['branch'], 'number' => '101', 'service_type' => 'consultation', 'active' => true, 'availability' => 'available']);

        return [...$a, 'specialty' => $specialty, 'doctor' => $doctor, 'room' => $room];
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

    public function test_real_cookie_http_crud_contract_csrf_and_idempotency(): void
    {
        $ids = $this->fixture();
        $a = $ids['a'];
        $jar = $this->clinicSession($a);
        $data = ['code' => 'synthetic', 'name' => 'Synthetic specialty', 'active' => true];
        $path = '/api/v1/clinic/specialties';
        $this->browser($jar, 'POST', $path, $data, false, (string) Str::uuid())->assertStatus(419);
        $this->browser($jar, 'POST', $path, $data)->assertUnprocessable();
        $key = (string) Str::uuid();
        $s = $this->browser($jar, 'POST', $path, $data, key: $key)->assertCreated()->assertExactJson(['id' => DB::connection('migration')->table('specialties')->value('id'), 'name' => 'Synthetic specialty'])->json();
        $this->browser($jar, 'POST', $path, $data, key: $key)->assertCreated()->assertJsonPath('id', $s['id']);
        $this->browser($jar, 'POST', $path, [...$data, 'name' => 'Different'], key: $key)->assertConflict();
        $this->browser($jar, 'PUT', $path.'/'.$s['id'], [...$data, 'name' => 'Updated synthetic'], key: (string) Str::uuid())->assertOk();
        $doctorData = ['membership_id' => $a['membership'], 'specialty_id' => $s['id'], 'branch_ids' => [$a['branch']], 'service_ids' => [$a['service']], 'active' => true];
        $doctor = $this->browser($jar, 'POST', '/api/v1/clinic/doctors', $doctorData, key: (string) Str::uuid())->assertCreated()->json();
        $roomData = ['branch_id' => $a['branch'], 'number' => '101', 'service_type' => 'consultation', 'active' => true, 'availability' => 'available'];
        $room = $this->browser($jar, 'POST', '/api/v1/clinic/rooms', $roomData, key: (string) Str::uuid())->assertCreated()->json();
        $a = [...$a, 'doctor' => $doctor, 'room' => $room];
        $rule = $this->rule($a);
        $schedule = $this->browser($jar, 'POST', '/api/v1/clinic/schedules', $rule, key: (string) Str::uuid())->assertCreated()->json();
        foreach (['doctors' => $doctor, 'rooms' => $room, 'schedules' => $schedule] as $kind => $resource) {
            $this->browser($jar, 'GET', '/api/v1/clinic/'.$kind.'/'.$resource['id'])->assertOk()->assertJsonPath('id', $resource['id']);
            $this->browser($jar, 'GET', '/api/v1/clinic/'.$kind)->assertOk()->assertJsonPath('meta.total', 1);
        }
        $this->browser($jar, 'PUT', '/api/v1/clinic/doctors/'.$doctor['id'], $doctorData, key: (string) Str::uuid())->assertOk();
        $this->browser($jar, 'PUT', '/api/v1/clinic/rooms/'.$room['id'], $roomData, key: (string) Str::uuid())->assertOk();
        $this->browser($jar, 'PUT', '/api/v1/clinic/schedules/'.$schedule['id'], [...$rule, 'slot_minutes' => 60], key: (string) Str::uuid())->assertOk();
        $url = '/api/v1/clinic/doctors/'.$doctor['id'].'/availability?'.http_build_query($this->filters($a));
        $this->browser($jar, 'GET', $url)->assertOk()->assertHeader('X-Availability-Mode', 'current-occupancy-hint')->assertJsonPath('meta.total', 8)->assertJsonPath('data.0.starts_at', '2030-01-07T04:00:00Z');
        $exceptionData = ['doctor_id' => $doctor['id'], 'branch_id' => $a['branch'], 'local_date' => '2030-01-07', 'kind' => 'closed', 'replacement' => null];
        $e = $this->browser($jar, 'POST', '/api/v1/clinic/schedule-exceptions', $exceptionData, key: (string) Str::uuid())->assertCreated()->json();
        $this->browser($jar, 'GET', '/api/v1/clinic/schedule-exceptions/'.$e['id'])->assertOk();
        $this->browser($jar, 'GET', '/api/v1/clinic/schedule-exceptions')->assertOk()->assertJsonPath('meta.total', 1);
        $this->browser($jar, 'PUT', '/api/v1/clinic/schedule-exceptions/'.$e['id'], $exceptionData, key: (string) Str::uuid())->assertOk();
        $this->browser($jar, 'GET', $url)->assertOk()->assertJsonPath('data', []);
        $this->browser($jar, 'GET', '/api/v1/clinic/rooms/'.$ids['b']['branch'])->assertNotFound();
        $this->browser($jar, 'POST', '/api/v1/clinic/rooms', [...$roomData, 'tenant_id' => $ids['b']['tenant']], key: (string) Str::uuid())->assertUnprocessable();
        $this->browser($jar, 'GET', $url.'&unexpected=1')->assertUnprocessable();
        $this->assertSame(2, DB::connection('migration')->table('scheduling_changes')->count());
        $metadata = DB::connection('migration')->table('audit_logs')->where('resource_type', 'specialties')->value('metadata');
        $this->assertStringNotContainsString('Synthetic specialty', $metadata);
        $this->browser($jar, 'POST', '/api/v1/auth/logout')->assertNoContent();
        $this->browser($jar, 'GET', '/api/v1/clinic/doctors')->assertUnauthorized();
    }

    public function test_runtime_forced_rls_dml_definers_and_cross_tenant_references(): void
    {
        $ids = $this->fixture();
        $a = $this->reference($ids['a']);
        $b = $this->reference($ids['b']);
        $this->command($a, 'schedules', $this->rule($a));
        $this->command($b, 'schedules', $this->rule($b));
        $tables = ['specialties', 'doctor_profiles', 'doctor_services', 'doctor_branches', 'rooms', 'schedule_rules', 'schedule_breaks', 'schedule_exceptions', 'schedule_exception_breaks', 'scheduling_changes', 'scheduling_idempotency'];
        foreach ($tables as $table) {
            $state = DB::selectOne('SELECT relrowsecurity,relforcerowsecurity FROM pg_class WHERE oid=?::regclass', [$table]);
            $this->assertTrue($state->relrowsecurity && $state->relforcerowsecurity);
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $privilege) {
                $this->assertFalse(DB::selectOne('SELECT has_table_privilege(current_user,?,?) AS allowed', [$table, $privilege])->allowed);
            }
        }
        foreach (['specialties', 'doctor_profiles', 'rooms', 'schedule_rules', 'doctor_services', 'doctor_branches', 'schedule_breaks'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->assertSqlState('42501', fn () => DB::table('rooms')->insert(['id' => (string) Str::uuid()]));
        foreach (['scheduling_validate_payload(text,jsonb)', 'scheduling_interval_conflict(jsonb,uuid,date)', 'doctor_branch_live(uuid,uuid)'] as $function) {
            $this->assertFalse(DB::selectOne('SELECT has_function_privilege(current_user,?,?) AS allowed', ['governance.'.$function, 'EXECUTE'])->allowed);
        }
        app(Scheduling::class)->context($this->actor($a['user']), $a['tenant'], function () use ($b) {
            foreach (['specialties', 'doctor_profiles', 'rooms', 'schedule_rules', 'doctor_services', 'doctor_branches', 'schedule_breaks'] as $table) {
                $this->assertSame(1, DB::table($table)->count());
                $this->assertSame(0, DB::table($table)->where('tenant_id', $b['tenant'])->count());
            }
            DB::select("SELECT set_config('app.credential_version','999',true)");
            $this->assertSame(0, DB::table('doctor_profiles')->count());
        });
        foreach (['membership_id' => $b['membership'], 'specialty_id' => $b['specialty']['id'], 'branch_ids' => [$b['branch']], 'service_ids' => [$b['service']]] as $key => $foreign) {
            $data = $a['doctor'];
            unset($data['id']);
            $data[$key] = $foreign;
            $this->denied(fn () => $this->command($a, 'doctors', $data, $a['doctor']['id']), 'not_found');
        }
        foreach (['doctor_id' => $b['doctor']['id'], 'branch_id' => $b['branch'], 'room_id' => $b['room']['id']] as $key => $foreign) {
            $this->denied(fn () => $this->command($a, 'schedules', [...$this->rule($a), $key => $foreign]), 'not_found');
        }
        $owner = DB::connection('migration');
        $this->assertSqlState('23503', fn () => $owner->table('doctor_services')->insert(['tenant_id' => $a['tenant'], 'doctor_id' => $a['doctor']['id'], 'service_id' => $b['service']]));
        $this->assertSqlState('23503', fn () => $owner->table('doctor_profiles')->insert(['tenant_id' => $a['tenant'], 'membership_id' => $b['membership'], 'specialty_id' => $a['specialty']['id'], 'active' => true]));
        $this->assertSqlState('23503', fn () => $owner->table('doctor_profiles')->where('id', $a['doctor']['id'])->update(['specialty_id' => $b['specialty']['id']]));
        $this->assertSqlState('23503', fn () => $owner->table('schedule_rules')->insert(['tenant_id' => $a['tenant'], ...$this->ruleRow($a), 'room_id' => $b['room']['id']]));
    }

    private function ruleRow(array $a): array
    {
        $v = $this->rule($a);
        unset($v['breaks']);

        return $v;
    }

    public function test_assigned_branch_projections_counts_filters_and_live_revocation(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $second = $this->branch($a);
        $doctor = $a['doctor'];
        unset($doctor['id']);
        $doctor['branch_ids'][] = $second;
        $this->command($a, 'doctors', $doctor, $a['doctor']['id']);
        $this->command($a, 'rooms', ['branch_id' => $second, 'number' => '101', 'service_type' => 'consultation', 'active' => true, 'availability' => 'available']);
        $this->command($a, 'schedules', $this->rule($a));
        DB::connection('migration')->table('membership_branches')->where('membership_id', $a['membership'])->where('branch_id', $second)->delete();
        foreach (['doctors.view', 'rooms.view', 'schedules.view'] as $code) {
            $this->grant($a, $code, 'assigned_branches');
        }
        $svc = app(Scheduling::class);
        $actor = $this->actor($a['user']);
        $this->assertSame([$a['branch']], $svc->show($actor, $a['tenant'], 'doctors', $a['doctor']['id'])['branch_ids']);
        $this->assertSame(1, $svc->listing($actor, $a['tenant'], 'rooms', [])['meta']['total']);
        $this->assertSame(0, $svc->listing($actor, $a['tenant'], 'doctors', ['branch_id' => $second])['meta']['total']);
        $this->assertSame(16, $this->slots($a)['meta']['total']);
        DB::connection('migration')->table('membership_branches')->where('membership_id', $a['membership'])->delete();
        $this->assertSame(0, $svc->listing($actor, $a['tenant'], 'doctors', [])['meta']['total']);
        $this->assertSame(0, $svc->listing($actor, $a['tenant'], 'schedules', [])['meta']['total']);
        $this->denied(fn () => $this->command($a, 'schedules', $this->rule($a)), 'forbidden');
    }

    public function test_membership_role_user_doctor_service_room_and_specialty_disable_stop_slots(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $this->command($a, 'schedules', $this->rule($a));
        $db = DB::connection('migration');
        foreach ([['doctor_profiles', $a['doctor']['id'], 'active', false], ['services', $a['service'], 'active', false], ['rooms', $a['room']['id'], 'active', false], ['rooms', $a['room']['id'], 'availability', 'unavailable']] as [$table,$id,$column,$value]) {
            $old = $db->table($table)->where('id', $id)->value($column);
            $db->table($table)->where('id', $id)->update([$column => $value, ...($column === 'must_change_password' ? ['temporary_password_expires_at' => now()->addDay()] : [])]);
            $this->assertSame([], $this->slots($a)['data']);
            $db->table($table)->where('id', $id)->update([$column => $old, ...($column === 'must_change_password' ? ['temporary_password_expires_at' => null] : [])]);
        }
        // Catalog specialty is optional for a configured doctor: disabling it no longer hides valid slots.
        $db->table('specialties')->where('id', $a['specialty']['id'])->update(['active' => false]);
        $this->assertNotEmpty($this->slots($a)['data']);
        $db->table('specialties')->where('id', $a['specialty']['id'])->update(['active' => true]);
        // Separate doctor from actor so revoking the doctor's membership doesn't also revoke the caller.
        $other = $this->otherEmployee($a);
        $doc = $a['doctor'];
        unset($doc['id']);
        $doc['membership_id'] = $other['membership'];
        $d = $this->command($a, 'doctors', $doc);
        $new = [...$a, 'doctor' => $d, 'room' => [...$a['room'], 'id' => $this->command($a, 'rooms', ['branch_id' => $a['branch'], 'number' => '102', 'service_type' => 'consultation', 'active' => true, 'availability' => 'available'])['id']]];
        $this->command($a, 'schedules', [...$this->rule($new), 'local_start' => '18:00', 'local_end' => '19:00', 'breaks' => []]);
        foreach ([['tenant_memberships', $other['membership'], 'active', false], ['users', $other['user'], 'active', false], ['users', $other['user'], 'must_change_password', true], ['tenant_roles', $other['role'], 'active', false]] as [$table,$id,$column,$value]) {
            $old = $db->table($table)->where('id', $id)->value($column);
            $db->table($table)->where('id', $id)->update([$column => $value, ...($column === 'must_change_password' ? ['temporary_password_expires_at' => now()->addDay()] : [])]);
            if ($column === 'must_change_password') {
                // Password rotation gates login, not receptionist booking eligibility.
                $this->assertNotEmpty($this->slots($new)['data']);
            } else {
                $this->assertSame([], $this->slots($new)['data']);
            }
            $db->table($table)->where('id', $id)->update([$column => $old, ...($column === 'must_change_password' ? ['temporary_password_expires_at' => null] : [])]);
        }
    }

    private function otherEmployee(array $a): array
    {
        $db = DB::connection('migration');
        $user = (string) Str::uuid();
        $membership = (string) Str::uuid();
        $role = (string) Str::uuid();
        $db->table('users')->insert(['id' => $user, 'login' => 'synthetic.'.$user, 'name' => 'Synthetic doctor', 'password_hash' => $db->table('users')->where('id', $a['user'])->value('password_hash'), 'must_change_password' => false]);
        $db->table('tenant_roles')->insert(['id' => $role, 'tenant_id' => $a['tenant'], 'code' => $role, 'name' => 'Synthetic doctor role']);
        $db->table('tenant_memberships')->insert(['id' => $membership, 'tenant_id' => $a['tenant'], 'user_id' => $user, 'role_id' => $role]);
        $db->table('membership_branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $a['tenant'], 'membership_id' => $membership, 'branch_id' => $a['branch']]);

        return ['user' => $user, 'membership' => $membership, 'role' => $role];
    }

    public function test_rooms_unique_per_branch_and_schedule_identity_and_history(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $room = $a['room'];
        unset($room['id']);
        $this->denied(fn () => $this->command($a, 'rooms', $room), 'conflict');
        $second = $this->branch($a);
        $this->assertNotEmpty($this->command($a, 'rooms', [...$room, 'branch_id' => $second])['id']);
        $this->denied(fn () => $this->command($a, 'rooms', [...$room, 'branch_id' => $second], $a['room']['id']), 'conflict');
        $rule = $this->command($a, 'schedules', $this->rule($a));
        $this->denied(fn () => $this->command($a, 'rooms', [...$room, 'active' => false], $a['room']['id']), 'conflict');
        $this->command($a, 'schedules', [...$this->rule($a), 'slot_minutes' => 60], $rule['id']);
        $old = json_decode(DB::connection('migration')->table('scheduling_changes')->value('previous'), true);
        $this->assertSame(30, $old['slot_minutes']);
        $this->assertCount(1, $old['breaks']);
        $this->assertSame(8, $this->slots($a)['meta']['total']);
    }

    public function test_schedule_conflicts_breaks_exceptions_and_override(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $rule = $this->rule($a);
        $this->command($a, 'schedules', $rule);
        $this->denied(fn () => $this->command($a, 'schedules', $rule), 'conflict');
        foreach ([['breaks' => [['local_start' => '08:00', 'local_end' => '10:00']]], ['breaks' => [['local_start' => '12:00', 'local_end' => '14:00'], ['local_start' => '13:00', 'local_end' => '15:00']]], ['local_end' => '09:00'], ['weekday' => 0], ['breaks' => [['local_start' => '13:00', 'local_end' => '14:00', 'tenant_id' => $a['tenant']]]]] as $bad) {
            $this->denied(fn () => $this->command($a, 'schedules', [...$rule, ...$bad]), 'invalid_input');
        }
        $this->assertSame(16, $this->slots($a)['meta']['total']);
        $ex = ['doctor_id' => $a['doctor']['id'], 'branch_id' => $a['branch'], 'local_date' => '2030-01-07', 'kind' => 'closed', 'replacement' => null];
        $e = $this->command($a, 'schedule-exceptions', $ex);
        $this->assertSame([], $this->slots($a)['data']);
        $replacement = [...$rule, 'local_start' => '10:00', 'local_end' => '12:00', 'breaks' => []];
        $this->command($a, 'schedule-exceptions', [...$ex, 'kind' => 'replace', 'replacement' => $replacement], $e['id']);
        $slots = $this->slots($a);
        $this->assertSame(4, $slots['meta']['total']);
        $this->assertSame('2030-01-07T05:00:00Z', $slots['data'][0]['starts_at']);
        $this->denied(fn () => $this->command($a, 'schedule-exceptions', [...$ex, 'kind' => 'replace', 'replacement' => [...$replacement, 'weekday' => 2]], $e['id']), 'invalid_input');
        $this->denied(fn () => $this->command($a, 'schedule-exceptions', $ex), 'conflict');
        $this->assertSame(1, DB::connection('migration')->table('scheduling_changes')->count());
    }

    public function test_timezone_dst_boundaries_range_and_duration_override(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $db = DB::connection('migration');
        $this->assertSame('Asia/Tashkent', $db->table('tenants')->where('id', $a['tenant'])->value('timezone'));
        $this->assertSqlState('23514', fn () => $db->table('tenants')->where('id', $a['tenant'])->update(['timezone' => '+05:00']));
        $this->assertSqlState('23514', fn () => $db->table('branches')->where('id', $a['branch'])->update(['timezone' => 'invented/zone']));
        $db->table('branches')->where('id', $a['branch'])->update(['timezone' => 'America/New_York']);
        $rule = [...$this->rule($a), 'weekday' => 7, 'local_start' => '01:00', 'local_end' => '04:00', 'breaks' => []];
        $id = $this->command($a, 'schedules', $rule)['id'];
        foreach ([['2030-03-10T00:00:00-05:00', '2030-03-11T00:00:00-04:00'], ['2030-11-03T00:00:00-04:00', '2030-11-04T00:00:00-05:00']] as [$from,$to]) {
            try {
                $this->slots($a, $this->filters($a, $from, $to));
                $this->fail('DST ambiguity/gap must be rejected.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->command($a, 'schedules', [...$rule, 'local_start' => '09:00', 'local_end' => '11:00'], $id);
        $db->table('doctor_services')->where('doctor_id', $a['doctor']['id'])->update(['duration_minutes' => 60]);
        $slots = $this->slots($a, $this->filters($a, '2030-03-10T00:00:00-05:00', '2030-03-11T00:00:00-04:00'));
        $this->assertSame(2, $slots['meta']['total']);
        $this->assertSame('2030-03-10T13:00:00Z', $slots['data'][0]['starts_at']);
        try {
            $this->slots($a, $this->filters($a, '2030-01-01T00:00:00Z', '2030-03-01T00:00:00Z'));
            $this->fail('Range must be bounded.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_replay_reauthorizes_permission_credentials_and_platform_and_patients_own_stays_closed(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $payload = $this->rule($a);
        $key = (string) Str::uuid();
        $first = $this->command($a, 'schedules', $payload, key: $key);
        $this->assertSame($first['id'], $this->command($a, 'schedules', $payload, key: $key)['id']);
        $this->denied(fn () => $this->command($a, 'schedules', [...$payload, 'slot_minutes' => 60], key: $key), 'conflict');
        $this->grant($a, 'schedules.create', null);
        $this->denied(fn () => $this->command($a, 'schedules', $payload, key: $key), 'forbidden');
        $this->assertSame(1, DB::connection('migration')->table('scheduling_idempotency')->where('operation', 'schedules.create')->count());
        $patient = app(Patients::class)->command($this->actor($a['user']), $a['tenant'], 'create', null, ['name' => 'Synthetic patient', 'date_of_birth' => '1990-01-01', 'branch_id' => $a['branch']], (string) Str::uuid());
        $this->grant($a, 'patients.view', 'own');
        $list = app(Patients::class)->listing($this->actor($a['user']), $a['tenant'], []);
        $this->assertSame([], $list['data']);
        DB::connection('migration')->table('users')->where('id', $a['user'])->update(['credential_version' => 2]);
        $this->denied(fn () => $this->command($a, 'rooms', ['branch_id' => $a['branch'], 'number' => '999', 'service_type' => 'consultation', 'active' => true, 'availability' => 'available']), 'forbidden');
        DB::connection('migration')->table('users')->where('id', $a['user'])->update(['credential_version' => 1, 'is_platform_admin' => true]);
        $this->denied(fn () => $this->command($a, 'specialties', ['code' => 'new', 'name' => 'Synthetic', 'active' => true]), 'forbidden');
        try {
            app(Scheduling::class)->context($this->actor($a['user']), $a['tenant'], fn () => $this->fail('Platform must not enter tenant context.'));
            $this->fail('Platform denied.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }
    }

    public function test_migration_down_refuses_data_and_is_repeatable_without_data(): void
    {
        $a = $this->fixture()['a'];
        $db = DB::connection('migration');
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'migration', '--step' => $this->migrationStepsFrom('2026_10_16_000008'), '--force' => true]));
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'migration', '--force' => true]));
        $this->assertSame('Asia/Tashkent', $db->table('tenants')->where('id', $a['tenant'])->value('timezone'));
        $this->reference($a);
        try {
            Artisan::call('migrate:rollback', ['--database' => 'migration', '--step' => $this->migrationStepsFrom('2026_10_16_000008'), '--force' => true]);
            $this->fail('No destructive rollback.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('forward migration', $e->getMessage());
        } finally {
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'migration', '--force' => true]));
        }
        $this->assertSame(1, $db->table('doctor_profiles')->count());
    }

    public function test_parallel_schedule_mutations_serialize_conflicts_and_same_key_replays(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $worker = <<<'WORKER'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$p=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
Illuminate\Support\Facades\DB::select("SELECT set_config('application_name',?,false)",[$p['label']]);
$actor=(new App\Modules\Identity\Models\User)->forceFill(['id'=>$p['user'],'credential_version'=>1]);
try {
 $v=app(App\Modules\Scheduling\Services\Scheduling::class)->command($actor,$p['tenant'],'schedules','create',null,$p['data'],$p['key']);
 echo json_encode(['status'=>201,'id'=>$v['id']]);
} catch (App\Modules\Access\Services\GovernanceDenied $e) { echo json_encode(['status'=>$e->reason==='conflict'?409:403]); }
WORKER;
        foreach ([false, true] as $sameKey) {
            $db = DB::connection('migration');
            $db->beginTransaction();
            $db->table('tenants')->where('id', $a['tenant'])->lockForUpdate()->first();
            $processes = [];
            $labels = [];
            $key = (string) Str::uuid();
            try {
                foreach ([0, 1] as $index) {
                    $label = 'schedule-race-'.Str::uuid();
                    $labels[] = $label;
                    $data = $sameKey ? [...$this->rule($a), 'local_start' => '18:00', 'local_end' => '19:00', 'breaks' => []] : $this->rule($a);
                    $p = new Process([PHP_BINARY, '-r', $worker], base_path(), ['APP_ENV' => 'testing']);
                    $p->setInput(json_encode(['label' => $label, 'user' => $a['user'], 'tenant' => $a['tenant'], 'data' => $data, 'key' => $sameKey ? $key : (string) Str::uuid()]));
                    $p->setTimeout(20);
                    $p->start();
                    $processes[] = $p;
                }
                $deadline = microtime(true) + 10;
                $waiting = 0;
                do {
                    $waiting = (int) DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE application_name IN (?,?) AND wait_event_type='Lock'", $labels)->n;
                    if ($waiting === 2) {
                        break;
                    }
                    usleep(50000);
                } while (microtime(true) < $deadline);
                $this->assertSame(2, $waiting, 'Both independent runtime transactions must reach a real PostgreSQL lock.');
                $db->commit();
                $results = [];
                foreach ($processes as $p) {
                    $p->wait();
                    $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
                    $results[] = json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                }
                $codes = array_column($results, 'status');
                sort($codes);
                $this->assertSame($sameKey ? [201, 201] : [201, 409], $codes);
                if ($sameKey) {
                    $this->assertSame($results[0]['id'], $results[1]['id']);
                }
            } finally {
                if ($db->transactionLevel() > 0) {
                    $db->rollBack();
                }
                foreach ($processes as $p) {
                    if ($p->isRunning()) {
                        $p->stop();
                    }
                }
            }
        }
        $this->assertSame(2, DB::connection('migration')->table('schedule_rules')->count());
        $this->assertSame(2, DB::connection('migration')->table('scheduling_idempotency')->where('operation', 'schedules.create')->count());
        $this->assertSame(2, DB::connection('migration')->table('audit_logs')->where('action', 'schedules.create')->count());
    }

    public function test_doctor_multi_branch_assignment_and_room_collision_across_doctors(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $second = $this->branch($a);
        $doctor = $a['doctor'];
        unset($doctor['id']);
        $doctor['branch_ids'][] = $second;
        $this->command($a, 'doctors', $doctor, $a['doctor']['id']);
        $this->assertSame(2, count(app(Scheduling::class)->show($this->actor($a['user']), $a['tenant'], 'doctors', $a['doctor']['id'])['branch_ids']));
        $room = $this->command($a, 'rooms', ['branch_id' => $second, 'number' => '101', 'service_type' => 'consultation', 'active' => true, 'availability' => 'available']);
        $this->command($a, 'schedules', $this->rule($a));
        $this->denied(fn () => $this->command($a, 'schedules', [...$this->rule($a), 'branch_id' => $second, 'room_id' => $room['id']]), 'conflict');
        $other = $this->otherEmployee($a);
        $doctor['membership_id'] = $other['membership'];
        $doctor['branch_ids'] = [$a['branch']];
        $d = $this->command($a, 'doctors', $doctor);
        $this->denied(fn () => $this->command($a, 'schedules', [...$this->rule($a), 'doctor_id' => $d['id']]), 'conflict');
        $this->command($a, 'schedules', [...$this->rule($a), 'branch_id' => $second, 'room_id' => $room['id'], 'weekday' => 2]);
        $slots = $this->slots($a, [...$this->filters($a, '2030-01-08T00:00:00+05:00', '2030-01-09T00:00:00+05:00'), 'branch_id' => $second]);
        $this->assertSame(16, $slots['meta']['total']);
        $this->denied(fn () => $this->command($a, 'doctors', [...$a['doctor'], 'branch_ids' => [$second]], $a['doctor']['id']), 'invalid_input');
        $data = $a['doctor'];
        unset($data['id']);
        $data['branch_ids'] = [$second];
        $this->denied(fn () => $this->command($a, 'doctors', $data, $a['doctor']['id']), 'conflict');
    }

    public function test_cross_timezone_and_exception_resource_conflicts_are_atomic(): void
    {
        $a = $this->reference($this->fixture()['a']);
        $second = $this->branch($a);
        $doctor = $a['doctor'];
        unset($doctor['id']);
        $doctor['branch_ids'][] = $second;
        $this->command($a, 'doctors', $doctor, $a['doctor']['id']);
        DB::connection('migration')->table('branches')->where('id', $second)->update(['timezone' => 'America/New_York']);
        $room = $this->command($a, 'rooms', ['branch_id' => $second, 'number' => '101', 'service_type' => 'consultation', 'active' => true, 'availability' => 'available']);
        $rule = [...$this->rule($a), 'effective_to' => '2030-01-31', 'local_start' => '18:00', 'local_end' => '19:00', 'breaks' => []];
        $this->command($a, 'schedules', $rule);
        // Tashkent 18:00 == New York 08:00 in January.
        $this->denied(fn () => $this->command($a, 'schedules', [...$rule, 'branch_id' => $second, 'room_id' => $room['id'], 'local_start' => '08:00', 'local_end' => '09:00']), 'conflict');
        $this->assertSame(1, DB::connection('migration')->table('schedule_rules')->count());
        $this->assertSame(1, DB::connection('migration')->table('scheduling_idempotency')->where('operation', 'schedules.create')->count());
        $ex = ['doctor_id' => $a['doctor']['id'], 'branch_id' => $second, 'local_date' => '2030-01-07', 'kind' => 'replace', 'replacement' => [...$rule, 'branch_id' => $second, 'room_id' => $room['id'], 'local_start' => '08:00', 'local_end' => '09:00']];
        $this->denied(fn () => $this->command($a, 'schedule-exceptions', $ex), 'conflict');
        $this->assertSame(0, DB::connection('migration')->table('schedule_exceptions')->count());
        $this->command($a, 'schedule-exceptions', [...$ex, 'replacement' => [...$ex['replacement'], 'local_start' => '10:00', 'local_end' => '11:00']]);
        $other = $this->otherEmployee($a);
        DB::connection('migration')->table('membership_branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $a['tenant'], 'membership_id' => $other['membership'], 'branch_id' => $second]);
        $otherDoctor = $this->command($a, 'doctors', [...$doctor, 'membership_id' => $other['membership'], 'branch_ids' => [$second]]);
        $this->denied(fn () => $this->command($a, 'schedules', [...$rule, 'branch_id' => $second, 'room_id' => $room['id'], 'local_start' => '10:00', 'local_end' => '11:00', 'doctor_id' => $otherDoctor['id']]), 'conflict');
    }

    public function test_forged_context_and_accidental_dml_grant_still_fail_closed(): void
    {
        $ids = $this->fixture();
        $a = $this->reference($ids['a']);
        $b = $this->reference($ids['b']);
        $svc = app(Scheduling::class);
        $data = $a['doctor'];
        unset($data['id']);
        $this->denied(fn () => $this->command($a, 'doctors', $data), 'conflict');
        try {
            $svc->show($this->actor($a['user']), $a['tenant'], 'doctors', $b['doctor']['id']);
            $this->fail('Foreign doctor must be hidden.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $svc->context($this->actor($a['user']), $a['tenant'], function () use ($b) {
            DB::select("SELECT set_config('app.tenant_id',?,true)", [$b['tenant']]);
            $this->assertSame(0, DB::table('doctor_profiles')->count());
            $this->assertSame(0, DB::table('rooms')->count());
        });
        $db = DB::connection('migration');
        $db->statement('GRANT INSERT,UPDATE,DELETE ON rooms TO blusser_app');
        try {
            $svc->context($this->actor($a['user']), $a['tenant'], function () use ($a) {
                $this->assertSame(0, DB::table('rooms')->where('id', $a['room']['id'])->update(['number' => 'forbidden']));
                $this->assertSame(0, DB::table('rooms')->where('id', $a['room']['id'])->delete());
            });
            $this->assertSqlState('42501', fn () => app(TenantContext::class)->run($this->actor($a['user']), $a['tenant'], function () use ($a) {
                DB::select("SELECT set_config('app.credential_version','1',true)");
                DB::table('rooms')->insert(['tenant_id' => $a['tenant'], 'branch_id' => $a['branch'], 'number' => '999', 'service_type' => 'consultation', 'active' => true, 'availability' => 'available']);
            }));
        } finally {
            $db->statement('REVOKE INSERT,UPDATE,DELETE ON rooms FROM blusser_app');
        }
        $this->assertSame('101', $db->table('rooms')->where('id', $a['room']['id'])->value('number'));
    }
}
