<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class OpenApiContractTest extends TestCase
{
    public function test_every_published_endpoint_has_a_unique_documented_operation_and_correct_security(): void
    {
        // JSON is a valid YAML 1.2 serialization; no extra runtime YAML dependency.
        $document = json_decode(file_get_contents(base_path('docs/openapi.yaml')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('3.1.0', $document['openapi']);
        $actual = [];
        foreach (Route::getRoutes() as $route) {
            $path = '/'.$route->uri();
            if (! str_starts_with($path, '/api/v1/') && $path !== '/sanctum/csrf-cookie') {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $actual[$path][strtolower($method)] = true;
            }
        }
        $ids = [];
        foreach ($document['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $this->assertTrue($actual[$path][$method] ?? false, $method.' '.$path.' is not a real route.');
                unset($actual[$path][$method]);
                $this->assertNotContains($operation['operationId'], $ids);
                $ids[] = $operation['operationId'];
                $public = in_array($path, ['/api/v1/health', '/sanctum/csrf-cookie', '/api/v1/auth/login']) || str_starts_with($path, '/api/v1/auth/ceo-recovery/');
                $this->assertSame($public ? [] : [['cookieAuth' => []]], $operation['security']);
                if ($method !== 'get') {
                    $this->assertContains('X-XSRF-TOKEN', array_column($operation['parameters'], 'name'));
                    $this->assertArrayHasKey('419', $operation['responses']);
                    $this->assertArrayHasKey('422', $operation['responses']);
                }
                foreach ($operation['responses'] as $status => $response) {
                    if (isset($response['$ref'])) {
                        $response = $document['components']['responses'][basename($response['$ref'])];
                    }
                    $this->assertArrayHasKey('Cache-Control', $response['headers'] ?? [], strtoupper($method).' '.$path.' response '.$status.' must document the no-store policy.');
                }
            }
        }
        foreach ($actual as $path => $methods) {
            $this->assertSame([], $methods, 'Undocumented endpoint: '.$path);
        }
        $this->walkReferences($document, $document);
    }

    private function walkReferences(array $node, array $document): void
    {
        if (isset($node['$ref'])) {
            $target = $document;
            foreach (explode('/', substr($node['$ref'], 2)) as $segment) {
                $this->assertArrayHasKey($segment, $target, 'Broken OpenAPI reference: '.$node['$ref']);
                $target = $target[$segment];
            }
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $this->walkReferences($value, $document);
            }
        }
    }
}
