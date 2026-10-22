<?php

namespace App\Modules\Access\Services;

use App\Modules\Access\Permissions;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Explicit provisioning through existing governance; never imports demo storage. */
final class DefaultClinicRoles
{
    public const TEMPLATES = [
        'administrator' => ['Administrator', ['*']],
        'registrator' => ['Registrator', ['reception.view', 'patients.view', 'patients.create', 'patients.update', 'appointments.view', 'appointments.create', 'appointments.update', 'appointments.check_in', 'visits.view', 'visits.create', 'queues.view', 'queues.create', 'queues.call', 'doctors.view', 'services.view']],
        'doctor' => ['Shifokor', ['workspace.view', 'workspace.consult', 'workspace.prescribe', 'workspace.refer', 'workspace.upload', 'workspace.complete', 'workspace.export', 'patients.view', 'appointments.view']],
        'laborant' => ['Laborant', ['laboratory.view', 'laboratory.sample', 'laboratory.start', 'laboratory.result', 'laboratory.review', 'laboratory.export']],
        'uzi_specialist' => ['UZI mutaxassisi', ['diagnostics.view', 'diagnostics.start', 'diagnostics.result', 'diagnostics.confirm', 'diagnostics.amend', 'diagnostics.export']],
        'cashier' => ['Kassir', ['cashier.view', 'cashier.shift', 'cashier.pay', 'cashier.history']],
    ];

    public function inspect(User $actor, string $tenant): array
    {
        return app(TenantContext::class)->run($actor, $tenant, function () {
            app(Permissions::class)->requireEntireClinic('roles.view');

            return DB::table('tenant_roles')->orderBy('name')->get(['id', 'code', 'name', 'active', 'protected'])->map(fn ($r) => (array) $r)->all();
        });
    }

    public function provision(User $actor, string $tenant, ?array $only = null): array
    {
        if ($only !== null && ($only === [] || array_diff($only, array_keys(self::TEMPLATES)))) {
            throw new \InvalidArgumentException('Unknown initial role selection.');
        }
        $catalog = app(TenantContext::class)->run($actor, $tenant, function () {
            app(Permissions::class)->requireEntireClinic('roles.view');
            // The CEO ledger is intentionally private to security definers.
            // Use the existing authenticated self projection; protected CEO roles
            // cannot be created/assigned through role administration.
            $self = json_decode(DB::selectOne('SELECT governance.self_membership() AS member')->member, true, flags: JSON_THROW_ON_ERROR);
            if (($self['role']['code'] ?? null) !== 'ceo' || ! ($self['role']['protected'] ?? false)) {
                throw new AuthorizationException('Only the designated clinic CEO may provision initial roles.');
            }
            app(Permissions::class)->requireEntireClinic('roles.create');
            app(Permissions::class)->requireEntireClinic('roles.manage_permissions');

            return DB::table('permissions')->pluck('allowed_scopes', 'code')->all();
        });
        $result = [];
        foreach (self::TEMPLATES as $code => [$name, $permissions]) {
            if ($only !== null && ! in_array($code, $only, true)) {
                continue;
            }
            $existing = $this->find($actor, $tenant, $code, $name);
            if ($existing) {
                $result[] = ['name' => $name, 'status' => 'preserved', 'id' => $existing];

                continue;
            }
            $grants = [];
            foreach ($permissions === ['*'] ? array_keys($catalog) : $permissions as $permission) {
                if (! isset($catalog[$permission])) {
                    throw new \RuntimeException('Missing catalog permission: '.$permission);
                }
                $preferred = $code === 'doctor' && in_array($permission, ['patients.view', 'appointments.view'], true) ? 'own' : ($code === 'registrator' ? 'assigned_branches' : 'entire_clinic');
                $allowed = explode(',', trim($catalog[$permission], '{}'));
                $grants[] = ['code' => $permission, 'scope' => in_array($preferred, $allowed, true) ? $preferred : 'entire_clinic'];
            }
            try {
                $saved = json_decode(app(Governance::class)->call('access_role_command', ['create', null, json_encode(['code' => $code, 'name' => $name, 'grants' => $grants], JSON_THROW_ON_ERROR), (string) Str::uuid(), null], $actor, $tenant), true, flags: JSON_THROW_ON_ERROR);
                $result[] = ['name' => $name, 'status' => 'created', 'id' => $saved['id']];
            } catch (GovernanceDenied $e) {
                // A concurrent run may have inserted this code/name first.
                $id = $e->reason === 'conflict' ? $this->find($actor, $tenant, $code, $name) : null;
                if (! $id) {
                    throw $e;
                }
                $result[] = ['name' => $name, 'status' => 'preserved', 'id' => $id];
            }
        }

        return $result;
    }

    private function find(User $actor, string $tenant, string $code, string $name): ?string
    {
        return app(TenantContext::class)->run($actor, $tenant, function () use ($code, $name) {
            app(Permissions::class)->requireEntireClinic('roles.view');

            return DB::table('tenant_roles')->where(fn ($q) => $q->where('code', $code)->orWhereRaw("lower(regexp_replace(btrim(name),'\\s+',' ','g'))=lower(?)", [$name])->when($code === 'doctor', fn ($q) => $q->orWhereRaw("lower(regexp_replace(btrim(name),'\\s+',' ','g'))=lower(?)", ['Shifokor kabineti'])))->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [$code])->orderBy('id')->value('id');
        });
    }
}
