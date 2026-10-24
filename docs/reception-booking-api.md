# Registratura: real appointment booking

The existing BookingDialog and reception table now use Sanctum/CSRF and PostgreSQL-backed clinic APIs. Booking supports both new and existing patients through real APIs. The separate registration/arrival demo has not been integrated and never supplies the real appointment table. Queue, payment, check-in, cancel and reschedule APIs are not called by this change.

## Read/write contracts

- `GET clinic/patients?q=...&page=...&per_page=8`: debounced patient name/number/phone/UUID search and pagination. The selected patient's UUID is submitted; an unlinked branch shows a warning, without linking it.
- `GET clinic/appointments/booking-options?branch_id=...`: minimal paginated read-only active doctor/specialty/service labels and effective branch timezone. Existing scheduling/booking scope helpers apply; no credentials or account identifiers are exposed. A null service duration is shown as schedule-dependent; slot start/end remain authoritative.
- `GET clinic/doctors/{doctor}/availability`: branch, service and one local day's UTC bounds; all server pages are loaded. Slot room/start/end are preserved exactly. Past slots are filtered. Availability is a hint; PostgreSQL exclusion/locking still decides booking conflicts.
- `POST clinic/appointments`: existing seven identifiers/timestamps plus optional nullable `note` (maximum 2000 characters), through existing idempotency handling. Only 201 emits success. 409 clears the slot and reloads availability. Other errors retain the form. Double submit is guarded.
- `GET clinic/appointments`: server date/branch/doctor/status/search and pagination. Names are resolved through scoped patient reads and booking options. Scheduled means **Yozilgan**. The right-hand schedule uses the same real appointments and availability. A successful booking selects its local date and the server page containing the new record.

Local-day bounds are calculated in the branch's IANA timezone, including 23/25-hour DST days and skipped dates; the browser timezone is not used. Selecting a distant future day makes a new one-day availability query, not a today-to-future range.

## Permissions

Booking requires `reception.view`, `appointments.view`, `appointments.create`, `patients.view`, `doctors.view`, `rooms.view`, `schedules.view`, and `services.view`. The existing backend requires `services.view` at `entire_clinic`; other scopes remain enforced by the established helpers/RLS. The booking form and existing-patient selector declare their actual permission explicitly rather than letting text such as “kelgan” be classified as check-in.

Existing roles, role templates, page access and employee assignments are **not** automatically changed. An authorized administrator must review the complete booking dependency list for an existing registrator role, including `rooms.view` and `schedules.view` when absent. The existing API uses these same view permissions; this change does not bypass them or open other pages automatically. The synthetic browser registrator is explicitly configured with the minimum required reads and appointment creation, with patient-create and without check-in permissions. No global grants or new permission codes are introduced.

## Local update and verification

```sh
git fetch origin
git switch feat/reception-booking-api
git pull --ff-only
docker compose build backend
docker compose run --rm migrate
docker compose up -d backend
cd frontend
npm ci
npm run typecheck
npm test
npm run build
```

The additive 000017 migration preserves existing appointments, reservation IDs, locks and idempotency. It adds nullable notes and the read projection. Rollback refuses to discard populated clinical notes. Earlier migrations are unchanged.

All integration tests must target **blusser_test**, never the clinic database. FoundationTestCase verifies both configured and actual database names before cleanup. With disposable test database credentials configured:

```sh
docker compose run --rm test php artisan test --filter='AppointmentsApiTest|SchedulingApiTest|OpenApiContractTest|DefaultClinicRoles'
docker compose run --rm test vendor/bin/pint --test
# Existing CI fixture command also creates a separate synthetic booking clinic.
docker compose run --rm test php artisan test --filter=BrowserFixtureTest
# Serve a backend explicitly configured with DB_DATABASE=blusser_test at :8000.
cd frontend
npx playwright test tests/browser/reception-real.spec.ts tests/browser/reception.spec.ts tests/browser/reception-permissions.spec.ts tests/browser/reception-patients-regression.spec.ts
```

`reception-real.spec.ts` does not intercept APIs: it logs in as a minimally authorized synthetic registrator, saves October 28 at 10:30, verifies the persisted record after refresh, and confirms no patient-create request. Other reception tests use explicit API-contract fixtures for deterministic conflicts, network errors, empty states, dependencies and layout; they are not evidence of a live database write.

