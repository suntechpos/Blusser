<?php

use App\Modules\ClinicOperations\Contracts\OperationsContract;
use App\Modules\Patients\Services\CeoGrantBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const TABLES = ['patients', 'patient_branches', 'patient_counters', 'patient_idempotency'];

    private const FUNCTIONS = ['patient_allowed(uuid,text)', 'patient_branch_visible(uuid,uuid)', 'patient_projection(uuid)', 'patient_command(text,uuid,jsonb,uuid)', 'patient_export_audit()'];

    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Patients migration requires migration credentials.');
        }
        DB::statement('CREATE TABLE patient_permission_provenance(permission_id uuid PRIMARY KEY REFERENCES permissions(id), code text NOT NULL, allowed_scopes text[] NOT NULL)');
        DB::statement('CREATE TABLE patient_grant_provenance(tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, role_id uuid NOT NULL, permission_id uuid NOT NULL REFERENCES permissions(id), PRIMARY KEY(tenant_id,role_id,permission_id))');
        foreach (OperationsContract::permissions() as $code => $scopes) {
            $existing = DB::table('permissions')->where('code', $code)->first();
            $encoded = '{'.implode(',', $scopes).'}';
            if ($existing && $existing->allowed_scopes !== $encoded) {
                throw new RuntimeException('Permission catalog mismatch: '.$code);
            }
            $id = $existing->id ?? (string) Str::uuid();
            if (! $existing) {
                DB::table('permissions')->insert(['id' => $id, 'code' => $code, 'allowed_scopes' => $encoded]);
                DB::table('patient_permission_provenance')->insert(['permission_id' => $id, 'code' => $code, 'allowed_scopes' => $encoded]);
            }
        }
        app(CeoGrantBackfill::class)->run(DB::connection(), true);
        DB::unprepared(file_get_contents(database_path('sql/patients.sql')));
        foreach (self::TABLES as $table) {
            DB::statement("REVOKE ALL ON $table FROM PUBLIC, blusser_app");
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY migration_owner ON $table TO blusser_owner USING(true) WITH CHECK(true)");
        }
        DB::statement('GRANT SELECT ON patients, patient_branches TO blusser_app');
        DB::statement('REVOKE ALL ON patient_permission_provenance, patient_grant_provenance FROM PUBLIC, blusser_app');
        foreach (self::FUNCTIONS as $signature) {
            DB::statement('ALTER FUNCTION governance.'.$signature.' OWNER TO blusser_security');
            DB::statement('REVOKE ALL ON FUNCTION governance.'.$signature.' FROM PUBLIC, blusser_app');
        }
        foreach (['patient_allowed(uuid,text)', 'patient_branch_visible(uuid,uuid)', 'patient_command(text,uuid,jsonb,uuid)', 'patient_export_audit()'] as $signature) {
            DB::statement('GRANT EXECUTE ON FUNCTION governance.'.$signature.' TO blusser_app');
        }
        // Tenant provenance is migration-only, still forced RLS. No runtime/security grants.
        DB::statement('ALTER TABLE patient_grant_provenance ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE patient_grant_provenance FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY migration_owner ON patient_grant_provenance TO blusser_owner USING(true) WITH CHECK(true)');
    }

    public function down(): void
    {
        // Refuse to discard dependent clinical data or subsequently delegated permissions.
        if (DB::table('patients')->exists() || DB::selectOne("SELECT EXISTS(SELECT 1 FROM role_permissions rp JOIN patient_permission_provenance p ON p.permission_id=rp.permission_id
            LEFT JOIN patient_grant_provenance g ON (g.tenant_id,g.role_id,g.permission_id)=(rp.tenant_id,rp.role_id,rp.permission_id)
            WHERE g.permission_id IS NULL OR rp.scope<>'entire_clinic') AS changed")->changed) {
            throw new RuntimeException('Patients data or delegated grants exist; use a reviewed forward migration.');
        }
        if (DB::selectOne("SELECT EXISTS(SELECT 1 FROM role_permissions rp JOIN patient_grant_provenance g ON (g.tenant_id,g.role_id,g.permission_id)=(rp.tenant_id,rp.role_id,rp.permission_id) WHERE rp.scope<>'entire_clinic') AS changed")->changed) {
            throw new RuntimeException('Deployment grants changed; use a reviewed forward migration.');
        }
        if (DB::selectOne('SELECT EXISTS(SELECT 1 FROM permissions p JOIN patient_permission_provenance d ON d.permission_id=p.id WHERE p.code<>d.code OR p.allowed_scopes<>d.allowed_scopes) AS changed')->changed) {
            throw new RuntimeException('Permission definitions changed after deployment.');
        }
        DB::statement('DROP POLICY patient_branches_read ON patient_branches');
        DB::statement('DROP POLICY patients_read ON patients');
        foreach (array_reverse(self::FUNCTIONS) as $signature) {
            DB::statement('DROP FUNCTION governance.'.$signature);
        }
        foreach (array_reverse(self::TABLES) as $table) {
            DB::statement('DROP TABLE '.$table);
        }
        DB::statement('DELETE FROM role_permissions rp USING patient_grant_provenance g WHERE (rp.tenant_id,rp.role_id,rp.permission_id)=(g.tenant_id,g.role_id,g.permission_id)');
        $ids = DB::table('patient_permission_provenance')->pluck('permission_id')->all();
        DB::statement('DROP TABLE patient_grant_provenance, patient_permission_provenance');
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
