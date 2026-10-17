# Sprint 3 Stage 3 — Doctors, rooms and scheduling

Delivery branch: `feature/doctors-scheduling-api`, based on `main` after PR #6, including the mandatory Patients PATCH If-Match guard. Stage 4–7 and frontend integration remain out of scope.

## Approved decisions and contract

The project owner approved Asia/Tashkent backfill for existing clinics and the addition of specialties POST/PUT protected by doctors.create/update. New clinics default to Asia/Tashkent. A nullable branch timezone overrides the clinic timezone. PostgreSQL validates real IANA timezone names (or UTC); numeric offsets are not timezone settings. Timezone configuration is deployment-managed in this stage, not a new tenant-settings endpoint. Subsequent timezone changes need a reviewed, audited deployment and conflict analysis before bookings are introduced.

Stage-1 Patient/Doctor/Room/Schedule/Exception/Availability JSON structures, scopes and state machines remain unchanged. Specialty responses retain id/name; the approved SpecialtyInput adds code/name/active. PUT replaces the complete input representation. No hard-delete endpoints exist. Doctor membership, room branch and schedule doctor/branch identity are immutable; exception doctor/branch/date identity is immutable. A new assignment is required rather than repurposing historical identities. Updating a weekly rule or exception appends its previous complete projection (including breaks) to scheduling_changes in the same transaction.

| Methods | Path (prefix /api/v1/clinic) | Permission |
|---|---|---|
| GET / POST | /specialties | doctors.view / doctors.create |
| PUT | /specialties/{id} | doctors.update |
| GET / POST | /doctors | doctors.view / doctors.create |
| GET / PUT | /doctors/{id} | doctors.view / doctors.update |
| GET / POST | /rooms | rooms.view / rooms.create |
| GET / PUT | /rooms/{id} | rooms.view / rooms.update |
| GET / POST | /schedules | schedules.view / schedules.create |
| GET / PUT | /schedules/{id} | schedules.view / schedules.update |
| GET / POST | /schedule-exceptions | schedules.view / schedules.create |
| GET / PUT | /schedule-exceptions/{id} | schedules.view / schedules.update |
| GET | /doctors/{doctor}/availability | doctors.view + schedules.view + rooms.view, intersected at the requested branch |

All writes require Idempotency-Key UUID, CSRF, full first-party cookie identity, current tenant membership, and entire_clinic action **and view** grants. No permission is implied by a role name. Reads support assigned_branches and entire_clinic exactly as the Stage-1 catalog allows. List pagination is page/per_page, default 50, maximum 100. Stable reference ordering is created_at/id; availability is starts_at/room_id/ends_at. Search q applies to specialty name, room number, or reference UUID for other lists. Unsupported doctor/status filters are rejected instead of authorizing a broader result.

## Data and authorization boundary

Migration `2026_10_16_000008_add_doctors_and_scheduling.php` adds specialties, doctor_profiles, doctor_services, doctor_branches, rooms, schedule_rules, schedule_breaks, schedule_exceptions, schedule_exception_breaks, scheduling_idempotency and scheduling_changes, plus tenant/branch timezone columns. Every new tenant table has ENABLE/FORCE RLS. Health and RLS regression inventories now include all 29 required relations. Every relationship includes tenant_id; schedule and replacement room FKs include branch_id. The ledger has typed tenant-composite resource references. A profile is unique per tenant membership and a room number per tenant branch.

blusser_app remains a non-owner, non-superuser, non-BYPASSRLS role. It receives SELECT only on scoped reference/schedule tables; no direct writes, ledger/history reads or access to private command helpers. SECURITY DEFINER command/read predicates belong to the NOLOGIN blusser_security role with search_path=pg_catalog and qualified objects. PUBLIC execution is revoked; execute grants are exact signatures. Scheduling adds only SELECT services to that private role, not runtime write access to existing tables. Projection uses SECURITY INVOKER and therefore cannot bypass runtime RLS when directly called.

scheduling_command accepts only five fixed resource kinds and create/update actions; it uses no dynamic SQL/table names. It validates shape, types, UUIDs, relationships, action scope, actor credential version and live dependencies inside one transaction. User locks are acquired in UUID order before the active tenant lock, followed by room dependencies. This shares the existing governance serialization protocol. Permission/membership/branch state is rechecked after locks. Cross-tenant/invisible relationships return generic 404, permission denial 403, invalid input 422 and schedule/identity/uniqueness conflicts 409. Mutations, ledger and success audit commit atomically; authorization denials are audited separately without request payloads or searched IDs.

