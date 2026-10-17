<?php

namespace App\Modules\Access\Services;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class Governance
{
    private const FUNCTIONS = ['identity_lookup', 'rate_reserve', 'rate_finish', 'password_change', 'provision', 'role_save', 'role_delete', 'branch_create', 'employee_create', 'employee_assign', 'employee_reset', 'link_request', 'pending_links', 'link_accept', 'recovery_open', 'recovery_approve', 'recovery_consume', 'login_exists', 'auth_failure', 'session_identity', 'session_start', 'session_write', 'session_logout', 'my_clinics', 'select_clinic', 'employee_directory', 'platform_clinics', 'platform_audit', 'link_reject', 'operation_denied', 'patient_command', 'patient_export_audit', 'scheduling_command', 'booking_command'];

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

                $value = $connection->selectOne('SELECT governance.'.$function.'('.$placeholders.') AS value', $parameters)->value;
                if (in_array($function, ['role_save', 'employee_create', 'employee_assign', 'employee_reset', 'link_request', 'link_accept'], true)) {
                    $resultId = in_array($function, ['employee_assign', 'employee_reset'], true) ? $parameters[0] : $value;
                    $connection->select('SELECT governance.assert_branch_delegation(?,?,?)', [$function, $resultId, $function === 'link_accept' ? $parameters[0] : null]);
                }

                return $value;
            });
        } catch (\PDOException $exception) {
            if ((($exception->errorInfo[0] ?? '') === '42501' || (in_array($function, ['patient_command', 'scheduling_command', 'booking_command'], true) && ($exception->errorInfo[0] ?? '') === 'P0002')) && $actor !== null && $function !== 'operation_denied') {
                // Original mutation has rolled back. Log only server-defined operation,
                // never parameters, searched identities, hashes or password bodies.
                try {
                    $this->call('operation_denied', [$function], $actor, $tenant);
                } catch (GovernanceDenied) { /* Invalid/revoked identities cannot author audit events. */
                }
            }
            if (in_array($function, ['patient_command', 'scheduling_command', 'booking_command'], true)) {
                throw new GovernanceDenied(match ($exception->errorInfo[0] ?? '') {
                    'P0002' => 'not_found', '23505' => 'conflict',
                    '23P01' => $function === 'booking_command' ? 'conflict' : 'forbidden',
                    '23503' => in_array($function, ['scheduling_command', 'booking_command'], true) ? 'conflict' : 'forbidden',
                    '22003' => in_array($function, ['scheduling_command', 'booking_command'], true) ? 'invalid_input' : 'forbidden',
                    '22023', '22P02', '22007', '22008', '23502', '23514' => 'invalid_input',
                    default => 'forbidden',
                });
            }
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
