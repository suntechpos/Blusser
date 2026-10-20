import { test } from "node:test";
import assert from "node:assert/strict";
import {
  createDiagnosticsStore,
  startExam,
  saveDraft,
  confirmResult,
  amendResult,
  cloneDraft,
  validateResult,
  filteredReferrals,
  internalReferrals,
  sampleCapabilities,
  diagnosticTemplates,
} from "../src/mocks/diagnostics.ts";
const actor = { id: "tester", name: "Test user", specialty: "Demo" };
const caps = sampleCapabilities;
function started() {
  const s = createDiagnosticsStore("2026-10-20");
  const e = s.exams["exam-125"];
  startExam(e, actor, caps, "2026-10-20T10:32:00");
  return { s, e };
}
test("internal queue excludes external/foreign; combined filters and empty date", () => {
  const s = createDiagnosticsStore("2026-10-20");
  assert.equal(internalReferrals(s).length, 8);
  const f = {
    date: "2026-10-20",
    query: "",
    testId: "",
    doctorId: "",
    branchId: "",
    status: "",
  };
  assert.equal(filteredReferrals(s, f).length, 8);
  assert.equal(filteredReferrals(s, { ...f, status: "new" }).length, 3);
  assert.equal(
    filteredReferrals(s, {
      ...f,
      query: "aliyeva",
      testId: "abdomen",
      doctorId: "karimov",
      branchId: "main",
    }).length,
    1,
  );
  assert.equal(filteredReferrals(s, { ...f, date: "2026-10-21" }).length, 0);
  assert.equal(
    s.referrals.find((r) => r.number === "YL-00129").scheduledAt,
    null,
  );
});
test("start once, partial draft and bytes retained; confirmation atomic and duplicate guarded", () => {
  const { e } = started();
  startExam(e, actor, caps, "later");
  assert.equal(e.startedAt, "2026-10-20T10:32:00");
  let d = cloneDraft(e.draft);
  d.description = "<p>Partial</p>";
  d.files = [
    {
      id: "file",
      name: "a.pdf",
      mime: "application/pdf",
      blob: new Blob(["test"]),
      size: 4,
    },
  ];
  saveDraft(e, d, caps);
  assert.equal(e.draft.files[0].blob, d.files[0].blob);
  assert.throws(() => confirmResult(e, d, actor, caps));
  assert.equal(e.status, "in_progress");
  assert.equal(e.versions.length, 0);
  d.conclusion = "<p>Test conclusion</p>";
  const v = confirmResult(e, d, actor, caps, "confirmed");
  assert.equal(v.author.id, "tester");
  assert.equal(e.status, "completed");
  assert.equal(e.draft, null);
  assert.ok(Object.isFrozen(v));
  assert.ok(Object.isFrozen(v.files));
  assert.throws(() => confirmResult(e, d, actor, caps));
  assert.equal(e.versions.length, 1);
});
test("amendment keeps published version and single pending draft; history immutable", () => {
  const { e } = started();
  const d = cloneDraft(e.draft);
  d.description = "First";
  d.conclusion = "Conclusion";
  const v1 = confirmResult(e, d, actor, caps);
  assert.throws(() => amendResult(e, " ", actor, caps));
  const next = amendResult(e, "Correction", actor, caps);
  next.description = "Second";
  saveDraft(e, next, caps);
  assert.equal(e.status, "completed");
  assert.equal(e.versions.at(-1), v1);
  assert.equal(v1.description, "First");
  assert.equal(amendResult(e, "Different", actor, caps).description, "Second");
  assert.equal(e.draft.number, 2);
  const v2 = confirmResult(e, e.draft, actor, caps);
  assert.equal(v2.reason, "Correction");
  assert.equal(e.versions.length, 2);
  assert.equal(e.versions[0].description, "First");
});
test("empty rich text/placeholders and denied sample capabilities cannot publish", () => {
  const { e } = started();
  for (const t of diagnosticTemplates) {
    assert.ok(validateResult({ ...e.draft, ...t }).description);
    assert.ok(validateResult({ ...e.draft, ...t }).conclusion);
  }
  assert.ok(
    validateResult({ ...e.draft, description: "<p><br></p>" }).description,
  );
  assert.throws(() => saveDraft(e, e.draft, { ...caps, draft: false }));
  assert.throws(() =>
    confirmResult(
      e,
      { ...e.draft, description: "Good", conclusion: "Good" },
      actor,
      { ...caps, confirm: false },
    ),
  );
  assert.equal(e.versions.length, 0);
  const s = createDiagnosticsStore();
  assert.throws(() =>
    startExam(s.exams["exam-125"], actor, { ...caps, start: false }),
  );
});
