<?php

namespace App\Modules\Cashier\Services;

use App\Modules\Access\Permissions;
use App\Modules\Access\Services\Governance;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\Identity;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class CashierPin
{
    // Non-credential timing fallback for a missing/unconfigured PIN.
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$ZHZYUXNZRlVLNFNhMWxScg$TN7f2Xfv5muFbKjURnBg/8UdeeQolWDDwyXNTBEc2Ug';

    public function verify(User $actor, string $tenant, #[\SensitiveParameter] string $pin, string $trustedIp): bool
    {
        $key = app(Identity::class)->reserve('cashier-pin:'.$tenant.':'.$actor->getKey(), 'cashier-pin:'.$trustedIp);
        $valid = false;
        try {
            $valid = app(TenantContext::class)->run($actor, $tenant, function () use ($pin) {
                abort_if(app(Permissions::class)->scope('cashier.view') === null, 403);
                // Security definer resolves ONLY the authenticated membership, never request IDs.
                try {
                    $hash = DB::connection('pgsql')->selectOne('SELECT governance.cashier_pin_record() AS value')->value;
                } catch (QueryException $e) {
                    abort_if(($e->errorInfo[0] ?? '') === '42501', 403);
                    throw $e;
                }
                $configured = is_string($hash) && str_starts_with($hash, '$argon2id$');
                $matches = Hash::driver('argon2id')->check($pin, $configured ? $hash : self::DUMMY_HASH);

                return $configured && $matches;
            });

            return $valid;
        } finally {
            // Reservation is committed outside verification's transaction; failures cannot roll it back.
            app(Governance::class)->call('rate_finish', [$key, $valid]);
        }
    }
}
