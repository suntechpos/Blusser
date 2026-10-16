# Sprint 3 Stage 2 — acceptance report

Base main ee9f86d (PR #5), working branch feature/patients-api. Stage 2 only.

## Final executed validation

- Full backend suite in freshly built Docker image, default www-data OS user:
  **91 passed, 5478 assertions**, 91.73 seconds; no disabled/skipped tests.
- Laravel Pint --test: **67 files passed**.
- composer validate --strict: valid; composer check-platform-reqs: all passed.
- OpenAPI route coverage, planned-versus-implemented consistency, response shape,
  reference resolution and unchanged permission/transition contracts passed.
- npm run build (vue-tsc -b && vite build): passed, 1805 modules.
- Tracked Docker backend build: passed. Cloud-only Compose override supplied host
  build networking, proxy hostname resolution, existing trusted CA bundle and
  COMPOSER_PREFER_INSTALL=source. GitHub ZIP requests were proxy-blocked, so exact
  composer.lock source commits were used; no TLS/signature checks disabled and no
  dependencies/lockfiles/Dockerfile/Compose runtime settings changed.
- Additive local development migrate: migration 000006 DONE (49.39 ms); no refresh,
  truncate or down on development. Test fixture cleanup was only in blusser_test.
- Actual Docker HTTP GET /api/v1/health: 200, {"status":"ok"}.
- Actual backend role: blusser_app, database blusser, superuser=false,
  BYPASSRLS=false; migration credential variables absent from runtime.
- git diff --check: passed. Frontend, compose.yaml, Dockerfile, Composer manifests
  and old SQL security/migration definitions remain unchanged.

Final suite command:

```sh
docker compose run --rm test sh -c 'php artisan test --no-ansi && vendor/bin/pint --test && composer validate --strict && composer check-platform-reqs'
```

Security tests use real PostgreSQL runtime connections for isolation assertions;
owner connection is used only for fixture setup, intentional ACL fault injection
and migration/deployment operations. Parallel creation uses two child processes
whose live runtime connections are both observed waiting for locks before release;
distinct requests allocate distinct numbers, identical retries create one patient.

## Coverage

Tenant and branch IDOR, hidden UUIDs/links/search counts, multi-clinic switching,
live permission/branch/membership revoke, erroneous platform membership, own
fail-closed, full cookie/CSRF/logout, strict input spoofing, archive/restore version
conflicts via approved ETag, replay/mismatch/revoke, CSV formula/10,000-row limit,
secret-free audit and sanitized DB read failures, runtime ACL/forced RLS/composite
FK, accidental DML grant still denied by RLS, transaction rollback, migration
up/down/re-up/refusal, owner CLI dry-run/apply/repeat/runtime denial, existing
Sprint 1–2 security regression.

## New schema and operational limits

Migration: 2026_10_16_000006_add_patients_api. Domain/control tables: patients,
patient_branches, patient_counters, patient_idempotency; deployment metadata:
patient_permission_provenance (global owner-only), patient_grant_provenance
(tenant-scoped, forced RLS, owner-only). Direct runtime writes are never granted.
See sprint-3-stage-2.md for all eight endpoints and command/RLS boundaries.

Own care relationships and appointment/visit history do not exist yet and fail
closed/remain unimplemented. doctor_id filter returns 422 until real dependencies
exist. No doctor/scheduling/appointments/queues/finance endpoints or frontend
integration were added. Future Stage 4 must integrate nonterminal visit/appointment
checks into archive and implement actual care visibility. Expired idempotency keys
are replaced lazily (24-hour TTL); no background cleanup service was introduced.
Backfill previews exclude unverified/inactive/platform identities; deployer may
explicitly re-run owner-only backfill after late CEO consent. Used deployments
require reviewed forward migrations, not destructive rollback.

Stage 3 requires separate approval. No merge is authorized by this delivery.

## Files

- `backend/app/Modules/Access/Services/Governance.php`
- `backend/app/Modules/Patients/Console/BackfillCeoPermissions.php`
- `backend/app/Modules/Patients/Http/PatientController.php`
- `backend/app/Modules/Patients/Models/Patient.php`
- `backend/app/Modules/Patients/Services/CeoGrantBackfill.php`
- `backend/app/Modules/Patients/Services/Patients.php`
- `backend/app/Modules/Platform/Http/HealthController.php`
- `backend/bootstrap/app.php`
- `backend/database/migrations/2026_10_16_000006_add_patients_api.php`
- `backend/database/sql/patients.sql`
- `backend/routes/api.php`
- `backend/tests/Feature/ClinicOperationsContractTest.php`
- `backend/tests/Feature/DatabaseSecurityTest.php`
- `backend/tests/Feature/MigrationLifecycleTest.php`
- `backend/tests/Feature/PatientsApiTest.php`
- `backend/tests/Feature/PatientsMigrationTest.php`
- `backend/tests/FoundationTestCase.php`
- `docs/architecture.md`
- `docs/database.md`
- `docs/implementation-plan.md`
- `docs/openapi-sprint-3.yaml`
- `docs/openapi.yaml`
- `docs/sprint-3-stage-2-validation.md`
- `docs/sprint-3-stage-2.md`
