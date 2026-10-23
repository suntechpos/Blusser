<?php

namespace App\Modules\Appointments\Services;

use App\Modules\Access\Permissions;
use App\Modules\Access\Services\Governance;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Models\Visit;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Services\Scheduling;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class Appointments
{
    private const MODELS = ['appointments' => Appointment::class, 'visits' => Visit::class];

    public function command(User $actor, string $tenant, string $action, ?string $id, array $payload, string $key): array
    {
        return json_decode(app(Governance::class)->call('booking_command', [$action, $id, json_encode($payload, JSON_THROW_ON_ERROR), $key], $actor, $tenant), true, flags: JSON_THROW_ON_ERROR);
    }

    public function bookingOptions(User $actor, string $tenant, array $filters): array
    {
        return app(Scheduling::class)->context($actor, $tenant, function () use ($filters) {
            foreach (['appointments.view', 'doctors.view', 'rooms.view', 'schedules.view'] as $permission) {
                abort_if(app(Permissions::class)->scope($permission) === null, 403);
            }
            app(Permissions::class)->requireEntireClinic('services.view');
            foreach (['doctors.view', 'rooms.view', 'schedules.view'] as $permission) {
                abort_unless(DB::selectOne('SELECT governance.scheduling_branch(?,?) AS allowed', [$permission, $filters['branch_id']])->allowed, 403);
            }

            $value = DB::selectOne('SELECT governance.reception_booking_options(?,?,?) AS value', [$filters['branch_id'], $filters['page'] ?? 1, $filters['per_page'] ?? 50])->value;
            abort_if($value === null, 404);

            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        });
    }

    public function listing(User $actor, string $tenant, string $kind, array $filters, ?string $patient = null, bool $export = false): array
    {
        return app(Scheduling::class)->context($actor, $tenant, function () use ($kind, $filters, $patient, $export) {
            abort_if(app(Permissions::class)->scope($kind.'.view') === null, 403);
            if ($patient !== null) {
                abort_if(app(Permissions::class)->scope('patients.view') === null, 403);
                abort_unless(DB::table('patients')->where('id', $patient)->exists(), 404);
            }
            $query = (new (self::MODELS[$kind]))->newQuery()->whereRaw('tenant_id=public.context_tenant()')->orderBy($kind === 'appointments' ? 'starts_at' : 'created_at')->orderBy('id');
            if ($patient !== null) {
                $query->where('patient_id', $patient);
            }
            foreach (['branch_id', 'doctor_id', 'status'] as $key) {
                if (isset($filters[$key])) {
                    $query->where($key, $filters[$key]);
                }
            }
            if (isset($filters['q'])) {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
                $query->where(function ($q) use ($term, $kind) {
                    $q->whereRaw('id::text ILIKE ?', [$term]);
                    if ($kind === 'appointments') {
                        $q->orWhereExists(function ($p) use ($term) {
                            $p->selectRaw('1')->from('patients')->whereColumn('patients.id', 'appointments.patient_id')->whereRaw('patients.tenant_id=public.context_tenant()')
                                ->where(fn ($p) => $p->where('patients.name', 'ilike', $term)->orWhere('patients.phone', 'ilike', $term)->orWhere('patients.patient_number', 'ilike', $term));
                        });
                    }
                });
            }
            foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
                if (isset($filters[$key])) {
                    $query->where($kind === 'appointments' ? 'starts_at' : 'arrived_at', $op, $filters[$key]);
                }
            }
            if ($export) {
                app(Permissions::class)->requireEntireClinic('appointments.export');
                app(Permissions::class)->requireEntireClinic('appointments.view');
            }
            $total = (clone $query)->count();
            abort_if($export && $total > 10000, 422, 'Export limit exceeded.');
            $page = (int) ($filters['page'] ?? 1);
            $size = (int) ($filters['per_page'] ?? 50);
            $rows = $export ? $query->limit(10000)->pluck('id') : $query->offset(($page - 1) * $size)->limit($size)->pluck('id');
            if ($export) {
                DB::select('SELECT governance.booking_export_audit()');
            }

            return ['data' => $rows->map(fn ($id) => $this->projection($kind, $id))->all(), 'meta' => ['page' => $page, 'per_page' => $size, 'total' => $total]];
        });
    }

    public function show(User $actor, string $tenant, string $kind, string $id, ?array $history = null): array
    {
        return app(Scheduling::class)->context($actor, $tenant, function () use ($kind, $id, $history) {
            abort_if(app(Permissions::class)->scope($kind.'.view') === null, 403);
            abort_unless((new (self::MODELS[$kind]))->newQuery()->where('id', $id)->exists(), 404);
            if ($history === null) {
                return $this->projection($kind, $id);
            }
            $query = DB::table('operation_events')->where($kind === 'appointments' ? 'appointment_id' : 'visit_id', $id)->orderBy('occurred_at')->orderBy('id');
            foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
                if (isset($history[$key])) {
                    $query->where('occurred_at', $op, $history[$key]);
                }
            }
            foreach (['branch_id', 'doctor_id', 'status'] as $key) {
                if (isset($history[$key])) {
                    $query->where($key === 'status' ? 'to_status' : $key, $history[$key]);
                }
            }
            if (isset($history['q'])) {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $history['q']).'%';
                $query->whereRaw('id::text ILIKE ?', [$term]);
            }
            $total = (clone $query)->count();
            $page = (int) ($history['page'] ?? 1);
            $size = (int) ($history['per_page'] ?? 50);
            $data = $query->offset(($page - 1) * $size)->limit($size)->get(['id', 'action', 'from_status', 'to_status', 'occurred_at'])->map(fn ($e) => ['id' => $e->id, 'action' => $e->action, 'from_status' => $e->from_status, 'to_status' => $e->to_status, 'occurred_at' => CarbonImmutable::parse($e->occurred_at)->utc()->toISOString()])->all();

            return ['data' => $data, 'meta' => ['page' => $page, 'per_page' => $size, 'total' => $total]];
        });
    }

    private function projection(string $kind, string $id): array
    {
        return json_decode(DB::selectOne('SELECT governance.booking_projection(?,?) AS value', [$kind, $id])->value, true, flags: JSON_THROW_ON_ERROR);
    }
}
