<?php

namespace App\Modules\Tenancy\Http;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ContextController
{
    public function __invoke(Request $request)
    {
        $tenant = $request->session()->get('active_tenant_id');
        abort_unless(is_string($tenant), 409, 'Select an active clinic.');

        return app(TenantContext::class)->run($request->user(), $tenant, function () use ($request) {
            abort_unless($request->query() === [], 422);
            $membership = DB::table('tenant_memberships')->where('tenant_id', DB::raw('public.context_tenant()'))->where('user_id', $request->user()->id)->where('active', true)->value('id');
            $grants = DB::table('permissions')->selectRaw('code, public.permission_scope(code) AS scope')->get()->filter(fn ($p) => $p->scope !== null)->pluck('scope', 'code');

            return response()->json(['membership_id' => $membership, 'grants' => (object) $grants->all(), 'branches' => json_decode(DB::selectOne('SELECT governance.self_branches() AS value')->value, true), 'timezone' => DB::table('tenants')->where('id', DB::raw('public.context_tenant()'))->value('timezone')]);
        }, consistentSnapshot: true);
    }
}
