# Sprint 3 Stage 2 — Patients API

Only Stage 2 is implemented. Stage 3–7 remain unimplemented; frontend source and
all existing designs are unchanged. Base: main ee9f86d, including PR #5.

## Delivered routes

All paths below have prefix `/api/v1/clinic`, require first-party Sanctum cookie,
CSRF for mutations, full identity and a session-selected active clinic.

| Method | Path | Permission |
|---|---|---|
| GET | /patients | patients.view |
| POST | /patients | patients.create + patient visibility |
| GET | /patients/{patient} | patients.view |
| PATCH | /patients/{patient} | patients.update + patient visibility |
| POST | /patients/{patient}/branches | patients.link_branch + patient visibility |
| POST | /patients/{patient}/archive | patients.archive entire_clinic + patient visibility |
| POST | /patients/{patient}/restore | patients.restore entire_clinic + patient visibility |
| GET | /patients/export | patients.export and patients.view entire_clinic |

Writes require UUID Idempotency-Key; unknown input is rejected. POST creation
returns 201, other writes 200, Patient JSON exactly follows Stage 1. With owner
approval, detail/write responses add `ETag: "N"`; parse N as positive integer for
archive/restore expected_version. Stale version/state is 409. PATCH still uses the
original demographic-only payload, no new required field. Missing/invisible IDs
are 404, absent action permission 403, validation 422, missing active clinic 409.

List/export: page/per_page, q (literal case-insensitive name/patient_number/normalized
phone substring), branch_id, status active/archived/all (default active), from/to
RFC3339 timestamps bounding created_at [from,to). The generic Stage 1 doctor_id
parameter is reserved and returns 422 until real doctor/care dependencies exist;
no fake relationship filter is applied. Histories remain unimplemented, return 404.
List uses data/meta and stable created_at/id order. Exports ignore pagination,
apply the same filters, refuse >10,000 rows, emit six approved columns and neutralize
spreadsheet formulas including leading whitespace/control characters. No PII in
filename, audit metadata or idempotency cache.

## Schema and write boundary

Additive migration `2026_10_16_000006_add_patients_api` creates patients,
patient_branches, patient_counters, patient_idempotency and migration-only
patient_permission_provenance/patient_grant_provenance. Phone is normalized, nullable
and nonunique; patient_number is tenant-unique and server allocated. Composite
patient/branch/linking-membership FKs enforce matching tenant. No physical deletion
API. Archive preserves rows/links; operational-history guards must be added with
real Appointment/Visit tables in Stage 4 (none exist now).

ENABLE/FORCE RLS on all tenant tables, including counters/idempotency/grant
provenance. Runtime can SELECT patients/visible branch links; has no direct
INSERT/UPDATE/DELETE on any patient table. Counters/idempotency/provenance have no
runtime SELECT either. Boolean visibility helpers and exact command signatures
are the only additional EXECUTE grants; private projection helper is inaccessible.
All functions have qualified relations and search_path=pg_catalog, owned by
NOLOGIN blusser_security. blusser_app remains non-owner/NOBYPASSRLS.

Writes validate full actor/version, active tenant/membership and action scope,
then lock actor -> tenant -> patient. Number allocation, first branch link, change,
idempotency registration and audit commit atomically. Hidden patient lookup/link
cannot discover other-branch identities. Permission/branch revocation is checked
again before replay, replay returns a current authorized projection, not cached
PII. Keys are actor-membership + tenant + operation scoped, payload+target hashed
canonically; changed payload is 409. TTL is 24 hours; expired matching keys are
replaced lazily, no background cleanup worker is introduced.

`own` is deliberately false for Patients until real active doctor/care relations
are implemented. assigned_branches uses current active membership_branches and
patient_branches; returned branch_ids are filtered, not the full patient branch
set. entire_clinic never crosses tenants and never grants Platform Admin access.
Registry changes are tenant-wide audit events without demographic values; a
branch-limited audit reader does not gain all registry metadata. Export audit and
read execute in one authorized transaction; a rejected export rolls audit back.

## Permission deployment and rollback

The frozen Stage 1 35-code catalog is inserted additively. Existing mismatched
scopes fail migration. Only verified active protected code=ceo role/membership/
user/tenant associations receive missing entire_clinic grants; no ordinary role
or platform identity gets automatic grants. Provenance tracks inserted catalog
rows and grants separately. Backfill audit stores deployment name/count per tenant.
New CEOs provisioned later receive the existing complete catalog through Sprint 2.

Down refuses patient data, later delegated dependencies, changed permission
definitions or edited deployment grants. It removes only deployment-owned grants
and catalog rows, preserving other modules' users, branches and original grants.
Use a reviewed forward migration on a used environment. Tests explicitly exercise
refusal, empty up/down/re-up and idempotent migrate. Test fixture cleanup is guarded
by BOTH configured and actual runtime/migration database name blusser_test; only
there may tenants/users be truncated before refreshing migrations.

## Run locally

Use existing secret setup; never commit .env. Run from repository root:

```sh
docker compose build backend
docker compose up -d --wait db
docker compose run --rm migrate
docker compose up -d --wait backend
curl --fail http://localhost:8000/api/v1/health
docker compose run --rm test
docker compose run --rm test vendor/bin/pint --test
docker compose run --rm test composer validate --strict
cd frontend && npm ci && npm run build
```

Migration credentials exist only in migrate/test services, never runtime backend.
The normal migrate command is additive; do not refresh/truncate development data.
See openapi.yaml for deployed API; openapi-sprint-3.yaml retains later-stage planned
contracts with Patient operations explicitly marked implemented.

### Late CEO verification and deployment preview

The migration and owner-only `patients:backfill-ceo` CLI share the same transactional
backfill service. It locks CEO users in UUID order before tenants, then rechecks
verified active protected associations and missing grants. This handles old pending
consent clinics that were deliberately excluded at initial migration: after CEO
verification, an authorized deployer can preview/apply missing grants. No runtime
API can perform this deployment action, and ordinary roles are never backfilled.

```sh
docker compose run --rm migrate php artisan patients:backfill-ceo
docker compose run --rm migrate php artisan patients:backfill-ceo --apply
```

Preview performs no writes and prints only a count. Apply is explicit, repeatable,
records grant provenance and deployment audit, and preserves existing grants.
Runtime credentials are rejected even if accidentally used for the CLI connection.
