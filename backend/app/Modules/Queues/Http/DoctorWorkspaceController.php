<?php

namespace App\Modules\Queues\Http;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DoctorWorkspaceController
{
    public function __invoke(Request $request)
    {
        $rules = [
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'doctor_id' => ['sometimes', 'uuid'],
            'branch_id' => ['sometimes', 'uuid'],
        ];
        if (array_diff(array_keys($request->query()), array_keys($rules))) {
            throw ValidationException::withMessages(['request' => 'Unknown fields are not allowed.']);
        }
        $filters = validator($request->query(), $rules)->validate();
        $tenant = $request->session()->get('active_tenant_id');
        abort_unless(is_string($tenant), 409, 'Select an active clinic.');

        return response()->json(
            app(TenantContext::class)->run($request->user(), $tenant, function () use ($filters) {
                $value = DB::selectOne(
                    'SELECT governance.doctor_workspace_feed(?::date, ?::uuid, ?::uuid) AS value',
                    [$filters['date'] ?? null, $filters['doctor_id'] ?? null, $filters['branch_id'] ?? null],
                )->value;

                return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            }, consistentSnapshot: true),
        );
    }
}
