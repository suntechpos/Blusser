import { test } from "node:test";
import assert from "node:assert/strict";
import { ContextGate, IntentKeys } from "../node_modules/.tests/context.js";
test("late previous-clinic work cannot commit after switching", async () => {
  const gate = new ContextGate();
  const old = gate.capture();
  let release;
  const slow = new Promise((r) => (release = r));
  let displayed = "";
  const commit = slow.then(() => {
    if (old.current()) displayed = "previous clinic";
  });
  gate.reset();
  release();
  await commit;
  assert.equal(displayed, "");
  assert.equal(old.signal.aborted, true);
  assert.equal(gate.capture().signal.aborted, false);
});
test("rapid clinic/branch changes invalidate every old generation", () => {
  const gate = new ContextGate();
  const a = gate.capture();
  gate.reset();
  const b = gate.capture();
  gate.reset();
  assert.equal(a.current(), false);
  assert.equal(b.current(), false);
  assert.equal(gate.capture().current(), true);
});
test("network retry retains the write intent; changed input gets a separate key", () => {
  const keys = new IntentKeys();
  const first = keys.get("POST patient payload A");
  assert.equal(keys.get("POST patient payload A"), first);
  assert.notEqual(keys.get("POST patient payload B"), first);
  keys.complete("POST patient payload A");
  assert.notEqual(keys.get("POST patient payload A"), first);
});
test("tenant invalidation discards intent references from previous tenant", () => {
  const keys = new IntentKeys();
  const first = keys.get("same request");
  keys.clear();
  assert.notEqual(keys.get("same request"), first);
});
