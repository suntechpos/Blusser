# Sprint 3 — planned PostgreSQL security schema

Stage-1 design, NOT executable SQL or a migration. Existing migrations, runtime credentials, RLS policies and governance functions stay unchanged. Follow sprint-3.md for scope semantics and openapi-sprint-3.yaml for payloads.

## Relations and constraints

All following tables: UUID primary key, tenant_id NOT NULL FK tenants, UNIQUE(tenant_id,id), ENABLE/FORCE RLS, migration-owner policy only for migrations. Domain rows use created_at/updated_at timestamptz; histories append-only. No runtime direct INSERT/UPDATE/DELETE grants. Only explicit audited command functions may write. Existing services remains tenant-owned.

| Relation | Planned data / composite constraints |
|---|---|
| patients | tenant-local patient_number UNIQUE(tenant_id,patient_number), name, phone nullable normalized, date_of_birth, gender nullable, archived_at; no phone UNIQUE |
| patient_branches | patient_id, branch_id, linked_by membership; UNIQUE(tenant_id,patient_id,branch_id); composite tenant FK to patient/branch/membership |
| specialties | tenant-owned code/name/active, tenant-local unique code |
| doctor_profiles | membership_id UNIQUE(tenant_id,membership_id), specialty_id, active; composite membership/specialty FKs |
| doctor_services | doctor_id, service_id, duration_minutes>0; tenant composite FKs; no financial fields |
| doctor_branches | doctor_id, branch_id; tenant FKs; command validates active membership_branches; revoke checked live on every read/write |
| rooms | branch_id, number, service_type, active, availability; UNIQUE(tenant_id,branch_id,number), UNIQUE(tenant_id,branch_id,id); tenant branch FK |
| schedule_rules | doctor_id, branch_id, room_id, weekday 1..7, local_start/end, slot_minutes>0, effective dates; room FK includes branch; doctor assignment live check |
| schedule_breaks | schedule_rule_id, local_start/end; tenant composite FK; within rule interval |
| schedule_exceptions | doctor_id, branch_id, local_date, kind closed/replace, replacement rule details; tenant composite FKs, unique doctor/branch/date; break children if replacement |
| appointments | patient_id, doctor_id, branch_id, service_id, room_id, starts_at/ends_at timestamptz, status, version; composite patient_branch and room_branch FKs, doctor/service tenant FKs, interval CHECK |
| visits | patient_id, doctor_id, branch_id, appointment_id nullable, status, arrived_at, version; composite patient_branch FK, same-patient/branch/doctor appointment consistency; UNIQUE(tenant_id,appointment_id) for nonnull appointments; partial UNIQUE(tenant_id,patient_id,branch_id,doctor_id) while nonterminal prevents concurrent duplicate walk-ins |
| queue_tickets | visit_id UNIQUE(tenant_id,visit_id), branch_id, kind scheduled/walk_in, local_date, number, status, version, called/started/completed times; UNIQUE(tenant_id,branch_id,kind,local_date,number); composite FK to visit INCLUDING branch |
| resource_reservations | one active occupancy representation shared by appointment and linked visit: doctor_id, room_id, branch_id, starts_at/ends_at, appointment_id/visit_id; constraints prohibit conflicting active doctor or room tstzrange('[)') per tenant; no duplicate reservation for linked visit |
| operation_events | aggregate_type/id, from/to state, action, actor_membership_id, branch_id, request_id, time; typed FK projections or separate appointment/visit/ticket histories, never unchecked polymorphic IDs |
| queue_counters | tenant+branch+kind+local_date PK; atomic row update under lock, inaccessible directly to runtime |
| operation_idempotency | tenant+actor_membership+operation+key UNIQUE, request_digest, resource references, expires_at; no bodies/PII/secrets cached; scoped command-only access |
| deployment_grant_provenance | migration identity + inserted permission/grant keys for safe backfill/down; no runtime grants |