## Earlier booking baseline verification

Before the registration extension, local checks passed: 57 targeted PostgreSQL feature tests (appointments, scheduling, patients, initial-role non-mutation and OpenAPI), 35 Playwright scenarios including the real booking and real Patients/Sanctum flows, frontend unit tests, TypeScript and production build. Pint checked the backend successfully. Native browser tests verify 409 recovery, double-submit, server pagination, a newly booked row on a later page, future dates beyond 31 days, branch dependencies and mobile bounds. Existing bundle-size/dynamic-import warnings remain unchanged. These are local results; they are not a claim that remote GitHub CI has passed.


## Changed files

- Frontend: `views/RegistraturaView.vue`, `components/reception/BookingDialog.vue`, `ReceptionPatientSearch.vue`, `reception.css`, `services/receptionAccess.ts`, new `receptionBooking.ts` and `receptionTime.ts`.
- Backend: `Modules/Appointments/Http/AppointmentController.php`, `Modules/Appointments/Services/Appointments.php`, the UUID search clause in `Modules/Patients/Services/Patients.php`, `routes/api.php`, new `2026_10_23_000017_add_reception_booking_details.php` and `sql/reception_booking_options.sql`.
- Contracts/docs: `docs/openapi.yaml` and this document.
- Tests: `AppointmentsApiTest.php`, `BrowserFixtureTest.php`, `reception-booking.test.mjs`, `reception.spec.ts`, `reception-permissions.spec.ts`, new `reception-booking.fixture.ts` and `reception-real.spec.ts`.

## Patient registration and minimal configuration extension

Both booking tabs now use real records. `Yangi bemor` is the default only with
`patients.create`; existing-patient booking remains independent of that grant.
Surname, given name and optional patronymic are joined into `PatientCreate.name`.
Phone and gender are nullable. The patient POST is a separate committed operation,
not an atomic transaction with appointment creation. A saved UUID/number remains
in the modal after a booking error. The demographic payload is retained while a
patient request has an uncertain outcome, so its retry uses the existing intent
key; definitive patient validation errors unlock corrections. Closing the modal
leaves any already-created patient on the server.

Patient search previously replaced every exception with “Qidiruv amalga oshmadi”.
It now distinguishes HTTP permission/session/CSRF/validation errors, 5xx and
network failures. Successful zero-result searches alone show “Bemor topilmadi”.
No claim is made about the user's inaccessible localhost response or database.

Branch options come from the session's active authorized branches. Specialty,
doctor and service options come from active tenant-local booking configuration;
missing doctor profiles, active memberships, specialty or doctor-service links
can leave them empty. They are not sourced from the demo management pages.
Availability additionally requires a valid schedule, active available room, and
an unoccupied slot. None of those records is fabricated on an empty database.

Registratura no longer exposes `Qabulni sozlash` or opens
`BookingConfigurationDialog.vue`. No configuration rows or backend endpoints
were removed. Selects continue to depend only on real backend records, not on
other management pages being implemented.

Read the local clinic configuration with one authenticated, read-only command
(after updating this branch):

```sh
cd ~/projects/Blusser/backend
php artisan blusser:booking-check --actor=kamron.ceo --tenant=bb799c8d-0058-4eba-a474-83142e47438d
```

It prompts for the current password without echoing it, verifies clinic
membership, prints effective booking scopes, visible branches, active doctor/room
counts, schedule counts and eligible booking doctors/service counts. It neither
seeds records nor changes grants. Counts honor the actor's RLS, so a denied read
is reported separately rather than treated as an empty table. The user's
localhost/database was not accessible from the execution environment.

If configuration is missing, an authorized administrator can explicitly create it
using the existing authenticated Sanctum/CSRF clinic APIs below. Obtain actual
membership IDs from `GET employees?format=profile` and actual branch IDs from
`GET context`; never use a demo UUID. Inspect the corresponding GET list before
each POST and reuse an existing matching record (specialty/service code, doctor
membership, room branch+number, exact schedule). Use a stable Idempotency-Key for
retries; never recreate an existing doctor profile or replace its assignments.
No automatic development seed is run because the user's actual configuration
has not been established.

