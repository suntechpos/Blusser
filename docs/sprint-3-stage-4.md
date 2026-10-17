# Sprint 3 Stage 4 — Appointments and Visits

Base: main `59b6ed8` (PR #7 merged). Branch: `feature/appointments-visits-api`. Stage 5–7 and frontend integration are not delivered.

## Approved queue boundary

The owner approved the minimal internal Queue foundation required by the Stage-1 Attendance contract: queue_tickets and queue_counters, forced RLS, atomically allocated tenant/branch/kind/local-day numbers, and one waiting ticket per Visit. Public Queue/Reception CRUD, call, recall, skip, requeue, start, complete and queue_cancel remain unimplemented. There is no alternate endpoint that transitions a checked-in appointment to cancelled or completes a consultation. Stage 5 must implement the approved coupled transitions rather than exposing arbitrary status writes.

## Delivered endpoints

Prefix `/api/v1/clinic`:

| Methods | Path | Permission / purpose |
|---|---|---|
| GET / POST | /appointments | appointments.view / appointments.create + patient/reference views; create scheduled booking |
| GET | /appointments/export | appointments.export + appointments.view, both entire_clinic; bounded CSV |
| GET | /appointments/{appointment} | appointments.view; includes derived late |
| POST | /appointments/{appointment}/reschedule | appointments.update; versioned scheduled -> scheduled |
| POST | /appointments/{appointment}/cancel | appointments.cancel; scheduled -> cancelled, release occupancy |
| POST | /appointments/{appointment}/no-show | appointments.no_show; explicit action only after starts_at |
| POST | /appointments/{appointment}/check-in | appointments.check_in + visits.create + queues.create; coupled Attendance |
| GET | /appointments/{appointment}/history | appointments.view, parent visibility AND original event doctor/branch scope |
| GET | /patients/{patient}/appointments | patients.view AND appointments.view |
| GET | /patients/{patient}/visits | patients.view AND visits.view |
| GET / POST | /visits | visits.view / visits.create + patients.view + queues.create; walk-in Attendance |
| GET | /visits/{visit} | visits.view |
| GET | /visits/{visit}/history | visits.view; additive endpoint for the explicitly requested Visit history, using the existing Event schema |

Mutations use Idempotency-Key UUID. State commands/reschedule take expected_version in the existing Stage-1 body. Unknown fields, actor/tenant/status spoofing and malformed offsets are rejected. Patient JSON, PATCH If-Match/ETag and archive/restore expected_version formats are unchanged. OpenAPI operation status is updated only for implemented Stage-4 endpoints; Queue/Reception operations remain planned.

Existing aggregate mutations additionally require current view authorization to bind/return an appointment. New appointment/reschedule checks the current patient view, active branch link, doctors.view, rooms.view, schedules.view and existing services.view rules. Old and new action scopes are checked on reschedule. Entire-clinic does not cross the active tenant. Role names never authorize an operation. The existing Stage-1 catalog and CEO delegation/backfill are unchanged; no grants are silently added or widened.

## Storage and PostgreSQL constraints

Migration `2026_10_17_000009_add_appointments_and_visits.php` adds:

- appointments: patient/doctor/branch/service/room, UTC interval, status/version, immutable occupancy identity.
- visits: separate patient/doctor/branch/service identity, optional consistent appointment reference, arrived_at, status/version and optional reservation identity.
- resource_reservations: shared appointment/Visit occupancy, active flag, one row per appointment and per linked Visit.
- queue_tickets and queue_counters: internal waiting-only foundation, not a public queue management module.
- operation_events: append-only, typed appointment/visit/ticket FKs, original doctor/branch, actor membership, server-defined action and states, request ID/time.
- operation_idempotency: tenant+actor+operation+key, canonical JSON digest, typed resource references and 24-hour expiry; no cached request bodies, names, phones or secrets.

All seven tenant relations ENABLE/FORCE RLS. Health/RLS readiness inventories include all 36 required relations. Tenant-composite FKs protect patient branch links, doctor branch/service assignments, room branch, linked appointment/Visit patient/doctor/branch/service consistency, ticket Visit branch and typed histories. No hard-delete API exists.

btree_gist is installed by the migration owner in the dedicated reservation_extensions schema; PUBLIC has no schema access. An existing extension in an unexpected schema causes a reviewable migration failure, not a silent search_path change. This is a PostgreSQL extension, no new service. The owner-only migration was exercised with PostgreSQL 17; runtime API never receives migration credentials.

Two partial GiST exclusion constraints enforce `(tenant_id, doctor_id, tstzrange(starts_at,ends_at,'[)'))` and `(tenant_id, room_id, range)` without overlap while active. Doctor exclusion spans branches. CHECK guarantees starts_at < ends_at. Adjacent intervals are allowed. Unique reservation/appointment/Visit keys and deferred tenant-composite circular FKs retain a single occupancy identity across reschedule and check-in. Deferred consistency triggers check active reservation against appointment state and paired Visit/appointment/reservation identity. Cancel/no-show deactivates the existing row instead of deleting history or affecting other bookings.

A partial unique index prevents concurrent duplicate nonterminal attendance for patient/branch/doctor. One appointment can have only one Visit; one Visit only one ticket. Queue counters increment and ticket insert commit together; failed check-in consumes no number. Local ticket date is derived from the server-recorded Visit arrival in the effective branch/clinic timezone. Kind/date/branch namespaces never reuse cancelled/skipped numbers in the future Stage-5 implementation.

## Write boundary and transactions

Runtime blusser_app remains non-owner, non-superuser, non-BYPASSRLS and cannot SET ROLE to privileged roles. It gets scoped SELECT on appointments, visits and operation_events; no direct DML or raw reservation/ticket/counter/ledger reads. The new booking_command(text,uuid,jsonb,uuid) is an exact Governance allowlist entry. Private helpers and trigger functions have no runtime/PUBLIC execute grant. All SECURITY DEFINER functions are owned by the existing NOLOGIN blusser_security role with fixed search_path=pg_catalog and qualified objects.

Commands accept a fixed action vocabulary and allowlisted payloads; no dynamic SQL/table names or trusted caller-supplied actor/tenant. Lock order remains ordered users -> active tenant -> ordered rooms -> appointment/reservation -> Visit -> ticket -> counter. Current role, branch, membership, scope and actor credential version are rechecked within that boundary. The expected appointment version and scheduled state are compared only after FOR UPDATE on the actual appointment row. Tenant serialization coordinates scheduling/governance mutations, and database exclusions remain an independent final safeguard.

Deferred schedule/break/exception/doctor-service guards reject a final schedule state that would invalidate existing nonterminal bookings. They inspect the final transaction, allowing a complete rule/break replacement without checking temporary intermediate states. Revoking staff eligibility does not silently cancel existing bookings; new booking/availability fails immediately and administrators must explicitly reschedule/cancel as appropriate. Governance now sanitizes PDO exceptions from deferred COMMIT constraints as well as ordinary QueryExceptions, returning generic conflict/validation errors without SQL/bindings.

Idempotency replay occurs after current actor/action/resource authorization and before stale version/state rejection. Changed payload under the same key returns 409. Check-in repeats cannot create a second Visit, ticket or occupancy; a different key on an already checked-in appointment conflicts. Audit success, typed events, counters, resources and idempotency ledger commit atomically. Denials use the existing separate secret-free audit boundary after rollback. reason_code is accepted as the existing contract specifies but is not copied into audit/history as arbitrary text.

## Scheduling, timezone and availability

PostgreSQL revalidates the live doctor membership/role/user/specialty, doctor branch membership, service, active compatible room, effective weekly rule or whole-day exception, breaks, slot grid and approved duration. Duration uses doctor_services override when configured, otherwise the approved schedule slot length; elapsed and local duration must agree. Schedule interval ends are exclusive.

Requests may use UTC Z/+00:00 instants or the actual branch offset. A stale/made-up nonzero offset that labels a nonexistent local clock time is rejected rather than silently shifted. Ambiguous fold/gap boundaries are rejected consistently with Stage 3, and ordinary slots on DST-transition days work. Branch IANA timezone overrides clinic timezone. Booking/reschedule must be in the future. Late remains derived, never an automatic cancellation; late check-in requires an explicit valid reschedule first. no_show is explicit and forbidden before the appointment starts.

The existing availability algorithm is retained, with only an additional scoped SECURITY DEFINER boolean occupancy predicate per candidate. Active doctor OR room reservations are subtracted without exposing patient IDs, raw reservations or foreign-branch projections. Response header is now `X-Availability-Mode: current-occupancy-hint`. This is still not a reservation or guarantee: booking/reschedule revalidates and inserts/updates the exclusion-protected occupancy inside its transaction.

Walk-in queues an arrived Visit without occupancy because the Stage-1 WalkInInput contains no room/interval. Stage-5 start must require a valid interval/room, revalidate, and reserve atomically. Appointments reuse their existing reservation at check-in; no duplicate occupancy is created. Early check-in is permitted for an otherwise valid scheduled appointment; late check-in is rejected. Stage 4 has no public Visit/ticket start/complete/cancel action.

## Patient own scope and archive protection

TenantContext now binds the verified actor credential version together with the tenant. Own patient access requires a live doctor profile for that user's active membership and an actual scheduled/checked_in/in_progress appointment or nonterminal Visit in a currently assigned active doctor branch. Terminal records alone do not authorize demographics. Branch projections disclose only branches with an active care relation, not every branch merely assigned to the doctor. Own demographic update additionally requires the independently granted patients.update permission and the existing If-Match/version guard; creating a doctor profile grants nothing.

Only narrow existing patient predicates/projection and the previous blanket own-update denial are changed via an additive migration. All original Patient command version, replay, tenant and branch logic is retained. A database trigger rejects patient archive with 409 if nonterminal appointments/Visits exist. Archive and booking share tenant serialization, preventing archive-vs-book races. Appointment/Visit histories remain independently scoped and do not grant ongoing patient/medical-history access.

## Local deployment and rollback

Use existing ignored .env credentials and Compose. No frontend/packages or financial data are added.

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

Tests run only against blusser_test, with both configured and actual database names checked before refresh/truncate. No destructive development/production migration is part of validation. Clinical runtime/RLS/security assertions use blusser_app. Owner credentials are used only by the guarded test fixture/migration connection; isolated raw GiST engine tests deliberately bypass command serialization under fixture credentials to prove simultaneous INSERT and UPDATE exclusions independently from the runtime-command concurrency tests.

Deployment migration up is transactional and owner-only. For an empty Stage-4 schema only, reviewed rollback is `docker compose run --rm migrate php artisan migrate:rollback --database=migration --step=1 --force`. Down refuses any Stage-4 clinical/event/counter/ledger rows, restores the exact pre-Stage-4 Patient predicates/command/projection, removes Stage-4 guards/relations and preserves the shared btree_gist installation. Stop/quiesce the API and pair schema rollback with the matching previous application image; Stage-4 runtime intentionally fails health against an older schema. Nonempty databases require a reviewed forward fix. Old migration lifecycle targets remain covered; tests restore later migrations even when an earlier protected down intentionally refuses data.

Remaining production limits: tenant-serialized writes and final schedule-guard scans favor correctness over throughput; long recurrence windows/in-memory availability pagination need scale review. Old idempotency entries are replaced on key reuse, but a general retention cleanup job is deferred. Timezone configuration remains deployment-managed and requires conflict review before a change. Stage 5 must expand waiting-only queue states and implement the approved transitions, rather than bypassing these constraints. See sprint-3-stage-4-validation.md for actual check results.
