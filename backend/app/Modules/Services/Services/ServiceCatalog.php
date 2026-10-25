<?php

namespace App\Modules\Services\Services;

use App\Modules\Access\Permissions;
use App\Modules\Access\Services\Governance;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ServiceCatalog
{
    public function context(User $actor, string $tenant, Closure $callback): mixed
    {
        try {
            return app(TenantContext::class)->run($actor, $tenant, function () use ($callback) {
                app(Permissions::class)->requireEntireClinic('services.view');

                return $callback();
            });
        } catch (QueryException $e) {
            throw new HttpException(($e->errorInfo[0] ?? '') === '42501' ? 403 : 503, 'Service catalog unavailable.');
        }
    }

    public function listing(User $actor, string $tenant, string $kind, array $filters, bool $export = false): array
    {
        return $this->context($actor, $tenant, function () use ($kind, $filters, $export) {
            if ($kind === 'categories') {
                app(Permissions::class)->requireEntireClinic('categories.view');
            }
            if ($export) {
                app(Permissions::class)->requireEntireClinic('categories.view');
                app(Permissions::class)->requireEntireClinic('categories.export');
                DB::select('SELECT governance.catalog_export_audit()');
            }
            $lookups = json_decode(DB::selectOne('SELECT governance.catalog_lookups() AS value')->value, true, flags: JSON_THROW_ON_ERROR);
            $table = $kind === 'categories' ? 'service_categories' : 'services';
            $query = DB::table($table)->whereRaw('tenant_id=public.context_tenant()')->orderBy('created_at')->orderBy('id');
            if (isset($filters['q'])) {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
                $query->where(function ($q) use ($term, $kind) {
                    $q->where('name', 'ilike', $term);
                    if ($kind === 'services') {
                        $q->orWhere('code', 'ilike', $term);
                    }
                });
            }
            if ($kind === 'services') {
                if (isset($filters['category_id'])) {
                    $query->where('category_id', $filters['category_id']);
                }
                if (isset($filters['branch_id'])) {
                    $branch = $filters['branch_id'];
                    $query->whereRaw(in_array($branch, array_column($lookups['branches'], 'id'), true) ? 'true' : 'false');
                    $query->where(function ($q) use ($branch) {
                        $q->whereNotExists(fn ($s) => $s->selectRaw('1')->from('service_branches')->whereColumn('service_branches.service_id', 'services.id')->whereColumn('service_branches.tenant_id', 'services.tenant_id'))
                            ->orWhereIn('id', DB::table('service_branches')->select('service_id')->where('branch_id', $branch));
                    });
                }
            }
            foreach (['from' => '>=', 'to' => '<'] as $key => $operator) {
                if (isset($filters[$key])) {
                    $query->where('created_at', $operator, $filters[$key]);
                }
            }
            // Summary cards describe the whole authorized catalog, independently of table filters.
            $summary = DB::table($table)->whereRaw('tenant_id=public.context_tenant()');
            $stats = ['total' => (clone $summary)->count(), 'active' => (clone $summary)->where('active', true)->count(), 'inactive' => (clone $summary)->where('active', false)->count(),
                'categories' => DB::table('service_categories')->whereRaw('tenant_id=public.context_tenant()')->count()];
            if (isset($filters['status']) && $filters['status'] !== 'all') {
                $query->where('active', $filters['status'] === 'active');
            }
            $total = (clone $query)->count();
            abort_if($export && $total > 10000, 422, 'Export is limited to 10000 services.');
            $page = (int) ($filters['page'] ?? 1);
            $size = (int) ($filters['per_page'] ?? 50);
            $data = ($export ? $query->limit(10000) : $query->offset(($page - 1) * $size)->limit($size))->pluck('id')->map(fn ($id) => $this->projection($kind, $id))->all();

            return [...$lookups, 'data' => $data, 'meta' => ['page' => $page, 'per_page' => $size, 'total' => $total], 'stats' => $stats];
        });
    }

    public function show(User $actor, string $tenant, string $id): array
    {
        return $this->context($actor, $tenant, function () use ($id) {
            $data = $this->projection('services', $id);
            abort_if($data === null, 404);

            return $data;
        });
    }

    public function projection(string $kind, string $id): ?array
    {
        $value = DB::selectOne('SELECT governance.catalog_projection(?,?::uuid) AS value', [$kind, $id])->value;

        return $value === null ? null : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    public function command(User $actor, string $tenant, string $kind, string $operation, ?string $id, array $payload, ?int $expected, string $key): array
    {
        return json_decode(app(Governance::class)->call('catalog_command', [$kind, $operation, $id, json_encode($payload, JSON_THROW_ON_ERROR), $expected, $key], $actor, $tenant), true, flags: JSON_THROW_ON_ERROR);
    }
}
