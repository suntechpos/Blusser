# Kassa demo UI

Kassa uses the existing clinic shell and Services mock catalog. It is a frontend-only demo: no API, real authentication, terminal, fiscal integration or patient database is used. Test PIN: **1122**, configured with the demo cashier in `src/mocks/cashier.ts`. The signed-in header identity is unchanged.

## Workflows

- Initially locked, without patient or financial data in the page; PIN dialog opens only on request. Four masked digits support keyboard, keypad, Backspace and Enter.
- PIN verification stays in memory. Reload requires PIN again and resumes the same saved open shift. Opening cash may be zero and is not revenue.
- Pending invoices, patient services and payment form use three columns. Catalog selections prevent duplicate services and require explicit quantities. Discounts support whole-so‘m amounts or percentages with up to two decimal places. Services, quantities and discounts lock after the first payment to preserve financial consistency.
- Cash, card, mixed and partial payments separate allocated income from cash tendered and change. Card transactions require a local terminal confirmation checkbox. Duplicate submission tokens cannot create duplicate operations.
- Each payment saves a receipt snapshot. Subsequent invoice changes and payments do not rewrite its original totals or remaining debt. Payment details also show current invoice totals separately.
- History and refunds support date range, search, method/cashier filters, pagination and CSV export of the entire filtered set. CSV formula cells are escaped.
- Refunds stay linked to immutable original payments and their line/method allocations. A canceled service creates a separate cancellation ledger entry for its remaining net charge; refunding a partial payment does not manufacture new debt. Canceling a service but refunding only part of its paid amount can leave credit, displayed separately until the remaining refund is made. Other reasons require a note.
- Shift closing computes expected cash as opening cash + allocated cash payments − cash refunds. Card net totals are separate. Nonzero actual-count variance requires a reason. Closing stores a snapshot, clears PIN verification and returns to the locked page. A new shift starts fresh totals.

## Persistence and receipts

Validated demo state is stored under `blusser.cashier.demo.v1` plus the active tenant identifier. PIN verification is never persisted. Writes complete before the visible ledger updates; quota errors preserve existing state, and revision checks reject stale-tab writes. Corrupt saved state is reported instead of silently resetting it.

Receipts are marked “Namunaviy chek. Fiskal hujjat emas”. They have no fabricated fiscal QR code. Preview and print contain receipt data only. Print failures do not undo payments. PDF export reuses the diagnostic report renderer with an optional 80 mm receipt mode; existing A4 callers retain their defaults. PDF text is rasterized to preserve Uzbek characters and is not selectable. A downloaded receipt was independently parsed as a one-page PDF, width 226.772 points (80 mm).

## Verification

- `npm test`: 7 test files passed, including cashier domain coverage for allocation, discounts, immutable snapshots, idempotency, partial payments, refunds, shift equations and persistence errors.
- `npm run build`: passed, including Vue/TypeScript checking. Existing bundle-size and mixed PDF import warnings remain.
- Cashier browser suite: 10/10 passed on system Chromium 154 with Playwright 1.64.1.
- Full isolated fixture browser regression: **72/72 passed** (3.4 minutes), covering cashier, diagnostics, laboratory, doctor, reception, patients contract fixtures, services, employees and departments/rooms. The publication snapshot excluded unrelated local Doctor referrals work and also passed a separate build. Live-backend `patients.spec.ts` was not run.
- Local logs: `/tmp/cashier-final-browser.log`, `/tmp/cashier-final-results.json`, `/tmp/cashier-unit.log`, `/tmp/cashier-build.log`, `/tmp/cashier-publication-build.log`; exit-code files all report 0.

Browser checks use mocked network/session data, not a live backend. Receipt download and blocked-print recovery are tested; native operating-system printer dialogs and real terminal payments are not. Screenshots and raw test logs remain local under `/tmp`, not committed.
