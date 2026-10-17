# Sprint 3 Stage 6 — Patients frontend slice

This delivery is intentionally limited to the existing Bemorlar page and its
create, edit and detail dialogs. It starts from main `c29c3bf` (merged Stage 5).
Stage 6 as a whole is not complete. No merge is authorized.

## Connected behavior

- Real patient list, server pagination, name/number/phone search, active/all and
  authorized branch filtering. Empty q is omitted rather than sent as null.
- Existing add dialog submits demographic fields and an authorized branch UUID.
  Patient numbers and resource IDs come from the backend.
- Details and edit open a fresh GET, never only a cached table row. PATCH uses
  the exact opaque ETag in If-Match. A 409 keeps the draft and reports conflict;
  there is no automatic overwrite. JSON/version contracts are unchanged.
- Existing CSV export uses the server endpoint with the same list filters and
  its separate permission. No client-side synthetic CSV is generated.
- Loading, empty, validation, forbidden, unavailable, conflict and expired-session
  states are explicit. Failed requests never fall back to demo patient rows.
- Idempotency keys survive an uncertain write retry of the same payload/version;
  changed intents and completed writes get another key. Saves are mutually
  exclusive while pending. Secrets and clinical data are not persisted locally.

## Shared authenticated context

Login template and styles are unchanged. Existing Sanctum cookie/CSRF login and
logout remain real. Reload calls auth/me; temporary-password identities must
complete the real password-change endpoint before any clinical request. Clinical
membership selection uses auth/clinics and auth/active-clinic. Platform operators
receive no clinical bypass or fabricated membership.

The shared shell shows actual login, membership role and active clinic. Existing
patient branch filtering is reused; no new operational page is introduced.
The Patients hash survives reload/back navigation. Other pages retain their
explicit demo banner; the unavailable notification badge is hidden on Patients.

Changing clinic or branch aborts old clinic requests, invalidates request
generations and idempotency intents, and remounts patient content, including open
dialogs. A late response cannot repopulate the next context. Cross-tab context
and logout messages invalidate memory only and contain no patient data or secrets.
Permissions gate controls for usability; the backend remains authoritative on
every operation, including own and assigned-branch scopes and revoked grants.

## Approved minimal backend support

`GET /api/v1/clinic/context` returns only the current member's effective
permission-to-scope map, own active branch assignments (ID/name/timezone),
membership ID and active clinic timezone. It requires a full identity and verified
server-selected membership. Query context spoofing is rejected. It does not need
roles.view, employees.view or access to a clinic-wide role catalog. If separately
authorized for branches.view, the client can use the existing branch list too.

Migration `2026_10_17_000011_add_self_branch_projection` adds the narrow
`governance.self_branches()` projection with the existing non-login security owner,
fixed pg_catalog search_path, no PUBLIC execute and explicit identity/tenant/
assignment checks. It does not relax FORCE RLS, table privileges or write rules.
Runtime receives no owner credentials. Historical rollback tests advance their
step counts for the additional migration and restore migrations after a protected
rollback refusal. The existing migration-lifecycle regression exercises roundtrip.

The previously approved services read API is deferred: Patients does not need it.
The published self-context contract is in docs/openapi.yaml; existing operations
and Patient JSON stay unchanged.

## Validation

All database tests executed exclusively against guarded `blusser_test` using the
runtime role for requests and the owner role only for fixture/migration work.
No development DB migrations, seed operations or data resets were performed.

- Fresh Docker image, default www-data: **156 backend tests, 8467 assertions,
  0 failures**, 134.86 seconds. Includes real PostgreSQL concurrency, RLS, scoped
  aggregates, Patients If-Match/idempotency, sessions, permissions, migration
  roundtrip and new self-context security tests.
- `vendor/bin/pint --test`: **100 files PASS**.
- `composer validate --strict`, `composer check-platform-reqs`: PASS.
- Both OpenAPI documents: openapi-spec-validator PASS.
- Frozen `npm ci`, `npm test` (4 context/idempotency tests),
  `npm run typecheck`, `npm run build`: PASS.
- `PW_CHROMIUM_PATH=/usr/bin/chromium npm run test:browser`: **8 passed**,
  15.6 seconds; no skipped tests or failures. Also covers a delayed auth/me
  response during a clinic change in another tab. Screenshot:
  `/tmp/blusser-patients.png` (synthetic data only).

The browser suite uses real Sanctum and PostgreSQL with synthetic test-only
identities. It tests create/details/edit, stale ETag against a real concurrent
writer, invalid input, CSV export, reload, branch/clinic changes, read-only actor,
platform denial, first-login password change, session expiration, failed/forbidden
list handling, retry and a delayed previous-clinic response. Only failure/delay
paths are intercepted; login and successful clinical operations use the backend.
No medical or real personal data is used. Traces, cookies and videos are not saved.

## Reproduction

For a normal reviewed local deployment, follow the root setup instructions, build
the backend, then apply migration 000011 with the migrate service before using
the new context endpoint. Do not add migration credentials to backend runtime.
Run the frontend from frontend/ with `npm ci` and `npm run dev`.

To reproduce validation on a **disposable blusser_test only**, from the repo root:

```sh
docker compose run --rm test
docker compose run --rm test vendor/bin/pint --test
docker compose run --rm test composer validate --strict
docker compose run --rm test composer check-platform-reqs
docker compose run --rm test php artisan test --filter=BrowserFixtureTest
```

The fixture test resets test data. Run it after the backend suite and before
browser tests. Create a temporary Compose override containing:

```yaml
services:
  backend:
    environment:
      DB_DATABASE: blusser_test
```

Start backend with both compose files and `up -d --wait backend`. Then:

```sh
cd frontend
npm ci
npm test
npm run typecheck
npm run build
npx playwright install --with-deps chromium
npm run test:browser
```

An installed Chromium can be selected with PW_CHROMIUM_PATH. Playwright starts
Vite itself. Browser tests are serial because the synthetic fixture is shared.
Do not run another DB-resetting suite concurrently. Stop the temporary API
afterwards; preserve volumes. On this cloud's vfs engine, run Docker containers
serially with enough free space instead of cloning multiple backend containers.

## Remaining screens and contracts

- Home, Registratura, Qabullar and Navbatlar are **not integrated in this PR**.
  Their view files are unchanged and demo presentation remains explicitly marked.
- Doctors/schedules and the other menu entries are not accepted finished screens.
  They require separate approved UI work and page-by-page integration.
- Patient gender filtering, last-visit filtering, visit statistics, “Yangi” and
  “Nazoratda” operational statuses do not have matching current list contracts.
  Existing controls are disabled and statistics show unavailable rather than
  invented numbers. Backend status active is available through the Faol tab;
  all includes archived records. Clinical history is not newly implemented here.
- Archive/restore/link-branch backend capabilities are unchanged; adding new UI
  workflows for them is outside these existing add/edit/detail dialogs.
- Existing appointment/queue flows may still use their original demo data under
  the demo banner. They are not production API workflows or completed Stage 6.
- Wider Stage 6 drafts are preserved outside the checkout in
  `/workspace/Blusser-stage6-deferred/`: BookingForm, booking.css, reference helper,
  wider operation types, a Patients draft with extra actions, and a services-read
  controller draft. They are not imported, committed, deleted or declared tested.
- GitHub PR creation/CI status must be reported separately from these local
  results; local green checks do not establish a remote CI result.
