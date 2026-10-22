# Roles / Reception follow-up

This is a small follow-up fix; main now contains PR #23. The PR targets main and contains only this follow-up. No clinical backend is added. The original worktree's eight unrelated files are excluded.

## Findings

`/clinic/permissions` already returns the distinct booking, patient, visit and queue permission codes. The reception section has only `reception.view` because those actions belong to their existing sections. The former text-based capture guard did not express all combined requirements. Reception handlers and buttons now use explicit shared requirements; Settings explains their codes and labels. Booking an existing patient does not authorize creating a new one. Check-in also requires visit/queue creation. Server APIs retain their live permission, tenant and branch checks. Reception's existing sample data/actions remain clearly marked local.

The new-role draft was blank, but `saved` still pointed at the previous protected role, leaving the protected-role notice visible. In create mode, `saved` is now absent throughout the editor; cancel restores the selection.

Normal clinic provisioning currently creates a protected CEO role only. `blusser:access-demo` explicitly adds Registrator, Shifokor and Kassir in **blusser_test only**. It does not create Administrator, Laborant or UZI mutaxassisi. This is code/test-environment evidence, not a claim about the user's local database.

`blusser:clinic-roles` is read-only by default and uses the runtime connection, real authentication and TenantContext. Explicit `--provision` requires the protected CEO identity plus role creation/delegation authority and uses existing governance commands. It fills missing Administrator, Registrator, Shifokor, Laborant, UZI mutaxassisi and Kassir templates. Existing canonical codes or normalized matching names (including custom roles) are preserved with all grants/assignments. No account is created or assigned a role. No localStorage is imported. Completed roles survive a partial failure; repeating the command fills the remainder. Database unique constraints handle concurrent insertion; the loser re-reads the existing role.

Initial roles are provisioned explicitly after the CEO completes activation/password change; merely reading Settings never mutates roles. These grants use existing permissions and do not turn demo clinical modules into real APIs.

## User's local environment

Before this command is installed, a local Docker database owner can inspect the user's existing database without mutations:

```sh
cd ~/projects/Blusser
docker compose exec -T -u postgres db psql -U postgres -d blusser -v ON_ERROR_STOP=1 -c "BEGIN READ ONLY; SELECT t.id AS clinic_id,t.slug,t.name AS clinic,r.id AS role_id,r.code,r.name AS role,r.active,r.protected FROM tenants t LEFT JOIN tenant_roles r ON r.tenant_id=t.id WHERE t.name ILIKE '%Shifo%' ORDER BY t.name,r.name; COMMIT;"
```

The database name/service match this project's Compose defaults. This is an explicit owner-only local diagnostic, not an API bypass added to the application.

After checking out this fix (or applying it after PR #23), rebuild the backend image so the new command exists:

```sh
cd ~/projects/Blusser
docker compose build backend
# Replace YOUR_LOGIN with the existing clinic CEO/admin login. Password is hidden.
docker compose run --rm backend php artisan blusser:clinic-roles --actor=YOUR_LOGIN
```

The command prints accessible clinic UUIDs, names and actual server roles. Send that output to identify Shifo Med Klinikasi; no password/session value is printed. Accounts without whole-clinic role viewing authority are denied. This command does not change roles.

Only if missing templates should be added, use the UUID printed above and the activated CEO account:

```sh
docker compose run --rm backend php artisan blusser:clinic-roles --actor=YOUR_LOGIN --tenant=bb799c8d-0058-4eba-a474-83142e47438d --provision --only=registrator,laborant,uzi_specialist
docker compose run --rm backend php artisan blusser:clinic-roles --actor=YOUR_LOGIN --tenant=bb799c8d-0058-4eba-a474-83142e47438d
```

A colliding custom code/name is reported preserved, not overwritten or merged. Existing roles may therefore intentionally differ from the default template grants. Review them separately in Settings.

The user confirmed clinic Shifo Med Klinikasi (`blusser-local`, `bb799c8d-0058-4eba-a474-83142e47438d`) and existing CEO, Kassir and Shifokor kabineti roles. Their database has not been accessed from this environment. The recommended selected provisioning above excludes those roles and Administrator entirely. Even an explicit doctor/cashier selection preserves matching custom roles; `Shifokor kabineti` is recognized as the existing doctor-role name. `--only` accepts template codes administrator, registrator, doctor, laborant, uzi_specialist, cashier.

## Real API evidence in the isolated test environment

A real Sanctum session against localhost:8000 returned only `reception.view` in the reception section, while patients.create / appointments.create / appointments.check_in / visits.create / queues.create were present in their own sections. The existing test-only access seed returned CEO, Registrator, Shifokor and Kassir. Selected CLI provisioning reused Registrator and added Laborant/UZI only; a second API read confirmed all four old role objects (IDs, grants, versions, protection and assignment counts) were unchanged. Logs: `/tmp/roles-fix-api-before.json`, `/tmp/roles-fix-api-after.json`, `/tmp/roles-fix-cli-provision.log`. These are not observations of the user's machine.