| Existing clinic API | Required payload / authority |
| --- | --- |
| `POST specialties` | `code`, `name`, `active`; entire-clinic doctors.view/create |
| `POST appointments/configuration-services` | `code`, `name`, `active`; entire-clinic services.view/create |
| `POST doctors` | actual `membership_id`, `specialty_id`, `branch_ids`, `service_ids`, `active`; entire-clinic doctors.view/create |
| `POST rooms` | actual `branch_id`, user-selected `number`, `service_type`, `active`, `availability=available`; entire-clinic rooms.view/create |
| `POST schedules` | actual `doctor_id`, `branch_id`, `room_id`, ISO weekday 1–7, `local_start`, `local_end`, `slot_minutes`, explicit `effective_from`/`effective_to`, `breaks`; entire-clinic schedules.view/create |

All writes use the existing audited governance commands and validations. Branches
and doctor accounts must be active and assigned beforehand. A schedule/room
conflict is rejected; slot validation and RLS are unchanged.

The only new API is GET/POST `clinic/appointments/configuration-services`: a
booking-only service list/create surface. It requires `services.view` at
`entire_clinic`, plus `services.create` at `entire_clinic` for POST. Existing
`scheduling_command` tenant/user locks, identity checks, payload validation,
auditing and request-digest replay protect the write. Migration 000018 extends
its existing idempotency ledger with a tenant-scoped service foreign key; it
preserves existing data and does not change any role, permission or assignment.
Rollback retains configured services. No full Services module is implemented.

Update on this extension branch with:

```sh
git fetch origin
git switch fix/reception-booking-without-setup
git pull --ff-only
docker compose build backend
docker compose run --rm migrate
docker compose up -d backend
cd frontend
npm ci
npm run build
```

Migrations 000017 and 000018 run through the existing migration credentials.
Do not run fixture/test commands against the user's database. Existing
registration/arrival and queue demos remain separate from this booking workflow.

Availability now includes optional `meta.unavailable_reason`: no schedule for the
requested date, an explicitly closed day, an unavailable scheduled room, an
inactive doctor/service configuration, or simply no free slots. These hints use
the same scoped reads and do not replace `booking_slot_valid()` at save time.

## Registration extension verification

- Full backend suite: **190 passed, 9298 assertions** in isolated `blusser_test`.
- After the final scoped availability diagnostics, the affected appointments,
  scheduling and OpenAPI suites were rerun: **39 passed, 4276 assertions**.
- Reception Playwright contract/permission/Patients regressions: **17 passed**.
- Separate live Sanctum/PostgreSQL browser scenarios: **3 passed** (existing
  patient, new patient + appointment, and real administrator configuration with
  preserved service/branch assignments, missing-schedule diagnosis and mobile bounds).
- Frontend unit suites: **9 passed**; TypeScript and production build passed.
- Pint: **120 files passed**; Composer strict validation and platform requirements
  passed. Existing PDF/dynamic-import and bundle-size warnings remain.
- The eight unrelated local files were verified unchanged by SHA-256.

Extension files: `BookingDialog.vue`, new `BookingConfigurationDialog.vue`,
`ReceptionPatientSearch.vue`, `reception.css`, `receptionBooking.ts`; scheduling
controller/service/availability, new `BookingService.php`, routes and migration
000018; OpenAPI/this document; appointment/fixture and reception browser tests.
No frontend dependency, role provisioning or unrelated module change is included.
These are local test results, not a remote GitHub Actions result.


## Urgent booking UI fix verification

The setup entry and its component import/mount were removed from BookingDialog;
its dependent branch/specialty/doctor/service selection and patient retry logic
were retained. In the isolated PostgreSQL fixture all four selects resolve real
IDs and both new/existing patient bookings persist after refresh. The user's
local database was not inspected; no claim is made that it contains the same
configuration. `blusser:booking-check` is covered by an authenticated integration
test asserting no patient/configuration/grant counts change and an unknown clinic
is refused. The backend configuration creation tests remain in place.

Current urgent-fix checks: **40 backend tests / 4282 assertions**, **16 related
browser regressions**, **3 real Sanctum/PostgreSQL browser tests**, TypeScript
(as part of the production build), build and changed-file Pint passed. No tests
were skipped or expectations relaxed. The eight unrelated files remain unchanged.
