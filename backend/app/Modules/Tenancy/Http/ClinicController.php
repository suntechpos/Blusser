<?php

namespace App\Modules\Tenancy\Http;

use App\Modules\Access\Permissions;
use App\Modules\Access\Services\Governance;
use App\Modules\Identity\Services\Identity;
use App\Modules\Tenancy\Services\ClinicAdministration;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ClinicController
{
    public function __construct(private ClinicAdministration $clinic, private Governance $db) {}

    public function branches(Request $request, ?string $branch = null)
    {
        return $this->read($request, 'branches', 'branches.view', ['id', 'name', 'code', 'active'], $branch);
    }

    public function createBranch(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'code' => 'required|string|max:64']);

        return response()->json(['id' => $this->clinic->createBranch($request->user(), $this->tenant($request), $data['name'], $data['code'])], 201);
    }

    public function roles(Request $request, ?string $role = null)
    {
        if ($role !== null) {
            Governance::uuid($role);
        }
        $offset = $this->offset($request);

        return $this->context($request, function () use ($role, $offset) {
            app(Permissions::class)->requireEntireClinic('roles.view');
            $query = DB::table('tenant_roles')->where('tenant_id', $this->currentTenant());
            if ($role !== null) {
                $query->where('id', $role);
            }
            $rows = $query->select('id', 'code', 'name', 'active', 'protected')->orderBy('id')->offset($offset)->limit(50)->get();
            foreach ($rows as $row) {
                $row->grants = DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('rp.tenant_id', $this->currentTenant())->where('rp.role_id', $row->id)->select('p.code', 'rp.scope')->orderBy('p.code')->get();
            }
            if ($role !== null) {
                abort_if($rows->isEmpty(), 404);

                return response()->json($rows->first());
            }

            return response()->json(['data' => $rows]);
        });
    }

    public function permissions(Request $request)
    {
        return $this->context($request, function () {
            app(Permissions::class)->requireEntireClinic('roles.view');
            $rows = DB::table('permissions')->select('code', 'allowed_scopes')->orderBy('code')->get();
            foreach ($rows as $row) {
                $row->allowed_scopes = explode(',', trim($row->allowed_scopes, '{}'));
            }

            return response()->json(['data' => $rows]);
        });
    }

    public function saveRole(Request $request, ?string $role = null)
    {
        $data = $request->validate(['code' => 'required|string|max:64', 'name' => 'required|string|max:255', 'grants' => 'present|array|max:100', 'grants.*.code' => 'required|string|max:100|distinct', 'grants.*.scope' => 'required|in:own,assigned_branches,entire_clinic']);

        return response()->json(['id' => $this->clinic->saveRole($request->user(), $this->tenant($request), $role, $data['code'], $data['name'], $data['grants'])], $role === null ? 201 : 200);
    }

    public function deleteRole(Request $request, string $role)
    {
        $this->clinic->deleteRole($request->user(), $this->tenant($request), $role);

        return response()->noContent();
    }

    public function employees(Request $request, ?string $employee = null)
    {
        if ($employee !== null) {
            Governance::uuid($employee);
        }
        $rows = json_decode($this->db->call('employee_directory', [$employee, $this->offset($request)], $request->user(), $this->tenant($request)), true, flags: JSON_THROW_ON_ERROR);
        if ($employee !== null) {
            abort_if($rows === [], 404);

            return response()->json($rows[0]);
        }

        return response()->json(['data' => $rows]);
    }

    public function createEmployee(Request $request)
    {
        $data = $request->validate(['login' => 'required|string|max:64', 'name' => 'required|string|max:255', 'temporary_password' => 'required|string|min:12|max:128', 'role_id' => 'required|uuid', 'branch_ids' => 'sometimes|array|max:100', 'branch_ids.*' => 'required|uuid|distinct']);

        return response()->json(['id' => $this->clinic->createEmployee($request->user(), $this->tenant($request), $data['login'], $data['name'], $data['temporary_password'], $data['role_id'], $data['branch_ids'] ?? [])], 201);
    }

    public function assignEmployee(Request $request, string $employee)
    {
        $data = $request->validate(['role_id' => 'sometimes|required|uuid', 'branch_ids' => 'sometimes|required|array|max:100', 'branch_ids.*' => 'required|uuid|distinct', 'active' => 'sometimes|required|boolean']);
        abort_if($data === [], 422, 'No changes supplied.');
        $this->clinic->assignEmployee($request->user(), $this->tenant($request), $employee, $data['role_id'] ?? null, $data['branch_ids'] ?? null, $data['active'] ?? null);

        return response()->noContent();
    }

    public function removeEmployee(Request $request, string $employee)
    {
        $this->clinic->assignEmployee($request->user(), $this->tenant($request), $employee, active: false);

        return response()->noContent();
    }

    public function resetEmployee(Request $request, string $employee)
    {
        $data = $request->validate(['current_password' => 'required|string|max:128', 'temporary_password' => 'required|string|min:12|max:128']);
        $this->limit($request, 'reset');
        $this->clinic->resetEmployee($request->user(), $this->tenant($request), $employee, $data['current_password'], $data['temporary_password']);

        return response()->noContent();
    }

    public function lookup(Request $request)
    {
        $data = $request->validate(['login' => 'required|string|max:64']);
        $this->limit($request, 'lookup');

        return response()->json(['exists' => $this->clinic->loginExists($request->user(), $this->tenant($request), $data['login'])]);
    }

    public function requestLink(Request $request)
    {
        $data = $request->validate(['login' => 'required|string|max:64', 'role_id' => 'required|uuid', 'branch_ids' => 'sometimes|array|max:100', 'branch_ids.*' => 'required|uuid|distinct']);
        $this->limit($request, 'link');

        return response()->json(['id' => $this->clinic->requestLink($request->user(), $this->tenant($request), $data['login'], $data['role_id'], $data['branch_ids'] ?? [])], 201);
    }

    public function links(Request $request)
    {
        return response()->json(['data' => $this->clinic->pendingLinks($request->user())]);
    }

    public function accept(Request $request, string $link)
    {
        $data = $request->validate(['current_password' => 'required|string|max:128']);
        $this->limit($request, 'link-accept');

        return response()->json(['id' => $this->clinic->acceptLink($request->user(), $link, $data['current_password'])]);
    }

    public function reject(Request $request, string $link)
    {
        $this->db->call('link_reject', [Governance::uuid($link)], $request->user());

        return response()->noContent();
    }

    public function audit(Request $request)
    {
        return $this->read($request, 'audit_logs', 'audit.view', ['id', 'actor_user_id', 'action', 'resource_type', 'resource_id', 'created_at']);
    }

    private function tenant(Request $request): string
    {
        $tenant = $request->session()->get('active_tenant_id');
        abort_unless(is_string($tenant), 409, 'Select an active clinic.');

        return $tenant;
    }

    private function currentTenant(): string
    {
        return DB::selectOne('SELECT public.context_tenant() AS id')->id;
    }

    private function context(Request $request, Closure $callback)
    {
        return app(TenantContext::class)->run($request->user(), $this->tenant($request), $callback);
    }

    private function offset(Request $request): int
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1|max:100000']);

        return (($data['page'] ?? 1) - 1) * 50;
    }

    private function read(Request $request, string $table, string $permission, array $columns, ?string $id = null)
    {
        if ($id !== null) {
            Governance::uuid($id);
        }
        $offset = $this->offset($request);

        return $this->context($request, function () use ($table, $permission, $columns, $id, $offset) {
            abort_if(app(Permissions::class)->scope($permission) === null, 403);
            $query = DB::table($table)->where('tenant_id', $this->currentTenant())->select($columns)->orderBy('id');
            if ($id !== null) {
                $row = $query->where('id', $id)->first();
                abort_if($row === null, 404);

                return response()->json($row);
            }

            $rows = $query->offset($offset)->limit(50)->get();
            if ($table === 'audit_logs') {
                foreach ($rows as $row) {
                    $row->created_at = CarbonImmutable::parse($row->created_at)->toISOString();
                }
            }

            return response()->json(['data' => $rows]);
        });
    }

    private function limit(Request $request, string $action): void
    {
        app(Identity::class)->reserve('http-'.$action.':'.$request->user()->id, $request->ip());
    }
}
