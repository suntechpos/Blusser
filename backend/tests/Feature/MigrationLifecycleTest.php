<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\FoundationTestCase;

final class MigrationLifecycleTest extends FoundationTestCase
{
    public function test_rolling_back_only_security_migration_does_not_leave_column_grants_open(): void
    {
        try {
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'migration', '--step' => 4, '--force' => true]));
            $this->assertSqlState('42501', fn () => DB::table('users')->select('id')->get());
            $this->assertSqlState('42501', fn () => DB::table('sessions')->get());
            $this->getJson('/api/v1/health')->assertStatus(503);
        } finally {
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'migration', '--force' => true]));
        }
    }

    public function test_migrations_are_repeatable_and_rollback_removes_schema_and_helpers(): void
    {
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'migration', '--force' => true]));
        $this->assertStringContainsString('Nothing to migrate', Artisan::output());
        try {
            $this->assertSame(0, Artisan::call('migrate:reset', ['--database' => 'migration', '--force' => true]));
            $this->assertNull(DB::connection('migration')->selectOne("SELECT to_regclass('public.users') AS name")->name);
            $this->assertNull(DB::connection('migration')->selectOne("SELECT to_regprocedure('public.permission_scope(text)') AS name")->name);
        } finally {
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'migration', '--force' => true]));
        }
        $this->assertSame('ok', $this->getJson('/api/v1/health')->assertOk()->json('status'));
    }
}
