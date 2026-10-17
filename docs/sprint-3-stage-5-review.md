# PR #9 — Stage 5 review

Reviewed base: `9cfca0a6c38772acb58e5925778f9121aa09aed2` on
`feature/queues-reception-api`. The public PR checks page confirms that base CI foundation succeeded (run 39028589503, 3m58s).
Review fixes do not merge main or start Stage 6.

## Finding fixed: P2 — inconsistent queue reads under concurrent commits

Queue list/detail and reception overview issued multiple READ COMMITTED statements.
Each statement could observe a different committed database state:

1. A separate committed branch/grant revocation between count and list produced
   meta.total=1 with data=[]; a later projection could also disappear after its
   existence check and fail decoding instead of returning a stable response.
2. A real independent runtime-role queue call between overview sections produced
   Visit queued and ticket called in the same response.

Two deterministic regression tests failed on the reviewed base with these exact
count/list and Visit/ticket mismatches. The second uses a separate PHP/PostgreSQL
runtime command, not a mocked state transition. Test database is blusser_test only.

Queue list/detail/overview now use a transaction-local REPEATABLE READ, READ ONLY
snapshot set before identity/membership/permission reads. Every projection, count,
RLS predicate and source capability in a response sees the same snapshot. A
revocation committed during an already-running read is visible on the next request;
it is not ignored or cached in session claims. Runtime permissions and FORCE RLS
remain authoritative. Successful and failed transactions restore connection modes
and clear tenant context; a third regression verifies rollback followed by a real
write on the same runtime connection.

TenantContext exposes an internal optional consistentSnapshot flag; existing
callers retain their previous default. The flag never comes from request input.
Mutations still go through Governance and their own transactions; user/tenant/room/
aggregate row locks, expected_version-after-lock and idempotency reauthorization
are unchanged. No schema migration is required for this PHP read-boundary fix.

## Other reviewed boundaries

- Independent action/view/reference permissions and conditional linked-appointment
  update/cancel capabilities; no platform bypass or cross-tenant IDs in payload.
- Tenant/branch/own RLS on lists, detail and projections; explicit overview ceiling
  and omitted unauthorized sections/counts; actor credential and grant revocations.
- Atomic ticket/Visit/Appointment graph, terminal-state refusal, recall events,
  skip/requeue preserving ticket number, queue cancel releasing linked occupancy.
- Scheduled reservation reuse and exact interval/room; walk-in start revalidation,
  PostgreSQL doctor/room exclusion constraints and release on completion/cancel.
- Deterministic lock order, ticket optimistic version after actual FOR UPDATE,
  reauthorized replay, payload conflicts, one ticket per Visit and atomic counters.
- Append-only typed history, sanitized errors, audit rollback, private definer
  helpers, fixed search_path, FORCE RLS and migration/runtime credential separation.
- Protected migration down/up and deployment limits; schemas/state graph remain
  unchanged. No frontend, medical, finance, realtime or public display additions.

The existing security/concurrency tests for these boundaries are retained in the
full regression suite. No further defect was confirmed within the approved Stage-5
rules by this review; this is not a production load or penetration-test claim.

## Validation

Targeted QueuesApiTest + DatabaseSecurityTest after the first two regressions:
**39 passed, 470 assertions**, 20.03 seconds. Final built-image suite, style,
Composer and artifact checks:

- Built image: sha256:7021d76021c33a1f97f333ea6bb295802245b41670e1f5b4abc4a0e1ecc2cfcd.
- Full suite: **152 passed, 8388 assertions**, 133.46 seconds; no failed/skipped tests.
- Pint --test: **96 files PASS**.
- composer validate --strict and composer check-platform-reqs: PASS (PHP 8.4.26).
- The final set-e test/style/Composer command exited 0 in the built image, without
  source mounts, default www-data OS user, dedicated blusser_test database.
- SHA-256 matches for all three changed backend files inside that tested image.
- git diff --check PASS. No migration, API schema, permission catalog or frontend changes.

GitHub API calls from this environment still return Forbidden for api.github.com.
Public GitHub checks HTML is readable and was used to verify the base run above.
A pushed review fix needs its own CI result; its latest result is reported separately
rather than inheriting the base green status. No merge is performed.
