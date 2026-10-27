<?php

namespace Tests\Feature;

use App\Modules\Access\Services\GovernanceDenied;
use App\Modules\Appointments\Services\Appointments;
use App\Modules\Patients\Services\Patients;
use App\Modules\Queues\Services\Queues;
use App\Modules\Scheduling\Services\Availability;
use App\Modules\Scheduling\Services\Scheduling;
use App\Modules\Services\Services\ServiceCatalog;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Process\Process;
use Tests\FoundationTestCase;

final class ServiceCatalogTest extends FoundationTestCase
{
    private function catalog(): ServiceCatalog
    {
        return app(ServiceCatalog::class);
    }

    private function command(array $a, string $kind, array $data, ?array $old = null, string $operation = 'create', ?string $key = null): array
    {
        return $this->catalog()->command($this->actor($a['user']), $a['tenant'], $kind, $operation, $old['id'] ?? null, $data, $old['version'] ?? null, $key ?? (string) Str::uuid());
    }

    private function category(array $a, string $name = 'Synthetic category'): array
    {
        return $this->command($a, 'categories', ['name' => $name, 'description' => '', 'color' => 'blue', 'active' => true]);
    }

    private function payload(array $a, array $category): array
    {
        return ['name' => 'Synthetic consult', 'category_id' => $category['id'], 'service_type' => 'consultation', 'branch_ids' => [$a['branch']], 'price' => '100000.15', 'timed' => true, 'duration_minutes' => 20, 'turnaround' => null, 'turnaround_unit' => 'hour', 'description' => '', 'active' => true];
    }

