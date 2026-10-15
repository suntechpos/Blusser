<?php

namespace App\Modules\Tenancy;

use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Internal boundary: actor must come from server-side authentication, never request input. */
final class TenantContext
{
    public function run(User $actor, string $tenantId, Closure $operation): mixed
    {
        $connection = DB::connection('pgsql');
        if ($connection->transactionLevel() !== 0) {
            throw new LogicException('Tenant context requires its own transaction; nested contexts are forbidden.');
        }
        if (! Str::isUuid($tenantId)) {
            throw new NotFoundHttpException;
        }

        return $connection->transaction(function () use ($connection, $actor, $tenantId, $operation) {
            $connection->select("SELECT set_config('app.user_id', ?, true), set_config('app.tenant_id', '', true)", [(string) $actor->getKey()]);
            $identity = $connection->table('users')->select('active', 'must_change_password', 'credential_version')->where('id', $actor->getKey())->first();
            if (! $identity || ! $identity->active || $identity->must_change_password || (int) $identity->credential_version !== $actor->credential_version) {
                throw new NotFoundHttpException;
            }
            if (! $connection->selectOne('SELECT public.is_active_member(?::uuid) AS allowed', [$tenantId])->allowed) {
                throw new NotFoundHttpException;
            }
            $connection->select("SELECT set_config('app.tenant_id', ?, true)", [$tenantId]);

            return $operation();
        });
    }
}
