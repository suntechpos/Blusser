<?php

namespace Tests\Feature;

use App\Modules\Access\Services\Governance;
use App\Modules\Access\Services\GovernanceDenied;
use App\Modules\Scheduling\Services\Availability;
use App\Modules\Scheduling\Services\Scheduling;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\FoundationTestCase;

final class EmployeeWorkScheduleTest extends FoundationTestCase
{
    private function setupEmployee(string $position = 'Registrator'): array
    {
        $f = $this->fixture();
        $a = $f['a'];
        $db = DB::connection('migration');
        $db->table('tenant_ceos')->insert(['tenant_id' => $a['tenant'], 'user_id' => $a['user'], 'membership_id' => $a['membership']]);
        $id = (string) Str::uuid();
        $db->table('employee_profiles')->insert(['id' => $id, 'tenant_id' => $a['tenant'], 'profile' => json_encode(['first' => 'Synthetic', 'last' => 'Employee', 'position' => $position, 'specialty' => 'Hirurg', 'branch' => $a['branch']])]);

        return [...$a, 'employee' => $id, 'other' => $f['b']];
    }

    private function days(): array
    {
        return [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '16:00', 'receives_patients' => false], ['weekday' => 2, 'start_time' => '10:00', 'end_time' => '17:00', 'receives_patients' => false]];
    }

    private function save(array $a, array $days, int $v = 0, array $removed = [], ?string $key = null): array
    {
        return json_decode(app(Governance::class)->call('employee_work_save', [$a['employee'], $a['branch'], json_encode($days), json_encode($removed), $v, $key ?? (string) Str::uuid()], $this->actor($a['user']), $a['tenant']), true);
    }

    private function read(array $a): array
    {
        return json_decode(app(Governance::class)->call('employee_work_read', [$a['employee'], $a['branch']], $this->actor($a['user']), $a['tenant']), true);
    }

    private function denied(callable $fn, string $reason): void
    {
        try {
            $fn();
            $this->fail('Expected rejection');
        } catch (GovernanceDenied $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_loginless_employees_of_all_jobs_need_no_clinical_catalog_or_membership(): void
    {
        foreach (['Registrator', 'Hamshira', 'Shifokor', 'Kassir', 'Laborant', 'Administrator', 'Boshqa'] as $position) {
            $a = $this->setupEmployee($position);
            $saved = $this->save($a, $this->days());
            $this->assertSame(1, $saved['version']);
            $this->assertCount(2, $this->read($a)['data']);
            $this->assertNull(DB::connection('migration')->table('employee_profiles')->where('id', $a['employee'])->value('membership_id'));
            $this->assertSame(0, DB::connection('migration')->table('doctor_profiles')->count());
            $this->assertSame(0, DB::connection('migration')->table('specialties')->count());
            // fixture() creates a fresh tenant pair, not a clinical dependency; clear only synthetic fixtures between jobs.
            DB::connection('migration')->statement('TRUNCATE tenants,users CASCADE');
        }
    }

    public function test_replay_versions_and_explicit_removal_protect_other_days(): void
    {
        $a = $this->setupEmployee();
        $key = (string) Str::uuid();
        $first = $this->save($a, $this->days(), 0, [], $key);
        $this->assertSame($first, $this->save($a, $this->days(), 0, [], $key));
        $this->denied(fn () => $this->save($a, $this->days(), 0), 'conflict');
        $this->denied(fn () => $this->save($a, [$this->days()[0]], 1), 'conflict');
        $this->assertCount(2, $this->read($a)['data']);
        $next = $this->save($a, [$this->days()[0]], 1, [2]);
        $this->assertCount(1, $next['data']);
        $this->assertSame($first['data'][0]['id'], $next['data'][0]['id']);
        $this->assertSame(2, $next['version']);
    }

    public function test_tenant_branch_permission_and_direct_dml_isolation(): void
    {
        $a = $this->setupEmployee();
        $this->save($a, $this->days());
        $this->denied(fn () => $this->read([...$a, 'tenant' => $a['other']['tenant'], 'user' => $a['other']['user']]), 'not_found');
        $db = DB::connection('migration');
        $otherBranch = (string) Str::uuid();
        $db->table('branches')->insert(['id' => $otherBranch, 'tenant_id' => $a['tenant'], 'code' => 'unassigned', 'name' => 'Unassigned synthetic branch']);
        $this->denied(fn () => $this->save([...$a, 'branch' => $otherBranch], $this->days()), 'not_found');
        $this->assertSame(1, $db->table('employee_work_calendars')->count());
        $p = $db->table('permissions')->where('code', 'employees.update')->value('id');
        $db->table('role_permissions')->where('role_id', $a['role'])->where('permission_id', $p)->delete();
        $this->denied(fn () => $this->save($a, $this->days(), 1), 'forbidden');
        $this->assertCount(2, $this->read($a)['data']);
        $this->denied(fn () => $this->read([...$a, 'branch' => $a['other']['branch']]), 'not_found');
        $this->assertSqlState('42501', fn () => DB::table('employee_work_schedules')->insert(['tenant_id' => $a['tenant'], 'employee_id' => $a['employee'], 'branch_id' => $a['branch'], 'weekday' => 3, 'start_time' => '09:00', 'end_time' => '17:00']));
    }

    public function test_duplicate_or_invalid_days_are_atomic(): void
    {
        $a = $this->setupEmployee();
        $this->denied(fn () => $this->save($a, [$this->days()[0], $this->days()[0]]), 'invalid_input');
        $this->denied(fn () => $this->save($a, [[...$this->days()[0], 'end_time' => '08:00']]), 'invalid_input');
        $saved = $this->read($a);
        $this->assertSame(0, $saved['version']);
        $this->assertSame([], $saved['data']);
    }

    public function test_optional_doctor_catalog_and_inactive_appointment_rules_keep_booking_validation(): void
    {
        $a = $this->setupEmployee('Shifokor');
        $service = app(Scheduling::class);
        $cmd = fn ($kind, $body, $id = null) => $service->command($this->actor($a['user']), $a['tenant'], $kind, $id ? 'update' : 'create', $id, $body, (string) Str::uuid());
        $body = ['membership_id' => $a['membership'], 'specialty_id' => null, 'branch_ids' => [$a['branch']], 'service_ids' => [], 'active' => true];
        $d = $cmd('doctors', $body);
        $this->assertNull($d['specialty_id']);
        $this->assertSame([], $d['service_ids']);
        $room = $cmd('rooms', ['branch_id' => $a['branch'], 'number' => 'Synthetic', 'service_type' => 'consultation', 'active' => true, 'availability' => 'available']);
        $rule = ['doctor_id' => $d['id'], 'branch_id' => $a['branch'], 'room_id' => $room['id'], 'weekday' => 1, 'local_start' => '09:00', 'local_end' => '10:00', 'slot_minutes' => 30, 'effective_from' => '2030-01-01', 'effective_to' => null, 'breaks' => []];
        $r = $cmd('schedules', $rule);
        $this->assertTrue($r['active']);
        $sp = $cmd('specialties', ['code' => 'real', 'name' => 'Real test specialty', 'active' => true]);
        $cmd('doctors', [...$body, 'specialty_id' => $sp['id'], 'service_ids' => [$a['service']]], $d['id']);
        $slot = fn ($live) => DB::connection('migration')->transaction(function () use ($a, $d, $room, $live) {
            $db = DB::connection('migration');
            $db->select("SELECT set_config('app.user_id',?,true),set_config('app.tenant_id',?,true),set_config('app.credential_version','1',true)", [$a['user'], $a['tenant']]);

            return $db->selectOne('SELECT governance.booking_slot_valid(?::uuid,?::uuid,?::uuid,?::uuid,?::timestamptz,?::timestamptz,?::boolean) AS ok', [$d['id'], $a['branch'], $a['service'], $room['id'], '2030-01-07T09:00:00+05:00', '2030-01-07T09:30:00+05:00', $live ? 'true' : 'false'])->ok;
        });
        $slots = fn () => app(Availability::class)->get($this->actor($a['user']), $a['tenant'], $d['id'], ['branch_id' => $a['branch'], 'service_id' => $a['service'], 'from' => '2030-01-07T00:00:00+05:00', 'to' => '2030-01-08T00:00:00+05:00']);
        $this->assertCount(2, $slots()['data']);
        $this->assertTrue($slot(true));
        $cmd('schedules', [...$rule, 'active' => false], $r['id']);
        $this->assertCount(0, $slots()['data']);
        $this->assertFalse($slot(true));
        $this->assertTrue($slot(false));
        $this->assertSame(1, DB::connection('migration')->table('schedule_rules')->count());
    }

    public function test_parallel_work_updates_conflict_and_same_intent_replays_without_duplicates(): void
    {
        $a = $this->setupEmployee();
        $worker = <<<'WORKER'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$p=json_decode(stream_get_contents(STDIN),true);Illuminate\Support\Facades\DB::select("SELECT set_config('application_name',?,false)",[$p['label']]);
$u=(new App\Modules\Identity\Models\User)->forceFill(['id'=>$p['user'],'credential_version'=>1]);
try{$v=app(App\Modules\Access\Services\Governance::class)->call('employee_work_save',[$p['employee'],$p['branch'],json_encode($p['days']),'[]',$p['version'],$p['key']],$u,$p['tenant']);echo json_encode(['status'=>200,'result'=>json_decode($v,true)]);}catch(App\Modules\Access\Services\GovernanceDenied $e){echo json_encode(['status'=>$e->reason==='conflict'?409:403]);}
WORKER;
        foreach ([false, true] as $same) {
            $db = DB::connection('migration');
            $version = $this->read($a)['version'];
            $db->beginTransaction();
            $db->table('tenants')->where('id', $a['tenant'])->lockForUpdate()->first();
            $processes = [];
            $labels = [];
            $key = (string) Str::uuid();
            try {
                foreach ([0, 1] as $index) {
                    $label = 'work-race-'.Str::uuid();
                    $labels[] = $label;
                    $days = $this->days();
                    $days[0]['end_time'] = $same ? '15:00' : ($index ? '15:30' : '16:30');
                    $p = new Process([PHP_BINARY, '-r', $worker], base_path(), ['APP_ENV' => 'testing']);
                    $p->setTimeout(20);
                    $p->setInput(json_encode([...$a, 'days' => $days, 'version' => $version, 'key' => $same ? $key : (string) Str::uuid(), 'label' => $label]));
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
                $this->assertSame(2, $waiting);
                $db->commit();
                $results = [];
                foreach ($processes as $p) {
                    $p->wait();
                    $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
                    $results[] = json_decode($p->getOutput(), true);
                }$codes = array_column($results, 'status');
                sort($codes);
                $this->assertSame($same ? [200, 200] : [200, 409], $codes);
                if ($same) {
                    $this->assertSame($results[0]['result'], $results[1]['result']);
                }
                $this->assertCount(2, $this->read($a)['data']);
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
    }
}
