# PR #23: Backend Foundation follow-up

GitHub Actions logs/check metadata could not be read in this environment: both GraphQL and REST returned `Forbidden`. Therefore the exact failed remote step/run is **not verified** here. The failures below were reproduced locally from the committed feature branch, using `.github/workflows/backend.yml` as the command source.

## Confirmed local failures and fixes

1. `Test real PostgreSQL runtime role` / `OpenApiContractTest` failed with `Failed asserting that an array has the key 'Cache-Control'`. Sixteen access-editor success/conflict/precondition response objects in `docs/openapi.yaml` omitted the header. The real `NoStore` middleware already sends `Cache-Control: no-store, private`. The specification now documents it, retaining success ETags. The existing assertion remains mandatory and now identifies the endpoint/status in failure output.
2. `Validate style, lockfile and PHP extensions` / `vendor/bin/pint --test` failed on `AccessDemo.php` and `AccessEditor.php`. Applying the existing Pint rules fixes only PHP formatting; permission checks and application behavior are unchanged.

The earlier local validation had stale OpenAPI content inside its test container. Checking backend source hashes alone did not catch that mismatch. This follow-up rebuilds a clean image with the current backend **and both current OpenAPI files**; the running test container's document/test hashes were checked against the working copy.

No tests, authorization rules, security assertions or workflow steps were disabled or relaxed. No unrelated local Doctor files/screenshots are included.

## Local reproduction environment

- Clean `git archive HEAD` snapshot plus these fixes; no unrelated working-tree changes.
- The workflow's pinned PHP 8.4 Dockerfile and PostgreSQL 17 image; Node 24.
- A distinct Compose project, `pr23-foundation`, with generated ephemeral credentials and `blusser_test` checks.
- Local proxy requires the Dockerfile's existing `build_ca` secret and `COMPOSER_PREFER_INSTALL=source` build option. Neither dependency constraints nor lockfiles changed.
- Local Docker uses the disk-heavy VFS driver. The initial disk-backed attempts exhausted this sandbox's disk. Only this task's test containers/image/cache records were removed; the new disposable PostgreSQL data mount uses tmpfs. Subsequent commands run serially where they share the test DB. This storage adaptation does not alter database roles/RLS or test assertions.
- Playwright's browser download CDN returned `403 Domain forbidden`; local browser checks use the installed Chromium rather than claiming the CI-managed browser was installed.

Commands follow the workflow: build, `docker compose up -d --wait db`, `docker compose run --rm migrate`, `docker compose run --rm test`, Pint/Composer checks, `npm ci`, `npm test`, `npm run build`, synthetic fixtures, browser regression and HTTP health smoke. Local-only Compose project/storage options isolate data; they are not repository workflow changes.

Detailed before/after output is retained in `/tmp/pr23-*.log`. GitHub CI status must be read from PR #23 after push; local success does not establish a green remote run.

## Verified checks

- Before fix: current committed OpenAPI reproduced the missing `Cache-Control` failure in `OpenApiContractTest`; the existing Pint check independently rejected the two PHP files.
- After the specification fix: full real PostgreSQL/Sanctum suite **164 passed / 8,636 assertions**, including the complete OpenAPI/security contract tests (`/tmp/pr23-backend-final.log`).
- Final rebuilt image, including both formatting fixes: **11 access/fixture/contract tests passed / 6,890 assertions** (`/tmp/pr23-targeted-verified.log`); **Pint passed all 105 files**, Composer strict validation and platform requirements passed (`/tmp/pr23-*-verified.log`).
- Clean Node 24 install: `npm ci`, **47 unit tests**, typecheck and production build passed. Existing bundle-size/dynamic-import warnings remain.
- All **725 published responses** now document Cache-Control. The global security assertion is retained; runtime authorization/no-store behavior was not changed.
- Workflow browser command: **88 passed**, with its existing 4 opt-in access cases gated by their environment flag (`/tmp/pr23-browser.log`). Those same **4 cases then passed** separately with `ACCESS_REAL_API=1` against freshly prepared real test accounts (`/tmp/pr23-access-browser-final.log`); all **92 browser scenarios executed successfully** across the two phases. No skip/ignore configuration was added.
- HTTP smoke passed with `{"status":"ok"}` (`/tmp/pr23-http-smoke-final.log`).
