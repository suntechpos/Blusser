# Xizmatlar — local frontend catalog

Base: updated `origin/main` (`2e1937a`, includes Reception PR #17).
The Services page uses the existing authenticated shell/sidebar, font and icons.
Only its route/banner integration is added to the shell. No backend, auth,
Patients API, Reception logic, Doctor referrals or historical amounts are changed.

## Features

- Reference catalog: derived total/active/inactive/category counters, category
  tabs, name/code + branch + status filters, page-size/pagination, filtered CSV.
  Initial 24 services, 22 active, five categories match the sample's content.
  Branches indicate availability, with one common price per service.
- Category add/edit, trimmed case-insensitive unique names, palette and preview,
  active switch; category card opens a compact management dialog with counts.
  Inactive categories are excluded from creation; an existing service may retain
  its original inactive category. Deactivation never mutates the category's services.
- Service create/edit/detail, generated unique immutable codes, multiple branches,
  nonnegative whole-so‘m prices, descriptions and status. Functional service type
  is independent of user-created category names.
- Consultation always needs duration; other types expose a timed-booking switch.
  Laboratory defaults to untimed and needs a positive result turnaround in hours
  or days. Turnaround and appointment duration are separate fields. Values that
  no longer apply are sanitized when saving, including on type changes.
- Deactivation requires confirmation; reactivation works. No deletion action.
- Shared WorkspaceDialog is reused for native focus containment, Escape, focus
  return and dirty-form discard confirmation. Its only shared change is an optional
  header icon, absent for existing consumers; Services styling stays under sv-*.
- Read-only details include all service fields. Empty catalog and unmatched search
  have different messages. Many category tabs/table scroll inside their containers.

## Boundaries

The typed catalog in `src/mocks/servicesCatalog.ts` is memory-only, instantiated
on mount (with optional initial catalog injection for reuse/empty-state tests).
It resets on remount/reload, does not call or write any API, does not update other
modules' mock data, and is explicitly marked as sample data. No live clinic prices,
booking or payment integration is claimed. CSV exports this local filtered catalog
across all its pages; quoted cells/BOM and spreadsheet-formula neutralization are used.

Reference dimensions: 1536×1024 for all three images; mobile check 390×844.
Screenshots under `/tmp/blusser-services-screenshots` are local-only, not committed.
The service form deliberately adds the required functional type and conditional
fields to the supplied reference. Final deliverable snapshot checks (excluding unrelated local referrals work):

- CI=1, one worker, installed headless Chromium 154: **47/47 browser tests passed**.
  Seven Services cases plus 40 existing Reception/Patients fixture, employees,
  departments/rooms and Doctor workspace/workflow regressions.
- `npm test`: **4/4 test files passed** (including category rules, functional service
  type, duration/turnaround separation, immutable unique codes and CSV safety).
- `npm run build`: passed, including `vue-tsc -b` type checking.
- Screenshots visually compared at the reference viewport; mobile bounds, focus,
  Escape/discard, filters/pages, status confirmation, inactive category history,
  empty catalog, many tabs, create/edit/details and CSV exercised in the browser.
- Patients API contracts and login/logout tested with existing network fixtures;
  no real database test was executed. No live backend validation is claimed.
- Eight unrelated local Doctor referrals files remain byte-for-byte unchanged.

The installed browser was used here; GitHub CI uses its pinned Playwright browser.
No screenshot is included in Git. PR/CI creation status must be confirmed separately
through the available GitHub connection.
