<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\FoundationTestCase;

final class HealthTest extends FoundationTestCase
{
    public function test_health_checks_real_runtime_connection_and_schema(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertExactJson(['status' => 'ok'])->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_health_fails_closed_with_privileged_connection(): void
    {
        config(['database.default' => 'migration']);
        $this->getJson('/api/v1/health')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
    }

    public function test_health_does_not_leak_database_error_details(): void
    {
        config(['database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => '1']);
        DB::purge('pgsql');
        $this->getJson('/api/v1/health')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
    }

    public function test_health_fails_if_required_rls_is_disabled(): void
    {
        $owner = DB::connection('migration');
        $owner->statement('ALTER TABLE branches DISABLE ROW LEVEL SECURITY');
        try {
            $this->getJson('/api/v1/health')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
        } finally {
            $owner->statement('ALTER TABLE branches ENABLE ROW LEVEL SECURITY');
        }
    }
}
