# Access control integration

Settings and Employees use the existing cookie/CSRF Sanctum session, tenant membership, governance functions and PostgreSQL FORCE RLS. No client role name grants authority. A membership has one permission role per clinic; `profile.position` is an independent job label. Historical demo localStorage is neither imported nor trusted.

## Settings

Roles, notes, assignment counts and the permission catalog load from `/api/v1/clinic/roles` and `/permissions`. Creates/updates/deletes use real governance commands. Updates/deletes require a strong `If-Match: "<version>"`; stale writes return 409, missing versions 428. Intents prevent duplicate writes. Names are normalized and unique per clinic; actions require their page view at an adequate scope. Existing delegation, branch, protected role, own role and designated CEO safeguards remain authoritative.

The sidebar and hash routing use effective permission codes. Denied direct hashes show 403 without loading the page. Focus and a visible-page 15-second poll refresh grants; backend authorization reads live database permissions on every request. Empty grants produce an explicit no-page state. General/Payments remain “Tez orada”.

## Employees

`GET /clinic/employees?format=profile` projects legacy memberships and stable employee profiles. Profile-mode POST/PATCH atomically saves profile, existing identity and membership via governance. Profile writes require Idempotency-Key; updates require If-Match. Loginless profiles create no user. Enabling access creates an existing-system user with a hashed temporary password and mandatory first-login change. Disabling membership revokes sessions and increments credential version. Password reset uses the separate existing endpoint and the actor's current password.

Existing accounts use the existing identity lookup/link request/acceptance workflow. A proposal does not grant access: the target signs in and accepts with their current password through “Hisob bog‘lash so‘rovlari”. Acceptance atomically attaches the same profile to the membership. Reject/expiry never creates another user.

Profile edits currently require entire-clinic employee authority; own/assigned reads remain scoped. Photo upload and schedule/exception persistence are explicitly unavailable in this editor. They are not silently written to browser storage.

## Scope and remaining boundaries

Patients, appointments, queues, doctors and scheduling keep their existing real API/RLS enforcement. Cashier, clinical workspace, laboratory, diagnostics and service catalog screens still have demo data/actions where no matching backend command exists. Catalog entries mark `ui_only`; central UI guards do not turn those demo actions into server functionality. Kassa demo PIN is 1122, separate from real account authentication. No production cashier PIN or payment integration was added.

New `employee_profiles` and `access_editor_intents` tables have FORCE RLS; runtime has no direct mutation rights. Intent rows store only a payload digest/result, not credentials. New definer functions have a fixed pg_catalog search path, non-public execution and existing security ownership. Rollback refuses persisted access-editor data; use a forward migration in a populated environment.

## Local execution

Use the existing `.env.example` / Compose configuration and distinct owner/runtime credentials. Never supply owner credentials to the API service.

```sh
docker compose build backend
docker compose up -d --wait db
docker compose run --rm migrate
docker compose up -d --wait backend
cd frontend
npm ci
npm run dev -- --host 127.0.0.1
```

Tests are destructive **only in the dedicated `blusser_test` database**, guarded against both configured and actual database names. Do not run backend suites and real browser tests concurrently against that database.

```sh
docker compose run --rm test
docker compose run --rm test vendor/bin/pint --test
docker compose run --rm test composer validate --strict
docker compose run --rm test composer check-platform-reqs
cd frontend
npm test
npm run build
PW_CHROMIUM_PATH=/usr/bin/chromium npx playwright test settings-roles.spec.ts employees.spec.ts
```

For an existing initialized test database, these commands explicitly select it (export both password environment variables securely first):

```sh
docker compose run --rm -e DB_DATABASE=blusser_test migrate php artisan blusser:platform-admin accessdemo.platform
docker compose run --rm -e DB_DATABASE=blusser_test -e ACCESS_DEMO_OPERATOR_PASSWORD -e ACCESS_DEMO_PASSWORD migrate php artisan blusser:access-demo --operator=accessdemo.platform
```

For isolated interactive accounts, provision an existing platform operator using `php artisan blusser:platform-admin accessdemo.platform` (hidden prompts) on `blusser_test`. Then run `php artisan blusser:access-demo --operator=accessdemo.platform` with `ACCESS_DEMO_OPERATOR_PASSWORD` and `ACCESS_DEMO_PASSWORD` supplied securely in the process environment. It refuses production/non-test databases, has no default password and does not overwrite existing passwords. Accounts: accessdemo.admin, accessdemo.registrator, accessdemo.doctor, accessdemo.cashier.

For automated real HTTP/browser validation, prepare `AccessBrowserFixtureTest` alone on the isolated backend, serve that backend at localhost:8000, then run:

```sh
ACCESS_REAL_API=1 PW_CHROMIUM_PATH=/usr/bin/chromium npx playwright test --config access-real.config.ts
```

