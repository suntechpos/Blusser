# Services catalog API integration

The existing Services page now reads and writes the existing PostgreSQL `services` catalog used by `doctor_services` and appointments. It does not import demo rows or create a second booking catalog. Existing service UUIDs/codes, doctor assignments and appointment/visit foreign keys remain intact. The Cashier demo's separate fixture imports are unchanged.

## Routes

All routes are under `/api/v1/clinic`, authenticated by the existing first-party Sanctum/full-identity middleware and active session tenant.

- `GET/POST appointments/configuration-services`: server list/new service. The old POST `{code,name,active}` setup contract is retained. New catalog POST omits `code`; the backend generates a unique tenant-local `XZ-...` code.
- `GET/PUT appointments/configuration-services/{id}`: detail/edit.
- `POST appointments/configuration-services/{id}/status`: `{active}`; soft status changes preserve history.
- `GET appointments/configuration-services/export`: filtered CSV, capped at 10,000 rows, authorized/audited, no silent truncation.
- `GET/POST service-categories`, `PUT service-categories/{id}`, `POST service-categories/{id}/status`: category listing/create/edit/status. No destructive category deletion is exposed.

List filters: `q`, `category_id`, `branch_id`, `status`, `page`, `per_page`. Response includes `data`, pagination `meta`, global tenant `stats`, actual `categories` with service counts and actual `branches`. Categories included in service lookups support the existing service form; category management requires its separate permission.

Mutations use the existing `Idempotency-Key` mechanism. PUT/status requires `If-Match` with the current record version; missing version gives 428 and stale edits 409. Backend-generated codes, tenant serialization, unique indexes and the intent ledger prevent duplicate parallel/retried creates. Current authority is rechecked before replay. The browser retains drafts on failure and does not show a successful local-only save.

## Storage and boundaries

Additive migration `2026_10_25_000021_add_service_catalog_metadata` adds category, service type, exact `numeric(14,2)` price, timing/turnaround metadata and version to `services`; new tables are `service_categories`, `service_branches`, and `service_catalog_counters`. Prices/turnaround travel as decimal strings, avoiding floating-point price conversion. New service writes require real active tenant branches and a valid category. Categories and service types are separate concepts; specialties are unchanged.

Old records retain unknown price/category/duration as null rather than fabricated values. Old services without explicit branch links retain their prior clinic-wide eligibility. Editing such a record requires explicitly choosing its new catalog metadata/branches. No synthetic catalog is seeded into local/production databases.

The existing entire-clinic `services.view/create/update/delete` and `categories.view/create/update/status/export` authorities are enforced server-side. Assigned/own scopes are not silently upgraded. New tables use FORCE RLS, tenant-composite foreign keys and owner/security policies; runtime application credentials have no direct mutation grants. Actual branch availability is checked in existing booking options, availability and live slot validation. Historical appointments remain readable after status/branch changes. Service duration/timing changes with existing appointments/visits are rejected with a conflict to preserve historical interval validity; other metadata/status updates remain available. Doctor-specific duration overrides take priority over service duration, which falls back to existing schedule slot length when null.

The existing UI, modal layouts, type/timing controls, branch chips, filtering/pagination, view/edit, color palette, responsive dimensions and dirty/focus handling are retained. Unknown metadata displays honestly; loading, empty, API error and stale-response states are explicit. No Registratura/doctor UI, Cashier behavior, backend workflow or GitHub Actions configuration is rewritten.

## Local update after merge

With the project's existing `.env` credentials:

```bash
cd ~/projects/Blusser
git switch main
git pull --ff-only origin main
docker compose build backend
docker compose run --rm migrate
docker compose up -d backend
cd frontend
npm ci
npm run build
# For the existing local Vite development setup:
npm run dev -- --host 127.0.0.1
```

If running PHP natively rather than Compose, run `php artisan migrate --database=migration --force` inside `backend` with the existing migration-owner credentials. Do not run test refresh/fixture commands against a development or production database. Rollback refuses once catalog data exists; use a reviewed forward migration. Previous applied migrations are unchanged.

## Validation

Tests use only `blusser_test`. The real browser acceptance creates a category and a decimal-priced service through the actual UI, reloads/edits/filters it, assigns that exact UUID to a real doctor through existing API, books a real appointment, disables the service, checks booking exclusion and preserves the appointment reference. Synthetic server fixtures used in isolated UI regressions are test-only.

Verified locally: 8 catalog PostgreSQL tests and 19 existing scheduling/room regressions passed (27 unique tests across targeted runs; successful suites were not restarted without relevant changes). Catalog coverage includes cross-tenant references, unsupported scope rejection, direct-DML/helper denial, status permission/replay revocation, stale versions, concurrent code generation/update/replay, exact decimal storage and additive migration preservation/refusal. All 8 mocked Services UI regressions passed (7 initial successes plus the corrected 1–480-minute contract assertion rerun). Both real API/PostgreSQL browser tests passed, including complete UI-to-appointment history and unauthorized reader denial. TypeScript/build passed; existing build chunk-size/dynamic-import warnings remain. Pint formatted the 6 changed PHP files. GitHub Actions workflows remain unchanged/manual; remote CI was not dispatched. The user's localhost database was not accessed or modified.

The final review added red→green regressions for branch restrictions on walk-in/queue admission and intent replay, plus consultation/laboratory payload validation. Saved appointment/visit history and existing cancel behavior are preserved. The migration uses guarded patches and exact backups of five existing function definitions. The core real browser booking flow was revalidated after that final shared-guard change.

The general `OpenApiContractTest` was also run and failed on the pre-existing, undocumented `/api/v1/clinic/employee-work-schedules/{employee}` GET/PUT route. Both the route and missing documentation were confirmed on base `origin/main` before this task. This change documents only Services/category APIs and does not change unrelated employee APIs or suppress the failing check. The updated OpenAPI JSON and 906 local references were validated independently. Remote CI remains unverified/manual.
