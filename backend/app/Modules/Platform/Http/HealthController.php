<?php

namespace App\Modules\Platform\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

final class HealthController
{
    public function __invoke(): JsonResponse
    {
        try {
            $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
            $security = DB::selectOne("SELECT count(*) AS total FROM pg_class WHERE relnamespace = 'public'::regnamespace AND relrowsecurity AND relforcerowsecurity AND relname IN ('users','tenants','branches','tenant_roles','role_permissions','tenant_memberships','membership_branches','services','membership_link_requests','membership_link_request_branches','audit_logs','tenant_ceos','ceo_recovery_requests','patients','patient_branches','patient_counters','patient_idempotency','patient_grant_provenance','specialties','doctor_profiles','doctor_services','doctor_branches','rooms','schedule_rules','schedule_breaks','schedule_exceptions','schedule_exception_breaks','scheduling_idempotency','scheduling_changes','appointments','visits','resource_reservations','queue_tickets','queue_counters','operation_events','operation_idempotency')");
            $queue = DB::selectOne("SELECT to_regprocedure('governance.queue_command(text,uuid,jsonb,uuid)') IS NOT NULL AND has_table_privilege(current_user,'queue_tickets','SELECT') AS ready");
            if (! $queue->ready || $role->name !== 'blusser_app' || $role->rolsuper || $role->rolbypassrls || (int) $security->total !== 36) {
                throw new \RuntimeException('Runtime database prerequisites are not satisfied.');
            }
        } catch (Throwable) {
            // Do not expose connection strings, SQL, credentials, or stack traces.
            return response()->json(['status' => 'unavailable'], 503)->header('Cache-Control', 'no-store');
        }

        return response()->json(['status' => 'ok'])->header('Cache-Control', 'no-store');
    }
}
