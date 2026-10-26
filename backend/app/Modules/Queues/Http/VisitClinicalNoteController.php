<?php

namespace App\Modules\Queues\Http;

use App\Modules\Queues\Services\VisitClinicalNotes;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class VisitClinicalNoteController
{
    public function __construct(private VisitClinicalNotes $notes) {}

    public function show(Request $request)
    {
        $this->validateStrict($request->query(), []);

        return response()->json($this->notes->show(
            $request->user(), $this->tenant($request), $request->route('visit'),
        ))->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request)
    {
        $data = $this->validateStrict($request->all(), [
            'expected_version' => 'required|integer|min:0',
            'complaints' => 'required|string|max:10000',
            'anamnesis' => 'required|string|max:10000',
            'examination' => 'required|string|max:10000',
        ]);
        // Empty strings are valid: clinicians can draft individual fields, and
        // deliberately clear fields. Laravel's default null-normalizer turns
        // empty request strings to null, so accept null values as empty text.
        return response()->json($this->notes->save(
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
