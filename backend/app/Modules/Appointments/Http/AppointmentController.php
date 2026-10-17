<?php

namespace App\Modules\Appointments\Http;

use App\Modules\Appointments\Services\Appointments;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AppointmentController
{
    public function __construct(private Appointments $appointments) {}

    public function index(Request $request)
    {
        return response()->json($this->appointments->listing($request->user(), $this->tenant($request), $this->kind($request), $this->filters($request), $request->route('patient')));
    }

    public function show(Request $request)
    {
        return response()->json($this->appointments->show($request->user(), $this->tenant($request), $this->kind($request), $this->id($request)));
    }

    public function history(Request $request)
    {
        return response()->json($this->appointments->show($request->user(), $this->tenant($request), $this->kind($request), $this->id($request), $this->filters($request, true)));
    }

    public function store(Request $request)
    {
        return $this->write($request, $this->kind($request) === 'visits' ? 'walk_in' : 'create', 201);
    }

    public function mutate(Request $request)
    {
        return $this->write($request, $request->route('operation'), 200);
    }

    public function export(Request $request)
    {
        $result = $this->appointments->listing($request->user(), $this->tenant($request), 'appointments', $this->filters($request), export: true);
        $handle = fopen('php://temp', 'w+');
        $cols = ['id', 'patient_id', 'doctor_id', 'branch_id', 'service_id', 'room_id', 'starts_at', 'ends_at', 'status', 'version'];
        fputcsv($handle, $cols, ',', '"', '');
        foreach ($result['data'] as $r) {
            fputcsv($handle, array_map(function ($k) use ($r) {
                $v = (string) $r[$k];

                return preg_match('/^[\p{Z}\p{C}\s]*[=+@\-]/u', $v) ? "'".$v : $v;
            }, $cols), ',', '"', '');
        }
        rewind($handle);
        $body = stream_get_contents($handle);
        fclose($handle);

        return response($body, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="appointments.csv"']);
    }

    private function write(Request $request, string $action, int $status)
    {
        $date = ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'];
        $rules = match ($action) {
            'create' => ['patient_id' => 'required|uuid', 'doctor_id' => 'required|uuid', 'branch_id' => 'required|uuid', 'service_id' => 'required|uuid', 'room_id' => 'required|uuid', 'starts_at' => $date, 'ends_at' => [...$date, 'after:starts_at']],
            'walk_in' => ['patient_id' => 'required|uuid', 'doctor_id' => 'required|uuid', 'branch_id' => 'required|uuid', 'service_id' => 'required|uuid'],
            'reschedule' => ['doctor_id' => 'required|uuid', 'branch_id' => 'required|uuid', 'room_id' => 'required|uuid', 'starts_at' => $date, 'ends_at' => [...$date, 'after:starts_at'], 'expected_version' => 'required|integer|min:1'],
            default => ['expected_version' => 'required|integer|min:1', 'reason_code' => 'sometimes|string|max:64'],
        };
        $this->allow($request->all(), array_keys($rules));
        $data = $request->validate($rules);
        $key = $request->header('Idempotency-Key');
        validator(['key' => $key], ['key' => 'required|uuid'])->validate();

        return response()->json($this->appointments->command($request->user(), $this->tenant($request), $action, $request->route('appointment'), $data, $key), $status);
    }

    private function filters(Request $request, bool $history = false): array
    {
        $fields = ['page', 'per_page', 'q', 'branch_id', 'doctor_id', 'status', 'from', 'to'];
        $this->allow($request->query(), $fields);
        $rules = ['page' => 'sometimes|integer|min:1|max:100000', 'per_page' => 'sometimes|integer|between:1,100',
            'q' => 'sometimes|string|max:100', 'branch_id' => 'sometimes|uuid', 'doctor_id' => 'sometimes|uuid',
            'status' => $this->kind($request) === 'visits' ? 'sometimes|in:arrived,queued,called,in_progress,completed,skipped,cancelled' : 'sometimes|in:scheduled,checked_in,in_progress,completed,cancelled,no_show',
            'from' => ['sometimes', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'],
            'to' => ['sometimes', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', ...($request->has('from') ? ['after:from'] : [])]];
        $data = $request->validate($rules);
        if (isset($data['from'],$data['to']) && strtotime($data['to']) - strtotime($data['from']) > 366 * 86400) {
            throw ValidationException::withMessages(['to' => 'Maximum filter range is 366 days.']);
        }

        return $data;
    }

    private function tenant(Request $request): string
    {
        $tid = $request->session()->get('active_tenant_id');
        abort_unless(is_string($tid), 409, 'Select an active clinic.');

        return $tid;
    }

    private function kind(Request $request): string
    {
        return $request->route('resourceKind') ?? 'appointments';
    }

    private function id(Request $request): string
    {
        return $request->route('appointment') ?? $request->route('visit');
    }

    private function allow(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw ValidationException::withMessages(['request' => 'Unknown fields are not allowed.']);
        }
    }
}
