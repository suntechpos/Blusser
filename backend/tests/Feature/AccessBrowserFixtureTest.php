<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\FoundationTestCase;

/** Deliberately synthetic fixtures in the guarded test database; no production defaults. */
final class AccessBrowserFixtureTest extends FoundationTestCase
{
    public function test_prepare_idempotent_access_browser_accounts(): void
    {
        $operatorPassword = 'Synthetic-platform-browser-42!';
        $password = 'Synthetic-access-browser-42!';
        DB::connection('migration')->table('users')->insert(['id' => (string) Str::uuid(), 'login' => 'accessdemo.platform', 'name' => 'Synthetic platform', 'password_hash' => Hash::make($operatorPassword), 'must_change_password' => false, 'is_platform_admin' => true]);
        putenv('ACCESS_DEMO_OPERATOR_PASSWORD='.$operatorPassword);
        putenv('ACCESS_DEMO_PASSWORD='.$password);
        try {
            $exit = Artisan::call('blusser:access-demo');
            $this->assertSame(0, $exit, Artisan::output());
            $users = DB::connection('migration')->table('users')->count();
            $this->assertSame(5, $users);
            $this->assertSame(0, Artisan::call('blusser:access-demo'), Artisan::output());
            $this->assertSame($users, DB::connection('migration')->table('users')->count());
            $this->assertSame(3, DB::connection('migration')->table('employee_profiles')->count());
            $this->assertSame('blusser_test', DB::connection()->selectOne('SELECT current_database() AS name')->name);
            $this->assertStringNotContainsString($password, Artisan::output());
        } finally {
            putenv('ACCESS_DEMO_OPERATOR_PASSWORD');
            putenv('ACCESS_DEMO_PASSWORD');
        }
    }
}
