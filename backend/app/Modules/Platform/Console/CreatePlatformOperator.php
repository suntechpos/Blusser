<?php

namespace App\Modules\Platform\Console;

use App\Modules\Access\Services\Governance;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Deployment-only command: never called by the runtime API. */
final class CreatePlatformOperator extends Command
{
    protected $signature = 'blusser:platform-admin {login}';

    protected $description = 'Create an audited platform operator using migration credentials and hidden password prompts';

    public function handle(): int
    {
        if (config('database.connections.migration.username') !== 'blusser_owner') {
            $this->error('Separate migration credentials are required.');

            return self::FAILURE;
        }
        $db = DB::connection('migration');
        if ($db->selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            return self::FAILURE;
        }
        $login = Governance::login($this->argument('login'));
        if ($db->table('users')->where('login', $login)->exists()) {
            $this->error('Login unavailable; existing identities are never promoted or overwritten.');

            return self::FAILURE;
        }
        $password = $this->secret('Password (12–128 characters)', false);
        Governance::password($password ?? '');
        if (! hash_equals($password, (string) $this->secret('Confirm password', false))) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }
        $hash = Hash::make($password);
        unset($password);
        try {
            $db->transaction(function () use ($db, $login, $hash) {
                $id = (string) Str::uuid();
                $db->table('users')->insert(['id' => $id, 'login' => $login, 'name' => 'Platform operator', 'password_hash' => $hash, 'must_change_password' => false, 'is_platform_admin' => true]);
                $db->table('audit_logs')->insert(['id' => (string) Str::uuid(), 'actor_user_id' => $id, 'action' => 'platform.operator_created', 'resource_type' => 'user', 'resource_id' => $id, 'request_id' => (string) Str::uuid()]);
            });
        } catch (QueryException) {
            $this->error('Creation failed; no identity was changed.');

            return self::FAILURE;
        }
        $this->info('Platform operator created. No password was printed.');

        return self::SUCCESS;
    }
}