Clinic timezone and optional branch timezone override require additive columns with IANA validation in application and validated effective timezone resolution. Do not treat numeric UTC offset as timezone. No schema alteration is executed in stage 1.

PostgreSQL implementation decision: btree_gist extension for tenant/doctor/room equality + interval exclusion in reservations, installed only by migration/bootstrap credentials after compatibility check. This is a PostgreSQL extension, no new service. Test both simultaneous INSERT and reschedule UPDATE collisions. Until constraints and real parallel tests pass, double-booking prevention is not delivered. References from appointments/visits to shared reservations must preserve a single occupancy identity; appointment check-in and visit start reuse/update it in one transaction.

## RLS and write boundary

Every policy first requires row.tenant_id=context_tenant and a current full non-platform identity with active clinic membership. SELECT additionally requires resource view permission and resource-specific own/assigned_branches/entire_clinic predicate. Patient-branch relationship access must not recursively traverse policies: use narrow non-public SECURITY DEFINER boolean helpers with fixed search_path=pg_catalog and qualified objects; validate actor version, active doctor/membership/branch and only then inspect relations. Helpers cannot return hidden patient identity/existence. Existing can_access alone is insufficient for patient ownership.

No write policy/grant is added to blusser_app. New SECURITY DEFINER commands owned by blusser_security (NOLOGIN) explicitly verify tenant, action permission/scope, current actor credential_version, branch assignment, target active state, every composite relationship and transition. Restrict EXECUTE to exact signatures; PUBLIC has none; no dynamic SQL or arbitrary operation/table argument. Add only vetted commands to Governance allowlist in later stages. Existing TenantContext/Governance do not permit nested transactions; a command must encompass all coupled writes and audit under ONE boundary, never invoke independent nested operations.

Lock order: existing user locks ordered by UUID -> tenant lock -> doctor/membership dependencies -> room -> reservation/appointment -> visit -> ticket -> counter; recheck grants/branch assignments after locks. Keep compatibility with Sprint-2 user->tenant order. Initial implementation can serialize commands per tenant for correctness; read-only availability remains concurrent. Lock schedule changes and membership-sensitive booking under same tenant protocol; database exclusions are final safeguard. Exceptions roll back resources, counters, histories and success audit atomically. Denied-operation audit occurs outside rolled-back mutation and contains only server-defined operation and authorized tenant, not searched patient IDs/inputs. Match existing secret-free audit conventions.

A view permission never authorizes mutations. INSERT/UPDATE visibility on OLD and NEW resources must both be checked in commands (including moving appointment branch). Neither caller-supplied branch_id nor doctor_id grants authority. Queue actions revalidate linked appointment/visit and all current branch constraints. Replays reauthorize against fresh membership/grants; idempotency cannot return cached data after revoke.

Patient demographics and per-branch histories have separate projection rules: a patient visible via branch A does not reveal branch B appointments/tickets. Counts and overview use exactly the same predicate. Export is an explicit capability and bounded audited operation. Platform clinic provisioning metadata does not grant any clinical projection.

## Security acceptance checklist for later stages

- Runtime role no ownership, superuser, BYPASSRLS or SET ROLE; no owner credentials in API environment.
- Enumerate every new tenant table in RLS tests/health readiness checks; required policies FORCE on up, fail closed on rollback/error.
- Test real blusser_app SELECT and attempted DML with missing, forged, expired and cross-tenant context; no owner-role isolation assertions.
- Cross-tenant and cross-branch foreign IDs in filters, bodies and detail routes; verify no count, existence, history, export or doctor-branch projection leaks.
- Own access expires when active doctor relationship ends; no historical relationship fallback. Revoke branch, role, membership and credentials between requests/replays.
- Platform admin denied even with erroneous membership; no bearer/CSRF bypass.
- True concurrent booking, reschedule, check-in, queue creation/call, late reschedule and rollback tests in isolated blusser_test only.
- Migration up/down/re-up and permission/CEO backfill are transactional, idempotent, provenance-aware and never affect ordinary tenant grants.
