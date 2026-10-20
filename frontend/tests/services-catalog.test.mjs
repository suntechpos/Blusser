import { test } from "node:test";
import assert from "node:assert/strict";
import {
  createServicesCatalog,
  blankCategory,
  saveCategory,
  validateCategory,
  blankService,
  serviceDraft,
  saveService,
  validateService,
  filterServices,
  catalogCsv,
  nextCode,
} from "../src/mocks/servicesCatalog.ts";
test("categories normalize duplicates; deactivation preserves all historical services", () => {
  const c = createServicesCatalog(),
    before = structuredClone(c.services);
  assert.ok(
    validateCategory(
      { ...blankCategory(), name: "  LABORATORIYA  " },
      c.categories,
    ).name,
  );
  const added = saveCategory(c, {
    ...blankCategory(),
    name: "  Guruh  ",
    color: "purple",
  }).category;
  assert.equal(added.name, "Guruh");
  assert.equal(
    saveCategory(c, { ...added, name: "GURUH" }, added.id).category.name,
    "GURUH",
  );
  assert.ok(
    validateCategory({ ...blankCategory(), name: "guruh" }, c.categories).name,
  );
  saveCategory(c, { ...c.categories[0], active: false }, c.categories[0].id);
  assert.deepEqual(c.services, before);
  const d = blankService(c.services, c.categories);
  d.categoryId = c.categories[0].id;
  d.name = "Example";
  d.price = "0";
  assert.ok(validateService(d, c).categoryId);
  const existing = c.services[0],
    edit = serviceDraft(existing);
  edit.name = "Renamed";
  assert.deepEqual(validateService(edit, c, existing), {});
  assert.equal(saveService(c, edit, existing.id).service.code, existing.code);
});
test("functional type controls duration, lab turnaround is independent and inactive values are sanitized", () => {
  const c = createServicesCatalog(),
    d = blankService(c.services, c.categories);
  d.name = "Lab";
  d.price = "0";
  d.type = "laboratory";
  d.categoryId = "cat-1";
  d.timed = false;
  d.turnaround = "2";
  d.turnaroundUnit = "day";
  assert.deepEqual(validateService(d, c), {});
  const lab = saveService(c, d).service;
  assert.equal(lab.duration, null);
  assert.equal(lab.turnaround, 2);
  assert.equal(lab.turnaroundUnit, "day");
  const edit = serviceDraft(lab);
  edit.type = "consultation";
  edit.timed = false;
  edit.duration = "45";
  const consultation = saveService(c, edit, lab.id).service;
  assert.equal(consultation.timed, true);
  assert.equal(consultation.duration, 45);
  assert.equal(consultation.turnaround, null);
  assert.ok(validateService({ ...edit, duration: "0" }, c, lab).duration);
  assert.ok(validateService({ ...d, turnaround: "0" }, c).turnaround);
  assert.ok(validateService({ ...d, turnaround: "Infinity" }, c).turnaround);
  assert.ok(validateService({ ...d, price: "-1" }, c).price);
  assert.ok(validateService({ ...d, price: "" }, c).price);
  assert.ok(validateService({ ...d, branchIds: [] }, c).branchIds);
  assert.ok(validateService({ ...d, branchIds: ["unknown"] }, c).branchIds);
  const other = {
    ...d,
    type: "other",
    timed: false,
    turnaround: "4",
    duration: "NaN",
  };
  assert.equal(saveService(c, other).service.duration, null);
  assert.equal(saveService(c, other).service.turnaround, null);
});
test("codes are unique and immutable, including stale creation drafts", () => {
  const c = createServicesCatalog(),
    d = blankService(c.services, c.categories);
  d.name = "New";
  d.price = "5";
  const first = saveService(c, d).service,
    second = saveService(c, d).service;
  assert.equal(first.code, "XZ-025");
  assert.equal(second.code, "XZ-026");
  assert.notEqual(first.id, second.id);
  assert.equal(nextCode(c.services), "XZ-027");
  const edit = { ...serviceDraft(first), code: "XZ-001", name: "Updated" };
  assert.equal(saveService(c, edit, first.id).service.code, "XZ-025");
});
test("filtered export spans all matching records, quotes CSV and blocks spreadsheet formulas", () => {
  const c = createServicesCatalog(),
    f = { query: "", categoryId: "cat-2", branchId: "", status: "active" };
  const labs = filterServices(c, f);
  assert.equal(labs.length, 7);
  assert.equal(filterServices(c, { ...f, branchId: "andijan" }).length, 3);
  const csv = catalogCsv(labs, c.categories);
  assert.equal(csv.split("\r\n").length, 8);
  assert.ok(csv.startsWith('\uFEFF"Kod"'));
  assert.ok(csv.includes("Umumiy qon tahlili"));
  const malicious = { ...c.services[0], name: '=HYPERLINK("example")\nnext' };
  const safe = catalogCsv([malicious], c.categories);
  assert.ok(safe.includes('"\'=HYPERLINK(""example"")\nnext"'));
  assert.equal(
    filterServices(
      { categories: [], services: [] },
      { query: "", categoryId: "", branchId: "", status: "" },
    ).length,
    0,
  );
});
