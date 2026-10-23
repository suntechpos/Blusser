<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Access\Permissions;
use App\Modules\Access\Services\Governance;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\BookingService;
use App\Modules\Scheduling\Models\DoctorProfile;
use App\Modules\Scheduling\Models\Room;
use App\Modules\Scheduling\Models\ScheduleException;
use App\Modules\Scheduling\Models\ScheduleRule;
use App\Modules\Scheduling\Models\Specialty;
use App\Modules\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class Scheduling
{
    private const MODELS = ['services' => BookingService::class, 'specialties' => Specialty::class, 'doctors' => DoctorProfile::class, 'rooms' => Room::class, 'schedules' => ScheduleRule::class, 'schedule-exceptions' => ScheduleException::class];

    public function context(User $actor, string $tenant, Closure $callback): mixed
    {
        try {
            return app(TenantContext::class)->run($actor, $tenant, function () use ($actor, $callback) {
                DB::select("SELECT set_config('app.credential_version',?,true)", [(string) $actor->credential_version]);

                return $callback();
            });
        } catch (QueryException) {
            throw new HttpException(503, 'Scheduling operation unavailable.');
        }
    }

    public function section(string $kind): string
    {
        return match ($kind) {
            'services' => 'services', 'specialties', 'doctors' => 'doctors', 'rooms' => 'rooms', default => 'schedules',
        };
    }

    public function listing(User $actor, string $tenant, string $kind, array $filters): array
    {
        return $this->context($actor, $tenant, function () use ($kind, $filters) {
            abort_if(app(Permissions::class)->scope($this->section($kind).'.view') === null, 403);
            if ($kind === 'services') {
                app(Permissions::class)->requireEntireClinic('services.view');
            }
            $query = (new (self::MODELS[$kind]))->newQuery()->whereRaw('tenant_id=public.context_tenant()')->orderBy('created_at')->orderBy('id');
            if (isset($filters['branch_id'])) {
                if ($kind === 'doctors') {
                    $query->whereIn('id', DB::table('doctor_branches')->select('doctor_id')->where('branch_id', $filters['branch_id']));
                } elseif ($kind === 'specialties') {
                    $query->whereIn('id', DB::table('doctor_profiles')->select('specialty_id')->whereIn('id', DB::table('doctor_branches')->select('doctor_id')->where('branch_id', $filters['branch_id'])));
                } else {
                    $query->where('branch_id', $filters['branch_id']);
                }
            }
            if (isset($filters['doctor_id'])) {
                if (in_array($kind, ['doctors', 'schedules', 'schedule-exceptions'], true)) {
                    $query->where($kind === 'doctors' ? 'id' : 'doctor_id', $filters['doctor_id']);
                } else {
                    abort(422, 'Doctor filter is not supported for this resource.');
                }
            }
            if (isset($filters['q'])) {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
                match ($kind) {
                    'services', 'specialties' => $query->where('name', 'ilike', $term),
                    'rooms' => $query->where('number', 'ilike', $term),
                    default => $query->whereRaw('id::text ILIKE ?', [$term]),
                };
            }
            if (isset($filters['status']) && $filters['status'] !== 'all') {
                abort_unless(in_array($kind, ['services', 'specialties', 'doctors', 'rooms'], true), 422);
                $query->where('active', $filters['status'] === 'active');
            }
            foreach (['from' => '>=', 'to' => '<'] as $key => $operator) {
                if (isset($filters[$key])) {
                    $query->where('created_at', $operator, $filters[$key]);
                }
            }
            $total = (clone $query)->count();
            $page = (int) ($filters['page'] ?? 1);
            $size = (int) ($filters['per_page'] ?? 50);
            $data = $query->offset(($page - 1) * $size)->limit($size)->pluck('id')->map(fn ($id) => $this->projection($kind, $id))->all();

            return ['data' => $data, 'meta' => ['page' => $page, 'per_page' => $size, 'total' => $total]];
        });
    }

    public function show(User $actor, string $tenant, string $kind, string $id): array
    {
        return $this->context($actor, $tenant, fn () => $this->visible($kind, $id));
    }

    public function visible(string $kind, string $id): array
    {
        abort_if(app(Permissions::class)->scope($this->section($kind).'.view') === null, 403);
        abort_unless((new (self::MODELS[$kind]))->newQuery()->where('id', $id)->exists(), 404);

        return $this->projection($kind, $id);
    }

    public function projection(string $kind, string $id): array
    {
        return json_decode(DB::selectOne('SELECT governance.scheduling_projection(?,?) AS value', [$kind, $id])->value, true, flags: JSON_THROW_ON_ERROR);
    }

    public function command(User $actor, string $tenant, string $kind, string $action, ?string $id, array $payload, string $key): array
    {
        return json_decode(app(Governance::class)->call('scheduling_command', [$kind, $action, $id, json_encode($payload, JSON_THROW_ON_ERROR), $key], $actor, $tenant), true, flags: JSON_THROW_ON_ERROR);
    }
}
