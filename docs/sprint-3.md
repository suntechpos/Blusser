# Sprint 3 — Clinic Operations contracts

Status: stage 1 only. No operational routes, migrations, grants or frontend integration are delivered here. `openapi.yaml` remains the deployed API contract; `openapi-sprint-3.yaml` is the explicitly planned contract. PHP OperationsContract supplies the permission vocabulary and transition graph, not an authorization service. Stages 2–7 require separate implementation and security verification; stage 2 requires the owner's approval.

## Boundaries and shared conventions

Laravel modular monolith: Patients, Doctors/Scheduling, Appointments/Visits and Queues/Reception use existing Identity, Tenancy and Access boundaries. No new login system, medical records, diagnoses, laboratory results, payments or public registration. Existing frontend designs stay intact. Financial cards must remain explicitly demo/unavailable until a separately authorized finance module exists.

All planned paths use `/api/v1/clinic`, first-party Sanctum cookie + CSRF, full identity, session-selected active clinic, fresh active membership and current permissions. Tenant is never accepted from a payload/header as authority. UUID resource IDs differ from human-readable patient/ticket numbers. Input is allowlisted; tenant_id, owner_id, creator and audit actor are server assigned. Unknown input is rejected. Resource queries/binding run inside verified TenantContext. Foreign/invisible resources return generic 404; missing action permission returns 403; no active clinic returns 409; expired identity 401; CSRF 419; invalid fields 422; booking/state/idempotency conflicts 409; rate limit 429. Error details must not expose foreign IDs, patient identities, SQL or credentials.

List query: page >=1, per_page 1..100 (default 50), q <=100, authorized branch_id, doctor_id where relevant, status allowlist, from/to RFC3339 offset timestamps with bounded range. Stable ordering `(starts_at,id)` for scheduled records, `(created_at,id)` otherwise. Responses use data + meta(page,per_page,total); counts, search, histories and exports use identical effective visibility. Offset pagination is stage-1 contract; availability ranges limited to 31 local days, overview to one local day, exported records bounded to 10,000 with 422 beyond limit. UTC instants are serialized with Z; server computes business dates in configured IANA clinic/branch timezone. Default new clinics Asia/Tashkent; legacy timezone backfill must be reviewed before migration. Weekly schedules use local wall time + timezone, exceptions local dates; ambiguous/nonexistent DST times require explicit offset or are rejected. End times exclusive.

## Patients and visibility

One patient per tenant, optionally linked to many branches. Phone is normalized for search, not globally/tenant unique. Tenant-local patient number is allocated atomically, never browser-generated. Possible duplicates are suggested only from visible patients, never auto-merged. No global patient search/linking across tenants; global identity linking applies only to employees.

A branch-scoped creator creates the patient and first authorized branch link atomically. `patients.link_branch` is separate from update; require visibility of patient in current context AND access to destination branch. A patient invisible to that actor cannot be discovered/linked by guessed UUID or telephone. A tenant-wide authorized operator handles cross-branch linking where no prior authorized relationship exists. Removing/moving branch links is deferred: must not erase appointment history or evade visibility. Demographic updates affect the single tenant record shared with other linked branches; audit records that fact. Lists/history disclose no visits from unauthorized branches.

Scope meanings (all also require active tenant membership and action permission):

- entire_clinic: this active tenant only.
- assigned_branches: active branches currently assigned to actor; patients require a patient_branches intersection, operational records require their own branch in the set. Doctor results expose only authorized branch/service projections, never every assignment of a partly visible employee.
- own: patients require an active doctor profile attached to actor's membership AND an active assigned care relation derived from that doctor's scheduled/checked_in/in_progress appointment or nonterminal visit. Completed/cancelled/no_show relationships alone grant no ongoing demographic access. For appointments/visits/tickets, doctor membership must match actor; never use created_by as ownership. Own also requires current doctor/employee branch assignment and active branch. Archived patients cannot be newly booked. This grants no medical-history access.

Patients archive/restore/export are entire_clinic only, explicitly delegated capabilities, not a role-name shortcut. Archive is reversible, prohibits new bookings/visits, preserves history and fails 409 while nonterminal appointments/visits exist. Restore is audited. No physical delete. CSV includes only approved demographic columns, neutralizes formula prefixes (=,+,-,@ plus leading control/whitespace), quotes delimiters/newlines, audits export without contents. Filters, totals and CSV never broaden scope.

## Doctors, rooms and scheduling

Doctor profile references a tenant membership (one profile per membership); specialty and services use tenant-owned IDs. Many branches are allowed only within current membership_branches; inactive profile/user/membership/role/branch excludes scheduling. Services are existing tenant services, no pricing. A service duration override belongs to schedule/doctor-service, not financial data. Rooms have branch, number, service type and active/availability state. Unique room number within branch. Branch identity is immutable; changing doctor/room branch requires a new appropriate assignment, not rewriting history. Activation/availability changes cannot invalidate existing appointments silently; return conflict with an authorized summary.

Weekly schedule rules: doctor, branch, room, weekday, local start/end, slot duration and breaks. Exception dates can close/replace hours for holidays/leave. Rules and exceptions cannot overlap inconsistently. Availability is computed from rules minus breaks/exceptions and active doctor/room occupancy; it is a hint, not a reservation. Revalidate everything on booking/reschedule in the same transaction. Scheduling changes that invalidate existing bookings fail 409; never automatically cancel them.

## Appointment, Visit, QueueTicket

