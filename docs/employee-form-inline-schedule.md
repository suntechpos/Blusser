# Employee form permissions and inline work schedule

Only employee add/edit flow changes. Registratura, RBAC scopes, authentication,
role grants, cashier PIN verification and production database schema are unchanged.
The previous employee-profile doctor-management entry remains available.

## Permissions presentation

The existing server page catalog feeds four equal-width cards: 4 columns at
1440px+, 2 at intermediate widths, 1 on small screens. Each card has its own
bounded vertical scroll. Search, bulk selection, confirmed clear, checked state and
allowed-scope selection call the original EmployeeForm permission handlers; no
permission codes or automatic grants were introduced. Unknown catalog views stay
visible in the management card instead of creating a fifth column.

The permissions section spans the form width. Its next section is `Ish jadvali`.
Inputs retain the existing employee design and no new modal is used.

## Real doctor scheduling

- Explicitly enable schedule saving; ordinary profile edits do not rewrite rules.
- Choose multiple weekdays, a common interval or per-day intervals, branch and
  effective dates. Doctors additionally choose real specialty, room, duration
  and services from existing clinic APIs.
- Existing doctor profiles (including inactive profiles) are looked up by real
  membership, never recreated blindly. Inactive profiles require explicit
  activation through the existing backend management workflow.
- Existing rules load from `GET schedules` using doctor and branch filters.
  A rule/group can be edited, while `Yangi jadval` starts a separate rule set.
  Unselected existing days are not deleted. Multiple intervals for one weekday
  remain separate rather than being silently collapsed. Existing breaks survive
  edits even though break management is outside this task.
- Existing doctor branch assignments are preserved when adding the selected
  branch. Explicit service selection is persisted through the existing doctor
  update API. The backend independently checks membership branches, authority,
  active records, overlaps and schedule validity.

## Save and retry

Employee/profile/account saving uses the existing access editor first. The server
ID is recorded as soon as its response succeeds, before directory refresh. A
failed refresh disables another submission until that saved record is recovered.
A successful profile response updates the form's ID/version/membership metadata.
Subsequent attempts PATCH that employee instead of POSTing another one.

Doctor/profile and individual weekday writes are separate commits, not one atomic
operation. Completed weekday IDs/checkpoints are retained in the open form.
Retrying a failed day does not create the successful days again; later edits or
reverting a value still issue the necessary update. The established API intent
keys protect retries with uncertain request responses. No credentials or schedule
payloads are added to browser storage.

## Explicit backend limits

The backend currently provides **doctor scheduling**, not a general work-schedule
API for other positions. Non-doctor fields are shown disabled with that limitation;
no demo schedule is reported as persisted. Loginless employee profiles can still
be saved, but a doctor profile requires an active account/membership. A newly
created account must complete its temporary-password change before the existing
scheduling security rules allow a doctor profile. On that failure the employee
stays saved, the error is visible and subsequent attempts use the same ID.

Existing-account linking still requires account-owner consent. Combining that
pending link with schedule saving is explicitly blocked with instructions to
finish consent first, then edit the employee to save the schedule. Login, password,
PIN and access-branch payload contracts are retained.

## Validation and deployment

All database checks use guarded `blusser_test`. The real form scenario verifies
multi-day saves, deliberately failed later writes, retry after editing/reverting
an already-saved day, exact service updates, refresh, and existing-rule updates
without duplicate employee/doctor creation. A second real scenario verifies a new
loginless doctor profile remains saved when scheduling lacks an account and that
retry PATCHes it. Existing employee/PIN/branch tests remain unchanged.

The test clinic fixture now includes its required CEO governance relation and a
separate synthetic inline-form clinician/service. They are created only by the
existing test fixture command in `blusser_test`, never by production provisioning.
No new migration or backend endpoint is required. Deploy existing backend code and
build frontend from this branch:

```sh
git fetch origin
git switch feat/employee-form-inline-schedule
git pull --ff-only
cd frontend
npm ci
npm run build
```

Final local checks: **12 distinct browser scenarios passed** (7 existing employee
regressions, 2 layout/recovery cases, 2 real inline-form cases and the preserved
real employee-management/booking workflow); **21 backend tests / 421 assertions**;
**9 frontend unit suites**; TypeScript/production build; changed-fixture Pint.
Fixture preparation also passed its two guarded tests. Existing bundle warnings
remain. Remote GitHub CI is separate from these local results.

Changed files: EmployeeForm, EmployeeScheduleSection, PagePermissions and their
CSS; employeeSchedule service; XodimlarView form-save orchestration; the optional
post-commit callback in settings/store; the synthetic browser fixture, two form
browser specifications and this document. Eight unrelated local files were
verified unchanged by SHA-256.
