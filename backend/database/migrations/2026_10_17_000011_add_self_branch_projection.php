<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Migration credentials required.');
        }
        DB::unprepared(<<<'SQL'
CREATE FUNCTION governance.self_branches() RETURNS jsonb LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT coalesce(jsonb_agg(jsonb_build_object('id',b.id,'name',b.name,'active',b.active,'timezone',coalesce(b.timezone,t.timezone)) ORDER BY b.id),'[]'::jsonb)
 FROM public.branches b JOIN public.tenants t ON t.id=b.tenant_id
 WHERE b.tenant_id=public.context_tenant() AND governance.scheduling_identity() AND b.active AND public.branch_assigned(b.id)
$$;
ALTER FUNCTION governance.self_branches() OWNER TO blusser_security;
REVOKE ALL ON FUNCTION governance.self_branches() FROM PUBLIC,blusser_app;
GRANT EXECUTE ON FUNCTION governance.self_branches() TO blusser_app;
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION governance.self_branches()');
    }
};
