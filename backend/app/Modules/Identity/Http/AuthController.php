<?php

namespace App\Modules\Identity\Http;

use App\Modules\Access\Services\Governance;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\Identity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class AuthController
{
    public function __construct(private Identity $identity, private Governance $db) {}

    public function login(Request $request)
    {
        $data = $request->validate(['login' => 'required|string|max:64', 'password' => 'required|string|max:128']);
        $actor = $this->identity->authenticate($data['login'], $data['password'], $request->ip());
        $this->establish($request, $actor);

        return response()->json($this->status($request, $actor));
    }

    public function show(Request $request)
    {
        return response()->json($this->status($request, $request->user()));
    }

    public function logout(Request $request)
    {
        $this->db->call('session_logout', [$request->session()->getId()], $request->user());
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::forgetGuards();

        return response()->noContent();
    }

    public function password(Request $request)
    {
        $data = $request->validate(['current_password' => 'required|string|max:128', 'password' => 'required|string|min:12|max:128|confirmed']);
        $this->identity->reserve('http-password:'.$request->user()->id, $request->ip());
        $actor = $this->identity->changePassword($request->user(), $data['current_password'], $data['password']);
        $this->establish($request, $actor);

        return response()->json($this->status($request, $actor));
    }

    public function clinics(Request $request)
    {
        return response()->json(['data' => json_decode($this->db->call('my_clinics', [], $request->user()), true, flags: JSON_THROW_ON_ERROR)]);
    }

    public function select(Request $request)
    {
        $data = $request->validate(['tenant_id' => 'required|uuid']);
        $this->db->call('select_clinic', [$data['tenant_id']], $request->user());
        $request->session()->put('active_tenant_id', $data['tenant_id']);

        return response()->noContent();
    }

    private function establish(Request $request, User $actor): void
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $this->db->call('session_start', [$request->session()->getId()], $actor);
        $request->session()->put('identity', ['id' => $actor->id, 'version' => $actor->credential_version, 'stage' => $actor->must_change_password ? 'password_change_required' : 'full']);
        Auth::forgetGuards();
    }

    private function status(Request $request, User $actor): array
    {
        return ['id' => $actor->id, 'login' => $actor->login, 'is_platform_admin' => $actor->is_platform_admin,
            'must_change_password' => $actor->must_change_password, 'active_tenant_id' => $request->session()->get('active_tenant_id')];
    }
}
