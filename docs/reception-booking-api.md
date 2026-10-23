# Registratura: real appointment booking

The existing BookingDialog and reception table now use Sanctum/CSRF and PostgreSQL-backed clinic APIs. Booking supports **existing patients only**; its new-patient tab is disabled. The separate registration/arrival demo has not been integrated and never supplies the real appointment table. Queue, payment, check-in, cancel and reschedule APIs are not called by this change.

## Read/write contracts

- `GET clinic/patients?q=...&page=...&per_page=8`: debounced patient name/number/phone/UUID search and pagination. The selected patient's UUID is submitted; an unlinked branch shows a warning, without linking it.
- `GET clinic/appointments/booking-options?branch_id=...`: minimal paginated read-only active doctor/specialty/service labels and effective branch timezone. Existing scheduling/booking scope helpers apply; no credentials or account identifiers are exposed. A null service duration is shown as schedule-dependent; slot start/end remain authoritative.
- `GET clinic/doctors/{doctor}/availability`: branch, service and one local day's UTC bounds; all server pages are loaded. Slot room/start/end are preserved exactly. Past slots are filtered. Availability is a hint; PostgreSQL exclusion/locking still decides booking conflicts.
- `POST clinic/appointments`: existing seven identifiers/timestamps plus optional nullable `note` (maximum 2000 characters), through existing idempotency handling. Only 201 emits success. 409 clears the slot and reloads availability. Other errors retain the form. Double submit is guarded.
- `GET clinic/appointments`: server date/branch/doctor/status/search and pagination. Names are resolved through scoped patient reads and booking options. Scheduled means **Yozilgan**. The right-hand schedule uses the same real appointments and availability. A successful booking selects its local date and the server page containing the new record.

Local-day bounds are calculated in the branch's IANA timezone, including 23/25-hour DST days and skipped dates; the browser timezone is not used. Selecting a distant future day makes a new one-day availability query, not a today-to-future range.

## Permissions

Booking requires `reception.view`, `appointments.view`, `appointments.create`, `patients.view`, `doctors.view`, `rooms.view`, `schedules.view`, and `services.view`. The existing backend requires `services.view` at `entire_clinic`; other scopes remain enforced by the established helpers/RLS. The booking form and existing-patient selector declare their actual permission explicitly rather than letting text such as “kelgan” be classified as check-in.

Existing roles, role templates, page access and employee assignments are **not** automatically changed. An authorized administrator must review the complete booking dependency list for an existing registrator role, including `rooms.view` and `schedules.view` when absent. The existing API uses these same view permissions; this change does not bypass them or open other pages automatically. The synthetic browser registrator is explicitly configured with the minimum required reads and appointment creation, without patient-create or check-in permissions. No global grants or new permission codes are introduced.

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

## Verification evidence

Local checks passed: 57 targeted PostgreSQL feature tests (appointments, scheduling, patients, initial-role non-mutation and OpenAPI), 35 Playwright scenarios including the real booking and real Patients/Sanctum flows, frontend unit tests, TypeScript and production build. Pint checked the backend successfully. Native browser tests verify 409 recovery, double-submit, server pagination, a newly booked row on a later page, future dates beyond 31 days, branch dependencies and mobile bounds. Existing bundle-size/dynamic-import warnings remain unchanged. These are local results; they are not a claim that remote GitHub CI has passed.


## Changed files

- Frontend: `views/RegistraturaView.vue`, `components/reception/BookingDialog.vue`, `ReceptionPatientSearch.vue`, `reception.css`, `services/receptionAccess.ts`, new `receptionBooking.ts` and `receptionTime.ts`.
- Backend: `Modules/Appointments/Http/AppointmentController.php`, `Modules/Appointments/Services/Appointments.php`, the UUID search clause in `Modules/Patients/Services/Patients.php`, `routes/api.php`, new `2026_10_23_000017_add_reception_booking_details.php` and `sql/reception_booking_options.sql`.
- Contracts/docs: `docs/openapi.yaml` and this document.
- Tests: `AppointmentsApiTest.php`, `BrowserFixtureTest.php`, `reception-booking.test.mjs`, `reception.spec.ts`, `reception-permissions.spec.ts`, new `reception-booking.fixture.ts` and `reception-real.spec.ts`.
