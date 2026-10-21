import { test } from "node:test";
import assert from "node:assert/strict";
import {
  cashierConfig,
  createCashState,
  validateCashState,
  beginShift,
  openShift,
  closeShift,
  shiftTotals,
  invoiceTotals,
  pay,
  refund,
  refundAvailable,
  allocate,
  amount,
  setQuantity,
  addServices,
  filterHistory,
  historyCsv,
  commitCash,
  loadCash,
} from "../src/mocks/cashier.ts";
const c = cashierConfig.cashier;
const discount = { kind: "amount", value: 0 };
function setup(opening = 200000) {
  const s = createCashState("2026-10-21");
  beginShift(s, opening, "", c, "2026-10-21T17:00:00");
  const i = s.invoices[0];
  return { s, i, sh: openShift(s) };
}
function input(i, override = {}) {
  return {
    invoiceId: i.id,
    token: "one",
    discount,
    partial: false,
    amount: 330000,
    cash: 330000,
    card: 0,
    tendered: 350000,
    terminalConfirmed: false,
    ...override,
  };
}
test("consistent fixtures: four pending, linked immutable history; seed closing cash/card equation", () => {
  const s = createCashState("2026-10-21");
  validateCashState(s);
  assert.equal(
    s.invoices.filter((i) => invoiceTotals(s, i).debt > 0).length,
    4,
  );
  assert.equal(s.payments.length, 7);
  assert.equal(s.refunds.length, 4);
  assert.deepEqual(shiftTotals(s, s.shifts[0]), {
    cash: 650000,
    card: 600000,
    cashRefund: 165000,
    cardRefund: 120000,
    expected: 685000,
    cardNet: 480000,
  });
  assert.equal(openShift(s), undefined);
  assert.equal(cashierConfig.pin, "1122");
});
test("whole-so‘m parsing, discounts and deterministic allocations conserve money", () => {
  assert.equal(amount("350 000"), 350000);
  for (const x of [
    "-1",
    "1.5",
    "NaN",
    "Infinity",
    "1e3",
    "",
    Number.MAX_SAFE_INTEGER,
  ])
    assert.throws(() => amount(x));
  assert.deepEqual(allocate(2, [1, 1, 1]), [1, 1, 0]);
  assert.equal(
    allocate(999999999999, [123456789, 987654321]).reduce((a, b) => a + b),
    999999999999,
  );
  const { s, i } = setup();
  assert.equal(
    invoiceTotals(s, i, { kind: "percent", value: 10 }).total,
    297000,
  );
  assert.throws(() => invoiceTotals(s, i, { kind: "percent", value: 101 }));
  assert.throws(() => invoiceTotals(s, i, { kind: "amount", value: 330001 }));
});
test("cash tender/change is separate from income; duplicate tokens create one payment and snapshot never mutates", () => {
  const { s, i, sh } = setup();
  const p = pay(s, input(i), c);
  assert.equal(p.change, 20000);
  assert.equal(p.amount, 330000);
  assert.equal(shiftTotals(s, sh).expected, 530000);
  assert.equal(invoiceTotals(s, i).debt, 0);
  assert.equal(pay(s, input(i), c), p);
  assert.equal(s.payments.filter((p) => p.token === "one").length, 1);
  assert.throws(() => setQuantity(s, i.id, i.lines[0].id, 2));
  assert.throws(() => addServices(s, i.id, ["service-8"]));
  const snapshot = JSON.stringify(p);
  assert.throws(() =>
    pay(
      s,
      input(i, { token: "two", discount: { kind: "amount", value: 1 } }),
      c,
    ),
  );
  assert.equal(JSON.stringify(p), snapshot);
});
test("card terminal check, mixed allocation, partial and next payment debt are exact", () => {
  const { s, i, sh } = setup();
  assert.throws(() =>
    pay(s, input(i, { cash: 0, card: 330000, tendered: 0 }), c),
  );
  assert.throws(() =>
    pay(
      s,
      input(i, {
        cash: 100000,
        card: 220000,
        tendered: 100000,
        terminalConfirmed: true,
      }),
      c,
    ),
  );
  const p = pay(
    s,
    input(i, {
      partial: true,
      amount: 200000,
      cash: 100000,
      card: 100000,
      tendered: 110000,
      terminalConfirmed: true,
    }),
    c,
  );
  assert.equal(p.change, 10000);
  assert.equal(invoiceTotals(s, i).debt, 130000);
  assert.throws(() =>
    pay(
      s,
      input(i, {
        token: "over",
        amount: 130001,
        cash: 130001,
        tendered: 130001,
      }),
      c,
    ),
  );
  pay(
    s,
    input(i, {
      token: "two",
      amount: 130000,
      cash: 0,
      card: 130000,
      tendered: 0,
      terminalConfirmed: true,
    }),
    c,
  );
  assert.equal(invoiceTotals(s, i).debt, 0);
  assert.equal(shiftTotals(s, sh).cash, 100000);
  assert.equal(shiftTotals(s, sh).card, 230000);
});
test("discounted partial payment refunds use line paid allocations; cancellation avoids artificial new debt", () => {
  const { s, i } = setup();
  const p = pay(
    s,
    input(i, {
      discount: { kind: "percent", value: 10 },
      partial: true,
      amount: 180000,
      cash: 180000,
      tendered: 180000,
    }),
    c,
  );
  const a = refundAvailable(s, p),
    line = a.lines.find((l) => l.serviceId === "service-7");
  assert.equal(p.total, 297000);
  assert.ok(line.available < 108000);
  const before = invoiceTotals(s, i).debt;
  const req = {
    paymentId: p.id,
    token: "refund",
    lines: [{ id: line.id, amount: line.available }],
    cash: line.available,
    card: 0,
    reason: "cancel",
    note: "",
  };
  const r = refund(s, req, c);
  assert.equal(refund(s, req, c), r);
  assert.equal(s.refunds.filter((r) => r.token === "refund").length, 1);
  assert.equal(p.amount, 180000);
  assert.equal(
    refundAvailable(s, p).lines.find((l) => l.id === line.id).available,
    0,
  );
  assert.equal(
    invoiceTotals(s, i).debt,
    Math.max(0, 297000 - 108000 - (180000 - line.available)),
  );
  assert.ok(invoiceTotals(s, i).debt <= before);
  assert.throws(() => refund(s, { ...req, token: "again" }, c));
});
test("refund enforces remaining line/method/drawer limits and other reason note; original payment preserved", () => {
  const { s, i, sh } = setup(0);
  const p = pay(
    s,
    input(i, {
      cash: 100000,
      card: 230000,
      tendered: 100000,
      terminalConfirmed: true,
    }),
    c,
  );
  const x = {
    paymentId: p.id,
    token: "r",
    lines: [{ id: i.lines[1].id, amount: 120000 }],
    cash: 100000,
    card: 20000,
    reason: "cancel",
    note: "",
  };
  const original = JSON.stringify(p);
  assert.throws(() => refund(s, { ...x, cash: 120000, card: 0 }, c));
  assert.throws(() => refund(s, { ...x, reason: "other", note: "" }, c));
  assert.throws(() =>
    refund(s, { ...x, lines: [{ id: i.lines[1].id, amount: 120001 }] }, c),
  );
  refund(s, x, c);
  assert.equal(JSON.stringify(p), original);
  assert.equal(invoiceTotals(s, i).debt, 0);
  assert.equal(shiftTotals(s, sh).expected, 0);
  assert.equal(shiftTotals(s, sh).cardNet, 210000);
  assert.throws(() => refund(s, { ...x, token: "twice" }, c));
});
test("shift closing zero/surplus/shortage, required variance reason; new shift starts independent", () => {
  for (const delta of [0, -1, 1]) {
    const { s, i, sh } = setup();
    pay(s, input(i), c);
    const expected = shiftTotals(s, sh).expected;
    if (delta) assert.throws(() => closeShift(s, expected + delta, "", c));
    closeShift(s, expected + delta, delta ? "Count difference" : "", c);
    assert.equal(sh.actual, expected + delta);
    assert.equal(sh.closing.expected, 530000);
    assert.equal(openShift(s), undefined);
    assert.throws(() => pay(s, input(i, { token: "closed" }), c));
    beginShift(s, 0, "new", c);
    assert.deepEqual(shiftTotals(s, openShift(s)), {
      cash: 0,
      card: 0,
      cashRefund: 0,
      cardRefund: 0,
      expected: 0,
      cardNet: 0,
    });
  }
});
test("write-before-publish, quota and stale-tab failures preserve current ledger; PIN verification not persisted", () => {
  const disk = new Map(),
    port = {
      getItem: (k) => disk.get(k) ?? null,
      setItem: (k, v) => disk.set(k, v),
    };
  let s = loadCash(port, "demo");
  const initial = JSON.stringify(s);
  assert.throws(() =>
    commitCash(
      s,
      {
        getItem: port.getItem,
        setItem: () => {
          throw Error("quota");
        },
      },
      "demo",
      (x) => beginShift(x, 0, "", c),
    ),
  );
  assert.equal(JSON.stringify(s), initial);
  const saved = commitCash(s, port, "demo", (x) =>
    beginShift(x, 200000, "", c),
  );
  assert.equal(openShift(saved).opening, 200000);
  assert.equal(openShift(loadCash(port, "demo")).id, openShift(saved).id);
  assert.ok(!disk.get("demo").includes("1122"));
  assert.ok(!disk.get("demo").includes("verified"));
  assert.throws(() => commitCash(s, port, "demo", () => {}));
});
test("catalog add avoids duplicates; deliberate quantity change and filtered CSV use whole filtered source", () => {
  const { s, i } = setup();
  addServices(s, i.id, ["service-8", "service-8"]);
  assert.equal(i.lines.filter((l) => l.serviceId === "service-8").length, 1);
  setQuantity(s, i.id, i.lines[0].id, 2);
  assert.equal(invoiceTotals(s, i).gross, 550000);
  const rows = filterHistory(s.payments, {
    from: "2026-10-21",
    to: "2026-10-21",
    query: "",
    method: "mixed",
    cashier: "",
  });
  assert.equal(rows.length, 1);
  assert.equal(rows[0].cash, 100000);
  assert.ok(historyCsv(rows).includes("230000"));
  assert.equal(
    filterHistory(s.payments, {
      from: "2026-10-22",
      to: "2026-10-22",
      query: "",
      method: "",
      cashier: "",
    }).length,
    0,
  );
});
