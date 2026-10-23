<?php

namespace App\Modules\Patients\Services;

use App\Modules\Access\Permissions;
use App\Modules\Access\Services\Governance;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class Patients
{
    public function context(User $actor, string $tenant, Closure $callback): mixed
    {
        try {
            return app(TenantContext::class)->run($actor, $tenant, $callback);
        } catch (QueryException $exception) {
            // SQL bindings can contain names/phones from searches. Never attach the
            // original exception to a framework response or exception report.
            throw new HttpException(($exception->errorInfo[0] ?? '') === '42501' ? 403 : 503, 'Patient operation unavailable.');
        }
    }

    public function listing(User $actor, string $tenant, array $filters, bool $export = false): array
    {
        return $this->context($actor, $tenant, function () use ($actor, $filters, $export) {
            abort_if(app(Permissions::class)->scope('patients.view') === null, 403);
            if ($export) {
                app(Permissions::class)->requireEntireClinic('patients.export');
                app(Permissions::class)->requireEntireClinic('patients.view');
                DB::connection('pgsql')->select("SELECT set_config('app.credential_version',?,true)", [(string) $actor->credential_version]);
                DB::connection('pgsql')->select('SELECT governance.patient_export_audit()');
            }
            $query = $this->query($filters);
            $total = (clone $query)->count();
            abort_if($export && $total > 10000, 422, 'Export limit exceeded.');
            $page = $filters['page'] ?? 1;
            $size = $filters['per_page'] ?? 50;
            $rows = $export ? $query->limit(10000)->get() : $query->offset(($page - 1) * $size)->limit($size)->get();

            $links = DB::connection('pgsql')->table('patient_branches')->whereIn('patient_id', $rows->modelKeys())->orderBy('branch_id')->get()->groupBy('patient_id');

            return ['data' => $rows->map(fn (Patient $p) => $this->present($p, ($links[$p->id] ?? collect())->pluck('branch_id')->all()))->all(), 'meta' => ['page' => (int) $page, 'per_page' => (int) $size, 'total' => $total]];
        });
    }

    public function show(User $actor, string $tenant, string $id): array
    {
        return $this->context($actor, $tenant, function () use ($id) {
            abort_if(app(Permissions::class)->scope('patients.view') === null, 403);
            $patient = Patient::query()->whereRaw('tenant_id = public.context_tenant()')->find($id);
            abort_if($patient === null, 404);

            return ['patient' => $this->present($patient), 'version' => (int) $patient->version];
        });
    }

    public function command(User $actor, string $tenant, string $action, ?string $id, array $data, string $key): array
    {
        return json_decode(app(Governance::class)->call('patient_command', [$action, $id, json_encode($data, JSON_THROW_ON_ERROR), $key], $actor, $tenant), true, flags: JSON_THROW_ON_ERROR);
    }

    private function query(array $filters): Builder
    {
        $query = Patient::query()->whereRaw('tenant_id = public.context_tenant()')->orderBy('created_at')->orderBy('id');
        if (isset($filters['q'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
            $query->where(fn ($q) => $q->where('name', 'ilike', $term)->orWhere('patient_number', 'ilike', $term)->orWhere('phone', 'ilike', $term)->orWhereRaw('id::text ILIKE ?', [$term]));
        }
        if (isset($filters['branch_id'])) {
            // RLS on BOTH relations: an unknown/unassigned branch cannot reveal matches.
            $query->whereIn('id', DB::connection('pgsql')->table('patient_branches')->select('patient_id')->where('branch_id', $filters['branch_id']));
        }
        if (($filters['status'] ?? null) === 'archived') {
            $query->whereNotNull('archived_at');
        } elseif (($filters['status'] ?? null) !== 'all') {
            $query->whereNull('archived_at');
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $operator) {
            if (isset($filters[$key])) {
                $query->where('created_at', $operator, CarbonImmutable::parse($filters[$key])->utc());
            }
        }

        return $query;
    }

    private function present(Patient $p, ?array $branches = null): array
    {
        return ['id' => $p->id, 'patient_number' => $p->patient_number, 'name' => $p->name, 'phone' => $p->phone,
            'date_of_birth' => $p->date_of_birth->format('Y-m-d'), 'gender' => $p->gender,
            'archived_at' => $p->archived_at?->toISOString(),
            'branch_ids' => $branches ?? DB::connection('pgsql')->table('patient_branches')->where('patient_id', $p->id)->orderBy('branch_id')->pluck('branch_id')->all()];
    }
}
