<?php

namespace App\Modules\Services\Http;

use App\Modules\Scheduling\Services\Scheduling;
use App\Modules\Services\Services\ServiceCatalog;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ServiceCatalogController
{
    public function __construct(private ServiceCatalog $catalog) {}

    public function index(Request $request)
    {
        return response()->json($this->catalog->listing($request->user(), $this->tenant($request), $this->kind($request), $this->filters($request)));
    }

    public function show(Request $request)
    {
        return response()->json($this->catalog->show($request->user(), $this->tenant($request), $request->route('id')));
    }

    public function store(Request $request)
    {
        // Retain the original scheduling setup contract and its existing intent ledger.
        if ($this->kind($request) === 'services' && $request->has('code')) {
            $this->allow($request->all(), ['code', 'name', 'active']);
            $request->validate(['code' => 'required|string|max:64', 'name' => 'required|string|max:255', 'active' => 'required|boolean']);

            return response()->json(app(Scheduling::class)->command($request->user(), $this->tenant($request), 'services', 'create', null, $request->all(), $this->key($request)), 201);
        }

        return $this->write($request, 'create', 201);
    }

    public function update(Request $request)
    {
        return $this->write($request, 'update');
    }

    public function status(Request $request)
    {
        return $this->write($request, 'status');
    }

    public function export(Request $request)
    {
        $result = $this->catalog->listing($request->user(), $this->tenant($request), 'services', $this->filters($request), true);
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['Kod', 'Xizmat nomi', 'Kategoriya', 'Xizmat turi', 'Narx', 'Davomiyligi', 'Filiallar', 'Holat'], ',', '"', '');
        $categories = array_column($result['categories'], 'name', 'id');
        $branches = array_column($result['branches'], 'name', 'id');
        foreach ($result['data'] as $service) {
            $values = [$service['code'], $service['name'], $categories[$service['category_id']] ?? '', $service['service_type'], $service['price'] ?? '', $service['duration_minutes'] ?? '', implode('; ', array_map(fn ($id) => $branches[$id] ?? $id, $service['branch_ids'])), $service['active'] ? 'Faol' : 'Faol emas'];
            $values = array_map(fn ($value) => preg_match('/^[=+\-@\t\r\n]/u', (string) $value) ? "'".$value : $value, $values);
            fputcsv($stream, $values, ',', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response($csv)->header('Content-Type', 'text/csv; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="blusser-services.csv"')->header('Cache-Control', 'no-store');
    }

    private function write(Request $request, string $operation, int $status = 200)
    {
        $kind = $this->kind($request);
        $rules = $operation === 'status' ? ['active' => 'required|boolean'] : ($kind === 'categories'
            ? ['name' => 'required|string|max:255', 'description' => 'present|string|max:5000', 'color' => 'required|in:blue,cyan,green,purple,orange,pink,slate', 'active' => 'required|boolean']
            : ['name' => 'required|string|max:255', 'category_id' => 'required|uuid', 'service_type' => 'required|in:consultation,laboratory,diagnostic,procedure,other',
                'branch_ids' => 'required|array|min:1|max:100', 'branch_ids.*' => 'required|uuid|distinct', 'price' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,11})(\.[0-9]{1,2})?$/'],
                'timed' => 'required|boolean', 'duration_minutes' => 'present|nullable|integer|between:1,480', 'turnaround' => ['present', 'nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,11})(\.[0-9]{1,2})?$/'],
                'turnaround_unit' => 'required|in:hour,day', 'description' => 'present|string|max:5000', 'active' => 'required|boolean']);
        $this->allow($request->all(), array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.')));
        if (array_key_exists('description', $request->all()) && $request->input('description') === null) {
            $request->merge(['description' => '']);
        }
        $request->validate($rules);
        $expected = null;
        if ($operation !== 'create') {
            $etag = $request->header('If-Match');
            abort_unless(is_string($etag), 428, 'If-Match is required.');
            if (! preg_match('/^"?([1-9][0-9]{0,17})"?$/D', $etag, $match)) {
                throw ValidationException::withMessages(['version' => 'Invalid If-Match version.']);
            }
            $expected = (int) $match[1];
        }

        return response()->json($this->catalog->command($request->user(), $this->tenant($request), $kind, $operation, $request->route('id'), $request->all(), $expected, $this->key($request)), $status);
    }

    private function filters(Request $request): array
    {
        $this->allow($request->query(), ['page', 'per_page', 'q', 'status', 'category_id', 'branch_id', 'from', 'to']);

        return $request->validate(['page' => 'sometimes|integer|min:1|max:100000', 'per_page' => 'sometimes|integer|between:1,100', 'q' => 'sometimes|string|max:100', 'status' => 'sometimes|in:active,inactive,all', 'category_id' => 'sometimes|uuid', 'branch_id' => 'sometimes|uuid', 'from' => 'sometimes|date', 'to' => 'sometimes|date']);
    }

    private function key(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        validator(['key' => $key], ['key' => 'required|uuid'])->validate();

        return $key;
    }

    private function kind(Request $request): string
    {
        return $request->route('catalogKind', 'services');
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
