# Employee schedule and room assignments

Rooms in **Bo‘limlar va Xonalar → Xonalar** use existing GET/POST/PUT rooms endpoints and real branch IDs. Search, active filtering and pagination remain available. Department links, floor and notes are not in this API contract: the room form does not submit them. The separate department demo is explicitly labeled nonpersistent; it cannot supply IDs for real rooms. Employee forms show only active, available rooms in the selected branch, excluding private rooms owned by another doctor. No demo room IDs are submitted.

Employee save remains sequential: profile/account → existing/new doctor → selected schedule days → GET verification of saved rules and room assignment. Partial failures retain the real employee and successful schedule IDs; retry updates those resources. Existing unselected rules are not deleted. New schedules start on the clinic-local current date and have no end date; existing effective dates remain intact.

## Contract and security

Migration `2026_10_24_000019_add_room_assignments` adds `rooms.usage_mode` (`shared` by default) and nullable `exclusive_doctor_id`. A check constraint and tenant/doctor/branch foreign key enforce the owner relationship. Existing rows and IDs are preserved; existing room clients may omit both added fields. Rooms GET includes these fields; rooms writes may explicitly supply a usage mode and owner. Schedules writes accept optional `room_usage`; private ownership uses that schedule's doctor ID. Exceptions retain the existing payload.

Room mode is a **room-wide** setting, not a per-weekday setting. A schedule writer changing room mode also needs existing `rooms.update` and `rooms.view` entire-clinic authority. A different doctor cannot release or take over someone else's private room through a schedule write. To change owners, an authorized room update must satisfy all ownership constraints; a room still assigned to other doctors cannot become private. Historical assignments count too: they are not silently deleted. The same doctor may release its room to shared mode through an authorized schedule update. An explicit authorized rooms update can also release it. Moving one rule does not implicitly release ownership of its former private room.

The existing actor-user → tenant locking protocol serializes scheduling commands. Usage updates, conflict checks, rules, audit and idempotency records are in the same database transaction. Triggers protect normal rules and exception replacements, including old clients that omit `room_usage`. Existing interval-conflict checks remain in force for shared rooms. Concurrent private claims on different weekdays have exactly one winner. Existing API branch/tenant permissions and RLS remain unchanged.

A new configuration-only doctor-branch predicate allows an authorized admin to configure a target with `must_change_password=true`. Actor authentication and permissions are unchanged. The target still must change its password before full authenticated access, and booking/availability continue to use the original live-doctor predicate. This is configuration support, not an authentication bypass.

Read-only employee profiles resolve room names and mode from the server. Shared/private mode is no longer UI-only.

## Local update

```bash
cd ~/projects/Blusser
git fetch origin
git switch feat/employee-schedule-room-integration
git pull --ff-only
cd backend
php artisan migrate --database=migration --force
cd ../frontend
npm ci
npm run build
```

The existing `migration` connection must use the project's `blusser_owner` credentials. No production or user-local fake rooms/doctors are seeded. Create actual rooms through the room page and select the corresponding active branch, specialty and services in employee configuration. This environment cannot verify or change the user's localhost database.

Rollback refuses when private ownership data exists; use a reviewed forward migration. For an empty/non-private database it restores the exact prior stored function definitions and removes the added schema fields. Existing applied migrations and baseline scheduling SQL are unchanged.

## Validation

All database/browser integration tests run against **blusser_test** only. Foundation tests verify both configured and actual database names before cleanup. New coverage includes temporary-password target setup with actor denial unchanged; shared-room boundaries/conflicts; private ownership/release; exceptions; tenant/branch isolation; atomic denial without room authority; and two independent PostgreSQL transactions competing for private ownership. Existing scheduling, migration, idempotency and access suites remain required.

Frontend coverage includes real room create/edit/reload, a new temporary-password doctor with three private-room days, profile read-back and reopening without duplicate employees, existing partial-write retries, room list/filter/pagination/server-conflict behavior, employee permissions/PIN regressions, and downstream appointment booking through the current inline configuration flow. The obsolete test reference to the modal removed on main in PR #44 is replaced by the current form path, preserving the actual booking assertions. No tests are skipped and no GitHub Actions configuration changes are made.

GitHub CI is manual; local results are reported separately from remote CI.

Final local verification (2026-10-24): 27 distinct backend cases passed (26-case scheduling/access run plus the added owner-transfer regression; the final six-case ownership suite passed). 25 distinct browser cases passed (24 in the combined run and the corrected downstream booking case passed on its targeted rerun). All 49 frontend unit tests passed, as did TypeScript, production build and Pint on the three changed PHP files. No skips were added. Existing PDF import/bundle-size warnings remain unrelated. The first combined browser run exposed the stale removed-modal test and a fixture date/branch assumption; the actual API and booking assertions were preserved while setup was moved to the current inline form and an unscheduled future date.
