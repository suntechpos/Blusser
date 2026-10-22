# Employee page access and cashier PIN

Employees retain their existing role ID, clinical department, job position and branch assignments. Optional per-profile `page_grants` select view permissions from the existing catalog. No new role or permission code is created. NULL preserves legacy behavior without migration backfills; explicit [] denies every page. These grants add page viewing only; action permissions still come from the role, clipped to the selected page scope. Incomparable own/assigned-branch scopes fail closed. Cross-page workflows still require their existing prerequisite page/role permissions.

The existing database `permission_scope` and governance delegation functions resolve effective permissions, so RLS, HTTP APIs, session grants, menu and route guards use the same result. Changed individual page selections revoke that user's sessions. The shared role and other employees are not edited. Existing assigned-branch delegation and ETag/idempotency rules also apply. Role assignment authority is required to change page access/PIN. UI scopes intersect the permission catalog with the actor's authority.

Cashier PIN creation/replacement uses four ASCII digits and confirmation. Argon2id hashes live in an RLS-protected table with no runtime SELECT grant. The API returns only `cashier_pin_set`; neither the PIN nor its hash is returned, flashed or written to audits. An omitted PIN preserves the old hash. It is not yet consumed by the demo cashier login/sessions/payment flows; those are outside this task.

Linking an existing account first saves a loginless profile with its selected views/PIN, then requests the existing explicit account-owner consent. If consent/request fails, the saved profile remains loginless; no account is silently created and no password is changed. Photo/schedule remain unavailable as before.

## Local update

Preserve local edits before switching branches (no reset/clean):

```sh
cd ~/projects/Blusser
git fetch origin
git switch feat/employee-page-access-pin
docker compose build backend
docker compose run --rm migrate
docker compose up -d backend
cd frontend
npm ci
npm run dev
```

The forward migration adds nullable page access and a private PIN table, and replaces existing permission/governance function definitions. It does not backfill/modify existing role grants or employee selections. Rollback refuses persisted new access/PIN data; use forward migrations for an installed system. Tests run only against guarded `blusser_test`.
