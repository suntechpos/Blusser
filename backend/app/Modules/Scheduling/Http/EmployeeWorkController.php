<?php

namespace App\Modules\Scheduling\Http;

use App\Modules\Access\Services\Governance;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class EmployeeWorkController
{
    public function show(Request $request, string $employee)
    {
        $data = $request->validate(['branch_id' => 'required|uuid']);

        return response()->json(json_decode(app(Governance::class)->call('employee_work_read', [$employee, $data['branch_id']], $request->user(), $this->tenant($request)), true));
    }

    public function save(Request $request, string $employee)
    {
        if (array_diff(array_keys($request->all()), ['branch_id', 'days', 'confirmed_removals'])) {
            throw ValidationException::withMessages(['payload' => 'Unknown fields.']);
        }
        $data = $request->validate(['branch_id' => 'required|uuid', 'days' => 'present|array|max:7', 'days.*' => 'array:weekday,start_time,end_time,receives_patients', 'days.*.weekday' => 'required|integer|between:1,7|distinct', 'days.*.start_time' => 'required|date_format:H:i', 'days.*.end_time' => 'required|date_format:H:i', 'days.*.receives_patients' => 'required|boolean', 'confirmed_removals' => 'present|array|max:7', 'confirmed_removals.*' => 'integer|between:1,7|distinct']);
        $key = $request->header('Idempotency-Key');
        validator(['key' => $key], ['key' => 'required|uuid'])->validate();
        $match = $request->header('If-Match');
        if (! preg_match('/^"?(\d+)"?$/', $match ?? '', $m)) {
            throw ValidationException::withMessages(['version' => 'If-Match required.']);
        }

        return response()->json(json_decode(app(Governance::class)->call('employee_work_save', [$employee, $data['branch_id'], json_encode($data['days']), json_encode($data['confirmed_removals']), (int) $m[1], $key], $request->user(), $this->tenant($request)), true));
    }

    private function tenant(Request $request): string
    {
        $tenant = $request->session()->get('active_tenant_id');
        abort_unless(is_string($tenant), 409, 'Select an active clinic.');

        return $tenant;
    }
}
