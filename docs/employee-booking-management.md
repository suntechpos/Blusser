# Employee-to-booking management: time-boxed delivery

## Completed

Existing employee list/create/update/account/role/branch/page-grant flows already use
`employees?format=profile` and the existing access editor APIs. They were retained,
not replaced. The old blanket “all employees are demo” footer was inaccurate and
is corrected; general employee schedules/exceptions remain explicitly unconnected.

On **Xodimlar → employee profile**, an authorized administrator can select an
actually assigned branch and open **Shifokor profili / ish jadvali**. The existing
configuration component is reused here, never in Registratura. It supports real
specialty/service creation, membership-linked doctor profile creation, service
assignment, room creation and doctor schedule creation through existing APIs.
No schema, endpoint, permission code, role or demo import was added.

When opened for an employee, the account and doctor choices are restricted to that
employee membership. Existing active profiles are preselected and duplicate-create
is disabled. Other employees' profiles and access grants are not rewritten.
The entry requires entire-clinic read/create authority for the configuration APIs;
backend governance independently authorizes every write.

A login account and branch assignment must exist. The account must have completed
its temporary-password change before the scheduling backend accepts a doctor
profile; this existing security rule is preserved. Inactive profile editing and
full doctor assignment management are not completed in this delivery.

## NOT COMPLETED within the 10-minute budget

- Full Services page conversion: its demo list, edit/activation UI, real category,
  price and duration CRUD are **not connected** by this change. The management
  dialog only creates the existing booking service contract (code/name/active).
- Full Departments/Rooms page conversion and general employee work schedule /
  exception integration remain **not connected**. The minimal doctor booking
  room/schedule workflow is available through the employee profile instead.
- Full doctor-profile edit/activation UI remains incomplete. Do not interpret a
  position label or page grant as an automatically created doctor profile.

These gaps are not reported as ready. No production/local user database was
modified or verified. No fake configuration is created automatically.

## Real verification

`employee-booking-real.spec.ts` uses real Sanctum sessions and PostgreSQL
`blusser_test` without API interception: it opens a real employee, creates a
specialty/service/doctor/room/schedule, reopens the existing profile and verifies
no duplicate creation, then books the existing real patient on 2030-01-09 and
verifies the appointment after refresh. Existing real new-patient booking tests
run alongside it. The fixture setup refuses non-test databases.

TypeScript and production build pass. Real browser scenarios: 4 passed.
Targeted appointment/scheduling backend regressions: 39 passed / 583 assertions.
Additional employee/access and reception regression results are reported in the PR.

## Update

```sh
git fetch origin
git switch feat/employee-booking-management
git pull --ff-only
cd frontend
npm ci
npm run build
```

No migration or seed is needed for this frontend change. Existing backend APIs
must already be deployed. If configuration is missing, enter real records using
the authorized employee management action; do not import demo catalogs.

Final targeted checks: employee/access integration **8 passed / 199 assertions**;
reception browser regressions **16 passed**. Together with the above real browser
and backend suites, no skipped/relaxed tests were introduced. If the prior
reception backend is not deployed yet, deploy its existing code/migrations first:
`docker compose build backend`, `docker compose run --rm migrate`,
`docker compose up -d backend`. This delivery adds no new migration.
