<?php

namespace App\Modules\Patients\Http;

use App\Modules\Patients\Services\Patients;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PatientController
{
    public function __construct(private Patients $patients) {}

    public function index(Request $request)
    {
        return response()->json($this->patients->listing($request->user(), $this->tenant($request), $this->filters($request)));
    }

    public function show(Request $request, string $patient)
    {
        return $this->resource($this->patients->show($request->user(), $this->tenant($request), $patient));
    }

    public function store(Request $request)
    {
        $data = $this->demographics($request, true);

        return $this->write($request, 'create', null, $data, 201);
    }

    public function update(Request $request, string $patient)
    {
        $data = $this->demographics($request, false);
        abort_if($data === [], 422, 'No changes supplied.');

        $etag = $request->header('If-Match');
        if (! is_string($etag) || ! preg_match('/^"([1-9][0-9]{0,18})"$/D', $etag, $match)
            || strlen($match[1]) === 19 && strcmp($match[1], '9223372036854775807') > 0) {
            throw ValidationException::withMessages(['If-Match' => 'A single strong quoted positive integer ETag is required.']);
        }
        // Internal command metadata only; PatientUpdate JSON remains demographic-only.
        $data['expected_version'] = (int) $match[1];

        return $this->write($request, 'update', $patient, $data);
    }

    public function link(Request $request, string $patient)
    {
        $this->allow($request->all(), ['branch_id']);

        return $this->write($request, 'link_branch', $patient, $request->validate(['branch_id' => 'required|uuid']));
    }

    public function archive(Request $request, string $patient)
    {
        return $this->state($request, $patient, 'archive');
    }

    public function restore(Request $request, string $patient)
    {
        return $this->state($request, $patient, 'restore');
    }

    public function export(Request $request)
    {
        $result = $this->patients->listing($request->user(), $this->tenant($request), $this->filters($request), true);
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, ['patient_number', 'name', 'phone', 'date_of_birth', 'gender', 'archived_at'], ',', '"', '');
        foreach ($result['data'] as $row) {
            $cells = [];
            foreach (['patient_number', 'name', 'phone', 'date_of_birth', 'gender', 'archived_at'] as $column) {
                $value = (string) ($row[$column] ?? '');
                // Quote CSV syntax AND neutralize spreadsheet formulas after whitespace/control.
                $cells[] = preg_match('/^[\p{Z}\p{C}\s]*[=+@\-]/u', $value) ? "'".$value : $value;
            }
            fputcsv($handle, $cells, ',', '"', '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="patients.csv"']);
    }

    private function state(Request $request, string $patient, string $action)
    {
        $this->allow($request->all(), ['expected_version', 'reason_code']);

        return $this->write($request, $action, $patient, $request->validate(['expected_version' => 'required|integer|min:1', 'reason_code' => 'sometimes|string|max:64']));
    }

    private function demographics(Request $request, bool $create): array
    {
        $this->allow($request->all(), ['name', 'phone', 'date_of_birth', 'gender', ...($create ? ['branch_id'] : [])]);
        $required = $create ? 'required' : 'sometimes|required';
        $data = $request->validate(['name' => $required.'|string|max:255', 'phone' => 'sometimes|nullable|string|max:32',
            'date_of_birth' => $required.'|date_format:Y-m-d|before_or_equal:today',
            'gender' => 'sometimes|nullable|in:female,male,unspecified', ...($create ? ['branch_id' => 'required|uuid'] : [])]);
        if (isset($data['phone'])) {
            $data['phone'] = preg_replace('/[\s().-]/u', '', $data['phone']);
            if (! preg_match('/^\+?[0-9]{3,20}$/D', $data['phone'])) {
                throw ValidationException::withMessages(['phone' => 'Invalid phone format.']);
            }
        }

        return $data;
    }

    private function filters(Request $request): array
    {
        $this->allow($request->query(), ['page', 'per_page', 'q', 'branch_id', 'status', 'from', 'to']);

        return $request->validate(['page' => 'sometimes|integer|min:1|max:100000', 'per_page' => 'sometimes|integer|min:1|max:100',
            'q' => 'sometimes|string|max:100', 'branch_id' => 'sometimes|uuid', 'status' => 'sometimes|in:active,archived,all',
            'from' => ['sometimes', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'],
            'to' => ['sometimes', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', ...($request->has('from') ? ['after:from'] : [])]]);
    }

    private function write(Request $request, string $action, ?string $id, array $data, int $status = 200)
    {
        $key = $request->header('Idempotency-Key');
        validator(['key' => $key], ['key' => 'required|uuid'])->validate();

        return $this->resource($this->patients->command($request->user(), $this->tenant($request), $action, $id, $data, $key), $status);
    }

    private function resource(array $result, int $status = 200)
    {
        return response()->json($result['patient'], $status)->header('ETag', '"'.(int) $result['version'].'"');
    }

    private function tenant(Request $request): string
    {
        $tenant = $request->session()->get('active_tenant_id');
        abort_unless(is_string($tenant), 409, 'Select an active clinic.');

        return $tenant;
    }

    private function allow(array $input, array $fields): void
    {
        if (array_diff(array_keys($input), $fields) !== []) {
            throw ValidationException::withMessages(['request' => 'Unknown fields are not allowed.']);
        }
    }
}
