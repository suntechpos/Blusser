# Sprint 3 Stage 4 — validation evidence

Base main: `59b6ed8` (PR #7 merged). Branch: `feature/appointments-visits-api`.

## Final results

| Check | Result |
|---|---|
| Docker backend build | PASS; image `sha256:4f884e500e6f7d1be6da8598ccf91b6c41484ecf3fcec6e06f28df838fa3b3ec` |
| Full backend suite in built image, normal www-data OS user | **126 passed, 7652 assertions**, 121.68 seconds; zero failed/skipped/disabled tests |
| PostgreSQL integration | PostgreSQL 17, actual blusser_app runtime role; all 36 required tenant/identity relations FORCE RLS |
| Laravel Pint --test | **92 files passed** |
| composer validate --strict | PASS |
| composer check-platform-reqs | PASS, PHP 8.4.26 and required extensions |
| OpenAPI | Both specifications validated by openapi-spec-validator; route/response/reference/security consistency tests PASS |
| Contract preservation | Patient/PatientUpdate/Doctor/Room/Schedule/Exception JSON schemas unchanged; Patient write bodies, ETag/If-Match responses unchanged; permission catalog, state machines and planned Stage-5 endpoints unchanged |
| Frontend npm run build | PASS, vue-tsc -b + Vite 8.3.4; 1805 modules, Vite build 305 ms; no frontend source changes |
| HTTP health | HTTP 200, {"status":"ok"}; database blusser_test, role blusser_app, migration password absent |
| Tested artifact | SHA-256 of all 22 changed/new backend files matched the built/tested runtime image |
| git diff --check | PASS |

The authoritative final command ran `set -e; php artisan test --cache-directory=/tmp/blusser-stage4-phpunit; vendor/bin/pint --test; composer validate --strict; composer check-platform-reqs` in the built Compose test service and exited 0. PHPUnit's supported cache-directory option keeps optional test artifacts writable outside the root-owned application directory; no tests/assertions are disabled. All database refresh/truncate, raw fixtures, migration lifecycle and HTTP smoke operations used **blusser_test** only. Both actual/configured database names are guarded in FoundationTestCase. Development/production databases were not migrated/refreshed/truncated. No real personal/medical data or secrets were introduced.

## Security and concurrency evidence

AppointmentsApiTest adds 19 real PostgreSQL integration tests. They cover real Sanctum cookie/CSRF HTTP requests; scoped CRUD/history/patient projections; immutable response fields; booking/reschedule/cancel/no-show/check-in; same-key replay, payload conflicts and fresh grants; tenant/branch IDOR and composite FKs; absence of runtime DML/raw reservation/ticket/counter/ledger access; live doctor/service/room/membership revocation; actor credential version; platform denial; schedule/break/exception/duration validation; timezone gap/fold/stale-offset rejection; real occupancy filtering; scoped audited CSV; protected migration up/down/re-up; patient archive refusal; and real doctor own relations that expire when only terminal attendance remains.

Runtime concurrency tests launch independent PHP/PostgreSQL blusser_app transactions and observe real PostgreSQL locks before releasing their gate. Overlapping booking requests yield 201/409; identical-key create/check-in retries return the same resource without duplicate occupancy/ticket/events; parallel reschedules with one expected_version yield 200/409. A separate test holds the actual appointment row, changes its version in another committed transaction, and proves a waiting runtime reschedule checks the version after the row lock. Parallel walk-ins reject an active attendance duplicate, allocate distinct monotonically increasing branch/kind/day numbers, and create no occupancy while waiting.

GiST engine isolation tests deliberately use guarded **owner fixtures** below the tenant command mutex: simultaneous INSERTs with the same doctor/different rooms and different doctors/same room block inside the resource_reservations write, then return SQLSTATE **23P01** naming the expected doctor/room constraint. A separate concurrent UPDATE collision also blocks in the reservation GiST write and rolls back. This proves the database constraints independently of PHP validation and tenant serialization; these owner tests are not presented as RLS/authorization evidence. All clinical RLS/security assertions and runtime command races use blusser_app. A test-only overlapping cross-branch schedule fixture additionally proves the runtime booking doctor exclusion survives scheduling misconfiguration. Interval CHECK, tenant-composite FKs and deferred occupancy identities are also verified.

A controlled database error at the final ticket INSERT proves check-in rolls back Visit, appointment state/version, reservation attachment, queue counter, ticket, event history and idempotency entry. Retrying succeeds with the first number, without partial attendance or consumed numbering. Deferred schedule guards return sanitized 409 and preserve existing bookings rather than cancelling/shifting them.

Existing Sprint 1–3 regression tests, Patients parallel PATCH/If-Match tests, CEO delegation/provenance and security migration rollback tests all pass. Rollback step counts were advanced solely to reach the same earlier migration targets; the Scheduling protected-down test now reapplies later migrations in finally. The prior Stage-2 assertion that /patients/{patient}/visits did not exist was replaced by explicit 200/empty-data/zero-total assertions for the now-implemented scoped endpoint. No denial assertion was removed to bypass authorization.

## Scope, deployment and known limits

15 Stage-4 operations are documented, including the additive Visit history read required by the task. Migration 000009 adds seven FORCE-RLS relations and the owner-approved internal waiting ticket/counter foundation. SECURITY DEFINER entry points are booking_command and booking_export_audit, plus narrow boolean read predicates; private validators/projections/constraint helpers have no runtime/PUBLIC execute grant. The permission catalog and existing CEO grants are unchanged. See sprint-3-stage-4.md for exact endpoint/permission lists, shared reservation FKs, exclusion constraints, archive/care predicates, deployment and protected rollback.

The cloud build reused the existing CA-verified source-install override and unchanged Composer lockfile. Existing vendor duplicate-class warnings from that source-install setup remain non-fatal; package discovery, tests and Composer validation pass. No repository Dockerfile or dependency/lockfile changes were needed.

Queue/Reception public API and visit/ticket lifecycle beyond initial queueing are deferred to Stage 5; waiting-only ticket CHECK intentionally prevents accidentally enabling those states. Walk-ins queue without reservations until Stage-5 start supplies a validated room/interval. Availability remains an advisory current-occupancy hint. Tenant serialization, schedule guard scans and availability pagination require performance review at production scale. General expired-idempotency cleanup and production deployment are not included. Frontend integration is Stage 6. No Stage 5 work or main merge is performed. GitHub Actions is not claimed as run by these local checks; the existing workflow requires a PR or main push.

## Changed/new files

- `backend/app/Modules/Access/Services/Governance.php`
- `backend/app/Modules/Appointments/Http/AppointmentController.php`
- `backend/app/Modules/Appointments/Models/Appointment.php`
- `backend/app/Modules/Appointments/Models/OperationEvent.php`
- `backend/app/Modules/Appointments/Models/OperationRecord.php`
- `backend/app/Modules/Appointments/Models/QueueTicket.php`
- `backend/app/Modules/Appointments/Models/ResourceReservation.php`
- `backend/app/Modules/Appointments/Models/Visit.php`
- `backend/app/Modules/Appointments/Services/Appointments.php`
- `backend/app/Modules/Platform/Http/HealthController.php`
- `backend/app/Modules/Scheduling/Http/SchedulingController.php`
- `backend/app/Modules/Scheduling/Services/Availability.php`
- `backend/app/Modules/Tenancy/TenantContext.php`
- `backend/database/migrations/2026_10_17_000009_add_appointments_and_visits.php`
- `backend/database/sql/appointments.sql`
- `backend/routes/api.php`
- `backend/tests/Feature/AppointmentsApiTest.php`
- `backend/tests/Feature/DatabaseSecurityTest.php`
- `backend/tests/Feature/MigrationLifecycleTest.php`
- `backend/tests/Feature/PatientsApiTest.php`
- `backend/tests/Feature/PatientsMigrationTest.php`
- `backend/tests/Feature/SchedulingApiTest.php`
- `docs/architecture.md`
- `docs/database.md`
- `docs/openapi-sprint-3.yaml`
- `docs/openapi.yaml`
- `docs/sprint-3-stage-4-validation.md`
- `docs/sprint-3-stage-4.md`
