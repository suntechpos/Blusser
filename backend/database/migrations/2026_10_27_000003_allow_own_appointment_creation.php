<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Own appointment scope migration requires owner credentials.');
        }

        // Extend the existing catalog row. Role grants, role IDs, memberships and
        // sessions are deliberately unchanged; an administrator must opt in.
        if (! DB::table('permissions')->where('code', 'appointments.create')->exists()) {
            throw new RuntimeException('Appointment creation permission is missing.');
        }
        DB::statement("UPDATE public.permissions SET allowed_scopes=array_prepend('own'::text,allowed_scopes) WHERE code='appointments.create' AND NOT ('own'=ANY(allowed_scopes))");

        // This ordering applies to dependencies inside ONE role. It does not
        // relax governance.check_grants(), which controls actor delegation and
        // still requires the existing branch-delegation checks.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION governance.scope_contains(parent_scope text,child_scope text)
RETURNS boolean LANGUAGE sql IMMUTABLE SET search_path=pg_catalog AS $$
 SELECT CASE parent_scope
   WHEN 'entire_clinic' THEN child_scope IN ('own','assigned_branches','entire_clinic')
   WHEN 'assigned_branches' THEN child_scope IN ('own','assigned_branches')
   WHEN 'own' THEN child_scope='own'
   ELSE false
 END IS TRUE
$$;

CREATE OR REPLACE FUNCTION governance.assert_role_dependencies(grants jsonb)
RETURNS void LANGUAGE plpgsql SET search_path=pg_catalog AS $$
DECLARE grant_value jsonb; view_code text; parent_code text;
BEGIN
 IF grants IS NULL OR jsonb_typeof(grants)<>'array' THEN
   RAISE EXCEPTION 'Invalid grants' USING ERRCODE='22023';
 END IF;
 FOR grant_value IN SELECT value FROM jsonb_array_elements(grants) LOOP
   view_code:=split_part(grant_value->>'code','.',1)||'.view';
   IF grant_value->>'code'<>view_code AND EXISTS(SELECT 1 FROM public.permissions WHERE code=view_code)
     AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(grants) parent_grant
       WHERE parent_grant->>'code'=view_code
         AND governance.scope_contains(parent_grant->>'scope',grant_value->>'scope')) THEN
     RAISE EXCEPTION 'Page view required' USING ERRCODE='22023';
   END IF;
   parent_code:=CASE split_part(grant_value->>'code','.',1)
     WHEN 'categories' THEN 'services.view'
     WHEN 'reception_actions' THEN 'reception.view'
     WHEN 'employee_schedule' THEN 'employees.view'
     WHEN 'departments' THEN 'rooms.view'
   END;
   IF parent_code IS NOT NULL AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(grants) parent_grant
     WHERE parent_grant->>'code'=parent_code
       AND governance.scope_contains(parent_grant->>'scope',grant_value->>'scope')) THEN
     RAISE EXCEPTION 'Parent view required' USING ERRCODE='22023';
   END IF;
 END LOOP;
END $$;
SQL);

        foreach (['scope_contains(text,text)', 'assert_role_dependencies(jsonb)'] as $function) {
            DB::statement('ALTER FUNCTION governance.'.$function.' OWNER TO blusser_security');
            DB::statement('REVOKE ALL ON FUNCTION governance.'.$function.' FROM PUBLIC,blusser_app');
        }

        // Employee page grants cap the role's actions. Keep the narrower scope
        // for mixed own/assigned grants, never turn a page grant into an action.
        // Existing view overrides and the absence of an action grant stay intact.
        $this->patch('governance.scope_for(uuid,uuid,text)', [
            "WHEN rp.scope=page.scope OR page.scope='entire_clinic' THEN rp.scope\n  WHEN rp.scope='entire_clinic' THEN page.scope" => "WHEN governance.scope_contains(page.scope,rp.scope) THEN rp.scope\n  WHEN governance.scope_contains(rp.scope,page.scope) THEN page.scope",
        ]);

        $this->patch('governance.access_role_command(text,uuid,jsonb,uuid,bigint)', [
            "parent_scope IS NULL OR (parent_scope<>'entire_clinic' AND parent_scope<>g->>'scope')" => "NOT governance.scope_contains(parent_scope,g->>'scope')",
            "(x->>'scope'='entire_clinic' OR x->>'scope'=g->>'scope')" => "governance.scope_contains(x->>'scope',g->>'scope')",
        ]);
        $grantCheck = 'PERFORM governance.check_grants(grants,public.context_user(),public.context_tenant());';
        $this->patch('governance.role_save(uuid,text,text,jsonb)', [
            $grantCheck => $grantCheck."\n PERFORM governance.assert_role_dependencies(grants);",
        ]);
    }

    /** Apply only reviewed anchors; repeated execution preserves the same result. */
    private function patch(string $signature, array $changes): void
    {
        $definition = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS sql', [$signature])->sql;
        foreach ($changes as $before => $after) {
            if (substr_count($definition, $after) === 1) {
                continue;
            }
            if (substr_count($definition, $before) !== 1) {
                throw new RuntimeException('Role dependency checks changed; review own appointment scope migration.');
            }
            $definition = str_replace($before, $after, $definition);
        }
        DB::unprepared($definition);
    }

    public function down(): void
    {
        throw new RuntimeException('Own appointment grants may be in use; reviewed forward migration required.');
    }
};