    private function denied(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('Expected catalog rejection.');
        } catch (GovernanceDenied $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    private function grant(array $a, string $code, ?string $scope): void
    {
        $db = DB::connection('migration');
        $where = ['tenant_id' => $a['tenant'], 'role_id' => $a['role'], 'permission_id' => $db->table('permissions')->where('code', $code)->value('id')];
        if ($scope === null) {
            $db->table('role_permissions')->where($where)->delete();
        } else {
            $db->table('role_permissions')->updateOrInsert($where, ['scope' => $scope]);
        }
    }

    public function test_category_and_service_crud_price_codes_filters_stats_and_stale_writes_are_real(): void
    {
        $ids = $this->fixture();
        $a = $ids['a'];
        $category = $this->category($a);
        $body = $this->payload($a, $category);
        $key = (string) Str::uuid();
        $service = $this->command($a, 'services', $body, key: $key);
        $this->assertSame('100000.15', $service['price']);
        $this->assertSame('XZ-001', $service['code']);
        $this->assertSame($service, $this->command($a, 'services', $body, key: $key));
        $this->denied(fn () => $this->command($a, 'services', [...$body, 'price' => '1.00'], key: $key), 'conflict');
        $next = $this->command($a, 'services', [...$body, 'name' => 'Synthetic second']);
        $this->assertNotSame($service['code'], $next['code']);
        $list = $this->catalog()->listing($this->actor($a['user']), $a['tenant'], 'services', ['category_id' => $category['id'], 'per_page' => 1, 'page' => 2]);
        $this->assertSame(2, $list['meta']['total']);
        $this->assertCount(1, $list['data']);
        $this->assertSame(2, $list['categories'][0]['service_count']);
        $this->assertSame(3, $list['stats']['total']);
        $updated = $this->command($a, 'services', [...$body, 'price' => '999999999999.99'], $service, 'update');
        $this->assertSame('999999999999.99', $updated['price']);
        $this->assertSame($service['id'], $updated['id']);
        $this->denied(fn () => $this->command($a, 'services', $body, $service, 'update'), 'conflict');
        $inactive = $this->command($a, 'services', ['active' => false], $updated, 'status');
        $this->assertFalse($inactive['active']);
        $filtered = $this->catalog()->listing($this->actor($a['user']), $a['tenant'], 'services', ['q' => $service['code'], 'branch_id' => $a['branch'], 'status' => 'inactive']);
        $this->assertSame($service['id'], $filtered['data'][0]['id']);
        $this->assertSame(1, $filtered['stats']['inactive']);
        $this->assertSame(0, $this->catalog()->listing($this->actor($a['user']), $a['tenant'], 'services', ['branch_id' => $ids['b']['branch']])['meta']['total']);
        $changed = $this->command($a, 'categories', ['name' => 'Changed category', 'description' => 'Saved', 'color' => 'green', 'active' => true], $category, 'update');
        $changed = $this->command($a, 'categories', ['active' => false], $changed, 'status');
        $this->assertFalse($changed['active']);
        $this->assertSame(2, $changed['service_count']);
        $this->assertSame('999999999999.99', DB::connection('migration')->table('services')->where('id', $service['id'])->value('price'));
    }

    public function test_catalog_security_foreign_tenant_scope_atomicity_and_status_permission_cannot_be_bypassed(): void
    {
        $ids = $this->fixture();
        $a = $ids['a'];
        $category = $this->category($a);
        $foreign = $this->category($ids['b']);
        $body = $this->payload($a, $category);
        $this->denied(fn () => $this->command($a, 'services', [...$body, 'category_id' => $foreign['id']]), 'not_found');
        $this->denied(fn () => $this->command($a, 'services', [...$body, 'branch_ids' => [$ids['b']['branch']]]), 'not_found');
        $this->assertSame(1, DB::connection('migration')->table('services')->where('tenant_id', $a['tenant'])->count());
        foreach ([['price' => '0.001'], ['price' => 10.1], ['duration_minutes' => 0], ['branch_ids' => [$a['branch'], $a['branch']]], ['timed' => false], ['made_up' => true]] as $bad) {
            $this->denied(fn () => $this->command($a, 'services', [...$body, ...$bad]), 'invalid_input');
        }
        $service = $this->command($a, 'services', $body);
        $this->grant($a, 'services.delete', null);
        $this->denied(fn () => $this->command($a, 'services', [...$body, 'active' => false], $service, 'update'), 'forbidden');
        $this->denied(fn () => $this->command($a, 'services', ['active' => false], $service, 'status'), 'forbidden');
        $this->assertTrue($this->catalog()->show($this->actor($a['user']), $a['tenant'], $service['id'])['active']);
        $this->grant($a, 'services.delete', 'entire_clinic');
        $statusKey = (string) Str::uuid();
        $this->command($a, 'services', [...$body, 'active' => false], $service, 'update', $statusKey);
        $this->grant($a, 'services.delete', null);
        $this->denied(fn () => $this->command($a, 'services', [...$body, 'active' => false], $service, 'update', $statusKey), 'forbidden');
        $this->grant($a, 'categories.status', null);
        $this->denied(fn () => $this->command($a, 'categories', ['name' => $category['name'], 'description' => '', 'color' => 'blue', 'active' => false], $category, 'update'), 'forbidden');
        // Foundation permits only clinicwide service view; unsupported scopes fail at the grant boundary.
        $this->assertSqlState('23514', fn () => $this->grant($a, 'services.view', 'assigned_branches'));
        $this->grant($a, 'services.view', null);
        $this->denied(fn () => $this->command($a, 'services', $body), 'forbidden');
        $this->grant($a, 'services.view', 'entire_clinic');
        app(TenantContext::class)->run($this->actor($a['user']), $a['tenant'], function () use ($ids) {
            $this->assertSame(0, DB::table('services')->where('tenant_id', $ids['b']['tenant'])->count());
            $this->assertSame(0, DB::table('service_categories')->where('tenant_id', $ids['b']['tenant'])->count());
            $this->assertSame(0, DB::table('service_branches')->where('tenant_id', $ids['b']['tenant'])->count());
            $this->assertSqlState('42501', fn () => DB::statement('UPDATE services SET active=false'));
        });
        app(TenantContext::class)->run($this->actor($a['user']), $a['tenant'], function () use ($service, $a) {
            $this->assertSqlState('42501', fn () => DB::select('SELECT governance.catalog_service_available(?,?)', [$service['id'], $a['branch']]));
        });
        $this->expectException(NotFoundHttpException::class);
        $this->catalog()->show($this->actor($a['user']), $a['tenant'], $ids['b']['service']);
    }

    public function test_catalog_lookups_work_without_category_or_branch_management_and_export_is_separately_authorized(): void
    {
        $a = $this->fixture()['a'];
        $category = $this->category($a);
        $this->command($a, 'services', $this->payload($a, $category));
        $this->grant($a, 'categories.view', null);
        $this->grant($a, 'branches.view', null);
        $list = $this->catalog()->listing($this->actor($a['user']), $a['tenant'], 'services', ['branch_id' => $a['branch']]);
        $this->assertSame($a['branch'], $list['branches'][0]['id']);
        $this->assertSame($category['id'], $list['categories'][0]['id']);
        $this->assertSame(2, $list['meta']['total']);
        $this->denied(fn () => $this->category($a, 'Denied management'), 'forbidden');
        try {
            $this->catalog()->listing($this->actor($a['user']), $a['tenant'], 'services', [], true);
            $this->fail('Export requires category view.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->grant($a, 'categories.view', 'entire_clinic');
        $export = $this->catalog()->listing($this->actor($a['user']), $a['tenant'], 'services', ['q' => 'Synthetic consult'], true);
        $this->assertCount(1, $export['data']);
        $this->assertSame(1, DB::connection('migration')->table('audit_logs')->where('action', 'catalog.export')->count());
    }

    public function test_legacy_service_creation_and_doctor_assignment_use_the_same_service_id_with_live_branch_and_duration(): void
    {
        $a = $this->fixture()['a'];
        $scheduling = app(Scheduling::class);
        $legacy = $scheduling->command($this->actor($a['user']), $a['tenant'], 'services', 'create', null, ['code' => 'legacy', 'name' => 'Legacy real service', 'active' => true], (string) Str::uuid());
        $legacyView = $this->catalog()->show($this->actor($a['user']), $a['tenant'], $legacy['id']);
        $this->assertNull($legacyView['price']);
        $this->assertSame([], $legacyView['branch_ids']);
        $service = $this->command($a, 'services', $this->payload($a, $this->category($a)));
        $specialty = $scheduling->command($this->actor($a['user']), $a['tenant'], 'specialties', 'create', null, ['code' => 'synthetic', 'name' => 'Synthetic specialty', 'active' => true], (string) Str::uuid());
        $doctor = $scheduling->command($this->actor($a['user']), $a['tenant'], 'doctors', 'create', null, ['membership_id' => $a['membership'], 'specialty_id' => $specialty['id'], 'branch_ids' => [$a['branch']], 'service_ids' => [$service['id']], 'active' => true], (string) Str::uuid());
        $room = $scheduling->command($this->actor($a['user']), $a['tenant'], 'rooms', 'create', null, ['branch_id' => $a['branch'], 'number' => '101', 'service_type' => 'consultation', 'active' => true, 'availability' => 'available'], (string) Str::uuid());
        $scheduling->command($this->actor($a['user']), $a['tenant'], 'schedules', 'create', null, ['doctor_id' => $doctor['id'], 'branch_id' => $a['branch'], 'room_id' => $room['id'], 'weekday' => 1, 'local_start' => '09:00', 'local_end' => '10:00', 'slot_minutes' => 30, 'effective_from' => '2030-01-01', 'effective_to' => null, 'breaks' => []], (string) Str::uuid());
        $filters = ['service_id' => $service['id'], 'branch_id' => $a['branch'], 'from' => '2030-01-07T00:00:00+05:00', 'to' => '2030-01-08T00:00:00+05:00'];
        $slots = app(Availability::class)->get($this->actor($a['user']), $a['tenant'], $doctor['id'], $filters);
        $this->assertSame(3, $slots['meta']['total']);
        $this->assertSame($service['id'], DB::connection('migration')->table('doctor_services')->where('doctor_id', $doctor['id'])->value('service_id'));
        $patient = app(Patients::class)->command($this->actor($a['user']), $a['tenant'], 'create', null, ['name' => 'Synthetic catalog patient', 'date_of_birth' => '1990-01-01', 'branch_id' => $a['branch']], (string) Str::uuid());
        $appointment = app(Appointments::class)->command($this->actor($a['user']), $a['tenant'], 'create', null, ['patient_id' => $patient['patient']['id'], 'doctor_id' => $doctor['id'], 'service_id' => $service['id'], 'branch_id' => $a['branch'], 'room_id' => $room['id'], 'starts_at' => '2030-01-07T09:00:00+05:00', 'ends_at' => '2030-01-07T09:20:00+05:00'], (string) Str::uuid());
        $this->denied(fn () => $this->command($a, 'services', [...$this->payload($a, $this->catalog()->listing($this->actor($a['user']), $a['tenant'], 'categories', [])['data'][0]), 'duration_minutes' => 30], $service, 'update'), 'conflict');
        $second = (string) Str::uuid();
        DB::connection('migration')->table('branches')->insert(['id' => $second, 'tenant_id' => $a['tenant'], 'code' => 'second', 'name' => 'Synthetic other branch']);
        $body = $this->payload($a, $this->catalog()->listing($this->actor($a['user']), $a['tenant'], 'categories', [])['data'][0]);
        $moved = $this->command($a, 'services', [...$body, 'branch_ids' => [$second]], $service, 'update');
        $this->assertSame(0, app(Availability::class)->get($this->actor($a['user']), $a['tenant'], $doctor['id'], $filters)['meta']['total']);
        $options = app(Appointments::class)->bookingOptions($this->actor($a['user']), $a['tenant'], ['branch_id' => $a['branch']]);
        $this->assertSame([], $options['data'][0]['services']);
        $this->command($a, 'services', ['active' => false], $moved, 'status');
        $this->assertSame($service['id'], app(Appointments::class)->show($this->actor($a['user']), $a['tenant'], 'appointments', $appointment['id'])['service_id']);
        $this->assertSame(0, app(Availability::class)->get($this->actor($a['user']), $a['tenant'], $doctor['id'], $filters)['meta']['total']);
        $this->assertSame($service['id'], DB::connection('migration')->table('doctor_services')->where('doctor_id', $doctor['id'])->value('service_id'));
    }

    public function test_admin_can_book_configured_doctor_before_first_login_without_catalog_specialty(): void
    {
        $a = $this->fixture()['a'];
        $db = DB::connection('migration');
        $doctorUser = (string) Str::uuid();
        $doctorMembership = (string) Str::uuid();
        $db->table('users')->insert([
            'id' => $doctorUser,
            'login' => 'synthetic.unrotated.doctor',
            'name' => 'New configurable doctor',
            'password_hash' => $db->table('users')->where('id', $a['user'])->value('password_hash'),
            'must_change_password' => true,
            'temporary_password_expires_at' => now()->addDay(),
        ]);
        $db->table('tenant_memberships')->insert([
            'id' => $doctorMembership,
            'tenant_id' => $a['tenant'],
            'user_id' => $doctorUser,
            'role_id' => $a['role'],
        ]);
        $db->table('membership_branches')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $a['tenant'],
            'membership_id' => $doctorMembership,
            'branch_id' => $a['branch'],
        ]);

        $service = $this->command($a, 'services', $this->payload($a, $this->category($a)));
        $scheduling = app(Scheduling::class);
        $doctor = $scheduling->command($this->actor($a['user']), $a['tenant'], 'doctors', 'create', null, [
            'membership_id' => $doctorMembership,
            'specialty_id' => null,
            'branch_ids' => [$a['branch']],
            'service_ids' => [$service['id']],
            'active' => true,
        ], (string) Str::uuid());
        $room = $scheduling->command($this->actor($a['user']), $a['tenant'], 'rooms', 'create', null, [
            'branch_id' => $a['branch'],
            'number' => '102',
            'service_type' => 'consultation',
            'active' => true,
            'availability' => 'available',
        ], (string) Str::uuid());
        $scheduling->command($this->actor($a['user']), $a['tenant'], 'schedules', 'create', null, [
            'doctor_id' => $doctor['id'],
            'branch_id' => $a['branch'],
            'room_id' => $room['id'],
            'weekday' => 1,
            'local_start' => '09:00',
            'local_end' => '10:00',
            'slot_minutes' => 30,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
            'breaks' => [],
        ], (string) Str::uuid());

        $options = app(Appointments::class)->bookingOptions($this->actor($a['user']), $a['tenant'], ['branch_id' => $a['branch']]);
        $this->assertSame(1, $options['meta']['total']);
        $this->assertSame($doctor['id'], $options['data'][0]['id']);
        $this->assertNull($options['data'][0]['specialty_id']);
        $this->assertSame($service['id'], $options['data'][0]['services'][0]['id']);

        $filters = [
            'service_id' => $service['id'],
            'branch_id' => $a['branch'],
            'from' => '2030-01-07T00:00:00+05:00',
            'to' => '2030-01-08T00:00:00+05:00',
        ];
        $slots = app(Availability::class)->get($this->actor($a['user']), $a['tenant'], $doctor['id'], $filters);
        $this->assertSame(3, $slots['meta']['total']);

        $patient = app(Patients::class)->command($this->actor($a['user']), $a['tenant'], 'create', null, [
            'name' => 'New booking patient',
            'date_of_birth' => '1990-01-01',
            'branch_id' => $a['branch'],
        ], (string) Str::uuid());
        $appointment = app(Appointments::class)->command($this->actor($a['user']), $a['tenant'], 'create', null, [
            'patient_id' => $patient['patient']['id'],
            'doctor_id' => $doctor['id'],
            'service_id' => $service['id'],
            'branch_id' => $a['branch'],
            'room_id' => $room['id'],
            'starts_at' => '2030-01-07T09:00:00+05:00',
            'ends_at' => '2030-01-07T09:20:00+05:00',
        ], (string) Str::uuid());
        $this->assertSame($doctor['id'], $appointment['doctor_id']);
        $this->assertTrue($db->table('users')->where('id', $doctorUser)->value('must_change_password'));
    }

    public function test_parallel_creates_generate_unique_codes_and_parallel_updates_reject_stale_versions(): void
    {
        $a = $this->fixture()['a'];
        $body = $this->payload($a, $this->category($a));
        $worker = <<<'WORKER'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$p=json_decode(stream_get_contents(STDIN),true);Illuminate\Support\Facades\DB::select("SELECT set_config('application_name',?,false)",[$p['label']]);
$u=(new App\Modules\Identity\Models\User)->forceFill(['id'=>$p['user'],'credential_version'=>1]);
try{$v=app(App\Modules\Services\Services\ServiceCatalog::class)->command($u,$p['tenant'],'services',$p['operation'],$p['id'],$p['body'],$p['version'],$p['key']);echo json_encode(['status'=>200,'result'=>$v]);}catch(App\Modules\Access\Services\GovernanceDenied $e){echo json_encode(['status'=>$e->reason==='conflict'?409:403]);}
WORKER;
        $created = [];
        foreach (['create', 'update', 'replay'] as $mode) {
            $db = DB::connection('migration');
            $db->beginTransaction();
            $db->table('tenants')->where('id', $a['tenant'])->lockForUpdate()->first();
            $processes = [];
            $labels = [];
            $key = (string) Str::uuid();
            try {
                foreach ([0, 1] as $index) {
                    $label = 'catalog-race-'.Str::uuid();
                    $labels[] = $label;
                    $p = new Process([PHP_BINARY, '-r', $worker], base_path(), ['APP_ENV' => 'testing']);
                    $p->setTimeout(20);
                    $p->setInput(json_encode([...$a, 'operation' => $mode === 'update' ? 'update' : 'create', 'id' => $mode === 'update' ? $created[0]['result']['id'] : null, 'version' => $mode === 'update' ? 1 : null, 'body' => [...$body, 'name' => 'Synthetic '.$mode], 'key' => $mode === 'replay' ? $key : (string) Str::uuid(), 'label' => $label]));
                    $p->start();
                    $processes[] = $p;
                }
                $deadline = microtime(true) + 10;
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
                }
                $codes = array_column($results, 'status');
                sort($codes);
                $this->assertSame($mode === 'update' ? [200, 409] : [200, 200], $codes);
                if ($mode === 'create') {
                    $created = $results;
                    $this->assertNotSame($results[0]['result']['code'], $results[1]['result']['code']);
                } elseif ($mode === 'replay') {
                    $this->assertSame($results[0]['result'], $results[1]['result']);
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
        $this->assertSame(4, DB::connection('migration')->table('services')->where('tenant_id', $a['tenant'])->count());
    }

    public function test_additive_migration_preserves_legacy_records_and_refuses_destructive_rollback_with_catalog_data(): void
    {
        $a = $this->fixture()['a'];
        // Verify the forward migration preserved pre-existing services. Rolling back
        // the last step no longer targets the catalog migration after later releases.
        $this->assertTrue(DB::connection('migration')->getSchemaBuilder()->hasColumn('services', 'price'));
        $this->assertSame('Synthetic service', DB::connection('migration')->table('services')->where('id', $a['service'])->value('name'));
        $this->assertNull($this->catalog()->show($this->actor($a['user']), $a['tenant'], $a['service'])['price']);

        $this->category($a);
        $originalConnection = DB::getDefaultConnection();
        try {
            // Invoke the catalog migration's own guard, never an unrelated later
            // migration selected by migrate:rollback --step=1.
            DB::setDefaultConnection('migration');
            $migration = require database_path('migrations/2026_10_25_000021_add_service_catalog_metadata.php');
            try {
                $migration->down();
                $this->fail('Refuse destructive catalog rollback.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Service catalog data retained', $e->getMessage());
            }
        } finally {
            DB::setDefaultConnection($originalConnection);
        }
        $this->assertSame(1, DB::connection('migration')->table('service_categories')->count());
    }

    public function test_walk_in_and_queue_creation_reject_service_outside_its_configured_branch(): void
    {
        $a = $this->fixture()['a'];
        $db = DB::connection('migration');
        $second = (string) Str::uuid();
        $db->table('branches')->insert(['id' => $second, 'tenant_id' => $a['tenant'], 'name' => 'Synthetic second branch', 'code' => 'second']);
        $db->table('membership_branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $a['tenant'], 'membership_id' => $a['membership'], 'branch_id' => $second]);
        $body = $this->payload($a, $this->category($a));
        $service = $this->command($a, 'services', $body);
        $scheduling = app(Scheduling::class);
        $specialty = $scheduling->command($this->actor($a['user']), $a['tenant'], 'specialties', 'create', null, ['name' => 'Synthetic specialty', 'code' => 'synthetic', 'active' => true], (string) Str::uuid());
        $doctor = $scheduling->command($this->actor($a['user']), $a['tenant'], 'doctors', 'create', null, ['membership_id' => $a['membership'], 'specialty_id' => $specialty['id'], 'branch_ids' => [$a['branch'], $second], 'service_ids' => [$service['id']], 'active' => true], (string) Str::uuid());
        $patients = app(Patients::class);
        $bPatient = $patients->command($this->actor($a['user']), $a['tenant'], 'create', null, ['name' => 'Synthetic B patient', 'date_of_birth' => '1990-01-01', 'branch_id' => $second], (string) Str::uuid())['patient'];
        $aPatient = $patients->command($this->actor($a['user']), $a['tenant'], 'create', null, ['name' => 'Synthetic A patient', 'date_of_birth' => '1991-01-01', 'branch_id' => $a['branch']], (string) Str::uuid())['patient'];
        $bPayload = ['patient_id' => $bPatient['id'], 'doctor_id' => $doctor['id'], 'branch_id' => $second, 'service_id' => $service['id']];
        $appointments = app(Appointments::class);
        $this->denied(fn () => $appointments->command($this->actor($a['user']), $a['tenant'], 'walk_in', null, $bPayload, (string) Str::uuid()), 'not_found');
        $this->denied(fn () => app(Queues::class)->command($this->actor($a['user']), $a['tenant'], 'create', null, $bPayload, (string) Str::uuid()), 'not_found');
        $this->assertSame(0, $db->table('visits')->where('service_id', $service['id'])->count());
        $this->assertSame(0, $db->table('queue_tickets')->where('branch_id', $second)->count());
        $aPayload = ['patient_id' => $aPatient['id'], 'doctor_id' => $doctor['id'], 'branch_id' => $a['branch'], 'service_id' => $service['id']];
        $walkKey = (string) Str::uuid();
        $allowed = $appointments->command($this->actor($a['user']), $a['tenant'], 'walk_in', null, $aPayload, $walkKey);
        $this->assertSame($a['branch'], $allowed['visit']['branch_id']);
        $this->assertSame($service['id'], $db->table('visits')->where('id', $allowed['visit']['id'])->value('service_id'));
        $this->assertSame(1, $db->table('visits')->where('service_id', $service['id'])->count());
        $this->assertSame(1, $db->table('queue_tickets')->where('branch_id', $a['branch'])->count());
        $queuePatient = $patients->command($this->actor($a['user']), $a['tenant'], 'create', null, ['name' => 'Synthetic queued patient', 'date_of_birth' => '1992-01-01', 'branch_id' => $a['branch']], (string) Str::uuid())['patient'];
        $queues = app(Queues::class);
        $queuePayload = [...$aPayload, 'patient_id' => $queuePatient['id']];
        $queueKey = (string) Str::uuid();
        $queued = $queues->command($this->actor($a['user']), $a['tenant'], 'create', null, $queuePayload, $queueKey);
        $this->command($a, 'services', [...$body, 'branch_ids' => [$second]], $service, 'update');
        // Replays and queue call must recheck current branch availability, too.
        $this->denied(fn () => $appointments->command($this->actor($a['user']), $a['tenant'], 'walk_in', null, $aPayload, $walkKey), 'not_found');
        $this->denied(fn () => $queues->command($this->actor($a['user']), $a['tenant'], 'create', null, $queuePayload, $queueKey), 'not_found');
        $this->denied(fn () => $queues->command($this->actor($a['user']), $a['tenant'], 'call', $queued['ticket']['id'], ['expected_version' => $queued['ticket']['version']], (string) Str::uuid()), 'not_found');
        // Historical attendance stays addressable and can still be cancelled.
        $cancelled = $queues->command($this->actor($a['user']), $a['tenant'], 'cancel', $queued['ticket']['id'], ['expected_version' => $queued['ticket']['version'], 'reason_code' => 'patient_request'], (string) Str::uuid());
        $this->assertSame('cancelled', $cancelled['ticket']['status']);
        $this->assertSame(2, $db->table('visits')->where('service_id', $service['id'])->count());
    }

    public function test_structured_catalog_payload_requires_consultation_duration_and_laboratory_result_time(): void
    {
        $a = $this->fixture()['a'];
        $body = $this->payload($a, $this->category($a));
        $this->denied(fn () => $this->command($a, 'services', [...$body, 'timed' => false, 'duration_minutes' => null]), 'invalid_input');
        $this->denied(fn () => $this->command($a, 'services', [...$body, 'service_type' => 'laboratory', 'timed' => false, 'duration_minutes' => null]), 'invalid_input');
        $lab = $this->command($a, 'services', [...$body, 'service_type' => 'laboratory', 'timed' => false, 'duration_minutes' => null, 'turnaround' => '2.50']);
        $this->assertSame('2.50', $lab['turnaround']);
        $this->assertNull($lab['duration_minutes']);
    }
}
