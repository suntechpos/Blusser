# PR #10 — Patients integration review

Scope: existing Patients UI and its shared authentication/context infrastructure.
No other operational page, backend authorization rule or migration is changed.

## Confirmed findings and fixes

1. **Unsaved edits were discarded on window focus (P2).** App's focus handler
   reused full authentication initialization, clearing context and unmounting the
   patient dialog before rechecking the session. The new browser regression failed
   against the initial PR: eight existing tests passed and the draft-preservation
   assertion failed. Focus now reads the current identity, actual grants and
   branch projection without remounting an unchanged context. Changed identity,
   clinic or authorization still invalidates previous requests and patient content;
   revoked view access also closes existing details/drafts. Authorization checking
   is retained, rather than removed to keep the form open.
2. **The CSV button did not reflect the complete server permission rule (P2).**
   It checked only patients.export. The existing backend additionally requires
   patients.view at entire_clinic; a valid assigned_branches viewer with a separate
   entire_clinic export grant therefore saw an action guaranteed to return 403.
   The UI now requires both entire_clinic scopes. A new real fixture and backend
   regression cover this combination. Server checks remain unchanged.

3. **Failed/loading lists displayed a zero in the pagination summary (P3).**
   The footer now shows unavailable instead of presenting an unverified zero
   patient count. The failed-list browser regression checks this explicitly.

Context-sensitive auth/me, auth/clinics and active-clinic requests also carry the
existing context abort signal and check their generation before applying results.
A superseded authentication response cannot restore a logged-out/previous context
or expire the new context through an old cancelled 401/419. Context projection
reads are assembled before applying grants/branches, avoiding partial updates.
If revalidation discovers a server-side clinic change, subsequent context-load
failures retain their error message and cannot leave previous-clinic data visible.

## Reviewed invariants

- API permissions remain authoritative; UI guards do not replace tenant, branch
  or own RLS. Platform identities have no clinical bypass. No SQL functions,
  privileges, RLS policies or runtime credentials were changed in this review.
- Clinic/branch selection clears patient rows, filters, dialogs and pending
  requests. Real delayed-response tests cover previous clinic patient data and
  auth/me, including another tab changing the selected clinic.
- Edit uses a fresh GET's opaque ETag. A real concurrent writer produces 409 and
  leaves the draft open; no silent overwrite, version field or backend shortcut.
- Validation errors keep the dialog open, expose field names, and do not report
  success. Existing invalid-date tests exercise actual server validation.
- CSV uses the same selected search/status/branch filters and server-side scope.
  Download contents are checked, not just the filename; another clinic's patient
  must be absent and an empty authorized branch produces headers only. Existing
  backend tests cover formula neutralization and export limits.
- The lost-response test lets a real POST commit, then deliberately drops its
  response. Retrying keeps the same idempotency key and creates exactly one
  patient. Successful authentication and clinical writes are never mocked.
- Real login, logout, reload, temporary-password change, expired-session handling
  and a delayed auth response after logout remain covered. Login template/styles
  and Patients styles remain unchanged.

## Validation

- Fresh Docker image, default www-data: **157 backend tests, 8476 assertions,
  zero failures**, 137.79 seconds. Includes the new export-scope denial regression
  and all existing real PostgreSQL security/concurrency/migration regressions.
- Pint: **100 files PASS**. Composer strict validation and platform checks: PASS.
- Frontend unit tests: **4 passed**. TypeScript and Vite production build: PASS.
- Real Sanctum/PostgreSQL Chromium browser suite: **15 passed**, 25.1 seconds,
  no skipped tests. The previous eight scenarios plus draft preservation, combined
  export scopes, CSV contents/filters, uncertain-create replay, permission
  invalidation, delayed-auth-after-logout and a failed context refresh after a
  server-side clinic change were exercised.
- The initial PR commit e26e6ea's GitHub run **39033128106 passed** (6m 1s).
  The follow-up commit triggers another PR run; its final status must be checked
  remotely and reported separately, never inferred from local green checks.

DB tests and browser fixtures are restricted to disposable `blusser_test`;
  development data and migrations are untouched. Frontend failure/delay/revocation scenarios
intercept only their designated responses; identity is real Sanctum throughout.

Broader Stage 6 work remains deferred as described in
`sprint-3-stage-6-patients.md`. Review fixes do not authorize another page or merge.
