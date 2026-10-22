import { test } from "node:test";
import assert from "node:assert/strict";
import {
  createRolesState,
  permissionSections,
  allCodes,
  assigned,
  canDemo,
  employeeCanDemo,
  setPermission,
  saveRole,
  deleteRole,
  validateState,
  migrateState,
  commitRoles,
  loadRoles,
} from "../src/mocks/settingsRoles.ts";
const copy = (x) => JSON.parse(JSON.stringify(x));
const fresh = () => ({
  id: "custom",
  name: "Yordamchi",
  note: "",
  permissions: ["patients.view"],
});
test("six stable roles, real employee counts, no job roles; cashier exact 4/6 and separate lab/UZI", () => {
  const s = createRolesState();
  assert.equal(s.roles.length, 6);
  assert.equal(s.employees.length, 24);
  const r = s.roles.find((r) => r.id === "cashier");
  assert.equal(r.permissions.length, 4);
  assert(canDemo(r, "demo.cashier.pay"));
  assert(!canDemo(r, "demo.cashier.refund"));
  assert.equal(
    assigned(s, "cashier"),
    s.employees.filter((e) => e.role === "Kassir").length,
  );
  const e = s.employees.find((e) => e.roleId === "cashier");
  assert(employeeCanDemo(s, e, "demo.cashier.pay"));
  setPermission(r, "demo.cashier.pay", false);
  assert(!employeeCanDemo(s, e, "demo.cashier.pay"));
  assert(!employeeCanDemo(s, { ...e, access: false }, "demo.cashier.view"));
  assert(!s.employees.find((e) => e.position === "Qorovul").roleId);
  assert(permissionSections.some((s) => s.id === "laboratory"));
  assert(permissionSections.some((s) => s.id === "diagnostics"));
  assert(!allCodes.includes("demo.cashier.delete"));
  assert(!allCodes.includes("employees.delete"));
});
test("view dependencies are destructive and do not revive children, unknown denied", () => {
  const r = fresh();
  setPermission(r, "patients.update", true);
  assert(canDemo(r, "patients.update"));
  setPermission(r, "patients.view", false);
  assert.deepEqual(r.permissions, []);
  setPermission(r, "patients.update", true);
  assert.deepEqual(r.permissions, []);
  setPermission(r, "patients.view", true);
  assert(!canDemo(r, "patients.update"));
  assert(!canDemo(r, "unknown.view"));
});
test("create validation, normalized duplicate names, cancel drafts and identity-preserving rename", () => {
  const s = createRolesState(),
    original = copy(s);
  const r = fresh();
  saveRole(s, r);
  for (const name of ["", "   ", "  kAsSiR  "])
    assert.throws(() => saveRole(copy(s), { ...r, id: "other", name }));
  assert.throws(() =>
    saveRole(copy(s), { ...r, id: "other", name: "Boshqa", permissions: [] }),
  );
  const c = s.roles.find((r) => r.id === "cashier");
  c.name = "Hisobchi";
  saveRole(s, c);
  assert.equal(assigned(s, c.id), assigned(original, c.id));
  assert.equal(original.roles.find((r) => r.id === "cashier").name, "Kassir");
});
test("delete blocks assigned/inactive and protected; last active manager cannot be disabled or reassigned", () => {
  const s = createRolesState();
  assert.throws(() => deleteRole(copy(s), "administrator"));
  assert.throws(() => deleteRole(copy(s), "cashier"));
  const next = copy(s);
  for (const e of next.employees)
    if (e.roleId === "administrator") e.access = false;
  assert.throws(() => validateState(next));
  const admin = copy(s.roles[0]);
  setPermission(admin, "roles.view", false);
  assert.throws(() => saveRole(copy(s), admin));
  saveRole(s, fresh());
  const before = copy(s.roles.filter((r) => r.id !== "custom"));
  deleteRole(s, "custom");
  assert.deepEqual(s.roles, before);
});
test("legacy employee role labels migrate without loss; IDs survive role rename", () => {
  const s = createRolesState();
  for (const e of s.employees) delete e.roleId;
  const migrated = migrateState(s);
  assert.equal(migrated.employees[0].roleId, "doctor");
  assert.equal(migrated.employees[0].schedule.days.length, 7);
  const renamed = copy(migrated);
  renamed.roles.find((r) => r.id === "doctor").name = "Doktor";
  assert.equal(migrateState(renamed).employees[0].roleId, "doctor");
});
test("atomic storage, revision conflict, bad JSON and no password/session replacement", () => {
  let data = null;
  const storage = { getItem: () => data, setItem: (_, v) => (data = v) };
  const s = loadRoles(storage, "test");
  const next = commitRoles(s, storage, "test", (d) => saveRole(d, fresh()));
  assert.equal(loadRoles(storage, "test").roles.length, 7);
  assert.equal(next.revision, 1);
  assert.equal(s.roles.length, 6);
  assert.throws(() => commitRoles(s, storage, "test", () => {}));
  const before = data;
  assert.throws(() =>
    commitRoles(
      next,
      {
        getItem: () => data,
        setItem: () => {
          throw Error("quota");
        },
      },
      "test",
      (d) => deleteRole(d, "custom"),
    ),
  );
  assert.equal(data, before);
  assert(!data.includes("password"));
  assert.throws(() =>
    loadRoles({ getItem: () => "{bad", setItem: () => {} }, "test"),
  );
});

test("explicit unassigned legacy jobs never gain a later same-named role on reload", () => {
  const s = createRolesState();
  const guard = s.employees.find((e) => e.position === "Qorovul");
  assert.equal(guard.roleId, "");
  saveRole(s, { ...fresh(), id: "guard", name: "Qorovul" });
  assert.equal(
    migrateState(s).employees.find((e) => e.id === guard.id).roleId,
    "",
  );
});

test("page view governs all related category, reception, schedule and department subgroups", () => {
  const s = createRolesState();
  const r = s.roles[0];
  for (const [parent, child] of [
    ["services.view", "demo.categories.create"],
    ["reception.view", "demo.reception_actions.book"],
    ["employees.view", "demo.employee_schedule.update"],
    ["rooms.view", "demo.departments.update"],
  ]) {
    assert(canDemo(r, child));
    setPermission(r, parent, false);
    assert(!canDemo(r, child));
    assert(!r.permissions.includes(child));
    const section = permissionSections.find((s) =>
      s.permissions.some((p) => p.code === child),
    );
    setPermission(r, section.permissions[0].code, true);
    assert(!r.permissions.includes(section.permissions[0].code));
    setPermission(r, parent, true);
    assert(!canDemo(r, child));
  }
});
