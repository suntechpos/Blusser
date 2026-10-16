<?php

namespace Tests\Feature;

use App\Modules\ClinicOperations\Contracts\OperationsContract;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ClinicOperationsContractTest extends TestCase
{
    private function document(): array
    {
        return json_decode(file_get_contents(base_path('docs/openapi-sprint-3.yaml')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_planned_operations_do_not_claim_to_be_deployed_or_replace_existing_routes(): void
    {
        $document = $this->document();
        $this->assertSame('planned', $document['x-implementation-status']);
        $this->assertSame(OperationsContract::VERSION, $document['x-contract-version']);
        $published = json_decode(file_get_contents(base_path('docs/openapi.yaml')), true, flags: JSON_THROW_ON_ERROR);
        $catalog = OperationsContract::permissions();
        $this->assertSame($catalog, $document['x-permission-catalog']);
        $existing = ['services.view', 'dashboard.view'];
        $ids = [];
        foreach ($document['paths'] as $path => $methods) {
            $this->assertStringStartsWith('/api/v1/clinic/', $path);
            foreach ($methods as $method => $operation) {
                $this->assertArrayNotHasKey($method, $published['paths'][$path] ?? []);
                foreach (Route::getRoutes() as $route) {
                    $this->assertFalse('/'.$route->uri() === $path && in_array(strtoupper($method), $route->methods(), true));
                }
                $this->assertSame('planned', $operation['x-implementation-status']);
                $this->assertSame([['cookieAuth' => []]], $operation['security']);
                $this->assertNotContains($operation['operationId'], $ids);
                $ids[] = $operation['operationId'];
                $this->assertNotEmpty($operation['x-required-permissions']);
                foreach ($operation['x-required-permissions'] as $permission) {
                    $this->assertTrue(isset($catalog[$permission]) || in_array($permission, $existing, true), $permission);
                }
                foreach (['401', '403', '404', '409', '419', '422', '429'] as $status) {
                    $this->assertArrayHasKey($status, $operation['responses']);
                }
                if ($method !== 'get') {
                    $headers = array_column(array_filter($operation['parameters'], fn ($p) => $p['in'] === 'header'), 'required', 'name');
                    $this->assertTrue($headers['X-XSRF-TOKEN']);
                    $this->assertTrue($headers['Idempotency-Key']);
                    $this->assertArrayHasKey('requestBody', $operation);
                }
            }
        }
        $this->assertGreaterThan(40, count($ids));
        $this->assertReferences($document, $document);
    }

    public function test_sensitive_actions_cannot_be_branch_or_own_grants_and_inputs_cannot_supply_identity(): void
    {
        $catalog = OperationsContract::permissions();
        foreach (['patients.archive', 'patients.restore', 'patients.export', 'appointments.export'] as $permission) {
            $this->assertSame(['entire_clinic'], $catalog[$permission]);
        }
        $this->assertNotContains('own', $catalog['patients.create']);
        $this->assertContains('own', $catalog['patients.view']);
        $this->assertArrayHasKey('patients.link_branch', $catalog);
        $document = $this->document();
        foreach ($document['components']['schemas'] as $name => $schema) {
            if (! str_ends_with($name, 'Input') && $name !== 'PatientUpdate') {
                continue;
            }
            $this->assertFalse($schema['additionalProperties'], $name);
            foreach (['tenant_id', 'user_id', 'owner_id', 'created_by', 'is_platform_admin', 'status'] as $field) {
                $this->assertArrayNotHasKey($field, $schema['properties'], $name);
            }
        }
    }

    public function test_transition_graph_has_no_terminal_escape_and_statuses_match_api_contract(): void
    {
        $schemas = $this->document()['components']['schemas'];
        foreach (['appointment' => 'Appointment', 'visit' => 'Visit', 'queue_ticket' => 'QueueTicket'] as $entity => $schema) {
            $statuses = $schemas[$schema]['properties']['status']['enum'];
            foreach (OperationsContract::transitions()[$entity] as [$from, $to]) {
                $this->assertContains($to, $statuses);
                foreach ($from as $state) {
                    $this->assertContains($state, $statuses);
                    $this->assertNotContains($state, ['completed', 'cancelled', 'no_show']);
                }
            }
        }
        $graph = OperationsContract::transitions();
        $this->assertSame($graph, $this->document()['x-state-machines']);
        $this->assertSame([['scheduled'], 'checked_in'], $graph['appointment']['check_in']);
        $this->assertSame([['checked_in'], 'cancelled'], $graph['appointment']['queue_cancel']);
        $this->assertSame([['called'], 'called'], $graph['queue_ticket']['recall']);
        $this->assertSame([['skipped'], 'waiting'], $graph['queue_ticket']['requeue']);
        $this->assertSame([['called'], 'in_progress'], $graph['queue_ticket']['start']);
    }

    private function assertReferences(array $node, array $document): void
    {
        if (isset($node['$ref'])) {
            $this->assertStringStartsWith('#/', $node['$ref']);
            $target = $document;
            foreach (explode('/', substr($node['$ref'], 2)) as $segment) {
                $this->assertArrayHasKey($segment, $target);
                $target = $target[$segment];
            }
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $this->assertReferences($value, $document);
            }
        }
    }
}
