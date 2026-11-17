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
            $summary = $export ? null : $this->summary($filters);
            $total = (clone $query)->count();
            abort_if($export && $total > 10000, 422, 'Export limit exceeded.');
            $page = $filters['page'] ?? 1;
            $size = $filters['per_page'] ?? 50;
            $rows = $export ? $query->limit(10000)->get() : $query->offset(($page - 1) * $size)->limit($size)->get();

            $links = DB::connection('pgsql')->table('patient_branches')->whereIn('patient_id', $rows->modelKeys())->orderBy('branch_id')->get()->groupBy('patient_id');

            $stats = collect();
            $canVisits = app(Permissions::class)->scope('visits.view') !== null;
            if ($canVisits && ! $export) {
                $stats = DB::connection('pgsql')->table('visits')
                    ->whereRaw('tenant_id = public.context_tenant()')
                    ->whereIn('patient_id', $rows->modelKeys())
                    ->when(isset($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
                    ->selectRaw('patient_id, count(*) as visit_count, max(arrived_at) as last_visit')
                    ->groupBy('patient_id')->get()->keyBy('patient_id');
            }

            return ['data' => $rows->map(function (Patient $p) use ($links, $stats, $canVisits, $export) {
                $row = $this->present($p, ($links[$p->id] ?? collect())->pluck('branch_id')->all());
                if ($canVisits && ! $export) {
                    $stat = $stats->get($p->id);
                    $row['visit_count'] = (int) ($stat?->visit_count ?? 0);
                    $row['last_visit'] = $stat?->last_visit ? CarbonImmutable::parse($stat->last_visit)->utc()->toISOString() : null;
                }
                return $row;
            })->all(), 'meta' => ['page' => (int) $page, 'per_page' => (int) $size, 'total' => $total, ...($summary === null ? [] : ['summary' => $summary])]];
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

    private function summary(array $filters): array
    {
        $base = array_intersect_key($filters, ['branch_id' => true]);
        $all = $this->query([...$base, 'status' => 'all'])->reorder();
        $zone = DB::connection('pgsql')->table('tenants')->whereRaw('id = public.context_tenant()')->value('timezone') ?? 'UTC';
        $now = CarbonImmutable::now($zone);
        $start = $now->startOfDay()->utc();
        $end = $now->startOfDay()->addDay()->utc();
        $today = null;
        if (app(Permissions::class)->scope('visits.view') !== null) {
            $today = DB::connection('pgsql')->table('visits')
                ->whereRaw('tenant_id = public.context_tenant()')
                ->whereIn('patient_id', (clone $all)->select('id'))
                ->when(isset($base['branch_id']), fn ($q) => $q->where('branch_id', $base['branch_id']))
                ->where('arrived_at', '>=', $start)->where('arrived_at', '<', $end)
                ->distinct()->count('patient_id');
        }
        return [
            'all' => (clone $all)->count(),
            'active' => (clone $all)->whereNull('archived_at')->count(),
            'archived' => (clone $all)->whereNotNull('archived_at')->count(),
            'new' => (clone $all)->where('created_at', '>=', $now->startOfMonth()->utc())->where('created_at', '<', $now->startOfMonth()->addMonth()->utc())->count(),
            'today' => $today,
        ];
    }

    private function query(array $filters): Builder
    {
        $query = Patient::query()->whereRaw('tenant_id = public.context_tenant()')->orderBy('created_at')->orderBy('id');
        if (isset($filters['q'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
            $query->where(fn ($q) => $q->where('name', 'ilike', $term)->orWhere('patient_number', 'ilike', $term)->orWhere('phone', 'ilike', $term)->orWhereRaw('id::text ILIKE ?', [$term]));
        }
        if (! empty($filters['new_this_month'])) {
            $zone = DB::connection('pgsql')->table('tenants')->whereRaw('id = public.context_tenant()')->value('timezone') ?? 'UTC';
            $month = CarbonImmutable::now($zone)->startOfMonth();
            $query->where('created_at', '>=', $month->utc())->where('created_at', '<', $month->addMonth()->utc());
        }
        if (isset($filters['gender'])) {
            $query->where('gender', $filters['gender']);
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
        if (isset($filters['visit_range'])) {
            abort_if(app(Permissions::class)->scope('visits.view') === null, 403);
            $cutoff = CarbonImmutable::now()->subDays($filters['visit_range'] === '7' ? 7 : 30);
            $visits = DB::connection('pgsql')->table('visits')->select('patient_id')
                ->whereRaw('tenant_id = public.context_tenant()')
                ->when(isset($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']));
            if ($filters['visit_range'] === 'old') {
                $query->whereIn('id', clone $visits)->whereNotIn('id', $visits->where('arrived_at', '>=', $cutoff));
            } elseif ($filters['visit_range'] === 'never') {
                $query->whereNotIn('id', $visits);
            } else {
                $query->whereIn('id', $visits->where('arrived_at', '>=', $cutoff));
            }
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
