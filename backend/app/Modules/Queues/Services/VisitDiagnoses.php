<?php

namespace App\Modules\Queues\Services;

use App\Modules\Access\Services\Governance;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class VisitDiagnoses
{
    public function show(User $actor, string $tenant, string $visit): array
    {
        return app(TenantContext::class)->run($actor, $tenant, function () use ($visit) {
            $json = DB::selectOne(
                'SELECT governance.doctor_diagnosis_read(?::uuid) AS value',
                [$visit],
            )->value;

            return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        }, consistentSnapshot: true);
    }

    public function save(User $actor, string $tenant, string $visit, array $body): array
    {
        $version = $body['expected_version'];
        unset($body['expected_version']);

        // Governance runs under the signed-in actor's tenant context.
        // No direct write to clinical tables is permitted for blusser_app.
        return json_decode(
            app(Governance::class)->call(
                'doctor_diagnosis_save',
                [$visit, $version, json_encode($body, JSON_THROW_ON_ERROR)],
                $actor,
                $tenant,
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
