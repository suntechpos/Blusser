<?php

namespace App\Modules\Access\Services;

use App\Modules\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class Governance
{
    private const FUNCTIONS = ['identity_lookup', 'rate_reserve', 'rate_finish', 'password_change', 'provision', 'role_save', 'role_delete', 'branch_create', 'employee_create', 'employee_assign', 'employee_reset', 'link_request', 'pending_links', 'link_accept', 'recovery_open', 'recovery_approve', 'recovery_consume', 'login_exists', 'auth_failure'];

    public function call(string $function, #[\SensitiveParameter] array $parameters = [], ?User $actor = null, ?string $tenant = null, bool $reauthenticated = false): mixed
    {
        if (! in_array($function, self::FUNCTIONS, true)) {
            throw new LogicException('Unknown governance operation.');
        }
        $connection = DB::connection('pgsql');
        if ($connection->transactionLevel() !== 0) {
            throw new LogicException('Governance operation requires its own transaction.');
        }
        try {
            return $connection->transaction(function () use ($connection, $function, $parameters, $actor, $tenant, $reauthenticated) {
                $connection->select("SELECT set_config('app.user_id',?,true), set_config('app.tenant_id',?,true), set_config('app.credential_version',?,true), set_config('app.reauthenticated',?,true)", [
                    $actor ? (string) $actor->getKey() : '', $tenant ?? '', $actor ? (string) $actor->credential_version : '', $reauthenticated ? 'true' : '',
                ]);
                $placeholders = implode(',', array_fill(0, count($parameters), '?'));

                return $connection->selectOne('SELECT governance.'.$function.'('.$placeholders.') AS value', $parameters)->value;
            });
        } catch (QueryException $exception) {
            throw new GovernanceDenied(($exception->errorInfo[0] ?? '') === '23505' ? 'conflict' : 'forbidden');
        }
    }

    public static function login(string $value): string
    {
        $value = strtolower(trim($value));
        if (! preg_match('/\A[a-z0-9._-]{3,64}\z/D', $value)) {
            throw new InvalidArgumentException('Invalid login format.');
        }

        return $value;
    }

    public static function uuid(string $value): string
    {
        if (! Str::isUuid($value)) {
            throw new InvalidArgumentException('Invalid identifier.');
        }

        return $value;
    }

    public static function uuids(?array $values): ?string
    {
        return $values === null ? null : '{'.implode(',', array_map(self::uuid(...), $values)).'}';
    }

    public static function text(string $value, int $limit = 255): string
    {
        if (trim($value) === '' || mb_strlen($value) > $limit) {
            throw new InvalidArgumentException('Invalid text length.');
        }

        return $value;
    }

    public static function password(#[\SensitiveParameter] string $value): void
    {
        if (mb_strlen($value) < 12 || mb_strlen($value) > 128) {
            throw new InvalidArgumentException('Password must contain 12 to 128 characters.');
        }
    }
}
