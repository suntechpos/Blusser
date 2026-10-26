<?php

namespace App\Modules\Queues\Services;

use App\Modules\Access\Permissions;
use App\Modules\Access\Services\Governance;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class Queues
{
    private function read(User $actor, string $tenant, Closure $callback): mixed
    {
        try {
            return app(TenantContext::class)->run($actor, $tenant, $callback, consistentSnapshot: true);
        } catch (QueryException) {
            throw new HttpException(503, 'Queue operation unavailable.');
        }
    }

    public function command(User $actor, string $tenant, string $action, ?string $id, array $payload, string $key): array
    {
        return json_decode(app(Governance::class)->call('queue_command', [$action, $id, json_encode($payload, JSON_THROW_ON_ERROR), $key], $actor, $tenant), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Ticket-scoped read; SQL only returns a slot for the signed-in doctor. */
    public function ownStartSlot(User $actor, string $tenant, string $ticket): array
    {
        return $this->read($actor, $tenant, function () use ($ticket) {
            try {
                $value = DB::selectOne(
                    'SELECT governance.own_doctor_start_slot(?::uuid) AS value', [$ticket],
                )->value;
            } catch (QueryException $exception) {
                if (($exception->errorInfo[0] ?? null) === '42501') abort(403);
                if (($exception->errorInfo[0] ?? null) === 'P0002') abort(404);
                throw $exception;
            }

            abort_if($value === null, 409, 'Bugungi jadvalda bu bemor uchun bo‘sh vaqt topilmadi.');

            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        });
    }

    public function listing(User $actor, string $tenant, array $filters): array
    {
        return $this->read($actor, $tenant, function () use ($filters) {
            abort_if(app(Permissions::class)->scope('queues.view') === null, 403);
            $q = DB::table('queue_tickets')->orderBy('created_at')->orderBy('id');
            foreach (['branch_id', 'status'] as $k) {
                if (isset($filters[$k])) {
                    $q->where($k, $filters[$k]);
                }
            }
            if (isset($filters['doctor_id'])) {
                // Projection enforces queue visibility without requiring visits.view for a queue-only read.
                $q->whereRaw("governance.queue_projection(id)->>'doctor_id' = ?", [$filters['doctor_id']]);
            }
            if (isset($filters['q'])) {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
                $q->whereRaw('id::text ILIKE ?', [$term]);
            }
            foreach (['from' => '>=', 'to' => '<'] as $k => $op) {
                if (isset($filters[$k])) {
                    $q->where('created_at', $op, $filters[$k]);
                }
            }
            $total = (clone $q)->count();
            $page = (int) ($filters['page'] ?? 1);
            $size = (int) ($filters['per_page'] ?? 50);
            $data = $q->offset(($page - 1) * $size)->limit($size)->pluck('id')->map(fn ($id) => $this->projection($id))->all();

            return ['data' => $data, 'meta' => ['page' => $page, 'per_page' => $size, 'total' => $total]];
        });
    }

    public function show(User $actor, string $tenant, string $id): array
    {
        return $this->read($actor, $tenant, function () use ($id) {
            abort_if(app(Permissions::class)->scope('queues.view') === null, 403);
            abort_unless(DB::table('queue_tickets')->where('id', $id)->exists(), 404);

            return $this->projection($id);
        });
    }

    private function projection(string $id): array
    {
        return json_decode(DB::selectOne('SELECT governance.queue_projection(?) AS value', [$id])->value, true, flags: JSON_THROW_ON_ERROR);
    }

    public function overview(User $actor, string $tenant, array $filters): array
    {
        return $this->read($actor, $tenant, function () use ($filters) {
            $scope = app(Permissions::class)->scope('reception.view')?->value;
            abort_unless(in_array($scope, ['assigned_branches', 'entire_clinic'], true), 403);
            $zone = DB::table('tenants')->where('id', DB::raw('public.context_tenant()'))->value('timezone');
            if (isset($filters['branch_id'])) {
                $branch = DB::table('branches')->where('id', $filters['branch_id'])->where('active', true)->first();
                abort_unless($branch, 404);
                if ($scope === 'assigned_branches') {
                    abort_unless(DB::selectOne('SELECT public.branch_assigned(?) AS ok', [$branch->id])->ok, 404);
                }
                $zone = $branch->timezone ?? $zone;
            }
            $day = $filters['local_date'] ?? CarbonImmutable::now($zone)->toDateString();
            $out = ['local_date' => $day, 'timezone' => $zone, 'counts' => (object) []];
            $counts = [];
            foreach (['appointments' => 'appointments.view', 'visits' => 'visits.view', 'tickets' => 'queues.view'] as $section => $permission) {
                if (app(Permissions::class)->scope($permission) === null) {
                    continue;
                }
                $table = $section === 'tickets' ? 'queue_tickets' : $section;
                $q = DB::table($table.' as r')->join('branches as b', function ($join) {
                    $join->on('b.id', '=', 'r.branch_id')->on('b.tenant_id', '=', 'r.tenant_id');
                })->where('b.active', true);
                if ($scope === 'assigned_branches') {
                    $q->whereRaw('public.branch_assigned(r.branch_id)');
                }
                foreach (['branch_id', 'doctor_id'] as $k) {
                    if (isset($filters[$k])) {
                        $section === 'tickets' && $k === 'doctor_id'
                            ? $q->whereRaw("governance.queue_projection(r.id)->>'doctor_id' = ?", [$filters[$k]])
                            : $q->where('r.'.$k, $filters[$k]);
                    }
                }
                if ($section === 'tickets') {
                    $q->where('r.local_date', $day);
                } else {
                    $col = $section === 'appointments' ? 'starts_at' : 'arrived_at';
                    // Each row uses its branch override, even in a multi-branch overview.
                    $q->whereRaw('(r.'.$col.' AT TIME ZONE coalesce(b.timezone,?))::date = ?::date', [$zone, $day]);
                }
                $ids = $q->orderBy($section === 'appointments' ? 'r.starts_at' : 'r.created_at')->orderBy('r.id')->pluck('r.id');
                $out[$section] = $ids->map(function ($id) use ($section) {
                    return $section === 'tickets' ? $this->projection($id) : json_decode(DB::selectOne('SELECT governance.booking_projection(?,?) AS value', [$section, $id])->value, true, flags: JSON_THROW_ON_ERROR);
                })->all();
                if ($section === 'appointments') {
                    $counts['scheduled'] = count(array_filter($out[$section], fn ($r) => $r['status'] === 'scheduled'));
                    $counts['late'] = count(array_filter($out[$section], fn ($r) => $r['late']));
                } elseif ($section === 'visits') {
                    $counts['arrived'] = count($out[$section]);
                } else {
                    foreach (['waiting', 'called', 'in_progress', 'completed'] as $state) {
                        $counts[$state] = count(array_filter($out[$section], fn ($r) => $r['status'] === $state));
                    }
                }
            }
            $out['counts'] = (object) $counts;

            return $out;
        });
    }
}
