<?php

namespace App\Modules\Queues\Http;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DisplayController
{
    public function index(Request $request)
    {
        $data = $request->validate(['branch_id' => 'required|uuid']);
        return $this->run($request, 'SELECT governance.display_index(?::uuid) AS value', [$data['branch_id']], true);
    }

    public function feed(Request $request)
    {
        $data = $request->validate(['branch_id' => 'required|uuid']);
        return $this->run($request, 'SELECT governance.display_feed(?::uuid,?::uuid) AS value', [$data['branch_id'], $request->route('display')], true);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'branch_id' => 'required|uuid',
            'expected_version' => 'required|integer|min:0',
            'name' => 'required|string|max:70',
            'zone' => 'required|string|max:70',
            'floor' => 'required|string|max:70',
            'room_ids' => 'required|array|min:1|max:100',
            'room_ids.*' => 'required|uuid|distinct',
            'sound' => 'required|boolean',
            'theme' => 'required|in:dark,light',
            'language' => 'required|in:uz,ru',
        ]);
        $branch = $data['branch_id'];
        $version = $data['expected_version'];
        unset($data['branch_id'], $data['expected_version']);
        $data['sound'] = (bool) $data['sound'];

        return $this->run($request, 'SELECT governance.display_save(?::uuid,?::uuid,?::bigint,?::jsonb) AS value', [$branch, $request->route('display'), $version, json_encode($data, JSON_THROW_ON_ERROR)]);
    }

    public function destroy(Request $request)
    {
        $data = $request->validate(['branch_id' => 'required|uuid', 'expected_version' => 'required|integer|min:1']);
        return $this->run($request, 'SELECT governance.display_delete(?::uuid,?::uuid,?::bigint) AS value', [$data['branch_id'], $request->route('display'), $data['expected_version']]);
    }

    private function run(Request $request, string $sql, array $params, bool $read = false)
    {
        $tenant = $request->session()->get('active_tenant_id');
        abort_unless(is_string($tenant), 409, 'Select an active clinic.');
        try {
            $value = app(TenantContext::class)->run($request->user(), $tenant, fn () => DB::selectOne($sql, $params)->value, consistentSnapshot: $read);
        } catch (QueryException $e) {
            abort(match ($e->errorInfo[0] ?? '') {
                '42501' => 403, 'P0002' => 404, '40001', '23505' => 409,
                '22023', '22P02', '23514', '23502' => 422, default => 503,
            });
        }

        return response()->json(json_decode($value, true, flags: JSON_THROW_ON_ERROR))->header('Cache-Control', 'no-store, private');
    }
}
