<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Additive predecessor: must run before 2026_10_22_000012 on upgrades from main. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Role-name preparation requires migration credentials.');
        }
        DB::statement('LOCK TABLE tenant_roles IN SHARE ROW EXCLUSIVE MODE');
        if (DB::selectOne("SELECT to_regclass('public.role_name_upgrades') AS name")->name === null) {
            DB::unprepared(<<<'SQL'
CREATE TABLE role_name_upgrades (
 tenant_id uuid NOT NULL REFERENCES tenants(id), role_id uuid NOT NULL,
 old_name varchar(255) NOT NULL, new_name varchar(255) NOT NULL,
 changed_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(tenant_id,role_id)
);
ALTER TABLE role_name_upgrades ENABLE ROW LEVEL SECURITY;
ALTER TABLE role_name_upgrades FORCE ROW LEVEL SECURITY;
CREATE POLICY migration_owner ON role_name_upgrades TO blusser_owner USING(true) WITH CHECK(true);
REVOKE ALL ON role_name_upgrades FROM PUBLIC,blusser_app;
SQL);
        }
        $rows = DB::select(<<<'SQL'
SELECT r.id,r.tenant_id,r.name FROM tenant_roles r
WHERE btrim(r.name)='' OR EXISTS (
 SELECT 1 FROM tenant_roles earlier WHERE earlier.tenant_id=r.tenant_id AND earlier.id<r.id
 AND lower(regexp_replace(btrim(earlier.name),'\s+',' ','g'))=lower(regexp_replace(btrim(r.name),'\s+',' ','g'))
) ORDER BY r.tenant_id,r.id
SQL);
        foreach ($rows as $row) {
            $base = DB::selectOne("SELECT coalesce(nullif(btrim(regexp_replace(?,'\\s+',' ','g')),''),'Rol') AS name", [$row->name])->name;
            $attempt = 0;
            do {
                $suffix = ' [role '.$row->id.($attempt ? ' #'.$attempt : '').']';
                $name = mb_substr($base, 0, 255 - mb_strlen($suffix)).$suffix;
                $exists = DB::selectOne("SELECT EXISTS(SELECT 1 FROM tenant_roles WHERE tenant_id=? AND id<>? AND lower(regexp_replace(btrim(name),'\\s+',' ','g'))=lower(regexp_replace(btrim(?),'\\s+',' ','g'))) AS present", [$row->tenant_id, $row->id, $name])->present;
                $attempt++;
            } while ($exists);
            DB::table('tenant_roles')->where('id', $row->id)->where('tenant_id', $row->tenant_id)->update(['name' => $name]);
            DB::table('role_name_upgrades')->insert(['tenant_id' => $row->tenant_id, 'role_id' => $row->id, 'old_name' => $row->name, 'new_name' => $name]);
        }
        // Protect the gap between this migration and the original 000012 transaction.
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS role_name_upgrade_guard ON tenant_roles(tenant_id,lower(regexp_replace(btrim(name),'\\s+',' ','g')))");
    }

    public function down(): void
    {
        if (DB::table('role_name_upgrades')->exists()) {
            throw new RuntimeException('Role-name upgrade history exists; use a forward migration.');
        }
        DB::statement('DROP INDEX IF EXISTS role_name_upgrade_guard');
        DB::statement('DROP TABLE role_name_upgrades');
    }
};
