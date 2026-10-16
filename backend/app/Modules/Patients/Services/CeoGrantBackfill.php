<?php

namespace App\Modules\Patients\Services;

use App\Modules\ClinicOperations\Contracts\OperationsContract;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use RuntimeException;

/** Deployment-only, never invoked by the runtime API. Defaults to a read-only preview. */
final class CeoGrantBackfill
{
    public function run(Connection $connection, bool $apply = false): int
    {
        if ($connection->selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Owner migration connection required.');
        }

        return $connection->transaction(function () use ($connection, $apply) {
            if ($apply) {
                $connection->select('SELECT id FROM users WHERE id IN (SELECT user_id FROM tenant_ceos) ORDER BY id FOR UPDATE');
                $connection->select('SELECT id FROM tenants WHERE id IN (SELECT tenant_id FROM tenant_ceos) ORDER BY id FOR UPDATE');
            }
            $ceos = $connection->select("SELECT c.tenant_id,r.id AS role_id FROM tenant_ceos c
                JOIN tenants t ON t.id=c.tenant_id AND t.status='active'
                JOIN tenant_memberships m ON (m.tenant_id,m.id,m.user_id)=(c.tenant_id,c.membership_id,c.user_id) AND m.active
                JOIN users u ON u.id=c.user_id AND u.active AND NOT u.is_platform_admin
                JOIN tenant_roles r ON (r.tenant_id,r.id)=(m.tenant_id,m.role_id) AND r.active AND r.protected AND r.code='ceo'");
            $total = 0;
            foreach ($ceos as $ceo) {
                $added = 0;
                foreach (OperationsContract::permissions() as $code => $scopes) {
                    $permission = $connection->table('permissions')->where('code', $code)->first();
                    if (! $permission || $permission->allowed_scopes !== '{'.implode(',', $scopes).'}') {
                        throw new RuntimeException('Permission catalog mismatch.');
                    }
                    $key = ['tenant_id' => $ceo->tenant_id, 'role_id' => $ceo->role_id, 'permission_id' => $permission->id];
                    if (! $connection->table('role_permissions')->where($key)->exists()) {
                        $added++;
                        if ($apply) {
                            $connection->table('role_permissions')->insert([...$key, 'scope' => 'entire_clinic']);
                            $connection->table('patient_grant_provenance')->insertOrIgnore($key);
                        }
                    }
                }
                if ($apply && $added > 0) {
                    $connection->table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $ceo->tenant_id,
                        'action' => 'permissions.backfilled', 'resource_type' => 'deployment',
                        'metadata' => json_encode(['migration' => 'patients-stage-2', 'count' => $added], JSON_THROW_ON_ERROR), 'request_id' => (string) Str::uuid()]);
                }
                $total += $added;
            }

            return $total;
        });
    }
}
