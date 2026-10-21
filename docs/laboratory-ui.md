# Laboratory local UI

Base: `origin/main` / `544abf1b4fd192c13a97b7a856671a0355d24b21` (includes Diagnostics PR #19).

## Workflows

- One typed local source links patients, internal referrals, tests, multiple specimens, drafts and immutable versions. Date/search/test/doctor/branch/status filters, cards and pagination share that source. Filters survive internal list/form navigation; external and foreign-clinic fixtures are excluded.
- Details are read-only. Collection validates type/time, assigns a unique specimen ID and records the session actor. Separate specimen types cannot be forced into one specimen. The label prints the exact ID as standard Code 39, patient/type/time and a demo marker. Duplicate collection is guarded.
- Start requires the necessary specimens and preserves the original start timestamp on resume. Partial drafts retain values and local attachments. Test-specific numeric, qualitative and text indicators are supported; required/format errors block submission, out-of-range values alone do not.
- Reference rules express age, sex and method. Missing/ambiguous matches show “Belgilanmagan”; qualitative values never get numeric bounds. All ranges/values are synthetic design examples, not automatic diagnoses.
- Submission enters read-only review. Return requires a reason, saves actor/time and preserves values. Confirmation atomically freezes a version with separate collector/entrant/submitter/confirmer fields. Failed/repeated confirmation cannot complete or duplicate a version.
- Amendment clones values, units, ranges, note and files to one pending next version. It must pass review again. The previous report stays current and the queue remains completed until confirmation. History viewing does not replace the current version; old exports are marked historical.
- Reuses Doctor dialogs/download/print and Diagnostics attachment grid/viewer/PDF.js. Optional attachment limits preserve existing consumers' defaults. Laboratory drafts allow 10 files/50 MiB total, each JPG/PNG/PDF up to 20 MiB. Object URLs/render tasks are disposed by the shared components.
- The existing multi-page raster PDF renderer is reused. Export includes only the selected patient's results, units/ranges/flags, specimen, staff/time/version and demo labeling, without the application shell. PDF text is not selectable; original attachments are downloaded separately.
- Unsaved result edits warn on list/sidebar/logout/clinic/hash/unload navigation. Existing modal focus and cancellation behavior is reused. Global shell/logo/header/authentication/clinical APIs are unchanged.

## Limitations

This is an in-memory mounted-module demonstration: values, history and Blob bytes reset on module unmount/reload or session/clinic change. Nothing is persisted to localStorage or sent to a clinical API. No backend, migration or Doctor integration is added.

`LabCapabilities` uses the isolated sample-capability pattern from Diagnostics, not a clinical role mapping or backend protection. Production permissions, persistence, concurrency and audit enforcement require a future API contract. Initial staff are explicit fixtures; actual new actions use the current session identity and do not hardcode the global header.

Eight unrelated local Doctor referrals files (including docs/screenshots) were preserved byte-for-byte and excluded. Laboratory screenshots remain local QA artifacts.

## Verification

- `npm test`: six files passed, including eight Laboratory model/barcode cases.
- `npm run build`: passed, including TypeScript; Vite bundle-size/mixed PDF import warnings remain.
- Browser tests run on a publication snapshot: committed main plus only this feature, without unrelated working changes; `CI=1`, a fresh server, one worker and system Chromium 154. This is not a claim of running the pinned CI browser or hosted GitHub workflow.
- Final resumed browser run on 2026-10-20: **62 passed, 0 failed, 0 skipped, 0 flaky** (2.3 minutes). Its log, JSON report and exit code are saved in `/tmp/laboratory-final-tests.log`, `.json` and `.exit`. An earlier interrupted run is not counted as passed.
- Cases cover queue/actions, filters/details, collection/label, partial drafts/resume, numeric/text/qualitative/range behavior, submit/return/confirm, amendment/history, bounded files/viewer, PDF/print and unsaved navigation. Regressions cover Diagnostics, Doctor, reception, services, employees, departments/rooms and Patients network contracts.
- Real backend/DB tests and native OS printer dialogs were not run. Print checks observe rendered popup pages and stub the print call; closing the popup preserves application behavior.
- Visual QA: supplied content layouts compared at 1536×1024 and 1280×900. Existing shell retained despite differing shell references; narrow tables scroll inside their cards. Screenshots, label and PDF renders: `/tmp/blusser-laboratory-screenshots/` (not committed).

Reproduction from `frontend/`:

```sh
npm test
npm run build
PW_CHROMIUM_PATH=/usr/bin/chromium npx playwright test laboratory.spec.ts diagnostics.spec.ts doctor-workspace.spec.ts doctor-workflows.spec.ts reception.spec.ts reception-patients-regression.spec.ts services-catalog.spec.ts departments-rooms.spec.ts employees.spec.ts
```
