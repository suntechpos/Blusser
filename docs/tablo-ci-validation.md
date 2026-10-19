# Tablo browser test synchronization (PR #16)

The preview DOM is rendered by Vue before `requestFullscreen()` settles. The old
test checked the preview text, conditionally exited fullscreen only when
`document.fullscreenElement` was already set, and immediately clicked `Yopish`.
A late fullscreen entry can therefore cover the close button (it belongs to the
modal header, outside the fullscreen `.tb-big-tv`).

A diagnostic run with real Chromium confirmed the blocked operation: clicking
`dialog > Yopish` while the TV is fullscreen retries with
`.tb-big-tv ... intercepts pointer events`. The ordinary original workflow also
passed 3 normal and 8 CPU-throttled runs; the historical CI failure cannot be
conclusively attributed to this race without its logs.

The test now waits for actual native fullscreen entry, exits fullscreen, verifies
exit, and then closes the dialog. Named steps distinguish clipboard, TV entry,
and exit/close. No timeout increase, skipped checks, browser API replacement in
the primary workflow, application changes, or backend changes were made.
A separate regression delays native fullscreen entry by 250ms and checks exit,
close, and preservation of the local screen list.

Validation (2026-10-19):

- Node 24.19.0, Playwright 1.64.1, installed Chromium 154 headless.
- `CI=1`, one worker, fresh Vite server, existing 45-second test timeout.
- Primary Tablo workflow plus delayed-entry regression: 10/10 passed (5 repeats).
- Frontend fixture browser regressions: 39/39 passed (working tree, including
  the preserved local referrals regression; that unrelated test is not committed).
- Frontend unit tests: 2/2 passed.
- `npm run build`: passed, including `vue-tsc -b`.
- No database tests were run.

Environment limits: GitHub Actions API returns `Forbidden`. The CI-pinned
Chromium 156 download from `cdn.playwright.dev` returns `403 Domain forbidden`.
Consequently the exact CI browser and historical failing action log could not
be inspected here. The new GitHub run must confirm the fix on that browser.
Unrelated local Doctor referrals changes are excluded from this commit.
