<?php

namespace App\Modules\Queues\Services;

use App\Modules\Access\Services\Governance;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class VisitRecommendations
{
    public function show(User $actor, string $tenant, string $visit): array
    {
        return app(TenantContext::class)->run($actor, $tenant, function () use ($visit) {
            $json = DB::selectOne(
                'SELECT governance.doctor_recommendation_read(?::uuid) AS value', [$visit],
            )->value;
            return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        }, consistentSnapshot: true);
    }

    public function save(User $actor, string $tenant, string $visit, array $body): array
    {
        $version = $body['expected_version'];
        unset($body['expected_version']);
        return json_decode(app(Governance::class)->call(
            'doctor_recommendation_save',
            [$visit, $version, json_encode($body, JSON_THROW_ON_ERROR)],
            $actor, $tenant,
        ), true, flags: JSON_THROW_ON_ERROR);
    }
}
