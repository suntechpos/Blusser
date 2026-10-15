<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\FoundationTestCase;

final class PlatformBootstrapTest extends FoundationTestCase
{
    public function test_controlled_cli_bootstrap_is_hash_only_and_audited(): void
    {
        $this->artisan('blusser:platform-admin', ['login' => 'synthetic.operator'])
            ->expectsQuestion('Password (12–128 characters)', 'Synthetic-bootstrap-42!')
            ->expectsQuestion('Confirm password', 'Synthetic-bootstrap-42!')
            ->assertSuccessful();
        $user = DB::connection('migration')->table('users')->where('login', 'synthetic.operator')->first();
        $this->assertTrue($user->is_platform_admin);
        $this->assertTrue(Hash::check('Synthetic-bootstrap-42!', $user->password_hash));
        $this->assertFalse($user->must_change_password);
        $this->assertSame(1, DB::connection('migration')->table('audit_logs')->where('action', 'platform.operator_created')->count());
        $this->artisan('blusser:platform-admin', ['login' => 'synthetic.operator'])->assertFailed();
    }

    public function test_runtime_credentials_cannot_create_platform_operator(): void
    {
        config(['database.connections.migration.username' => 'blusser_app']);
        $this->artisan('blusser:platform-admin', ['login' => 'forbidden.operator'])->assertFailed();
        $this->assertFalse(DB::connection('migration')->table('users')->where('login', 'forbidden.operator')->exists());
    }
}
