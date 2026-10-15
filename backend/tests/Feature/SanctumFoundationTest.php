<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\FoundationTestCase;

final class SanctumFoundationTest extends FoundationTestCase
{
    public function test_csrf_handshake_creates_only_an_anonymous_session(): void
    {
        config(['session.driver' => 'versioned']);
        $this->get('/sanctum/csrf-cookie')->assertNoContent()->assertCookie('XSRF-TOKEN')->assertCookie(config('session.cookie'));
        $session = DB::table('sessions')->first();
        $this->assertNotNull($session);
        $this->assertNull($session->user_id);
        $this->assertNull($session->credential_version);
        $this->assertSame('anonymous', $session->auth_stage);
    }

    public function test_sanctum_guard_rejects_unauthenticated_and_bearer_requests(): void
    {
        // Test-only route: no authenticated business API is exposed in Sprint 1.
        Route::middleware('auth:sanctum')->get('/api/test-protected', fn () => ['unexpected' => true]);
        $this->getJson('/api/test-protected')->assertUnauthorized();
        $this->withToken('synthetic-not-a-token')->getJson('/api/test-protected')->assertUnauthorized();
    }

    public function test_no_public_registration_endpoints_are_exposed(): void
    {
        $this->postJson('/api/v1/auth/register', [])->assertNotFound();
        $this->getJson('/api/v1/clinics')->assertNotFound();
    }

    public function test_state_changing_web_request_without_csrf_token_is_rejected(): void
    {
        // Laravel normally skips CSRF in tests. Exercise the real middleware here.
        $this->app->detectEnvironment(fn () => 'local');
        Route::middleware('web')->post('/test-csrf', fn () => ['unexpected' => true]);
        $this->postJson('/test-csrf')->assertStatus(419);
    }
}
