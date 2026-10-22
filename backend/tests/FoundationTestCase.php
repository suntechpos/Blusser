<?php

namespace Tests;

use App\Modules\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

abstract class FoundationTestCase extends TestCase
{
    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.connections.migration.database') !== 'blusser_test' || config('database.connections.pgsql.database') !== 'blusser_test') {
            throw new \RuntimeException('Destructive integration tests require the dedicated blusser_test database.');
        }
        if (! self::$migrated) {
            // Production down() intentionally refuses patient data. Cleanup is authorized
            // ONLY here after checking both actual database names, never in migrations.
            foreach (['migration', 'pgsql'] as $name) {
                if (DB::connection($name)->selectOne('SELECT current_database() AS name')->name !== 'blusser_test') {
                    throw new \RuntimeException('Refusing cleanup outside the isolated test database.');
                }
            }
            if (DB::connection('migration')->selectOne("SELECT to_regclass('public.tenants') AS name")->name !== null) {
                DB::connection('migration')->statement('TRUNCATE tenants, users CASCADE');
            }
            $exit = Artisan::call('migrate:refresh', ['--database' => 'migration', '--force' => true]);
            if ($exit !== 0) {
                throw new \RuntimeException(Artisan::output());
            }
            self::$migrated = true;
        }
        DB::connection('migration')->statement('TRUNCATE tenants, users CASCADE');
        DB::connection('migration')->statement('TRUNCATE auth_rate_limits');
    }

    protected function tearDown(): void
    {
        foreach (DB::getConnections() as $connection) {
            $connection->disconnect();
        }
        parent::tearDown();
    }

    protected function fixture(): array
    {
        $db = DB::connection('migration');
        $ids = [];
        $hash = Hash::make('Synthetic-password-42!');
        foreach (['a', 'b'] as $label) {
            foreach (['tenant', 'user', 'role', 'membership', 'branch', 'service', 'audit'] as $kind) {
                $ids[$label][$kind] = (string) Str::uuid();
            }
            $i = $ids[$label];
            $db->table('users')->insert(['id' => $i['user'], 'login' => 'synthetic.'.$label, 'name' => 'Synthetic '.$label,
                'password_hash' => $hash, 'must_change_password' => false]);
            $db->table('tenants')->insert(['id' => $i['tenant'], 'name' => 'Synthetic Clinic '.$label, 'slug' => 'synthetic-'.$label]);
            $db->table('tenant_roles')->insert(['id' => $i['role'], 'tenant_id' => $i['tenant'], 'code' => 'custom', 'name' => 'Custom synthetic role']);
            foreach ($db->table('permissions')->get() as $permission) {
                $db->table('role_permissions')->insert(['tenant_id' => $i['tenant'], 'role_id' => $i['role'], 'permission_id' => $permission->id, 'scope' => 'entire_clinic']);
            }
            $db->table('tenant_memberships')->insert(['id' => $i['membership'], 'tenant_id' => $i['tenant'], 'user_id' => $i['user'], 'role_id' => $i['role']]);
            $db->table('branches')->insert(['id' => $i['branch'], 'tenant_id' => $i['tenant'], 'name' => 'Synthetic branch', 'code' => 'main']);
            $db->table('membership_branches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $i['tenant'], 'membership_id' => $i['membership'], 'branch_id' => $i['branch']]);
            $db->table('services')->insert(['id' => $i['service'], 'tenant_id' => $i['tenant'], 'name' => 'Synthetic service', 'code' => 'basic']);
            $db->table('audit_logs')->insert(['id' => $i['audit'], 'tenant_id' => $i['tenant'], 'actor_user_id' => $i['user'], 'branch_id' => $i['branch'], 'action' => 'fixture.created', 'resource_type' => 'fixture', 'request_id' => (string) Str::uuid()]);
        }

        return $ids;
    }

    /** Target a schema version, rather than assuming a fixed number of later migrations. */
    protected function migrationStepsFrom(string $prefix): int
    {
        return DB::connection('migration')->table('migrations')->where('migration', '>=', $prefix)->count();
    }

    protected function actor(string $id): User
    {
        return (new User)->forceFill(['id' => $id, 'credential_version' => 1]);
    }

    protected function assertSqlState(string $expected, \Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Expected PostgreSQL to reject operation with '.$expected);
        } catch (QueryException $exception) {
            $this->assertSame($expected, $exception->errorInfo[0]);
        }
    }
}
