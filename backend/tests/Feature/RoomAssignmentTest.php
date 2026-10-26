<?php

namespace Tests\Feature;

use App\Modules\Access\Services\GovernanceDenied;
use App\Modules\Scheduling\Services\Availability;
use App\Modules\Scheduling\Services\Scheduling;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\FoundationTestCase;

final class RoomAssignmentTest extends FoundationTestCase
{
    private function command(array $a, string $kind, array $body, ?string $id = null, ?string $key = null): array
    {
        return app(Scheduling::class)->command($this->actor($a['user']), $a['tenant'], $kind, $id ? 'update' : 'create', $id, $body, $key ?? (string) Str::uuid());
    }

    private function setupRoom(): array
    {
        $fixtures = $this->fixture();
        $a = $fixtures['a'];
        $specialty = $this->command($a, 'specialties', ['code' => 'test', 'name' => 'Test', 'active' => true]);
        $room = $this->command($a, 'rooms', ['branch_id' => $a['branch'], 'number' => 'Test room', 'service_type' => 'consultation', 'active' => true, 'availability' => 'available']);

        return [...$a, 'specialty' => $specialty, 'room' => $room, 'other' => $fixtures['b']];
    }

    private function doctor(array $a, bool $temporary = false): array
    {
        $db = DB::connection('migration');
        $user = (string) Str::uuid();
        $member = (string) Str::uuid();
        $db->table('users')->insert(['id' => $user, 'login' => 'synthetic-'.Str::uuid(), 'name' => 'Synthetic doctor', 'password_hash' => Hash::make('Synthetic-password-42!'), 'must_change_password' => $temporary, 'temporary_password_expires_at' => $temporary ? now()->addDay() : null]);
        $db->table('tenant_memberships')->insert(['id' => $member, 'tenant_id' => $a['tenant'], 'user_id' => $user, 'role_id' => $a['role']]);
        $db->table('membership_branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $a['tenant'], 'membership_id' => $member, 'branch_id' => $a['branch']]);
        $d = $this->command($a, 'doctors', ['membership_id' => $member, 'specialty_id' => $a['specialty']['id'], 'branch_ids' => [$a['branch']], 'service_ids' => [$a['service']], 'active' => true]);

        return [...$d, 'user' => $user];
    }

    private function rule(array $a, array $d, array $override = []): array
    {
        return [...['doctor_id' => $d['id'], 'branch_id' => $a['branch'], 'room_id' => $a['room']['id'], 'weekday' => 1, 'local_start' => '09:00', 'local_end' => '12:00', 'slot_minutes' => 30, 'effective_from' => '2030-01-01', 'effective_to' => null, 'breaks' => [], 'room_usage' => 'shared'], ...$override];
    }

    private function rejected(callable $fn, string $reason = 'conflict'): void
    {
        try {
            $fn();
            $this->fail('Expected rejection');
        } catch (GovernanceDenied $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_temporary_password_target_can_be_configured_but_cannot_authenticate_scheduling(): void
    {
        $a = $this->setupRoom();
        $d = $this->doctor($a, true);
        $r = $this->command($a, 'schedules', $this->rule($a, $d));
        $this->assertSame($d['id'], $r['doctor_id']);
        $this->assertTrue((bool) DB::connection('migration')->table('users')->where('id', $d['user'])->value('must_change_password'));
        $this->rejected(fn () => $this->command([...$a, 'user' => $d['user']], 'schedules', $this->rule($a, $d, ['weekday' => 2])), 'forbidden');
        $slots = app(Availability::class)->get($this->actor($a['user']), $a['tenant'], $d['id'], ['branch_id' => $a['branch'], 'service_id' => $a['service'], 'from' => '2030-01-07T00:00:00+05:00', 'to' => '2030-01-08T00:00:00+05:00']);
        // Reception may book a configured doctor before their first password rotation;
        // the doctor still cannot authenticate until changing the password.
        $this->assertNotEmpty($slots['data']);
    }

    public function test_shared_room_conflicts_replays_and_private_owner_changes_are_atomic(): void
    {
        $a = $this->setupRoom();
        $one = $this->doctor($a);
        $two = $this->doctor($a);
        $body = $this->rule($a, $one);
        $key = (string) Str::uuid();
        $first = $this->command($a, 'schedules', $body, null, $key);
        $this->assertSame($first['id'], $this->command($a, 'schedules', $body, null, $key)['id']);
        $this->command($a, 'schedules', $this->rule($a, $two, ['local_start' => '12:00', 'local_end' => '14:00']));
        $this->rejected(fn () => $this->command($a, 'schedules', $this->rule($a, $two)));
        $this->rejected(fn () => $this->command($a, 'schedules', [...$body, 'room_usage' => 'private'], $first['id']));
        $room = app(Scheduling::class)->show($this->actor($a['user']), $a['tenant'], 'rooms', $a['room']['id']);
        $this->assertSame('shared', $room['usage_mode']);
        $this->assertSame(2, DB::connection('migration')->table('schedule_rules')->count());
    }

    public function test_private_room_blocks_other_doctors_even_on_other_days_and_can_be_released(): void
    {
        $a = $this->setupRoom();
        $one = $this->doctor($a);
        $two = $this->doctor($a);
        $body = $this->rule($a, $one, ['room_usage' => 'private']);
        $r = $this->command($a, 'schedules', $body);
        $room = app(Scheduling::class)->show($this->actor($a['user']), $a['tenant'], 'rooms', $a['room']['id']);
        $this->assertSame('private', $room['usage_mode']);
        $this->assertSame($one['id'], $room['exclusive_doctor_id']);
        $this->rejected(fn () => $this->command($a, 'schedules', $this->rule($a, $two, ['weekday' => 2])));
        $replacement = $this->rule($a, $two, ['weekday' => 2]);
        unset($replacement['room_usage']);
        $this->rejected(fn () => $this->command($a, 'schedule-exceptions', ['doctor_id' => $two['id'], 'branch_id' => $a['branch'], 'local_date' => '2030-01-08', 'kind' => 'replace', 'replacement' => $replacement]));
        $this->command($a, 'schedules', [...$body, 'room_usage' => 'shared'], $r['id']);
        $this->command($a, 'schedules', $this->rule($a, $two, ['weekday' => 2]));
        $this->assertNull(DB::connection('migration')->table('rooms')->value('exclusive_doctor_id'));
    }

    public function test_room_updates_require_authority_and_preserve_tenant_and_branch_boundaries(): void
    {
        $a = $this->setupRoom();
        $d = $this->doctor($a);
        $db = DB::connection('migration');
        $permission = $db->table('permissions')->where('code', 'rooms.update')->value('id');
        $db->table('role_permissions')->where('role_id', $a['role'])->where('permission_id', $permission)->delete();
        $this->rejected(fn () => $this->command($a, 'schedules', $this->rule($a, $d, ['room_usage' => 'private'])), 'forbidden');
        $this->assertSame(0, $db->table('schedule_rules')->count());
        $this->assertSame('shared', $db->table('rooms')->value('usage_mode'));
        $other = $a['other'];
        $this->rejected(fn () => $this->command($other, 'schedules', $this->rule($a, $d)), 'not_found');
    }

    public function test_explicit_room_owner_transfer_is_rejected_after_assignment_and_release_clears_owner(): void
    {
        $a = $this->setupRoom();
        $one = $this->doctor($a);
        $two = $this->doctor($a);
        $body = ['branch_id' => $a['branch'], 'number' => $a['room']['number'], 'service_type' => 'consultation', 'active' => true, 'availability' => 'available', 'usage_mode' => 'private', 'exclusive_doctor_id' => $one['id']];
        $this->command($a, 'rooms', $body, $a['room']['id']);
        $this->command($a, 'rooms', [...$body, 'exclusive_doctor_id' => $two['id']], $a['room']['id']);
        $this->command($a, 'schedules', $this->rule($a, $two, ['room_usage' => 'private']));
        $this->rejected(fn () => $this->command($a, 'rooms', $body, $a['room']['id']));
        $this->assertSame($two['id'], DB::connection('migration')->table('rooms')->value('exclusive_doctor_id'));
        $room = $this->command($a, 'rooms', [...$body, 'usage_mode' => 'shared', 'exclusive_doctor_id' => null], $a['room']['id']);
        $this->assertSame('shared', $room['usage_mode']);
        $this->assertNull($room['exclusive_doctor_id']);
        $this->assertSame(1, DB::connection('migration')->table('schedule_rules')->count());
    }

    public function test_parallel_private_claims_on_different_days_have_only_one_winner(): void
    {
        $a = $this->setupRoom();
        $one = $this->doctor($a);
        $two = $this->doctor($a);
        $worker = <<<'WORKER'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$p=json_decode(stream_get_contents(STDIN),true);
Illuminate\Support\Facades\DB::select("SELECT set_config('application_name',?,false)",[$p['label']]);
$actor=(new App\Modules\Identity\Models\User)->forceFill(['id'=>$p['user'],'credential_version'=>1]);
try { app(App\Modules\Scheduling\Services\Scheduling::class)->command($actor,$p['tenant'],'schedules','create',null,$p['data'],$p['key']);echo '201'; }
catch(App\Modules\Access\Services\GovernanceDenied $e) {echo $e->reason==='conflict'?'409':'403';}
WORKER;
        $db = DB::connection('migration');
        $db->beginTransaction();
        $db->table('tenants')->where('id', $a['tenant'])->lockForUpdate()->first();
        $processes = [];
        $labels = [];
        try {
            foreach ([$one, $two] as $index => $d) {
                $label = 'private-race-'.Str::uuid();
                $labels[] = $label;
                $process = new Process([PHP_BINARY, '-r', $worker], base_path(), ['APP_ENV' => 'testing']);
                $process->setInput(json_encode(['user' => $a['user'], 'tenant' => $a['tenant'], 'label' => $label, 'key' => (string) Str::uuid(), 'data' => $this->rule($a, $d, ['weekday' => $index + 1, 'room_usage' => 'private'])]));
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            $waiting = 0;
            do {
                $waiting = (int) DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE application_name IN (?,?) AND wait_event_type='Lock'", $labels)->n;
                if ($waiting === 2) {
                    break;
                }usleep(50000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $waiting);
            $db->commit();
            $codes = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $codes[] = (int) $process->getOutput();
            }
            sort($codes);
            $this->assertSame([201, 409], $codes);
            $this->assertSame(1, $db->table('schedule_rules')->count());
            $this->assertSame($db->table('schedule_rules')->value('doctor_id'), $db->table('rooms')->value('exclusive_doctor_id'));
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }
}
