<?php

namespace App\Modules\Scheduling\Http;

use App\Modules\Scheduling\Services\Availability;
use App\Modules\Scheduling\Services\Scheduling;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class SchedulingController
{
    public function __construct(private Scheduling $scheduling) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);

        return response()->json($this->scheduling->listing($request->user(), $this->tenant($request), $request->route('resourceKind'), $filters));
    }

    public function show(Request $request)
    {
        return response()->json($this->scheduling->show($request->user(), $this->tenant($request), $request->route('resourceKind'), $request->route('id')));
    }

    public function store(Request $request)
    {
        return $this->write($request, 'create', 201);
    }

    public function update(Request $request)
    {
        return $this->write($request, 'update', 200);
    }

    public function availability(Request $request)
    {
        $filters = $this->filters($request, true);

        return response()->json(app(Availability::class)->get($request->user(), $this->tenant($request), $request->route('doctor'), $filters))
            ->header('X-Availability-Mode', 'current-occupancy-hint');
    }

    private function write(Request $request, string $action, int $status)
    {
        $key = $request->header('Idempotency-Key');
        validator(['key' => $key], ['key' => 'required|uuid'])->validate();
        $kind = $request->route('resourceKind');
        $rules = match ($kind) {
            'specialties' => ['code' => 'required|string|max:64', 'name' => 'required|string|max:255', 'active' => 'required|boolean'],
            'doctors' => ['membership_id' => 'required|uuid', 'specialty_id' => 'required|uuid', 'branch_ids' => 'required|array|min:1|max:100', 'branch_ids.*' => 'required|uuid|distinct', 'service_ids' => 'required|array|min:1|max:100', 'service_ids.*' => 'required|uuid|distinct', 'active' => 'required|boolean'],
            'rooms' => ['branch_id' => 'required|uuid', 'number' => 'required|string|max:255', 'service_type' => 'required|string|max:255', 'active' => 'required|boolean', 'availability' => 'required|in:available,unavailable'],
            'schedules' => $this->scheduleRules(),
            'schedule-exceptions' => ['doctor_id' => 'required|uuid', 'branch_id' => 'required|uuid', 'local_date' => 'required|date_format:Y-m-d', 'kind' => 'required|in:closed,replace', 'replacement' => 'present|nullable|array'],
        };
        $fields = array_filter(array_keys($rules), fn ($k) => ! str_contains($k, '.'));
        $this->allow($request->all(), $fields);
        $request->validate($rules);

        // The DB repeats strict shape/type/range checks, including replacement payloads.
        return response()->json($this->scheduling->command($request->user(), $this->tenant($request), $kind, $action, $request->route('id'), $request->all(), $key), $status);
    }

    private function scheduleRules(): array
    {
        return ['doctor_id' => 'required|uuid', 'branch_id' => 'required|uuid', 'room_id' => 'required|uuid', 'weekday' => 'required|integer|between:1,7',
            'local_start' => 'required|date_format:H:i', 'local_end' => 'required|date_format:H:i', 'slot_minutes' => 'required|integer|between:1,480',
            'effective_from' => 'required|date_format:Y-m-d', 'effective_to' => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:effective_from',
            'breaks' => 'present|array|max:100', 'breaks.*' => 'array:local_start,local_end', 'breaks.*.local_start' => 'required|date_format:H:i', 'breaks.*.local_end' => 'required|date_format:H:i'];
    }

    private function filters(Request $request, bool $availability = false): array
    {
        $this->allow($request->query(), ['page', 'per_page', 'q', 'branch_id', 'doctor_id', 'status', 'from', 'to', ...($availability ? ['service_id'] : [])]);
        $required = $availability ? 'required' : 'sometimes';
        $rules = ['page' => 'sometimes|integer|min:1|max:100000', 'per_page' => 'sometimes|integer|between:1,100', 'q' => 'sometimes|string|max:100',
            'branch_id' => $required.'|uuid', 'doctor_id' => 'sometimes|uuid', 'status' => 'sometimes|in:active,inactive,all',
            'from' => [$required, 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'],
            'to' => [$required, 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', ...($request->has('from') ? ['after:from'] : [])],
            ...($availability ? ['service_id' => 'required|uuid'] : [])];
        $filters = $request->validate($rules);
        if ($availability) {
            if (isset($filters['doctor_id']) && $filters['doctor_id'] !== $request->route('doctor')) {
                throw ValidationException::withMessages(['doctor_id' => 'Doctor must match the path.']);
            }
            if ((new \DateTimeImmutable($filters['to']))->getTimestamp() - (new \DateTimeImmutable($filters['from']))->getTimestamp() > 32 * 86400) {
                throw ValidationException::withMessages(['to' => 'Availability is limited to 31 local days.']);
            }
        }

        return $filters;
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
