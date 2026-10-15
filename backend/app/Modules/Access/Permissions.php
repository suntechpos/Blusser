<?php

namespace App\Modules\Access;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** No implicit permission hierarchy; a scope is not a resource authorization by itself. */
final class Permissions
{
    public function scope(string $permission): ?PermissionScope
    {
        $scope = DB::connection('pgsql')->selectOne('SELECT public.permission_scope(?) AS scope', [$permission])->scope;

        return $scope === null ? null : PermissionScope::tryFrom($scope);
    }

    public function requireEntireClinic(string $permission): void
    {
        if ($this->scope($permission) !== PermissionScope::EntireClinic) {
            throw new AuthorizationException;
        }
    }
}
