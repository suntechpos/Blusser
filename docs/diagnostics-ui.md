# UZI va Diagnostika — local UI workflows

Based on `origin/main` at `cad4198a226cc92725cccdf9db030ffad30cdb1b` (includes reception and services PRs). This feature does not add a backend, API endpoints, clinical authorization contract, database migrations or Doctor Workspace integration.

## Behavior

- One typed in-memory store links patient, referral, exam, result, pending draft and immutable published versions. Internal referrals for the sample clinic populate the queue; external and foreign-clinic fixtures are excluded.
- Date means **referral creation date**, not scheduled examination date. Unscheduled examinations show “Belgilanmagan”. Counts, status tabs, search, test/doctor/branch filters and pagination use the same store. Filters survive list/examination navigation.
- Viewing details does not start an examination. Waiting → start records the session actor and a single start timestamp; in-progress → continue restores the saved partial draft and attachments.
- Draft save accepts incomplete text. Final confirmation requires both text fields and rejects unfilled template placeholders. Rich text is restricted to basic formatting; pasted text cannot execute HTML. A confirmation publishes exactly one frozen version and switches to read-only mode.
- Four templates contain structures to fill, never automatic normal findings or diagnoses. Replacing existing text requires confirmation; cancellation and application preserve attachments.
- Local files: multiple JPG/PNG/PDF, maximum 20 MiB each, extension/MIME/header checks, explicit errors, thumbnails, draft removal and original-byte downloads. Files remain local; “Tanlangan (lokal)” does not imply upload. Object URLs and PDF render tasks are disposed on unmount.
- Reuses Doctor `DocumentViewer`, PDF.js, `WorkspaceDialog`, `downloadBlob` and `printBlob`. Optional title/context, file-navigation slot and `containImage` preserve existing Doctor defaults. Image fit can contain both dimensions in diagnostics. File navigation and actual PDF page navigation are separate; corrupt PDFs show an error.
- Amendments require a reason and create/resume one pending next-version draft, copying text/files. The queue remains completed and the previous published version remains current until confirmation. History preserves old author/time/reason/content/attachments and does not replace the current result when viewed.
- Result PDF/print includes only the selected patient/result, identifiers, version, author, time, full text, attachment names and demo labeling. Old versions are explicitly marked “TARIXIY VERSIYA”. Multi-page raster PDFs preserve Uzbek glyphs without a font/network dependency; PDF text is not selectable and attachments are downloaded separately, not embedded in the report.
- Unsaved edits warn on return to queue, sidebar/logout navigation, clinic changes, hash navigation and browser reload/close. Native browser unload dialogs remain browser-controlled. No authentication code is changed.

## Scope and limitations

This follows the existing mounted-module mock approach: state and Blob bytes live in memory, survive internal list/form navigation, and reset when the module is unmounted/reloaded or the clinic/session changes. Nothing is persisted to localStorage or sent to a clinical system. Initial completed records and specialist assignments are explicitly sample fixtures. Newly started/published records use the authenticated session identity; the global header is unchanged.

`SampleCapabilities` is separate demo configuration, not a clinical role mapping or backend protection. Domain mutations check the applicable sample flag; no permission is inferred from Patients grants or role names. Production authorization, persistence, concurrency and audit enforcement require a future backend contract. Existing platform/temporary-session gates remain in the application shell.

Unrelated local Doctor referrals changes, documentation and three screenshots were preserved byte-for-byte and excluded from this feature. Diagnostic screenshots are local QA artifacts only and are not committed.

## Validation (2026-10-20)

Tests ran against a publication snapshot consisting of committed main plus only this feature's files, without the unrelated working-tree changes. The snapshot used a fresh Vite server on port 5189, `CI=1`, one worker, and `/usr/bin/chromium` 154. This is not a claim of running the pinned CI Chromium build or a hosted GitHub workflow.

- `npm test`: passed all five test files; diagnostic model tests cover filtering, idempotent start, partial save, atomic/duplicate confirmation, frozen history, single amendment draft, placeholders and denied sample capabilities.
- `npm run build`: passed (includes `vue-tsc -b`). Vite reports bundle-size and mixed static/dynamic PDF import warnings.
- Browser regression run: **54 passed** — diagnostics (7), Doctor workspace/workflows (16), reception (9), Patients network-contract regression (1), services (7), departments/rooms (6), employees (8). Patients real-backend/DB tests were not run; its regression uses network fixtures.
- Diagnostic export test additionally verifies captured complete Uzbek content and selected patient/version metadata, parses/renders a generated multi-page PDF, checks rendered pixels, and distinguishes file navigation from PDF pagination. Print verification observes the print call on the generated popup and rendered page images; it does not automate an OS printer dialog.
- After the optional diagnostic image-containment variant, the affected diagnostics and Doctor suites were rerun: **23 passed**.
- Visual inspection: supplied 1536×1024 layouts compared with local list, editor, referral, template, confirmation, completed, amendment, history and viewer screenshots. At 1280×900 the page/footer stay within the viewport; queue tables scroll locally when needed. QA attachments are labeled synthetic images, not copied clinical images.

Reproduction from `frontend/`:

```sh
npm test
npm run build
PW_CHROMIUM_PATH=/usr/bin/chromium npx playwright test diagnostics.spec.ts doctor-workspace.spec.ts doctor-workflows.spec.ts reception.spec.ts reception-patients-regression.spec.ts services-catalog.spec.ts departments-rooms.spec.ts employees.spec.ts
```

Local diagnostic screenshots and a rendered historical export: `/tmp/blusser-diagnostics-screenshots/`. Publication snapshot: `/tmp/blusser-diagnostics-publication/`. Neither directory is a committed artifact.
