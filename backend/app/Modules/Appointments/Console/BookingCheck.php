<?php

namespace App\Modules\Appointments\Console;

use App\Modules\Access\Services\Governance;
use App\Modules\Appointments\Services\Appointments;
use App\Modules\Identity\Services\Identity;
use App\Modules\Scheduling\Services\Scheduling;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Authenticated, read-only diagnosis; never seeds clinical records or grants. */
final class BookingCheck extends Command
{
    protected $signature = 'blusser:booking-check {--actor= : Existing login} {--tenant= : Existing clinic UUID}';

    protected $description = 'Inspect effective booking grants and real branch/doctor/service/room/schedule configuration without changing it';

    public function handle(Identity $identity, Governance $governance, Scheduling $scheduling, Appointments $appointments): int
    {
        $password = $this->secret('Joriy parol');
        try {
            $actor = $identity->authenticate((string) ($this->option('actor') ?: $this->ask('Login')), (string) $password, 'booking-check-cli');
            $tenant = Governance::uuid((string) $this->option('tenant'));
            $clinics = json_decode($governance->call('my_clinics', [], $actor), true, flags: JSON_THROW_ON_ERROR);
            if (! in_array($tenant, array_column($clinics, 'id'), true)) {
                throw new \RuntimeException('Clinic unavailable.');
            }
            $branches = app(TenantContext::class)->run($actor, $tenant, function () {
                $codes = ['reception.view', 'patients.view', 'patients.create', 'appointments.view', 'appointments.create', 'doctors.view', 'rooms.view', 'schedules.view', 'services.view'];
                $this->table(['Permission', 'Effective scope'], array_map(fn ($code) => [$code, DB::selectOne('SELECT public.permission_scope(?) AS value', [$code])->value ?? 'MISSING'], $codes));

                return json_decode(DB::selectOne('SELECT governance.self_branches() AS value')->value, true, flags: JSON_THROW_ON_ERROR);
            });
            $this->info('Read only. No configuration, permissions or assignments were changed.');
            foreach ($branches as $branch) {
                $this->info($branch['name'].' · '.$branch['id']);
                foreach (['doctors', 'rooms', 'schedules'] as $kind) {
                    try {
                        $filters = ['branch_id' => $branch['id'], 'per_page' => 100];
                        if ($kind !== 'schedules') {
                            $filters['status'] = 'active';
                        }
                        $result = $scheduling->listing($actor, $tenant, $kind, $filters);
                        $this->line($kind.': '.$result['meta']['total']);
                    } catch (\Throwable $e) {
                        $this->line($kind.': denied/unavailable ('.(method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 'error').')');
                    }
                }
                try {
                    $catalog = $appointments->bookingOptions($actor, $tenant, ['branch_id' => $branch['id'], 'per_page' => 100]);
                    $this->line('Eligible booking doctors: '.$catalog['meta']['total']);
                    foreach ($catalog['data'] as $doctor) {
                        $this->line($doctor['id'].' · '.$doctor['name'].' · '.$doctor['specialty_name'].' · services: '.count($doctor['services']));
                    }
                    if ($catalog['meta']['total'] === 0) {
                        $this->warn('Check active doctor membership/user/specialty and doctor-branch assignments. No demo fallback.');
                    }
                } catch (\Throwable $e) {
                    $this->warn('booking-options denied/unavailable; check grants above, including services.view=entire_clinic.');
                }
            }
            if ($branches === []) {
                $this->warn('No visible active branch; check membership branch assignments.');
            }

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Unable to inspect booking. Check login, password, tenant UUID and active membership.');

            return self::FAILURE;
        } finally {
            unset($password);
        }
    }
}