Doctor eligibility is evaluated live from profile, specialty, global user, tenant membership, membership role, doctor branch, membership branch, active branch and service. Branch-scoped doctor responses expose only currently authorized, live branch assignments. Deactivation/revocation removes future availability immediately. An entire-clinic administrator can still inspect inactive reference records for management. Doctor profile creation changes no grants and does not enable patients own access: without actual Appointment/Visit care relationships that predicate remains fail-closed.

Stage 2 already installed the exact Stage-1 permission catalog and verified-CEO provenance/backfill mechanism. Stage 3 verifies that catalog and does not silently grant or widen any role. Existing CEO delegation remains the only runtime way to issue capabilities.

## Schedule and availability semantics

Weekly windows are local wall time, weekday ISO 1–7, effective dates inclusive, interval ends exclusive. Same-doctor and same-room windows cannot overlap, including across branches/timezones. Breaks must be non-overlapping, inside the window, and are subtracted from offered slots. Overlap checks conservatively reject overlapping work windows even if breaks might make some individual slots non-overlapping.

A single exception per doctor/branch/local date overrides that whole day: closed offers no slots, replace supplies its own window, room, slot length and breaks. Replacement doctor/branch/weekday/effective range must cover that date; the response canonicalizes its effective dates to that date. Exception conflicts with other doctors/rooms/branches are checked in the command. No invented holiday calendar is installed.

Availability uses the existing doctor_services eligibility relation. The Stage-1 input has no duration override field, so the schedule slot_minutes supplies duration unless a reviewed doctor_services duration override exists. No arbitrary duration or financial fields are added to services. Room compatibility means the active, available, same-branch room explicitly selected by an authorized schedule; service_type is a descriptive category, not an invented service-code relation.

Availability is read-only, limited to 31 local calendar days, and returns UTC Z instants. PHP's silent DST normalization is rejected: nonexistent or ambiguous slot boundaries, or elapsed intervals that differ from the configured duration across a transition, return 422. Ordinary slots on transition days still work. Bounds are applied to complete slot intervals and pagination occurs after deterministic ordering.

`X-Availability-Mode: schedule-only` explicitly identifies these as schedule suggestions, **not bookings or guaranteed free resources**. Appointments, visits, reservations and real occupancy do not exist in Stage 3. There is no double-booking-prevention claim. Stage 4 must revalidate and reserve atomically with its approved database exclusion constraints. Room active/availability/type changes that conflict with existing current/future schedules return 409; referenced doctor branch removal cannot erase schedule history.

Idempotency stores a request digest and typed resource reference, never request bodies or employee data. Retention is 24 hours; replays require current actor/grants and return a freshly authorized projection. A changed request under the same key conflicts. Expired entries for a reused key are replaced; a general retention-cleanup job is not part of this stage.

## Deployment, local validation and rollback

Use the existing ignored local .env and runtime/migration credentials split. No new secrets or packages are required. From repository root:

```sh
docker compose build backend
docker compose up -d --wait db
docker compose run --rm migrate
docker compose up -d --wait backend
curl --fail http://localhost:8000/api/v1/health
docker compose run --rm test
docker compose run --rm test vendor/bin/pint --test
docker compose run --rm test composer validate --strict
docker compose run --rm test composer check-platform-reqs
cd frontend
npm ci
npm run build
```

The test service and FoundationTestCase require **blusser_test** on both connections and verify the actual database names before refresh/truncate. Never run destructive test setup against development/production. The backend runtime service never receives migration credentials. Migration up is transactional, owner-only and audits each existing clinic's approved timezone backfill without identity data. The deployment smoke check uses a normal API process, not the privileged migration connection.

Rollback uses `docker compose run --rm migrate php artisan migrate:rollback --database=migration --step=1 --force` only after review. Down refuses any scheduling/reference/history/ledger data and any non-default clinic or explicit branch timezone; it never silently discards data/configuration. If refused, retain the schema and deploy a reviewed forward fix. Empty-schema down/up, protection of nonempty data, and earlier migration/security lifecycle regressions are tested. Deployment audit events remain append-only. Permission catalog/grants are not removed by this migration.

Cross-timezone recurrence validation may inspect a long effective-date horizon; indefinite rules and large tenant schedules need performance review before production-scale use. Availability currently calculates the authorized date-range result before offset pagination. Neither this nor the intentionally serialized mutation protocol is a performance claim for large installations.

Validation evidence is recorded in sprint-3-stage-3-validation.md. Frontend files, Login, Patients JSON, patient optimistic locking and archive/restore contracts remain unchanged. Stage 4 is not started.
