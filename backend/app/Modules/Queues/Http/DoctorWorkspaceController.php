<?php

namespace App\Modules\Queues\Http;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DoctorWorkspaceController
{
    public function __invoke(Request $request)
    {
        $rules = [
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'doctor_id' => ['sometimes', 'uuid'],
            'branch_id' => ['sometimes', 'uuid'],
        ];
        if (array_diff(array_keys($request->query()), array_keys($rules))) {
            throw ValidationException::withMessages(['request' => 'Unknown fields are not allowed.']);
        }
        $filters = validator($request->query(), $rules)->validate();
        $tenant = $request->session()->get('active_tenant_id');
        abort_unless(is_string($tenant), 409, 'Select an active clinic.');

        return response()->json(
            app(TenantContext::class)->run($request->user(), $tenant, function () use ($filters) {
                $value = DB::selectOne(
                    'SELECT governance.doctor_workspace_feed(?::date, ?::uuid, ?::uuid) AS value',
                    [$filters['date'] ?? null, $filters['doctor_id'] ?? null, $filters['branch_id'] ?? null],
                )->value;

                $feed = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

                // The security-definer feed already restricts tickets to this doctor
                // and branch. Fetch service IDs with normal visit RLS, without exposing
                // other patients, to resolve real schedule availability for queue start.
                if (($feed['doctor']['id'] ?? null) && ($feed['branch']['id'] ?? null) && ! empty($feed['tickets'])) {
                    $visitIds = array_values(array_filter(array_column($feed['tickets'], 'visit_id')));
                    $services = DB::table('visits')
                        ->whereRaw('tenant_id = public.context_tenant()')
                        ->where('doctor_id', $feed['doctor']['id'])
                        ->where('branch_id', $feed['branch']['id'])
                        ->whereIn('id', $visitIds)
                        ->pluck('service_id', 'id');
                    // Real consultation start timestamp comes from the ticket,
                    // not from a browser clock or the retired demonstration data.
                    // Ticket RLS is enforced in addition to the secured feed.
                    $ticketIds = array_column($feed['tickets'], 'id');
                    $started = DB::table('queue_tickets')
                        ->whereRaw('tenant_id = public.context_tenant()')
                        ->where('branch_id', $feed['branch']['id'])
                        ->whereIn('id', $ticketIds)
                        ->selectRaw('id, to_char(started_at AT TIME ZONE \'UTC\', \'YYYY-MM-DD"T"HH24:MI:SS"Z"\') AS started_at')
                        ->get()->keyBy('id');
                    foreach ($feed['tickets'] as &$ticket) {
                        $ticket['service_id'] = $services->get($ticket['visit_id'] ?? '') ?: null;
                        $ticket['started_at'] = $started->get($ticket['id'])?->started_at;
                    }
                    unset($ticket);
                }

                return $feed;
            }, consistentSnapshot: true),
        );
    }
}
