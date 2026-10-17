# Sprint 3 Stage 3 — validation evidence

Base: main `8fc45b6576ac826a37edefb17ed30e110eafaad0` (PR #6 merged), working branch `feature/doctors-scheduling-api`.

## Final checks

| Check | Result |
|---|---|
| Docker backend build | PASS; image sha256:a6bf99af23e9da94621d8932c442fb890917d06621290aebcfffa00918819ce7 |
| Full backend suite in the built image, default www-data OS user | **107 passed, 6710 assertions**, 108.18 seconds; no failed/skipped/disabled tests |
| PostgreSQL/runtime security | Real PostgreSQL 17, blusser_app non-owner/non-superuser/non-BYPASSRLS; forced RLS inventory includes 29 relations |
| Laravel Pint --test | **82 files passed** |
| composer validate --strict | PASS |
| composer check-platform-reqs | PASS; PHP 8.4.26 and required extensions |
| OpenAPI validator | Both docs/openapi.yaml and docs/openapi-sprint-3.yaml PASS |
| OpenAPI route/security regression | PASS in full suite; 20 added operations, including the two approved specialty mutations |
| Previous contracts | Every previously published operation unchanged; Patients operations/JSON, permission catalog, state machines and planned Stage 4–7 operations unchanged |
| Frontend npm run build | PASS: vue-tsc -b and Vite 8.3.4; 1805 modules, Vite build 333 ms; no frontend source changes |
| HTTP health smoke | HTTP 200, {"status":"ok"}; backend configured with blusser_test and blusser_app, migration password absent |
| Image/source consistency | All 21 changed/new backend files matched the tested Docker image by SHA-256 |
| git diff --check | PASS |

The final test/style/composer command used set -e so any failed stage would stop the command. Test databases were exclusively blusser_test on both migration and runtime connections, protected by the existing actual-database-name guard. No development/production refresh, truncate or domain fixtures were performed. The temporary test-database HTTP backend was stopped after the smoke check; the development database was not migrated by this validation. No secrets or real employee/patient data were introduced.

## New coverage

SchedulingApiTest adds 13 integration tests covering actual Sanctum cookie/CSRF HTTP CRUD; exact specialty response fields; mandatory idempotency UUIDs; replay/conflict and fresh grant checks; two-tenant runtime RLS and hidden IDs; forced-RLS/direct-DML/SECURITY DEFINER privileges; tenant-composite doctor/membership/specialty/service/room FKs; assigned-branch projections, counts and filters; live membership/role/user/profile/service/specialty/room revocation; multiple doctor branches; room number uniqueness and immutable branch; safe history snapshots; overlapping windows and breaks; closed/replace exceptions; timezone validation and DST gap/fold rejection; duration override; bounded availability; stale actor versions; platform denial; patient own fail-closed; empty migration down/up and refusal to discard data.

Parallel mutation tests use two independent PHP/PostgreSQL runtime transactions. Both are observed waiting on actual PostgreSQL locks before the parent releases its tenant lock: different keys for overlapping schedules yield 201/409, whereas identical-key retries yield 201/201 with one resource, one ledger row and one success audit per mutation. Cross-timezone weekly and exception room/doctor conflicts also roll back atomically. An accidental DML grant test confirms that absence of write RLS policies still rejects INSERT and affects zero rows for UPDATE/DELETE; the extra test grant is revoked in finally.

Existing Sprint 1/2, Patients optimistic PATCH/parallel lock tests, CEO delegation/provenance and migration-security rollback tests all remain in the full passing suite. Rollback step counts were advanced only to reach the same prior target migration after adding 000008; no assertion was removed.

## Environment notes and remaining limits

Cloud dependency retrieval reused the repository's existing source-install build override with verified CA/proxy configuration and unchanged Composer lockfile. The same vendor duplicate-class warnings were already present in the preceding Stage 2 build; build and platform validation pass. PHPUnit emitted a non-fatal warning because its optional /app/.phpunit.result.cache path is not writable by www-data. All tests executed and passed; no application/test assertions were disabled to suppress that cache warning.

Availability is schedule-only, not a booking or occupancy guarantee. Stage 4 reservations, appointments/visits, Stage 5 queues and Stage 6 frontend integration were not implemented. Existing services must be provisioned through the project's approved catalog process; no service CRUD or financial module was added. Long cross-timezone recurrence horizons and in-memory availability pagination require production-scale performance review. See sprint-3-stage-3.md for API semantics, deployment, protected rollback and local commands.

GitHub Actions is not claimed as run by local validation: the existing workflow triggers on pull_request or pushes to main, not an unreviewed feature-branch push. No merge is performed.

## Changed and new files

- `backend/app/Modules/Access/Services/Governance.php`
- `backend/app/Modules/Platform/Http/HealthController.php`
- `backend/app/Modules/Scheduling/Http/SchedulingController.php`
- `backend/app/Modules/Scheduling/Models/DoctorBranch.php`
- `backend/app/Modules/Scheduling/Models/DoctorProfile.php`
- `backend/app/Modules/Scheduling/Models/DoctorService.php`
- `backend/app/Modules/Scheduling/Models/Room.php`
- `backend/app/Modules/Scheduling/Models/ScheduleBreak.php`
- `backend/app/Modules/Scheduling/Models/ScheduleException.php`
- `backend/app/Modules/Scheduling/Models/ScheduleRule.php`
- `backend/app/Modules/Scheduling/Models/SchedulingRecord.php`
- `backend/app/Modules/Scheduling/Models/Specialty.php`
- `backend/app/Modules/Scheduling/Services/Availability.php`
- `backend/app/Modules/Scheduling/Services/Scheduling.php`
- `backend/database/migrations/2026_10_16_000008_add_doctors_and_scheduling.php`
- `backend/database/sql/scheduling.sql`
- `backend/routes/api.php`
- `backend/tests/Feature/DatabaseSecurityTest.php`
- `backend/tests/Feature/MigrationLifecycleTest.php`
- `backend/tests/Feature/PatientsMigrationTest.php`
- `backend/tests/Feature/SchedulingApiTest.php`
- `docs/architecture.md`
- `docs/database.md`
- `docs/openapi-sprint-3.yaml`
- `docs/openapi.yaml`
- `docs/sprint-3-stage-3-validation.md`
- `docs/sprint-3-stage-3.md`