Appointment is a planned interval; Visit is actual attendance (also walk-in); QueueTicket orders a Visit. They have separate IDs and histories. The transition vocabulary is machine-readable in OperationsContract and planned OpenAPI; UI Uzbek labels map to stable English codes.

Appointment starts scheduled; check-in atomically creates one Visit and one waiting ticket and marks checked_in (intermediate visit arrived->queued events retained). Walk-in creates visit+ticket without appointment; repeated patient/branch/doctor attendance is blocked while an active matching visit exists. Appointment check-in cannot be performed twice with different keys. Ticket start/complete atomically update visit and linked appointment. Queue cancellation closes visit and transitions linked checked_in appointment to cancelled with a distinct audited queue-cancel reason; this linked transition is not a public appointment cancel shortcut. Skipping preserves checked_in appointment, visit skipped, and original ticket; requeue reuses same ticket/number, validates schedule/occupancy, never allocates a duplicate. Terminal records cannot reopen. Recall emits an event without changing called state. Command responses return the synchronized resource; stale/illegal state changes return 409. Each action has a separate permission; reschedule requires appointments.update. Ticket start/complete/cancel additionally require corresponding appointment update/cancel permission if linked; initial enqueue/check-in requires visits.create + queues.create and appointment check_in when applicable.

Late is a derived flag (scheduled start passed and no check-in), never automatic cancellation. No-show is explicit after scheduled start, only scheduled state. Late check-in requires an available replacement interval submitted through reschedule first; no automatic shifting of other appointments. Frontend offers only server-returned availability, still subject to final conflict check.

Booking and reschedule require patient visibility+active branch link, doctor active membership branch and service eligibility, active compatible room in same branch, valid schedule and no conflicts. No free-form patient/doctor/service names. Duration derives from approved schedule/service contract. Walk-ins and queue start also reserve a doctor/room interval; no roomless concurrent consultation shortcut. Queued visits may wait without reserving occupancy; start must receive a valid interval/room and recheck atomically. A scheduled appointment's reservation is reused for its linked visit, not duplicated.

Idempotency-Key (UUID) required for writes. Store tenant+actor+operation+key, canonical request hash and minimal resource/result reference, retention 24 hours; reauthorize on replay, changed payload 409. Lock resources in deterministic order, serialize state changes; combine with database unique/exclusion constraints, not only PHP validation. Queue number uniqueness: tenant+branch+kind(scheduled/walk_in)+local date; skipped/cancelled numbers never reused. Sequence increment and insertion commit atomically.

## Permissions and CEO backfill

OperationsContract::permissions() is the proposed catalog; no migration seeds it yet. Existing permissions remain unchanged. Reception overview requires reception.view AND the underlying view permission for each returned projection; omit unauthorized sections (no counts/timing side channels). UI capabilities must report actor's effective grants, not clinic-wide role catalog. No Platform Admin bypass, including erroneous membership.

Future additive migration: insert missing catalog entries; verify matching existing allowed_scopes or fail (never silently overwrite); backfill only protected code=ceo roles whose tenant_ceos membership/user/role agree. Add entire_clinic grant per new code only where allowed; record deployment audit per tenant with codes/counts, no PII. Do not grant ordinary roles, platform users, inactive/unverified CEO associations, or modify existing grants. CEO then explicitly delegates new capabilities. Dry-run counts, partial-failure rollback, repeat execution and down restoration require tests. Maintain a dedicated migration provenance table for inserted permissions/grants so rollback removes only deployment-owned rows; if subsequent role edits depend on them, refuse destructive down and require reviewed forward fix. Owner credentials remain migrate/test only.

## Stages and acceptance

1. This contract, PHP vocabulary, OpenAPI and security schema; static consistency and existing regressions.
2. Patients + tenant/branch RLS, archive/restore/export; two-tenant, branch, own-relation, spoofing, duplicates and CSV tests.
3. Doctor/room/schedule reference APIs; membership revocation, scoped projections, exceptions, timezone and availability tests.
4. Appointments/visits; true concurrent PostgreSQL bookings, room conflict, check-in idempotency, history and rollback tests.
5. Queues/reception; concurrent calls/number allocation, transition permissions, skip/recall/requeue, scoped aggregates, late arrival tests.
6. Frontend API integration only after backend acceptance: retain design; first-login password form, clinical membership selection, capability guards, purge/cancel old tenant requests, reload/session expiry and no fake login.
7. Full real PostgreSQL runtime-role regression + CI, migration lifecycle and OpenAPI-route parity. No merge without owner consent.

Tests use only blusser_test. Existing FoundationTestCase refreshes/truncates that dedicated database; never invoke it against development/production. Stage-1 tests must not claim patient isolation is implemented. Remaining stages must add RLS/health coverage for every new tenant table and validate IDOR across patients/doctors/rooms/services, revocations, platform denial, CSRF and stale sessions.

## Initial delegation recipes (not automatic roles or grants)

CEO may create receptionist roles with patients.view/create/update/link_branch,
appointments.view/create/update/cancel/check_in/no_show, visits.view/create,
queues.view/create/call/recall/start/complete/skip/cancel/requeue and reception.view
at assigned_branches, plus the required reference-data views (services.view uses
its existing entire_clinic-only catalog rule). Ordinary doctor roles use own for
patients.view and assigned appointments/visits/queues; no patients.create/archive/
restore/export, no patient-wide or assigned-branch patient view is automatically
granted by creating a doctor profile. Clinical administrators receive archive,
restore or export only by explicit CEO delegation; role labels never authorize.
If a doctor is separately delegated wider operational authority, that is an explicit
audited RBAC decision, not an implicit result of doctor profile membership.
