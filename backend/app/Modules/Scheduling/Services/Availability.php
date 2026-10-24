<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Access\Permissions;
use App\Modules\Identity\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only availability hints, including current occupancy; never a reservation. */
final class Availability
{
    public function get(User $actor, string $tenant, string $doctor, array $filters): array
    {
        $scheduling = app(Scheduling::class);

        return $scheduling->context($actor, $tenant, function () use ($scheduling, $doctor, $filters) {
            foreach (['doctors.view', 'rooms.view', 'schedules.view'] as $permission) {
                abort_if(app(Permissions::class)->scope($permission) === null, 403);
            }
            $scheduling->visible('doctors', $doctor);
            $page = (int) ($filters['page'] ?? 1);
            $size = (int) ($filters['per_page'] ?? 50);
            $context = DB::selectOne('SELECT governance.scheduling_availability_context(?,?,?) AS value', [$doctor, $filters['branch_id'], $filters['service_id']])->value;
            $slots = [];
            $reason = 'configuration_unavailable';
            if ($context !== null) {
                $context = json_decode($context, true, flags: JSON_THROW_ON_ERROR);
                $slots = $this->slots($doctor, $filters, $context, $reason);
            }

            return ['data' => array_slice($slots, ($page - 1) * $size, $size), 'meta' => ['page' => $page, 'per_page' => $size, 'total' => count($slots), 'unavailable_reason' => $reason]];
        });
    }

    private function slots(string $doctor, array $filters, array $context, ?string &$reason): array
    {
        $zone = new DateTimeZone($context['timezone']);
        $utc = new DateTimeZone('UTC');
        $from = new DateTimeImmutable($filters['from']);
        $to = new DateTimeImmutable($filters['to']);
        $day = new DateTimeImmutable($from->setTimezone($zone)->format('Y-m-d'), $utc);
        $last = new DateTimeImmutable($to->modify('-1 microsecond')->setTimezone($zone)->format('Y-m-d'), $utc);
        if ($day->diff($last)->days >= 31) {
            throw ValidationException::withMessages(['to' => 'Availability is limited to 31 local days.']);
        }
        $rules = DB::table('schedule_rules')->where('active', true)->where('doctor_id', $doctor)->where('branch_id', $filters['branch_id'])->get();
        $exceptions = DB::table('schedule_exceptions')->where('doctor_id', $doctor)->where('branch_id', $filters['branch_id'])->get()->keyBy('local_date');
        // service_type is a room category, NOT a service identity. Eligibility comes
        // from doctor_services; Stage 1 has no room-to-service relation to invent.
        $roomQuery = DB::table('rooms')->where('branch_id', $filters['branch_id'])->where('active', true)->where('availability', 'available');
        if (isset($filters['q'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
            $roomQuery->where('number', 'ilike', $term);
        }
        if (($filters['status'] ?? null) === 'inactive') {
            return [];
        }
        $rooms = $roomQuery->pluck('id')->all();
        $slots = [];
        $hasWindow = false;
        $hasRoomWindow = false;
        $closed = false;
        for (; $day <= $last; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $exception = $exceptions[$date] ?? null;
            if ($exception) {
                $closed = $closed || $exception->kind === 'closed';
                $windows = $exception->kind === 'closed' ? [] : [$exception];
            } else {
                $windows = $rules->filter(fn ($r) => $r->weekday === (int) $day->format('N') && $r->effective_from <= $date && ($r->effective_to === null || $r->effective_to >= $date));
            }
            foreach ($windows as $window) {
                $hasWindow = true;
                if (! in_array($window->room_id, $rooms, true)) {
                    continue;
                }
                $hasRoomWindow = true;
                $breaks = DB::table($exception ? 'schedule_exception_breaks' : 'schedule_breaks')->where($exception ? 'schedule_exception_id' : 'schedule_rule_id', $window->id)->get();
                $minutes = $context['duration_minutes'] ?? $window->slot_minutes;
                $start = new DateTimeImmutable($date.' '.$window->local_start, $utc);
                $end = new DateTimeImmutable($date.' '.$window->local_end, $utc);
                $this->instant($start->format('Y-m-d H:i:s'), $zone);
                $this->instant($end->format('Y-m-d H:i:s'), $zone);
                for ($cursor = $start; ($next = $cursor->modify('+'.$minutes.' minutes')) <= $end; $cursor = $next) {
                    $blocked = $breaks->contains(fn ($b) => $cursor->format('H:i:s') < $b->local_end && $next->format('H:i:s') > $b->local_start);
                    if ($blocked) {
                        continue;
                    }
                    $lo = $this->instant($cursor->format('Y-m-d H:i:s'), $zone);
                    $hi = $this->instant($next->format('Y-m-d H:i:s'), $zone);
                    if ($hi->getTimestamp() - $lo->getTimestamp() !== $minutes * 60) {
                        throw ValidationException::withMessages(['from' => 'A requested schedule interval crosses an ambiguous or nonexistent local time.']);
                    }
                    if ($lo >= $from && $hi <= $to && DB::selectOne('SELECT governance.booking_slot_free(?,?,?,?) AS allowed', [$doctor, $window->room_id, $lo->format('c'), $hi->format('c')])->allowed) {
                        $slots[] = ['doctor_id' => $doctor, 'branch_id' => $filters['branch_id'], 'room_id' => $window->room_id, 'starts_at' => $lo->format('Y-m-d\TH:i:s\Z'), 'ends_at' => $hi->format('Y-m-d\TH:i:s\Z')];
                    }
                }
            }
        }
        usort($slots, fn ($a, $b) => [$a['starts_at'], $a['room_id'], $a['ends_at']] <=> [$b['starts_at'], $b['room_id'], $b['ends_at']]);

        $reason = $slots !== [] ? null : (! $hasWindow ? ($closed ? 'schedule_closed' : 'no_schedule') : (! $hasRoomWindow ? 'room_unavailable' : 'no_free_slots'));

        return $slots;
    }

    /** Reject PHP's silent normalization and either interpretation of a DST fold. */
    private function instant(string $wall, DateTimeZone $zone): DateTimeImmutable
    {
        $naive = new DateTimeImmutable($wall, new DateTimeZone('UTC'));
        $timestamp = $naive->getTimestamp();
        $offsets = array_unique(array_column($zone->getTransitions($timestamp - 172800, $timestamp + 172800), 'offset'));
        $matches = [];
        foreach ($offsets as $offset) {
            $candidate = (new DateTimeImmutable('@'.($timestamp - $offset)))->setTimezone($zone);
            if ($candidate->format('Y-m-d H:i:s') === $wall) {
                $matches[] = $candidate->setTimezone(new DateTimeZone('UTC'));
            }
        }
        if (count($matches) !== 1) {
            throw ValidationException::withMessages(['from' => 'Ambiguous or nonexistent local schedule time; select a valid interval.']);
        }

        return $matches[0];
    }
}
