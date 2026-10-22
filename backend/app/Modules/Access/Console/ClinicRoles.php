<?php

namespace App\Modules\Access\Console;

use App\Modules\Access\Services\DefaultClinicRoles;
use App\Modules\Access\Services\Governance;
use App\Modules\Identity\Services\Identity;
use Illuminate\Console\Command;

final class ClinicRoles extends Command
{
    protected $signature = 'blusser:clinic-roles {--actor= : Existing clinic login} {--tenant= : Clinic UUID from the read-only listing} {--provision : Explicitly create missing initial roles; CEO only} {--only= : Comma-separated template codes, e.g. registrator,laborant,uzi_specialist}';

    protected $description = 'Read clinic IDs and server roles; optionally provision missing initial roles without replacing existing grants';

    public function handle(Identity $identity, Governance $db, DefaultClinicRoles $roles): int
    {
        $login = (string) ($this->option('actor') ?: $this->ask('Login'));
        $password = $this->secret('Joriy parol');
        try {
            $actor = $identity->authenticate($login, (string) $password, 'clinic-roles-cli');
            $clinics = json_decode($db->call('my_clinics', [], $actor), true, flags: JSON_THROW_ON_ERROR);
            $this->table(['Klinika ID', 'Klinika', 'Hisob roli'], array_map(fn ($c) => [$c['id'], $c['name'], $c['role']], $clinics));
            $tenant = $this->option('tenant');
            $only = $this->option('only') === null ? null : array_values(array_unique(array_map('trim', explode(',', (string) $this->option('only')))));
            if ($only !== null && ! $this->option('provision')) {
                $this->error('--only requires --provision.');

                return self::FAILURE;
            }
            if ($this->option('provision') && ! $tenant) {
                $this->error('--provision requires --tenant from the listing.');

                return self::FAILURE;
            }
            foreach ($clinics as $clinic) {
                if ($tenant && $tenant !== $clinic['id']) {
                    continue;
                }
                $this->info($clinic['name'].' · '.$clinic['id']);
                if ($this->option('provision')) {
                    $this->table(['Boshlang‘ich rol', 'Holat', 'Rol ID'], array_map(fn ($r) => [$r['name'], $r['status'], $r['id']], $roles->provision($actor, $clinic['id'], $only)));
                }
                $this->table(['Rol ID', 'Kod', 'Nom', 'Faol', 'Himoyalangan'], array_map(fn ($r) => [$r['id'], $r['code'], $r['name'], $r['active'] ? 'ha' : 'yo‘q', $r['protected'] ? 'ha' : 'yo‘q'], $roles->inspect($actor, $clinic['id'])));
            }
            if ($tenant && ! in_array($tenant, array_column($clinics, 'id'), true)) {
                $this->error('Clinic is unavailable to this account.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Unable to inspect/provision roles. Check credentials, active clinic membership and role authority. Existing roles were not replaced; a provisioning retry preserves completed roles.');

            return self::FAILURE;
        } finally {
            unset($password);
        }
    }
}
