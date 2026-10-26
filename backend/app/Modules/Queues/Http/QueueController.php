<?php

namespace App\Modules\Queues\Http;

use App\Modules\Queues\Services\Queues;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class QueueController
{
    public function __construct(private Queues $queues) {}

    public function index(Request $request)
    {
        $data = $this->validate($request->query(), ['page' => 'sometimes|integer|min:1|max:100000', 'per_page' => 'sometimes|integer|between:1,100', 'q' => 'sometimes|string|max:100', 'branch_id' => 'sometimes|uuid', 'doctor_id' => 'sometimes|uuid', 'status' => 'sometimes|in:waiting,called,in_progress,completed,skipped,cancelled', 'from' => $this->date('sometimes'), 'to' => [...$this->date('sometimes'), ...($request->has('from') ? ['after:from'] : [])]]);
        if (isset($data['from'], $data['to']) && strtotime($data['to']) - strtotime($data['from']) > 366 * 86400) {
            throw ValidationException::withMessages(['to' => 'Maximum filter range is 366 days.']);
        }

        return response()->json($this->queues->listing($request->user(), $this->tenant($request), $data));
    }

    public function show(Request $request)
    {
        $this->validate($request->query(), []);

        return response()->json($this->queues->show($request->user(), $this->tenant($request), $request->route('ticket')));
    }

    public function ownStartSlot(Request $request)
    {
        $this->validate($request->query(), []);

        return response()->json(
            $this->queues->ownStartSlot($request->user(), $this->tenant($request), $request->route('ticket')),
        );
    }

    public function store(Request $request)
    {
        return $this->write($request, 'create', 201);
    }

    public function mutate(Request $request)
    {
        return $this->write($request, $request->route('operation'), 200);
    }

    public function overview(Request $request)
    {
        $data = $this->validate($request->query(), ['local_date' => 'sometimes|date_format:Y-m-d', 'branch_id' => 'sometimes|uuid', 'doctor_id' => 'sometimes|uuid']);

        return response()->json($this->queues->overview($request->user(), $this->tenant($request), $data));
    }

    private function write(Request $request, string $action, int $status)
    {
        $rules = match ($action) {
            'create' => ['patient_id' => 'required|uuid', 'doctor_id' => 'required|uuid', 'branch_id' => 'required|uuid', 'service_id' => 'required|uuid'],
            'start' => ['expected_version' => 'required|integer|min:1', 'room_id' => 'required|uuid', 'starts_at' => $this->date('required'), 'ends_at' => [...$this->date('required'), 'after:starts_at']],
            default => ['expected_version' => 'required|integer|min:1', 'reason_code' => 'sometimes|string|min:1|max:64'],
        };
        $data = $this->validate($request->all(), $rules);
        $key = $request->header('Idempotency-Key');
        validator(['key' => $key], ['key' => 'required|uuid'])->validate();

        return response()->json($this->queues->command($request->user(), $this->tenant($request), $action, $request->route('ticket'), $data, $key), $status);
    }

    private function date(string $required): array
    {
        return [$required, 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'];
    }

    private function validate(array $data, array $rules): array
    {
        if (array_diff(array_keys($data), array_keys($rules))) {
            throw ValidationException::withMessages(['request' => 'Unknown fields are not allowed.']);
        }

        return validator($data, $rules)->validate();
    }

    private function tenant(Request $request): string
    {
        $tenant = $request->session()->get('active_tenant_id');
        abort_unless(is_string($tenant), 409, 'Select an active clinic.');

        return $tenant;
    }
}
