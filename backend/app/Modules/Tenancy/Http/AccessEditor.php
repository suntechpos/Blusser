<?php

namespace App\Modules\Tenancy\Http;

use App\Modules\Access\Permissions;
use App\Modules\Access\Services\Governance;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** DTO adapter over the existing governance boundary, not a second identity system. */
final class AccessEditor
{
    private function tenant(Request $r): string
    {
        $id = $r->session()->get('active_tenant_id');
        abort_unless(is_string($id), 409);

        return Governance::uuid($id);
    }

    private function key(Request $r, bool $required = false): string
    {
        return Governance::uuid($r->header('Idempotency-Key') ?? ($required ? '' : (string) Str::uuid()));
    }

    private function version(Request $r): ?int
    {
        $v = $r->header('If-Match');
        if ($v === null) {
            return null;
        }abort_unless(preg_match('/^"([1-9][0-9]*)"$/D', $v, $m), 422);

        return (int) $m[1];
    }

    public function role(Request $r, ?string $id, bool $delete = false)
    {
        app(TenantContext::class)->run($r->user(), $this->tenant($r), function () use ($r, $id, $delete) {
            app(Permissions::class)->requireEntireClinic($delete ? 'roles.delete' : ($id ? 'roles.update' : 'roles.create'));
            if (! $delete) {
                app(Permissions::class)->requireEntireClinic('roles.manage_permissions');
            }
            if ($id) {
                $row = DB::table('tenant_roles')->where('id', Governance::uuid($id))->first();
                abort_if(! $row || $row->protected || DB::table('tenant_memberships')->where('role_id', $id)->where('user_id', $r->user()->id)->exists(), 403);
            }
        });
        $body = $delete ? [] : $r->validate(['code' => 'required|string|max:64', 'name' => 'required|string|max:255', 'note' => 'sometimes|nullable|string|max:300', 'grants' => 'required|array|min:1|max:150', 'grants.*.code' => 'required|string|max:100|distinct', 'grants.*.scope' => 'required|in:own,assigned_branches,entire_clinic']);
        $result = json_decode(app(Governance::class)->call('access_role_command', [$delete ? 'delete' : ($id ? 'update' : 'create'), $id ? Governance::uuid($id) : null, json_encode($body, JSON_THROW_ON_ERROR), $this->key($r), $this->version($r)], $r->user(), $this->tenant($r)), true, flags: JSON_THROW_ON_ERROR);

        return $delete ? response()->noContent() : response()->json($result, $id ? 200 : 201)->header('ETag', '"'.$result['version'].'"');
    }

    public function linkEmployee(Request $r)
    {
        $data = $r->validate(['employee_id' => 'required|uuid', 'login' => 'required|string|max:64', 'role_id' => 'required|uuid', 'branch_ids' => 'required|array|max:100', 'branch_ids.*' => 'required|uuid|distinct']);
        $result = json_decode(app(Governance::class)->call('staff_link', [$data['employee_id'], Governance::login($data['login']), $data['role_id'], Governance::uuids($data['branch_ids']), $this->key($r, true), $this->version($r)], $r->user(), $this->tenant($r)), true, flags: JSON_THROW_ON_ERROR);

        return response()->json($result, 201);
    }

    public function employees(Request $r, ?string $id)
    {
        $data = $r->validate(['page' => 'sometimes|integer|min:1|max:100000', 'format' => 'required|in:profile']);
        $rows = json_decode(app(Governance::class)->call('staff_directory', [$id ? Governance::uuid($id) : null, (($data['page'] ?? 1) - 1) * 50], $r->user(), $this->tenant($r)), true, flags: JSON_THROW_ON_ERROR);
        if ($id) {
            abort_if($rows === [], 404);

            return response()->json($rows[0])->header('ETag', '"'.$rows[0]['version'].'"');
        }

        return response()->json(['data' => $rows]);
    }

    public function saveEmployee(Request $r, ?string $id = null)
    {
        $data = $r->validate(['active' => 'required|boolean', 'access' => 'required|boolean', 'login' => 'nullable|string|max:64', 'role_id' => 'nullable|uuid', 'branch_id' => 'required|uuid', 'branch_ids' => ($id ? 'sometimes' : 'present').'|array|max:100', 'branch_ids.*' => 'required|uuid|distinct', 'temporary_password' => 'nullable|string|min:12|max:128',
            'profile' => 'required|array:first,last,patronymic,phone,dob,gender,address,position,department,hired,specialty,duration,note',
            'profile.first' => 'required|string|max:120', 'profile.last' => 'required|string|max:120', 'profile.phone' => 'required|string|max:30', 'profile.position' => 'required|string|max:100', 'profile.patronymic' => 'nullable|string|max:120', 'profile.dob' => 'nullable|date|before_or_equal:today', 'profile.gender' => 'nullable|in:Erkak,Ayol', 'profile.address' => 'nullable|string|max:500', 'profile.department' => 'nullable|string|max:120', 'profile.hired' => 'nullable|date', 'profile.specialty' => 'nullable|string|max:120', 'profile.duration' => 'nullable|integer|min:5|max:480', 'profile.note' => 'nullable|string|max:2000']);
        if ($data['access']) {
            $r->validate(['login' => 'required|string|max:64', 'role_id' => 'required|uuid']);
            $data['login'] = Governance::login($data['login']);
        }
        if (isset($data['temporary_password'])) {
            $data['password_hash'] = Hash::make($data['temporary_password']);
        }unset($data['temporary_password']);
        $data['name'] = trim($data['profile']['first'].' '.$data['profile']['last']);
        $result = json_decode(app(Governance::class)->call('staff_command', [$id ? Governance::uuid($id) : null, json_encode($data, JSON_THROW_ON_ERROR), $this->key($r, true), $this->version($r)], $r->user(), $this->tenant($r)), true, flags: JSON_THROW_ON_ERROR);

        return response()->json($result, $id ? 200 : 201)->header('ETag', '"'.$result['version'].'"');
    }
}
