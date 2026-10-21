import { test } from "node:test";
import assert from "node:assert/strict";
import {
  createLabStore,
  internalLabs,
  filterLabs,
  pendingTypes,
  collectSample,
  nextSampleId,
  startLab,
  cloneLabDraft,
  saveLab,
  submitLab,
  returnLab,
  confirmLab,
  amendLab,
  validateRows,
  rowFlag,
  resolveReference,
  labTests,
  sampleLabCapabilities as caps,
} from "../src/mocks/laboratory.ts";
import { specimenBarcode } from "../src/components/laboratory/barcode.ts";
const actor = { id: "a", name: "Collector", specialty: "Demo" },
  tech = { id: "b", name: "Technician", specialty: "Demo" },
  reviewer = { id: "c", name: "Reviewer", specialty: "Demo" },
  now = new Date("2026-10-20T12:00:00");
function sample() {
  const s = createLabStore("2026-10-20"),
    r = s.referrals[0],
    j = s.jobs[r.id];
  collectSample(s, r, "Venoz qon", "2026-10-20T11:00", "", actor, caps, now);
  return { s, r, j };
}
function started() {
  const x = sample();
  startLab(x.j, x.r, tech, caps, "2026-10-20T11:05");
  return x;
}
function filled() {
  const x = started();
  x.j.draft.rows.forEach(
    (r, i) => (r.value = String([128, 4.4, 11.2, 250, 39, 89, 12][i])),
  );
  return x;
}
test("one source, internal queue counts, combined filters, missing samples", () => {
  const s = createLabStore("2026-10-20");
  assert.equal(internalLabs(s).length, 8);
  const f = {
    date: "2026-10-20",
    query: "",
    testId: "",
    doctorId: "",
    branchId: "",
    status: "",
  };
  assert.equal(filterLabs(s, { ...f, status: "new" }).length, 2);
  assert.equal(
    filterLabs(s, {
      ...f,
      query: "ALIYEVA",
      testId: "cbc",
      branchId: "main",
      doctorId: "karimov",
    }).length,
    1,
  );
  assert.equal(filterLabs(s, { ...f, date: "2026-10-21" }).length, 0);
  assert.equal(s.jobs[s.referrals[0].id].samples.length, 0);
});
test("collection is atomic, unique, timestamp validated; mixed specimen types remain separate", () => {
  const s = createLabStore("2026-10-20"),
    r = s.referrals[0],
    j = s.jobs[r.id];
  r.testIds = ["cbc", "urine"];
  assert.throws(() =>
    collectSample(s, r, "Venoz qon", "2026-10-21T11:00", "", actor, caps, now),
  );
  assert.equal(j.samples.length, 0);
  assert.throws(() =>
    collectSample(s, r, "Venoz qon", "2026-10-20T08:00", "", actor, caps, now),
  );
  const first = collectSample(
    s,
    r,
    "Venoz qon",
    "2026-10-20T11:00",
    "",
    actor,
    caps,
    now,
  );
  assert.equal(j.status, "new");
  assert.deepEqual(first.testIds, ["cbc"]);
  assert.deepEqual(pendingTypes(r, j), ["Siydik"]);
  assert.throws(() =>
    collectSample(s, r, "Venoz qon", "2026-10-20T11:01", "", actor, caps, now),
  );
  const second = collectSample(
    s,
    r,
    "Siydik",
    "2026-10-20T11:02",
    "",
    actor,
    caps,
    now,
  );
  assert.notEqual(first.id, second.id);
  assert.equal(j.status, "collected");
  assert.notEqual(nextSampleId(s), second.id);
  assert.throws(() =>
    collectSample(s, r, "Siydik", "2026-10-20T11:03", "", actor, caps, now),
  );
  assert.equal(j.samples.length, 2);
});
test("partial draft resumes without changing start; wrong formats fail; out of range does not", () => {
  const { j, r } = started();
  startLab(j, r, tech, caps, "later");
  assert.equal(j.startedAt, "2026-10-20T11:05");
  let d = cloneLabDraft(j.draft);
  d.rows[0].value = "118";
  d.files = [
    {
      id: "f",
      name: "x.pdf",
      size: 4,
      mime: "application/pdf",
      blob: new Blob(["data"]),
    },
  ];
  saveLab(j, d, tech, caps);
  assert.equal(j.draft.files[0].blob, d.files[0].blob);
  assert.throws(() => submitLab(j, d, tech, caps));
  assert.equal(j.status, "in_progress");
  d.rows.forEach(
    (r, i) => (r.value = String([118, 4.4, 11.2, 250, 39, 89, 12][i])),
  );
  assert.equal(rowFlag(d.rows[0]), "low");
  assert.equal(rowFlag(d.rows[2]), "high");
  assert.deepEqual(validateRows(d), {});
  d.rows[1].value = "4,4";
  assert.deepEqual(validateRows(d), {});
  d.rows[1].value = "4abc";
  assert.ok(validateRows(d)[d.rows[1].id]);
  assert.equal(j.versions.length, 0);
});
test("review return reason and audit survive edits; publication frozen and duplicate guarded", () => {
  const { j } = filled();
  submitLab(j, j.draft, tech, caps, "submitted");
  assert.equal(j.status, "review");
  assert.throws(() => returnLab(j, " ", reviewer, caps));
  returnLab(j, "Check value", reviewer, caps, "returned");
  assert.equal(j.status, "in_progress");
  assert.equal(j.draft.returns[0].actor.id, "c");
  assert.equal(j.draft.rows[0].value, "128");
  submitLab(j, j.draft, tech, caps, "resubmitted");
  const v = confirmLab(j, reviewer, caps, "confirmed");
  assert.equal(j.status, "completed");
  assert.equal(v.enteredBy.id, "b");
  assert.equal(v.confirmedBy.id, "c");
  assert.equal(v.returns[0].reason, "Check value");
  assert.ok(Object.isFrozen(v.rows));
  assert.ok(Object.isFrozen(v.rows[0].reference));
  assert.throws(() => confirmLab(j, reviewer, caps));
  assert.equal(j.versions.length, 1);
});
test("amendment single draft preserves current report through re-review and creates immutable v2", () => {
  const { j } = filled();
  submitLab(j, j.draft, tech, caps);
  const v1 = confirmLab(j, reviewer, caps);
  assert.throws(() => amendLab(j, "", tech, caps));
  const d = amendLab(j, "Correction", tech, caps);
  d.rows[0].value = "140";
  saveLab(j, d, tech, caps);
  assert.equal(amendLab(j, "other", tech, caps).rows[0].value, "140");
  assert.equal(j.status, "completed");
  assert.equal(j.versions.at(-1), v1);
  assert.throws(() => confirmLab(j, reviewer, caps));
  submitLab(j, j.draft, tech, caps);
  assert.equal(j.status, "completed");
  returnLab(j, "Double-check", reviewer, caps);
  assert.equal(j.status, "completed");
  submitLab(j, j.draft, tech, caps);
  const v2 = confirmLab(j, reviewer, caps);
  assert.equal(v2.number, 2);
  assert.equal(v2.reason, "Correction");
  assert.equal(v1.rows[0].value, "128");
  assert.equal(j.versions.length, 2);
});
test("reference qualification, missing/ambiguous norms and qualitative values never get false numeric flags", () => {
  const r = createLabStore("2026-10-20").referrals[0],
    i = labTests[0].indicators[0];
  assert.equal(resolveReference(i, r, "demo-cbc", "2026-10-20").min, 120);
  assert.equal(
    resolveReference(i, { ...r, gender: "Erkak" }, "demo-cbc", "2026-10-20")
      .min,
    130,
  );
  assert.equal(
    resolveReference(i, { ...r, dob: "2020-01-01" }, "demo-cbc", "2026-10-20"),
    null,
  );
  assert.equal(resolveReference(i, r, "unknown", "2026-10-20"), null);
  assert.equal(
    resolveReference(
      { ...i, references: [...i.references, i.references[0]] },
      r,
      "demo-cbc",
      "2026-10-20",
    ),
    null,
  );
  const base = {
    id: "x",
    testId: "urine",
    required: true,
    name: "x",
    unit: "",
    reference: null,
    value: "5",
    kind: "numeric",
  };
  assert.equal(rowFlag(base), "unknown");
  assert.equal(
    rowFlag({
      ...base,
      kind: "qualitative",
      value: "Aniqlanmadi",
      options: ["Aniqlanmadi"],
    }),
    "qualitative",
  );
});
test("denied sample capabilities cannot collect/start/save/submit/return/confirm/amend", () => {
  const s = createLabStore("2026-10-20"),
    r = s.referrals[0];
  assert.throws(() =>
    collectSample(
      s,
      r,
      "Venoz qon",
      "2026-10-20T11:00",
      "",
      actor,
      { ...caps, collect: false },
      now,
    ),
  );
  const { j, r: ref } = sample();
  assert.throws(() => startLab(j, ref, tech, { ...caps, start: false }));
  startLab(j, ref, tech, caps);
  assert.throws(() => saveLab(j, j.draft, tech, { ...caps, draft: false }));
  assert.throws(() => submitLab(j, j.draft, tech, { ...caps, submit: false }));
  const f = filled().j;
  submitLab(f, f.draft, tech, caps);
  assert.throws(() =>
    returnLab(f, "reason", reviewer, { ...caps, review: false }),
  );
  assert.throws(() => confirmLab(f, reviewer, { ...caps, confirm: false }));
  assert.equal(f.versions.length, 0);
  confirmLab(f, reviewer, caps);
  assert.throws(() => amendLab(f, "reason", tech, { ...caps, amend: false }));
});
test("Code 39 specimen barcode matches its printed ID, including start/stop and quiet zones", () => {
  const id = "NM-00109",
    { path, width } = specimenBarcode(id),
    bars = [...path.matchAll(/M(\d+) 0h(\d+)v60h-\d+z/g)].map((m) => ({
      x: +m[1],
      w: +m[2],
    }));
  assert.equal(bars[0].x, 10);
  assert.equal(bars.length, (id.length + 2) * 5);
  assert.ok(width - (bars.at(-1).x + bars.at(-1).w) >= 10);
  const expected = [
    0x094, 0x013, 0x142, 0x085, 0x034, 0x034, 0x121, 0x034, 0x064, 0x094,
  ];
  let x = 10;
  for (let c = 0; c < expected.length; c++) {
    for (let b = 8; b >= 0; b--) {
      const w = expected[c] & (1 << b) ? 3 : 1;
      if (b % 2 === 0) {
        const bar = bars.shift();
        assert.deepEqual(bar, { x, w });
      }
      x += w;
    }
    x++;
  }
  assert.throws(() => specimenBarcode("wrong-id"));
});
