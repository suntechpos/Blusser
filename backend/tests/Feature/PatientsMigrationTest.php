<?php

namespace Tests\Feature;

use App\Modules\Access\Services\GovernanceDenied;
use App\Modules\ClinicOperations\Contracts\OperationsContract;
use App\Modules\Patients\Services\Patients;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\FoundationTestCase;

final class PatientsMigrationTest extends FoundationTestCase
{
    private function rollback(): void
    {
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'migration', '--step' => $this->migrationStepsFrom('2026_10_16_000006'), '--force' => true]));
    }

    public function test_verified_ceo_only_backfill_provenance_repeatability_and_safe_down(): void
    {
        $ids = $this->fixture();
        $owner = DB::connection('migration');
        $a = $ids['a'];
        $newIds = $owner->table('permissions')->whereIn('code', array_keys(OperationsContract::permissions()))->pluck('id');
        $owner->table('role_permissions')->whereIn('permission_id', $newIds)->delete();
        $owner->table('tenant_roles')->where('id', $a['role'])->update(['code' => 'ceo', 'protected' => true]);
        $owner->table('tenant_ceos')->insert(['tenant_id' => $a['tenant'], 'user_id' => $a['user'], 'membership_id' => $a['membership']]);
        $oldCount = $owner->table('role_permissions')->count();
        $this->rollback();
        $this->assertSame($oldCount, $owner->table('role_permissions')->count());
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'migration', '--force' => true]));
        $this->assertSame(35, $owner->table('patient_grant_provenance')->where('tenant_id', $a['tenant'])->count());
        $this->assertSame(0, $owner->table('patient_grant_provenance')->where('tenant_id', $ids['b']['tenant'])->count());
        $this->assertSame($oldCount + 35, $owner->table('role_permissions')->count());
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'migration', '--force' => true]));
        $this->assertSame($oldCount + 35, $owner->table('role_permissions')->count());
        $this->rollback();
        $this->assertSame($oldCount, $owner->table('role_permissions')->count());
        $this->assertSame(2, $owner->table('users')->count());
        $this->assertSame(2, $owner->table('branches')->count());
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'migration', '--force' => true]));
    }

    public function test_owner_backfill_preview_late_verified_ceo_and_runtime_denial(): void
    {
        $ids = $this->fixture();
        $a = $ids['a'];
        $owner = DB::connection('migration');
        $newIds = $owner->table('permissions')->whereIn('code', array_keys(OperationsContract::permissions()))->pluck('id');
        $owner->table('role_permissions')->whereIn('permission_id', $newIds)->delete();
        $owner->table('tenant_roles')->where('id', $a['role'])->update(['code' => 'ceo', 'protected' => true]);
        $owner->table('tenant_ceos')->insert(['tenant_id' => $a['tenant'], 'user_id' => $a['user'], 'membership_id' => $a['membership']]);
        $this->assertSame(0, Artisan::call('patients:backfill-ceo'));
        $this->assertStringContainsString('Preview: 35', Artisan::output());
        $this->assertSame(0, $owner->table('patient_grant_provenance')->count());
        $this->assertSame(0, Artisan::call('patients:backfill-ceo', ['--apply' => true]));
        $this->assertSame(35, $owner->table('patient_grant_provenance')->count());
        $this->assertSame(0, $owner->table('patient_grant_provenance')->where('tenant_id', $ids['b']['tenant'])->count());
        $this->assertSame(0, Artisan::call('patients:backfill-ceo', ['--apply' => true]));
        $this->assertStringContainsString('Applied: 0', Artisan::output());
        $original = config('database.connections.migration');
        try {
            config(['database.connections.migration' => config('database.connections.pgsql')]);
            DB::purge('migration');
            $this->assertSame(1, Artisan::call('patients:backfill-ceo', ['--apply' => true]));
        } finally {
            config(['database.connections.migration' => $original]);
            DB::purge('migration');
        }
    }

    public function test_patch_lock_migration_roundtrip_preserves_patient_rows_and_restores_guard(): void
    {
        $a = $this->fixture()['a'];
        $service = app(Patients::class);
        $actor = $this->actor($a['user']);
        $created = $service->command($actor, $a['tenant'], 'create', null, ['name' => 'Synthetic retained version', 'date_of_birth' => '1990-01-01', 'branch_id' => $a['branch']], (string) Str::uuid());
        $id = $created['patient']['id'];
        try {
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'migration', '--step' => $this->migrationStepsFrom('2026_10_16_000007'), '--force' => true]));
            $legacy = $service->command($actor, $a['tenant'], 'update', $id, ['name' => 'Synthetic legacy update'], (string) Str::uuid());
            $this->assertSame(2, $legacy['version']);
        } finally {
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'migration', '--force' => true]));
        }
        try {
            $service->command($actor, $a['tenant'], 'update', $id, ['name' => 'Synthetic rejected update'], (string) Str::uuid());
            $this->fail('Version guard must be restored.');
        } catch (GovernanceDenied $exception) {
            $this->assertSame('invalid_input', $exception->reason);
        }
        $updated = $service->command($actor, $a['tenant'], 'update', $id, ['name' => 'Synthetic guarded update', 'expected_version' => 2], (string) Str::uuid());
        $this->assertSame(3, $updated['version']);
        $this->assertSame($id, $updated['patient']['id']);
        $this->assertSame($created['patient']['patient_number'], $updated['patient']['patient_number']);
    }

    public function test_down_refuses_later_delegated_grants_even_without_patient_records(): void
    {
        $this->fixture();
        try {
            Artisan::call('migrate:rollback', ['--database' => 'migration', '--step' => $this->migrationStepsFrom('2026_10_16_000006'), '--force' => true]);
            $this->fail('Later delegated grants must not be deleted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forward migration', $exception->getMessage());
        } finally {
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'migration', '--force' => true]));
        }
        $this->assertSame(0, DB::connection('migration')->table('patients')->count());
        $this->assertSame(70, DB::connection('migration')->table('role_permissions')->whereIn('permission_id', DB::connection('migration')->table('permissions')->whereIn('code', array_keys(OperationsContract::permissions()))->pluck('id'))->count());
    }

    public function test_down_refuses_patient_data_and_subsequent_role_delegations(): void
    {
        $a = $this->fixture()['a'];
        app(Patients::class)->command($this->actor($a['user']), $a['tenant'], 'create', null, ['name' => 'Synthetic retained', 'date_of_birth' => '1990-01-01', 'branch_id' => $a['branch']], (string) Str::uuid());
        try {
            Artisan::call('migrate:rollback', ['--database' => 'migration', '--step' => $this->migrationStepsFrom('2026_10_16_000006'), '--force' => true]);
            $this->fail('Clinical data must prevent destructive rollback.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forward migration', $exception->getMessage());
        } finally {
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'migration', '--force' => true]));
        }
        $this->assertSame(1, DB::connection('migration')->table('patients')->count());
        $this->assertSame(2, DB::connection('migration')->table('users')->count());
    }
}
