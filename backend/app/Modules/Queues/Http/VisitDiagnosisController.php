<?php

namespace App\Modules\Queues\Http;

use App\Modules\Queues\Services\VisitDiagnoses;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class VisitDiagnosisController
{
    public function __construct(private VisitDiagnoses $diagnoses) {}

    public function show(Request $request)
    {
        $this->validateStrict($request->query(), []);

        return response()->json($this->diagnoses->show(
            $request->user(), $this->tenant($request), $request->route('visit'),
        ))->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request)
    {
        $data = $this->validateStrict($request->all(), [
            'expected_version' => 'required|integer|min:0',
            'diagnosis_text' => 'required|string|min:1|max:4000',
            'diagnosis_type' => 'required|in:preliminary,final',
            'icd_code' => ['present', 'nullable', 'string', 'max:8', 'regex:/^[A-Za-z][0-9]{2}(\.[0-9A-Za-z]{1,4})?$/'],
        ]);
        $data['diagnosis_text'] = trim($data['diagnosis_text']);
        if ($data['diagnosis_text'] === '') {
            throw ValidationException::withMessages(['diagnosis_text' => 'Tashxis matni bo‘sh bo‘lmasin.']);
        }
        $data['icd_code'] = isset($data['icd_code']) ? strtoupper(trim($data['icd_code'])) : null;

        return response()->json($this->diagnoses->save(
            $request->user(), $this->tenant($request), $request->route('visit'), $data,
        ))->header('Cache-Control', 'private, no-store');
    }

    private function tenant(Request $request): string
    {
        $tenant = $request->session()->get('active_tenant_id');
        abort_unless(is_string($tenant), 409, 'Select an active clinic.');

        return $tenant;
    }

    private function validateStrict(array $data, array $rules): array
    {
        if (array_diff(array_keys($data), array_keys($rules))) {
            throw ValidationException::withMessages(['request' => 'Unknown fields are not allowed.']);
        }

        return validator($data, $rules)->validate();
    }
}
