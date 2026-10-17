# Sprint 3 Stage 5 — Queues / Reception

Base: main `ee7d16c1be01f5a67af192501a10bcb3f65010e4` (PR #8).
Branch: `feature/queues-reception-api`. No frontend or Stage 6 changes.

## Published operations

All paths below have `/api/v1/clinic` prefix. First-party Sanctum cookie/CSRF,
full identity, server-selected active clinic, live membership and effective action
scope are mandatory. Platform operators have no clinical bypass.

| Method | Path | Action |
|---|---|---|
| GET | /queue-tickets | queues.view; scoped paginated list |
| POST | /queue-tickets | queues.create + visits.create + patients.view; walk-in attendance |
| GET | /queue-tickets/{ticket} | queues.view; scoped QueueTicket |
| POST | /queue-tickets/{ticket}/call | queues.call |
| POST | /queue-tickets/{ticket}/recall | queues.recall |
| POST | /queue-tickets/{ticket}/start | queues.start |
| POST | /queue-tickets/{ticket}/complete | queues.complete |
| POST | /queue-tickets/{ticket}/skip | queues.skip |
| POST | /queue-tickets/{ticket}/cancel | queues.cancel |
| POST | /queue-tickets/{ticket}/requeue | queues.requeue |
| GET | /reception/overview | reception.view intersected with each source view |

List filters: page/per_page (default 1/50, maximum size 100), q (escaped resource
UUID search), branch_id, doctor_id, status, from/to offset timestamps. Bounds are
inclusive from/exclusive to, maximum combined interval 366 days; order created_at,id.
Unknown inputs are rejected. GET detail does not accept query fields. UUIDs are
opaque resource IDs; ticket numbers are not IDs. Responses preserve the Stage-1
QueueTicket, Attendance, Appointment, Visit and input schemas.

Every mutation needs UUID Idempotency-Key. Commands except create need positive
expected_version of the **ticket**. StartInput additionally needs room_id,
starts_at and ends_at. Attendance mutations independently require queues.view,
visits.view, and appointments.view when linked; permissions are intersected per
resource, not inferred from the action. Linked start/complete require
appointments.update; linked cancel requires appointments.cancel. Start/requeue
also require patients.view; start needs the existing doctors.view, rooms.view,
schedules.view and entire-clinic-only services.view reference permissions.
Queue-only reads do not silently require visits.view or expose extra Visit fields.
No new permissions, automatic grants, role names or CEO backfills are introduced.

## Transitions, reservations and concurrency

The approved OperationsContract graph is unchanged:

- waiting -> called; recall stays called and emits a new event/version.
- called -> in_progress -> completed.
- waiting/called -> skipped; skipped -> waiting via requeue.
- waiting/called/skipped -> cancelled. There is no in-progress cancel shortcut.
- Terminal completed/cancelled records cannot reopen.

Ticket waiting maps to Visit queued; other ticket states map directly to Visit.
Linked appointments stay checked_in through call/recall/skip/requeue, move to
in_progress/completed on start/complete, and cancelled on queue cancel. All changes,
versions, typed histories, audit and idempotency ledger commit together. Responses
are synchronized Attendance objects. Recall is a state-preserving event, not a no-op.

Stage-4 ticket/counter/Visit/reservation tables and GiST exclusions are reused.
Skip preserves the ticket, number, Visit and linked appointment reservation;
requeue never increments a counter. Cancel/complete deactivate occupancy.
Scheduled start requires the exact room/interval of the existing active reservation,
not an implicit reschedule; requeue revalidates that reservation and schedule.
An expired scheduled interval conflicts, preserving late-arrival rules: no automatic
shift or cancellation, and no public rescheduling of a checked-in appointment.

Walk-ins wait without occupancy. Requeue rechecks live doctor/service/patient/branch
eligibility; because its approved CommandInput has no interval, it makes no slot
claim. Start supplies the interval/room and revalidates schedule, breaks, exceptions,
service duration/grid, timezone/DST, live doctor membership/branch and room eligibility
before creating one reservation. UTC instants or the actual branch offset are accepted;
stale local offset labels, ambiguous/gap slots and already-ended intervals conflict.
Scheduled starts reuse occupancy; two walk-in starts competing for a doctor or room
cannot both commit. The existing deferred schedule-edit guard now also protects
active walk-in reservations; completion releases that constraint on future edits.

Lock order: sorted actor/doctor users -> tenant -> sorted rooms -> appointment /
reservation -> Visit -> **actual ticket FOR UPDATE**. Ticket expected_version is
checked after that lock. Tenant serialization coordinates existing governance,
booking and schedule commands. Database exclusion and deferred consistency
constraints remain independent safeguards. No direct runtime DML is granted.

Idempotency is tenant+actor+operation+original UUID key with canonical JSON digest,
minimal Visit reference and 24-hour expiry, never cached patient bodies. Changed
payload conflicts. Replay reauthorizes current action, views, scope and actor version
before returning the current synchronized representation without duplicate events.
Start reference grants are checked fail-closed (including NULL grants) on replay;
call/recall also recheck live doctor/branch/service eligibility.
Queue create reuses booking_command inside the same transaction with a private
random key, removes its transient ledger entry, and persists queue.create with the
original client key. A visits-create key cannot alias a queue-create operation.

## Reception overview

One local date, optional branch_id and doctor_id. Default date is today in the
selected branch timezone, otherwise tenant timezone. Each row is selected by its
own branch timezone (tenant fallback); tickets use their immutable allocation date.
The response timezone indicates the selected branch or tenant reference timezone.

Queue list/detail and overview use a transaction-local REPEATABLE READ, READ ONLY
snapshot, established before identity and permission reads. A response therefore
cannot mix counts/projections or Visit/ticket states from different commits. RLS
and effective grants are evaluated in that same snapshot; revocations apply on
the next request. Commit/rollback restores ordinary connection modes and clears
tenant settings. Mutation transactions and lock order are unchanged.

reception.view is an additional ceiling: assigned_branches intersects actor's
current assignments even when a source view grants entire_clinic. Source RLS still
applies, including own. Unauthorized sections **and their counts** are omitted,
not populated with clinic-wide totals or inferred timing data:

- appointments.view -> appointments, scheduled count, derived late count.
- visits.view -> visits, arrived count (number of visible Visits on that date).
- queues.view -> tickets, waiting/called/in_progress/completed state counts.

No source views yields counts={} without section arrays. Invisible branch filter
returns generic 404; lists with invisible filters return no records. No patient
names, phones or other demographic/medical data are added to operational schemas.

## Migration, security and rollback

Additive migration `2026_10_17_000010_add_queues_and_reception.php` expands the existing
waiting-only CHECK and adds internal called/started/completed timestamps and a list
index. No new tables; all 36 required relations remain FORCE RLS. A scoped SELECT
policy is added to queue_tickets for blusser_app. Counters, reservations and ledger
remain inaccessible. Private queue_scope/queue_attendance/integrity helpers are not
executable by runtime/PUBLIC. Only queue_visible, guarded queue_projection and
queue_command are exposed. SECURITY DEFINER owners remain NOLOGIN blusser_security,
fixed search_path=pg_catalog, qualified objects. Existing permission catalog, identity,
CSRF, session, tenant isolation and migration/runtime credential separation stay intact.

Deferred triggers enforce ticket/Visit status and scheduled/walk-in identity, and
require occupancy for an in-progress Visit. Health checks require the new command
and scoped ticket read privilege as well as the existing role/FORCE RLS inventory.
Audit is append-only, secret-free, actor-bound. Histories use existing typed events;
no extra public queue-history endpoint or public projection/table function is added.

Up is transactional and owner-only. Down refuses any progressed tickets, lifecycle
events or queue.* ledger rows; it never erases clinical history. Without Stage-5
operations it restores waiting-only CHECK, privileges, columns and the exact
Stage-4 schedule guard, preserving preexisting Stage-4 waiting tickets/counters.
Quiesce API and pair rollback with the Stage-4 image; otherwise health fails closed.
Nonempty deployments require a reviewed forward fix. Earlier migration tests retain
their original rollback targets; fixture terminalization now uses real queue cancel
instead of breaking the new Visit/ticket invariant under owner credentials.

## Local verification and limits

Use existing local .env and credentials; never commit them. Deployment uses the
existing owner-only migrate service. Automated tests have guards for configured
and actual **blusser_test** database names and use blusser_app for security assertions.

```sh
docker compose build backend
docker compose up -d --wait db
docker compose run --rm migrate
docker compose up -d --wait backend
curl --fail http://localhost:8000/api/v1/health
docker compose run --rm test
docker compose run --rm test vendor/bin/pint --test
docker compose run --rm test composer validate --strict
docker compose run --rm test composer check-platform-reqs
cd frontend
npm ci
npm run build
```

The migrate command above is a deployment instruction, not permission to refresh
any development database during validation. This task validates only blusser_test.

No frontend integration, Stage 6, WebSocket/SSE, sound, public display, auto-call-next,
priority optimization, medical records or finance. Overview is a single-day scoped
projection, not realtime transport. Tenant serialization and unpaginated one-day
overview require production load assessment. Existing general idempotency retention
cleanup remains deferred. See sprint-3-stage-5-validation.md for actual results and
separate local validation from GitHub CI status.
