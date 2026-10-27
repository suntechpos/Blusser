# Doctor queue skip, cancellation and optional rebooking

Extends the existing PR #98 doctor cabinet modals. No clinical-record, employee, cashier or reception UI changes.

## API

- `POST /api/v1/clinic/queue-tickets/{ticket}/skip`
- `POST /api/v1/clinic/queue-tickets/{ticket}/cancel`

Both require a UUID `Idempotency-Key`, the current `expected_version`, a nonblank `reason_code` (up to 64 characters), and optionally `note` (up to 2000 characters). `rebook` is optional and accepts only `room_id`, offset-aware `starts_at` and `ends_at`.

```json
{
  "expected_version": 1,
  "reason_code": "patient_request",
  "note": "Bemor boshqa vaqtni so‘radi.",
  "rebook": {
    "room_id": "<real-room-uuid>",
    "starts_at": "2030-01-07T09:00:00+05:00",
    "ends_at": "2030-01-07T09:30:00+05:00"
  }
}
```

The appointment's patient, doctor, service and branch are derived from the authorized ticket, never supplied by the client. The new appointment, reservation, old queue/visit/linked-appointment transition, reason/note, events and idempotency entry commit in one transaction. Invalid or occupied slots roll back all changes, including release of an old reservation during cancellation.

The existing state graph remains: skip accepts waiting/called; cancellation accepts waiting/called/skipped. In-progress or completed consultations cannot use these actions.

`GET /api/v1/clinic/queue-tickets/{ticket}/rebooking-options?action=skip|cancel&local_date=YYYY-MM-DD` returns `{local_date, timezone, slots:[{room_id, starts_at, ends_at}]}`. Slots use the branch timezone, real active schedules, service duration, breaks, effective dates, exception replacements/closures and reservations. The read does not reserve anything: the POST rechecks under the existing actor/tenant/room/attendance locks and PostgreSQL interval exclusions. Occupied slots are omitted; an empty list is displayed honestly.

The response retains the existing attendance projection and adds `ticket.reason_code`, `ticket.note`, `ticket.rebooked_appointment_id`, and `rebooked_appointment` (null without a visible replacement). Refreshing a ticket returns persisted metadata.

## Permissions, versions and retries

The existing `queues.skip`/`queues.cancel`, `queues.view`, `visits.view` and tenant/branch/own checks remain. Linked appointments additionally require their existing view/cancel permissions. Rebooking requires `appointments.create`, `appointments.view`, `patients.view` and live doctor/service/patient/branch eligibility. Own doctors receive only the scoped ticket's slots, without broad catalog reads; staff retain the existing catalog prerequisites. No roles or grants are changed automatically.

Permission revocation is checked on replay, including visibility of the replacement appointment. The same successful key/body cannot create another booking; a changed body with that key conflicts. Competing new keys are serialized and checked against the locked ticket version. The frontend retains the existing request-key mechanism, prevents double submission and ignores obsolete availability responses.

## Migration and history

`2026_10_27_000002_add_queue_dispositions` is additive and forward-only. Existing rows receive null metadata and retain their state and links. The active-attendance unique index keeps its existing behavior except for a skipped visit explicitly superseded by a new appointment. That patient can check in for the new appointment while the old skipped history remains. Superseded tickets cannot requeue or create a second replacement; a later cancellation preserves the existing link. Non-rebooked skipped tickets remain requeueable under the existing rules. Deferred integrity checks keep queue and visit replacement links consistent.

## Local update and verification

With the project's existing `.env` and Docker setup:

```sh
git fetch origin
git switch feat/doctor-queue-disposition
git pull --ff-only origin feat/doctor-queue-disposition
docker compose build backend
docker compose run --rm migrate
docker compose up -d backend
cd frontend
npm ci
npm run typecheck
npm run build
```

Backend tests must use a **fresh isolated `blusser_test`** database, never a clinic database. Existing forward-only migrations intentionally reject rollback on a previously migrated test database.

```sh
docker compose run --rm test php artisan test --filter=QueueDispositionTest
docker compose run --rm test php artisan test --filter='QueuesApiTest|DoctorWorkspaceFeedTest|ServiceCatalogTest|OpenApiContractTest'
cd frontend
PW_CHROMIUM_PATH=/usr/bin/chromium npx playwright test tests/browser/doctor-queue-actions.spec.ts tests/browser/doctor-workspace.spec.ts
```

Omit `PW_CHROMIUM_PATH` when using Playwright's installed Chromium. New browser regressions mock the transport; backend regressions exercise real PostgreSQL runtime roles and real Sanctum HTTP requests. See the PR validation report for actual results and pre-existing failures; these commands are not a claim that the entire repository suite passes.

## Validation performed

Tested against PostgreSQL in `blusser_test` only. Results combine the initial runs and focused reruns after correcting test fixtures; successful tests were not repeatedly rerun.

