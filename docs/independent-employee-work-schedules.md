# Independent employee working hours

`employee_work_calendars` and `employee_work_schedules` are separate from appointment `schedule_rules`. Every position can save working hours using its real employee profile ID and primary/assigned branch. No target login account, doctor profile, specialty, service or room is required. `EmployeeForm` specialty stays free text (for example `Hirurg`) and is saved as entered. Work-only forms do not read or write clinical catalog/doctor/schedule endpoints.

## API and authorization

- `GET /api/v1/clinic/employee-work-schedules/{employee}?branch_id={branch}` returns `{version,data}`. Rows contain real IDs, employee/branch IDs, weekday 1–7, start/end time and a persisted `receives_patients` intent flag.
- `PUT` on the same resource takes `{branch_id,days:[{weekday,start_time,end_time,receives_patients}],confirmed_removals:[weekday]}`, `If-Match` calendar version (`0` for a new calendar) and the existing `Idempotency-Key` header.
- Uses existing authenticated session tenant and governance actor/credential checks. Reads require employee visibility; writes require existing entire-clinic `employees.update` authority. Tenant/employee/branch foreign keys, FORCE RLS and no runtime DML grants enforce the boundary. Target login/membership is optional. The branch must be the profile's real primary branch or a linked membership branch; another tenant/branch cannot be injected.
- User → tenant locks serialize mutation with existing governance operations. Calendar versions prevent parallel stale updates; the existing intent ledger supports identical retries and rejects mismatched replays. Each employee/branch/weekday has one stable row ID. Only this branch's calendar is changed. Missing saved days require explicit confirmed removals and correct version; omitted days cannot disappear silently.

## Form and save flow

The existing schedule card is retained. Enable schedule editing, add days and choose times, then use the normal employee Save action (the card footer delegates to that same action). Employee/profile save happens first; its real ID/version is retained on later failure. Calendar PUT and GET verification happen next. A successful calendar is retained if the optional clinical phase fails; retry does not recreate the employee, unchanged calendar, doctor or previously saved appointment rules. Editing/removing a saved weekday requires confirmation. Async initial loading disables schedule editing until persisted rows are hydrated.

Doctors default to **Bemor qabul qiladi: off** on new forms. When enabled, individual days can receive patients or remain work-only; common-time mode has the same per-day control. Rooms/duration/mode are required only for actual receiving days. Turning off known weekly receiving rules requires confirmation and marks the appointment rows inactive, preserving IDs/history and booked appointments. Reenabling reuses those rows. Existing dated appointment exceptions remain separate explicit overrides and are not deleted.

The legacy doctor API now accepts explicit `specialty_id:null` and `service_ids:[]` without fabricating catalog entries. Existing real clinical assignments are preserved if free-text specialty does not map to a unique catalog entry. A new doctor's clinical configuration still uses a real membership and the existing doctor/room/schedule permissions; a work-only employee never needs that membership. Actual patient booking still requires a real assigned service, valid live configuration, room and slot; enabling an empty clinical configuration does not invent a bookable service. Optional clinical metadata can later be configured through the existing API.

`doctor_profiles.specialty_id` becomes nullable. `schedule_rules.active` defaults true, so old rows remain active. Availability/new booking ignore inactive weekly rules. Existing appointment integrity checks still validate stored booked intervals without invalidating appointments just because weekly reception was disabled. Private-room ownership, historical assignments, conflicts, tenant/branch safeguards and password-change requirements remain unchanged.

## Profile view

The read-only tab reads the general employee calendar for all jobs. It shows all seven weekdays and `Dam olish` on missing days. Receiving-day columns resolve real active appointment rules and room API data; work-only days show dashes. It contains no write controls. Clinical read errors do not hide working hours; they are reported separately. The profile branch selector includes the primary branch of loginless employees.

Legacy appointment rules are **not** copied into general working hours or presented as employee working hours. Existing appointments/rules remain intact; a legacy employee with no general calendar gets the honest empty state until ordinary working hours are saved explicitly.

## Local update and tests

```bash
cd ~/projects/Blusser
git fetch origin
git switch feat/independent-employee-work-schedules
git pull --ff-only
cd backend
php artisan migrate --database=migration --force
cd ../frontend
npm ci
npm run build
```

Migration connection uses the project's migration-owner credentials. Migration `2026_10_25_000020` preserves existing records and takes snapshots of changed function definitions for exact rollback. Rollback refuses once work calendars, nullable-specialty profiles or inactive appointment rows exist; use a reviewed forward migration. Existing applied migrations are unchanged. No user-local/production fake data is created and no GitHub workflow is changed.

All real tests use `blusser_test`; test fixtures contain a separate synthetic work clinic with genuinely empty specialties/services/rooms. Coverage includes Registrator/Hamshira/Hirurg without login, API→PostgreSQL→GET→profile→refresh→edit, partial failure/retry, explicit removal, independent concurrent work writes, nullable clinical metadata, mixed receiving/work-only days, weekly reception disable/re-enable without duplicates, and existing appointment/private-room/permission/PIN regressions. This environment cannot reach or alter the user's localhost database.

Verified locally: PostgreSQL backend suites — 33 passed (523 assertions); real API/browser acceptance plus profile regressions — 12 passed; related mocked employee/permission/PIN/room browser regressions — 25 passed; frontend unit tests — 49 passed, no skips; TypeScript and production build passed; Pint passed for all 8 changed PHP files. Build retains existing bundle-size/dynamic-import warnings. GitHub CI is separate and has not been run manually for this change.
