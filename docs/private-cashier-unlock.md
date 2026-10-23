# Private cashier PIN verification

`POST /api/v1/clinic/cashier/unlock` accepts only `cashier_pin` (four ASCII digits) in its JSON body. First-party Sanctum cookies, CSRF and a full authenticated identity are required. The active clinic and employee come from the session; employee/user IDs and query parameters are rejected. Any active primary role may unlock if its effective `cashier.view` permission allows it.

The service uses the existing shared PostgreSQL limiter: five failed attempts per authenticated user/clinic in fifteen minutes, plus thirty total attempts per IP in fifteen minutes. Reservations commit before verification and cannot be rolled back by an invalid PIN. A new session/IP does not reset the account budget. The IP namespace is separate from password login. Authentication attempts are never cached/replayed by frontend command idempotency.

A narrowly scoped security-definer function looks up only the current membership's active profile/PIN. PHP verifies the stored Argon2id hash inside the runtime TenantContext transaction while holding the same user row lock used by PIN/membership writers. No table read privilege is added. Missing PINs fail closed with a timing fallback that is never a valid credential. The response is empty 204 on success, generic invalid-PIN 422 on mismatch/missing PIN, 403 on missing effective permission and 429 when throttled. Responses are no-store. PINs/hashes are not returned, audited, flashed or stored in browser storage.

The hardcoded frontend PIN is removed. The unlock state is memory-only and clears on account/clinic/context change, cancel or unmount; pending requests cannot unlock a cancelled dialog. No unlock token, server payment API, accounting API or new shift implementation is introduced. The existing cashier ledger/shift/payment functionality remains a clearly marked local demo. The employee form remains responsible for configuring/replacing PINs.

## Local update

Preserve local edits, then use the feature branch:

```sh
cd ~/projects/Blusser
git fetch origin
git switch fix/private-cashier-unlock
docker compose build backend
docker compose run --rm migrate
docker compose up -d backend
cd frontend
npm run build
```

Migration 000016 adds only the private lookup function and its ACL; it does not change PINs, roles or employee assignments. Existing employees need a PIN configured in their employee form. There is no fallback demo PIN.