- All 13 new `QueueDispositionTest` cases passed, including real Sanctum requests, competing PostgreSQL transactions, failed linked-cancellation rollback and skip → replacement appointment → check-in.
- Related existing backend coverage: 38 passed, 5 failed. `OpenApiContractTest` passed.
- All 6 new Playwright action cases passed. Existing doctor-workspace coverage: 24 passed, 7 failed.
- TypeScript, production build, and Pint for all 8 changed PHP files passed. Build reports the existing large-chunk/dynamic-import warnings.
- All 8 unrelated local files matched their pre-task hashes.

The following failures also reproduced on pristine base commit `09afd1d` with the same environment. Their assertions, application behavior and CI workflow were not weakened or disabled:

| Existing backend test | Confirmed failure on base |
| --- | --- |
| `own_doctor_feed_never_returns_another_doctors_patients` | Fixed 2026-10-26 feed date does not match tickets created on the current clinic date. |
| `real_clinical_notes_are_versioned_persistent_and_restricted_to_active_doctor_visit` | Fixture updates the ticket and visit in separate autocommits; the existing deferred consistency constraint rejects the first update. |
| `real_visit_diagnosis_is_versioned_audited_and_scoped_to_own_doctor` | Same fixture consistency failure. |
| `migration_roundtrip_and_protected_nonempty_rollback` | Existing forward-only migration refuses rollback (base fails at `000001_add_doctor_overdue_queue_feed`; this branch encounters the new forward-only migration first). |
| `parallel_walkin_starts_and_completes_preserve_exclusion_and_single_events` | Fixture calls two patients before starting its race; the existing single-called-patient guard rejects the second call. |

| Existing browser test | Confirmed failure on base |
| --- | --- |
| `UI: prior-day warning is compact and unfinished tickets live in one queue tab` | Fixture's default feed date and frozen browser date differ; waiting count is zero. |
| `signed-in doctor shows own real queue and not demo patient data` | Expects the obsolete demo complaint text. |
| `Task #02: refreshing the same doctor keeps the selected patient and clinical tab visible` | Expects a selected queue row while the current loading branch hides the queue list. |
| `Tasks #05/#06: complaints anamnesis and examination persist for own ongoing visit` | Expects the version text while the success notice occupies the existing conditional branch. |
| `Tasks #05/#06: denied consult permission keeps notes read-only` | Expects a disabled save button; the existing access boundary hides it. |
| `Task #06: diagnosis cannot be edited when consult grant is missing` | Unscoped permission-text locator matches both clinical-note and diagnosis hints. |
| `clinic date switching fetches new rows and does not resurrect demo queue` | Hardcoded fixture date assumption conflicts with the current date. |

The entire repository suite has not been claimed green. GitHub CI results are separate from these local checks.

## Own-scope appointment creation follow-up

Migration `2026_10_27_000003_allow_own_appointment_creation` adds `own` to the existing `appointments.create` catalog entry. It does not alter role grants or memberships. In Settings, an authorized administrator can now explicitly grant `Qabul yaratish → O‘ziga tegishli`, alongside the doctor's own appointment/patient/visit/queue views and skip/cancel permissions. Reload the permission catalog after applying the migration.

A role may combine a broader page view with narrower actions: `own ⊆ assigned_branches ⊆ entire_clinic`. Both the frontend editor and the current/legacy backend role writers enforce that dependency rule, including secondary parent views. Missing or narrower views still reject the save. Actor delegation and branch-delegation rules retain their previous separate checks.

Own creation uses the existing server-side doctor identity and live membership/doctor branch checks. It cannot create for a different doctor or a removed branch. Ticket rebooking works with own appointment creation/view and patient view without granting clinic/branch-wide appointment access or broad catalog lists. Generic appointment creation retains its existing catalog-read prerequisites. No booking, queue transaction, occupancy or idempotency guard was removed.

The CEO deployment backfill retains strict catalog validation and recognizes only the exact old and reviewed new appointment-create scope arrays. This keeps historical migrations and the existing backfill command compatible; it does not automatically grant the new own scope.

The same containment rule now resolves effective role-action/page-grant intersections. A role's own action with an assigned-branches page grant remains own; the reverse combination also narrows to own. Missing role actions remain absent. Existing page-view overrides and legacy profiles with no page-grant overrides retain their behavior. This fixes the disabled rebooking option for real employee profiles, beyond merely saving a valid role.

Follow-up local verification: 10 focused `DoctorQueueScopeTest` cases and 17 related existing backend cases passed (27 unique cases across targeted runs); 3 permission-editor browser cases plus the 6 queue-action browser cases passed. TypeScript, production build, and Pint for the 3 changed PHP files passed. The known unrelated failures documented above were not rerun or suppressed. No remote CI result is inferred from local checks.

Focused follow-up commands (backend requires the isolated fresh `blusser_test` described above):

```sh
docker compose run --rm test php artisan test --filter=DoctorQueueScopeTest
cd frontend
npx playwright test tests/browser/appointment-permission-scopes.spec.ts tests/browser/doctor-queue-actions.spec.ts
```

After deployment, reload Settings and explicitly choose the doctor's `Qabul yaratish → O‘ziga tegishli` grant. Own appointment/patient/visit/queue views and the required skip/cancel actions must also be granted; role grants are not provisioned automatically by this migration.
