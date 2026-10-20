# Registratura: frontend workflows

Based on `origin/main` (`cadfcc2`), independently of the unmerged legacy UI polish
branch. No backend, auth, API contract, migration, Docker, or Patients production
code changes. Existing unrelated local Doctor referrals work is excluded.

## Implemented

- Latest full-page reference: two top actions; six desktop statistic cards;
  compact search/filters and eight-row pagination; status badges and appropriate
  `Keldi` / `Ko‘rish` actions; today's doctor timeline and three quick actions.
- Shared sidebar typography: 14px menu text, readable 11px group headings,
  balanced row spacing, existing logo/navigation/selected state retained.
  No global page-content typography overrides.
- Booking: new/existing patient modes, F.I.Sh./phone/ID search, loading/empty/error
  and retry, explicit patient selection, patient card and change selection.
  Mode switching preserves appointment selections and patient drafts.
- Required patient fields align with current model (name/date of birth);
  phone and gender optional. Phone matches offer a choice and never imply identity.
  Reception's two workflows share the same local patient form and search component.
- Branch/department/doctor/service/date-dependent availability; 30/60 minute
  durations, overlaps (also across branches), lunch/off days, disabled occupied
  times, separate no-schedule and no-capacity messages. Native date picker accepts
  future dates beyond 15 days; date changes revalidate selection.
- Reuses existing DateField (adds only an optional `min` prop, with no changed
  behavior for existing consumers). Same DD.MM.YYYY display and calendar icon.
- Saving immediately displays the new `Yozilgan` row, even for a future date or
  with prior filters. A future-date list has an explicit date and a return-to-today
  action. The statistics and right panel continue to describe today.
- Arrival: search existing patient, select explicitly among today's encounters,
  transition booked to waiting once. Row `Keldi` opens the same workflow with the
  encounter preselected and is available only for today. Existing queued/completed
  encounters cannot be queued again. Without a booking, choose today's branch and
  doctor/live queue; missing patients use the shared local form and then continue.
- Native dialogs: Escape, focus containment/return, internal scrolling, fixed
  footer, narrow-screen bounds. Details and menu actions stay local.

## Explicit boundaries

Reception uses only `src/mocks/reception.ts`, instantiated per page mount; it has
**no patient or reception API integration**. Its search does not search the real
clinic database. Patient addition, booking, and queue entry affect memory only,
reset on leaving/remounting or refreshing, and show local-demo wording. No real
patient is created by Reception. The Patients page retains its existing API path.
This is not a Stage 5 scheduling engine: the fixture's weekday/lunch schedule is
synthetic. Revenue is unavailable (`—`), with no invented financial amount.

## Validation

The reference full-page and modal images use a 1536×1024 viewport. Locally captured
and visually inspected page, new/existing booking, arrival and 390×844 mobile
screenshots are kept under `/tmp/blusser-reception-screenshots` and are not committed.
Final deliverable snapshot (excluding unrelated local Doctor work):

- `CI=1`, headless installed Chromium 154, one worker: **40/40 browser tests passed**
  (10 Reception/Patients fixture cases and 30 existing departments, employees,
  Doctor workspace/workflow regressions).
- `npm test`: **3/3 test files passed**, including Reception model cases for
  duration overlap, missing/full capacity, validation, phone identity, and check-in.
- `npm run build`: passed, including `vue-tsc -b` type checking.
- Same-reference viewport screenshots inspected; mobile 390×844 bounds, modal
  focus containment/return, internal scrolling, and sidebar navigation verified.
- Patients fixture regression covers search/page/branch, detail, ETag PATCH,
  conflict and validation, create, and CSV; login/logout tested with network fixtures.
  This does not claim a new live backend/database validation.
- No database tests were run. No screenshot/image is included in the commit.
- Local unrelated Doctor referrals files remain byte-for-byte unchanged.

The browser checks use the existing installed Chromium; GitHub CI's bundled
browser is separately exercised by its normal workflow. GitHub CI status is not
claimed without access to the Actions API.
