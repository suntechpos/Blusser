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

                // The workspace feed's scheduled projection may omit optimistic-lock
                // versions. Resolve them from RLS-visible appointments for this doctor
                // and branch; never trust a client-supplied version.
                if (($feed['doctor']['id'] ?? null) && ($feed['branch']['id'] ?? null) && ! empty($feed['scheduled'])) {
                    $appointmentIds = array_values(array_filter(array_column($feed['scheduled'], 'id')));
                    $versions = DB::table('appointments')
                        ->whereRaw('tenant_id = public.context_tenant()')
                        ->where('doctor_id', $feed['doctor']['id'])
                        ->where('branch_id', $feed['branch']['id'])
                        ->whereIn('id', $appointmentIds)
                        ->pluck('version', 'id');
                    foreach ($feed['scheduled'] as &$appointment) {
                        $version = $versions->get($appointment['id']);
                        $appointment['version'] = $version === null ? null : (int) $version;
                    }
                    unset($appointment);
                }

                // Cross-day active visits are not part of the selected day's
                // patient list. Show only authorized, doctor-scoped overdue
                // tickets; unresolved inaccessible tickets still block call
                // through the existing has_active_patient boolean.
                if (($feed['doctor']['id'] ?? null) && ($feed['branch']['id'] ?? null)) {
                    $overdue = DB::selectOne(
                        'SELECT governance.doctor_workspace_overdue(?::uuid, ?::uuid) AS value',
                        [$feed['doctor']['id'], $feed['branch']['id']],
                    )->value;
                    $feed['overdue'] = json_decode($overdue, true, flags: JSON_THROW_ON_ERROR);
                } else {
                    $feed['overdue'] = [];
                }

                // A consultation started on an earlier queue date can be completed
                // today. Include it in today's completed tab even though its original
                // queue local_date remains unchanged. Read through normal tenant RLS.
                $feed['completed_today'] = [];
                if (($feed['doctor']['id'] ?? null) && ($feed['branch']['id'] ?? null) && ($feed['date'] ?? null)) {
                    $timezone = $feed['branch']['timezone'] ?? 'Asia/Tashkent';
                    $day = $feed['date'];
                    $alreadyVisible = array_column($feed['tickets'] ?? [], 'id');
                    $recent = DB::table('queue_tickets as q')
                        ->join('visits as v', function ($join) {
                            $join->on('v.tenant_id', '=', 'q.tenant_id')
                                ->on('v.id', '=', 'q.visit_id');
                        })
                        ->join('patients as p', function ($join) {
                            $join->on('p.tenant_id', '=', 'v.tenant_id')
                                ->on('p.id', '=', 'v.patient_id');
                        })
                        ->join('services as svc', function ($join) {
                            $join->on('svc.tenant_id', '=', 'v.tenant_id')
                                ->on('svc.id', '=', 'v.service_id');
                        })
                        ->whereRaw('q.tenant_id = public.context_tenant()')
                        ->where('v.doctor_id', $feed['doctor']['id'])
                        ->where('q.branch_id', $feed['branch']['id'])
                        ->where('q.status', 'completed')
                        ->whereRaw('(q.completed_at AT TIME ZONE ?)::date = ?::date', [$timezone, $day])
                        ->whereNotIn('q.id', $alreadyVisible)
                        ->whereRaw('governance.queue_visible(q.id)')
                        ->whereRaw("governance.booking_visible('visits', v.id, 'visits.view')")
                        ->where(function ($query) {
                            $query->whereNull('v.appointment_id')
                                ->orWhereRaw("governance.booking_visible('appointments', v.appointment_id, 'appointments.view')");
                        })
                        ->orderBy('q.completed_at')
                        ->limit(100)
                        ->get([
                            'q.id', 'v.id as visit_id', 'p.id as patient_id',
                            'p.patient_number', 'p.name', 'p.date_of_birth as dob',
                            'p.gender', 'p.phone', 'svc.name as service',
                            'q.number', 'q.kind', 'q.status', 'q.version',
                            'v.appointment_id', 'q.created_at',
                        ]);
                    $feed['completed_today'] = $recent->toArray();
                }

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
