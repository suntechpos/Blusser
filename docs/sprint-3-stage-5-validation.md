# Sprint 3 Stage 5 — validation evidence

Base main: `ee7d16c1be01f5a67af192501a10bcb3f65010e4` (PR #8).
Branch: `feature/queues-reception-api`.

## Executed checks

Final built image: `sha256:992203fe9f99731893bd0af6fa7e219a8330a017be7ab2f10160487f7fe46703`.

The authoritative final command runs inside that image, without source bind mounts,
under its default www-data OS user:

```sh
docker compose run --rm test sh -lc 'set -e; php artisan test --cache-directory=/tmp/blusser-stage5-phpunit; vendor/bin/pint --test; composer validate --strict; composer check-platform-reqs'
```

| Final built-image check | Result |
|---|---|
| Full backend suite | **149 passed, 8371 assertions**, 131.84 seconds; no failures/skips/disabled tests |
| Laravel Pint --test | **96 files PASS** |
| composer validate --strict | PASS |
| composer check-platform-reqs | PASS; PHP 8.4.26 and all required extensions |

The final set-e command completed with exit code 0. No tests/assertions were removed
or skipped. The supported PHPUnit cache-directory option writes optional artifacts
to /tmp without altering the normal image user or runtime DB role.

Additional independent checks:

- Targeted QueuesApiTest: **23 passed, 234 assertions**, 15.55 seconds.
- Earlier full regression before the last four security tests: **145 passed,
  8332 assertions**, 134.34 seconds. This is not the final full-suite result.
- Frontend `npm run build`: PASS; vue-tsc -b and Vite 8.3.4, 1805 modules, 321 ms.
  No frontend source, package manifest or lockfile changed.
- Both OpenAPI 3.1 documents: openapi-spec-validator PASS.
- Static contract comparison: all previously deployed operations unchanged;
  all Stage-1 JSON schemas, state machines and permission catalog unchanged;
  exactly 11 Queue/Reception operations promoted to implemented.
- HTTP runtime health: HTTP 200, {"status":"ok"}; actual database blusser_test,
  actual role blusser_app, migration password absent. Temporary API stopped afterward.
- Artifact SHA-256: all **14** changed/new backend + OpenAPI files matched the
  built and tested image (12 backend files and both specifications).
- git diff --check: PASS.

All destructive database operations use **blusser_test** only. FoundationTestCase
checks actual and configured database names before refresh/truncate; runtime RLS
and API assertions use non-owner/non-superuser/non-BYPASSRLS blusser_app. Owner
credentials are limited to guarded migrations and synthetic fixtures. No development
or production database was migrated/refreshed/truncated during this validation.

## Security, concurrency and atomicity coverage

23 new tests exercise all 11 endpoints with real Sanctum cookie sessions and CSRF,
not fake login or actingAs. They verify independent queue actions, required views,
linked appointment cancel/update capabilities, generic foreign UUID denial,
platform denial, missing context, raw scoped queue reads and denied runtime DML /
raw counter/reservation/ledger/private-helper access. Shared DatabaseSecurityTest
checks all 36 FORCE-RLS relations, every definer owner/fixed search_path and no
PUBLIC function execution. Missing reference grants fail closed, including NULL
results from existing SQL helpers and replays after grant or credential revocation.

Branch/own tests cover current assignment revocation, another doctor in the same
branch, source/reception scope intersection, branch timezone/date filters and omitted
unauthorized sections/counts. Doctor/service eligibility and schedule/room conflicts
are rechecked. Existing patient, scheduling, identity, RLS, CSRF and RBAC suites remain.

Independent PHP processes use the real runtime role and wait on observed PostgreSQL
locks: competing call and skip/cancel yield 200/409; same-key recall/complete replay
returns 200/200 with one event/version transition. Concurrent creation rejects active
attendance duplicates and allocates distinct branch/kind/date numbers. Concurrent
walk-in starts competing for occupancy yield 200/409; completion releases occupancy.
An independently held actual ticket row is updated before the waiting command resumes:
expected_version is checked after FOR UPDATE, returning conflict without new history.

Tests verify linked Appointment/Visit/ticket synchronized states; scheduled reservation
reuse without duplication; skip/requeue preserving number and reservation; terminal
escape refusal; wrong offset and late scheduled requeue conflicts without auto-shifts.
Injected final audit failure rolls back ticket/Visit state, reservation, events and
ledger. Deferred schedule edits cannot invalidate active walk-in occupancy. Existing
Stage-4 tests independently prove raw concurrent PostgreSQL GiST exclusions and
failed check-in counter rollback; these tests are preserved.

Migration 000010 up/down/re-up, protected nonempty down and retained Stage-4 waiting
tickets are tested. Earlier rollback step counts still reach the same historical
migrations. Two former owner fixtures now use real queue cancel instead of violating
ticket/Visit consistency; their patient own-access-expiry assertions remain intact.
The former no-ticket-SELECT assertion is replaced by scoped read/foreign-tenant checks;
DML, counters, reservations and ledger remain denied.

## Environment observations and remaining scope

The existing CA-verified source-install build override and unchanged Composer lockfile
were reused. Docker vfs cache exhausted disk during one intermediate rebuild; only
identified obsolete task image/cache layers were removed, without deleting PostgreSQL
volumes or changing source requirements. The final image was rebuilt afterward.
Existing vendor source-install duplicate-class warnings are non-fatal and unchanged.

No frontend integration, Stage 6, WebSocket/SSE, sound, public display, medical or
financial scope. One-day overview scale, tenant serialization and general expired
idempotency cleanup remain production limitations described in sprint-3-stage-5.md.

GitHub REST API access currently fails at the HTTPS CONNECT proxy with **403 Forbidden**
for api.github.com; this is distinct from native Git read/push authorization. A real PR
creation and CI status must be confirmed separately; local PASS results are not GitHub
Actions results. Network configuration must allow api.github.com before those operations
can be retried; no token/secret values should be supplied in chat.

## Changed/new files

- `backend/app/Modules/Access/Services/Governance.php`
- `backend/app/Modules/Platform/Http/HealthController.php`
- `backend/app/Modules/Queues/Http/QueueController.php`
- `backend/app/Modules/Queues/Services/Queues.php`
- `backend/database/migrations/2026_10_17_000010_add_queues_and_reception.php`
- `backend/database/sql/queues.sql`
- `backend/routes/api.php`
- `backend/tests/Feature/AppointmentsApiTest.php`
- `backend/tests/Feature/MigrationLifecycleTest.php`
- `backend/tests/Feature/PatientsMigrationTest.php`
- `backend/tests/Feature/QueuesApiTest.php`
- `backend/tests/Feature/SchedulingApiTest.php`
- `docs/architecture.md`
- `docs/database.md`
- `docs/implementation-plan.md`
- `docs/openapi-sprint-3.yaml`
- `docs/openapi.yaml`
- `docs/sprint-3-stage-5-validation.md`
- `docs/sprint-3-stage-5.md`
- `docs/sprint-3.md`

## PR #9 review follow-up

See [review](sprint-3-stage-5-review.md). The reviewed base is 9cfca0a; the review
adds three concurrent-read/rollback regressions and a transaction-local read-only
snapshot boundary for queue list/detail/overview. No schema or write rules changed.

Final review image: sha256:7021d76021c33a1f97f333ea6bb295802245b41670e1f5b4abc4a0e1ecc2cfcd.
Full suite **152 passed, 8388 assertions**, 133.46 seconds; Pint **96 files PASS**;
Composer strict/platform PASS. Command exited 0 under normal image www-data and
blusser_app runtime role, test database blusser_test only. All three changed backend
files matched the image by SHA-256. No frontend or OpenAPI contract changes.
The earlier API-access limitation remains, but public checks HTML can be read;
base PR #9 foundation run 39028589503 was verified succeeded via that page.
