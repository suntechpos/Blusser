<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->owner();
        DB::unprepared(<<<'SQL'
CREATE FUNCTION governance.cashier_pin_record() RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN
 -- Same user lock as employee/PIN updates, held through PHP hash verification.
 PERFORM governance.lock_users(ARRAY[public.context_user()]);
 PERFORM governance.actor();
 IF NOT public.is_active_member(public.context_tenant()) OR public.permission_scope('cashier.view') IS NULL THEN
  RAISE EXCEPTION 'Cashier access denied' USING ERRCODE='42501';
 END IF;
 RETURN (SELECT s.pin_hash FROM public.employee_cashier_secrets s
 JOIN public.employee_profiles p ON (p.tenant_id,p.id)=(s.tenant_id,s.employee_id) AND p.active
 JOIN public.tenant_memberships m ON (m.tenant_id,m.id)=(p.tenant_id,p.membership_id) AND m.active
 WHERE m.tenant_id=public.context_tenant() AND m.user_id=public.context_user());
END $$;
ALTER FUNCTION governance.cashier_pin_record() OWNER TO blusser_security;
REVOKE ALL ON FUNCTION governance.cashier_pin_record() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION governance.cashier_pin_record() TO blusser_app;
SQL);
    }

    public function down(): void
    {
        $this->owner();
        DB::statement('DROP FUNCTION governance.cashier_pin_record()');
    }

    private function owner(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Cashier PIN migration requires migration credentials.');
        }
    }
};
