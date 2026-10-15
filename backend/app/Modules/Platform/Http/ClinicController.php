<?php

namespace App\Modules\Platform\Http;

use App\Modules\Access\Services\Governance;
use App\Modules\Identity\Services\Identity;
use App\Modules\Platform\Services\ClinicProvisioning;
use Illuminate\Http\Request;

final class ClinicController
{
    public function __construct(private ClinicProvisioning $platform, private Governance $db) {}

    public function index(Request $request)
    {
        return $this->listing($request, 'platform_clinics');
    }

    public function audit(Request $request)
    {
        return $this->listing($request, 'platform_audit');
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'slug' => 'required|string|max:100|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'ceo_login' => 'required|string|max:64', 'temporary_password' => 'nullable|string|min:12|max:128']);

        return response()->json($this->platform->createClinic($request->user(), $data['name'], $data['slug'], $data['ceo_login'], $data['temporary_password'] ?? null), 201);
    }

    public function recovery(Request $request, string $tenant)
    {
        Governance::uuid($tenant);
        $data = $request->validate(['current_password' => 'required|string|max:128']);
        app(Identity::class)->reserve('http-recovery:'.$request->user()->id, $request->ip());

        return response()->json(['id' => $this->platform->requestRecovery($request->user(), $tenant, $data['current_password'])], 201);
    }

    public function approve(Request $request, string $recovery)
    {
        Governance::uuid($recovery);
        $data = $request->validate(['current_password' => 'required|string|max:128', 'verification_reference' => 'required|string|min:3|max:100']);
        app(Identity::class)->reserve('http-recovery:'.$request->user()->id, $request->ip());
        $token = $this->platform->approveRecovery($request->user(), $recovery, $data['verification_reference'], $data['current_password']);

        return response()->json(['request_id' => $recovery, 'token' => $token->revealOnce()]);
    }

    public function recover(Request $request, string $recovery)
    {
        Governance::uuid($recovery);
        $data = $request->validate(['token' => 'required|string|size:64', 'password' => 'required|string|min:12|max:128|confirmed']);
        $this->platform->recover($recovery, $data['token'], $data['password'], $request->ip());

        return response()->noContent();
    }

    private function listing(Request $request, string $function)
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1|max:100000']);

        return response()->json(['data' => json_decode($this->db->call($function, [(($data['page'] ?? 1) - 1) * 50], $request->user()), true, flags: JSON_THROW_ON_ERROR)]);
    }
}
