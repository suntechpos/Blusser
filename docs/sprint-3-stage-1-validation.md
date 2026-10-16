# Sprint 3 stage 1 — validation

Branch: feature/registratura-api, based on main 83f5d0e (includes merged PR #4).

## Delivered

- Declarative OperationsContract: 35 proposed permissions/scopes and appointment,
  visit, ticket transition graph. Not connected to authorization or database seeds.
- Separate planned OpenAPI 3.1 contract: 53 operations, request/response schemas,
  cookie/CSRF, idempotency, permission metadata and machine-readable state graph.
- Domain, security-schema, CEO-backfill, concurrency and staged acceptance design.
- Contract tests; Docker build copies both deployed and planned specifications.
- Architecture/database/implementation-plan links to stage-1 decisions.

No migrations, operational controllers/routes, permission grants, frontend source
or existing security mechanisms changed. Stage 2 is not implemented.

## Executed checks

- Full backend suite: **75 passed, 6823 assertions**, 70.16 seconds.
- Final contract-only rerun after specification refactoring: **3 passed,
  4880 assertions**. These are included in the full-suite count, not extra tests.
- Laravel Pint --test: **59 files passed**.
- composer validate --strict and composer check-platform-reqs: passed, PHP 8.4.26.
- Frontend npm run build (vue-tsc -b && vite build): passed, 1805 modules.
- git diff --check: passed.

Backend checks used the existing Laravel 13 Docker image with current app/tests/docs
mounted read-only. A fresh image build was not run. Local container OS user root was
needed to read workspace mounts; this does not change the API configuration or DB
role. Existing security tests explicitly assert blusser_app runtime identity,
non-owner/NOBYPASSRLS privileges and denied SET ROLE. Test-only migration credentials
were provided by the existing Compose test service; tests operated on blusser_test.
No development/production schema or records were migrated/refreshed/truncated.

To reproduce with a fresh image and configured local secrets:

```sh
docker compose build backend
docker compose run --rm test sh -c 'php artisan test --no-ansi && vendor/bin/pint --test && composer validate --strict && composer check-platform-reqs'
cd frontend
npm ci
npm run build
```

Use the existing documented local environment setup for initial DB bootstrap and
migrations. Never point the test service at development/production. No secrets are
included here. CI was not triggered by this local stage-1 validation.

## Limits

These checks preserve Sprint 1–2 behavior and validate planned contracts. They do
NOT demonstrate patient isolation, booking concurrency or queue correctness:
those APIs/tables do not exist yet. Actual RLS/FK/parallel-command and migration
backfill tests are mandatory in the subsequent approved stages. Frontend remains
demo; clinic selection/first-password/capability integration is deferred to stage 6.