The fixture's synthetic credentials are test-only. `access-real.spec.ts` does not intercept API responses. `access-editor.fixture.ts` and other UI fixture suites intentionally mock API responses and are not evidence of backend authorization. Test logs for this delivery are saved under `/tmp/access-*.log`; final verified totals are recorded in the delivery report.

## Earlier delivery checks (before PR #23 review fixes)

- Full real PostgreSQL/Sanctum backend suite: **164 passed, 8,629 assertions** (`/tmp/access-full-final.log`). Includes RLS, delegation, CEO, sessions, concurrent existing commands, profile/account atomicity, consent, role versions and rollback protection.
- Real access browser suite, without API interception: **4 passed** (`/tmp/access-real-final.log`), run from the publication snapshot excluding unrelated local files. Covers role/staff creation, mandatory password change, allowed menus/direct URL denial, immediate API revocation, client action capture guard, loginless profile, stale draft and zero-page grants.
- API form fixtures: **9 passed** (including server field validation beside its input); working-tree UI regression selection **65 passed**. Publication snapshot selection excluding unrelated Doctor changes: **38 passed** (`/tmp/access-publication-browser.log`), including corrected service/authorized-branch read expectation.
- Frontend production build/typecheck and **8 unit tests** passed in that publication snapshot. Existing bundle-size/dynamic-import warnings remain.
- Existing Patients real Sanctum/browser regression: **15 passed** (`/tmp/access-patients-final.log`); denial clears the entire component and its clinical content. This existing suite includes explicit HTTP fault injection alongside real endpoints.
- Pint check, Composer strict validation and platform requirements passed. OpenAPI JSON and all **789** local references resolve.

The first full run reported 11 failures; migration test rollback depths were updated to include the new migration while retaining their assertions. Deactivation now explicitly expects revoked-session 401. The final full run above passed without disabling tests or relaxing scope enforcement.

## PR #23 review fixes

The membership `employee_version` is the authoritative opaque profile ETag for linked accounts. Legacy active/role/branch writes advance it inside the same transaction; loginless profiles retain their own version. 000013 initially seeds membership versions from profiles. Additive 000014 then invalidates all pre-fix employee ETags before the upgrade completes, including on installations that already applied 000013. Linked tokens advance beyond both membership/profile versions; loginless profile tokens advance too. Locks and the version comparison prevent a stale form (including concurrent requests) from restoring revoked access. The disabled-access profile path uses the same branch-delegation guard as legacy assignment; a denial rolls back profile, membership and session changes.

`branch_id` is profile metadata. `branch_ids` is the independent access assignment: required on create, optional on PATCH. Omission preserves every current access branch. A changed list still requires `employees.assign_branches`; ordinary profile edits do not. The form exposes explicit access-branch checkboxes only editable with that authority.

The existing 000012 migration is unchanged. Additive `2026_10_21_000012_prepare_access_role_names` sorts before it for upgrades from main and also runs as a pending migration on already upgraded installations. It locks role writes and keeps the smallest UUID's original name in each clinic/normalized-name collision group. Other colliding names use the whitespace-normalized original plus ` [role <full UUID>]`; if occupied, use ` [role <UUID> #1]`, then #2, etc. The base is truncated to keep 255 characters. Blank display names use `Rol` as the base. Rows are processed by tenant/UUID order. No roles are merged or deleted; IDs, codes, protection, permissions and memberships are unchanged. Owner-only FORCE-RLS `role_name_upgrades` records old/new names. A temporary unique index prevents new collisions between migration transactions; 000013 removes that duplicate index once the original permanent unique index exists. Rollback with rename history or editor data refuses and requires a forward migration.

Regression evidence before fixes at 6e4c823: `/tmp/review-fixes-red-backend.log` (four failures), `/tmp/review-fixes-red-parallel.log` (stale parallel request incorrectly succeeded), `/tmp/review-fixes-red-frontend.log` (two failures). The new tests also exercise permitted same-branch disabling, fresh-profile saves, authorized branch reassignment and a collision-free repeated migration. Existing revoked-session 401 assertions remain unchanged.

### Upgrade ETag invalidation (remaining P2)

000012 legacy membership changes left profile versions unchanged, so preserving those ETags across deployment could accept an old access=true form after revocation. Existing 000012/000013 migrations remain unchanged. Additive `2026_10_22_000014_invalidate_pre_integrity_employee_etags` locks membership/profile writes, advances each membership beyond the maximum of its current membership/profile versions, and synchronizes linked profiles; loginless profiles advance independently. It changes only concurrency tokens, never access flags, identities, roles, branch assignments or profile data. Clients must reload old forms after deployment; fresh authorized edits work normally.

Normal repeated `migrate` does not advance tokens again. Down intentionally never decrements a token (no schema objects are added); explicit rollback/reapply advances again rather than revalidating stale forms. The regression starts in real 000012, opens ETag=1 linked and legacy-only forms, revokes access through the legacy DELETE endpoint, runs the real pending migrations, and requires 409 with access still false. It also checks data/assignments, fresh edits and repeated migration behavior.
