/** Isolated persistent demo ledger. No real patients, bank, fiscal service or auth. */
import { createServicesCatalog } from "./servicesCatalog.ts";
import { localDate } from "./reception.ts";
export const cashierConfig = {
  pin: "1122",
  cashier: { id: "demo-kamron", name: "Kamron" },
  clinic: "Shifo Med Klinikasi",
  branch: "Asosiy filial",
  register: "Kassa №1",
  storagePrefix: "blusser.cashier.demo.v1",
} as const;
export type Method = "cash" | "card" | "mixed";
export type Cashier = { id: string; name: string };
export type Line = {
  id: string;
  serviceId: string;
  name: string;
  provider: string;
  qty: number;
  price: number;
};
export type Discount = { kind: "percent" | "amount"; value: number };
export type Invoice = {
  id: string;
  patientId: string;
  patientName: string;
  phone: string;
  date: string;
  branch: string;
  lines: Line[];
  discount: Discount;
};
export type Payment = {
  id: string;
  token: string;
  invoiceId: string;
  patientId: string;
  patientName: string;
  phone: string;
  shiftId: string;
  at: string;
  cashier: Cashier;
  amount: number;
  cash: number;
  card: number;
  tendered: number;
  change: number;
  lines: Line[];
  discount: number;
  gross: number;
  total: number;
  paidBefore: number;
  debtAfter: number;
  allocations: Record<string, number>;
};
export type Refund = {
  id: string;
  token: string;
  paymentId: string;
  invoiceId: string;
  patientId: string;
  patientName: string;
  shiftId: string;
  at: string;
  cashier: Cashier;
  amount: number;
  cash: number;
  card: number;
  lines: { id: string; name: string; amount: number }[];
  reason: "cancel" | "duplicate" | "other";
  note: string;
};
export type Cancellation = {
  id: string;
  invoiceId: string;
  lineId: string;
  amount: number;
  refundId: string;
};
export type Shift = {
  id: string;
  cashier: Cashier;
  openedAt: string;
  opening: number;
  note: string;
  closedAt: string | null;
  actual: number | null;
  closeNote: string;
  closing: ShiftTotals | null;
};
export type ShiftTotals = {
  cash: number;
  card: number;
  cashRefund: number;
  cardRefund: number;
  expected: number;
  cardNet: number;
};
export type CashState = {
  schema: 1;
  revision: number;
  invoices: Invoice[];
  payments: Payment[];
  refunds: Refund[];
  cancellations: Cancellation[];
  shifts: Shift[];
};
export const catalog = createServicesCatalog();
const MAX = 1_000_000_000_000;
export function amount(value: string | number) {
  const s = String(value).trim().replace(/\s/g, "");
  if (!/^\d+$/.test(s) || !Number.isSafeInteger(Number(s)) || Number(s) > MAX)
    throw Error("Summani 0 yoki musbat butun so‘mda kiriting.");
  return Number(s);
}
function sum(values: number[]) {
  const n = values.reduce((a, b) => a + b, 0);
  if (!Number.isSafeInteger(n) || n > MAX || n < 0)
    throw Error("Summa ruxsat etilgan chegaradan oshdi.");
  return n;
}
export const method = (x: { cash: number; card: number }): Method =>
  x.cash && x.card ? "mixed" : x.card ? "card" : "cash";
export const methodNames = { cash: "Naqd", card: "Karta", mixed: "Aralash" };
export function discountAmount(gross: number, d: Discount) {
  if (!Number.isFinite(d.value) || d.value < 0)
    throw Error("Chegirmani to‘g‘ri kiriting.");
  if (d.kind === "percent") {
    if (
      d.value > 100 ||
      Math.abs(d.value * 100 - Math.round(d.value * 100)) > 1e-6
    )
      throw Error("Chegirma 0–100% va ko‘pi bilan 2 kasr xonasida bo‘lsin.");
    return Number(
      (BigInt(gross) * BigInt(Math.round(d.value * 100)) + 5000n) / 10000n,
    );
  }
  const n = amount(d.value);
  if (n > gross) throw Error("Chegirma xizmatlar summasidan oshmasin.");
  return n;
}
/** Deterministic largest remainder allocation in whole so‘m, with integer products. */
export function allocate(total: number, weights: number[]) {
  amount(total);
  weights.forEach(amount);
  const denominator = weights.reduce((a, b) => a + BigInt(b), 0n);
  if (!denominator) {
    if (total) throw Error("Taqsimlash uchun summa yo‘q.");
    return weights.map(() => 0);
  }
  const raw = weights.map((w, i) => ({
    i,
    base: Number((BigInt(total) * BigInt(w)) / denominator),
    remainder: (BigInt(total) * BigInt(w)) % denominator,
  }));
  let remaining = total - raw.reduce((n, r) => n + r.base, 0);
  const order = [...raw].sort((a, b) =>
    a.remainder === b.remainder
      ? a.i - b.i
      : a.remainder > b.remainder
        ? -1
        : 1,
  );
  for (const r of order) {
    if (!remaining) break;
    r.base++;
    remaining--;
  }
  return raw.map((r) => r.base);
}
export function invoiceTotals(s: CashState, i: Invoice, d = i.discount) {
  const gross = sum(
    i.lines.map((l) => {
      amount(l.price);
      if (!Number.isInteger(l.qty) || l.qty < 1 || l.qty > 999)
        throw Error("Miqdor 1–999 oralig‘ida bo‘lsin.");
      return l.price * l.qty;
    }),
  );
  const discount = discountAmount(gross, d),
    net = gross - discount;
  const lineAmounts = allocate(
    net,
    i.lines.map((l) => l.price * l.qty),
  );
  const canceled = i.lines.map((l) =>
    sum(
      s.cancellations
        .filter((c) => c.invoiceId === i.id && c.lineId === l.id)
        .map((c) => c.amount),
    ),
  );
  const charges = lineAmounts.map((n, k) => Math.max(0, n - canceled[k]!));
  const paidByLine = i.lines.map(
    (l) =>
      s.payments
        .filter((p) => p.invoiceId === i.id)
        .reduce((n, p) => n + (p.allocations[l.id] ?? 0), 0) -
      s.refunds
        .filter((r) => r.invoiceId === i.id)
        .reduce(
          (n, r) =>
            n +
            r.lines
              .filter((x) => x.id === l.id)
              .reduce((a, b) => a + b.amount, 0),
          0,
        ),
  );
  const total = sum(charges),
    paid =
      s.payments
        .filter((p) => p.invoiceId === i.id)
        .reduce((n, p) => n + p.amount, 0) -
      s.refunds
        .filter((r) => r.invoiceId === i.id)
        .reduce((n, r) => n + r.amount, 0);
  return {
    gross,
    discount,
    total,
    paid,
    debt: Math.max(0, total - paid),
    credit: Math.max(0, paid - total),
    lineAmounts,
    charges,
    paidByLine,
    canceled: sum(canceled),
    status:
      total === 0
        ? "Bekor qilingan"
        : paid >= total
          ? "To‘langan"
          : paid > 0
            ? "Qisman to‘langan"
            : "To‘lanmagan",
  };
}
export const openShift = (s: CashState) => s.shifts.find((x) => !x.closedAt);
export function shiftTotals(s: CashState, shift: Shift): ShiftTotals {
  const p = s.payments.filter((p) => p.shiftId === shift.id),
    r = s.refunds.filter((r) => r.shiftId === shift.id);
  const cash = sum(p.map((x) => x.cash)),
    card = sum(p.map((x) => x.card)),
    cashRefund = sum(r.map((x) => x.cash)),
    cardRefund = sum(r.map((x) => x.card));
  return {
    cash,
    card,
    cashRefund,
    cardRefund,
    expected: shift.opening + cash - cashRefund,
    cardNet: card - cardRefund,
  };
}
function active(s: CashState, c: Cashier) {
  const sh = openShift(s);
  if (!sh || sh.cashier.id !== c.id) throw Error("Avval kassani oching.");
  return sh;
}
function next(prefix: string, ids: string[]) {
  return (
    prefix +
    String(
      Math.max(
        0,
        ...ids
          .map((id) => Number(id.slice(prefix.length)))
          .filter(Number.isFinite),
      ) + 1,
    ).padStart(5, "0")
  );
}
export function beginShift(
  s: CashState,
  opening: number,
  note: string,
  c: Cashier,
  at = new Date().toISOString(),
) {
  if (openShift(s)) throw Error("Ochiq smena mavjud.");
  amount(opening);
  const sh: Shift = {
    id: next(
      "SM-",
      s.shifts.map((x) => x.id),
    ),
    cashier: { ...c },
    opening,
    note: note.trim(),
    openedAt: at,
    closedAt: null,
    actual: null,
    closeNote: "",
    closing: null,
  };
  s.shifts.push(sh);
  return sh;
}
export function closeShift(
  s: CashState,
  actual: number,
  note: string,
  c: Cashier,
  at = new Date().toISOString(),
) {
  const sh = active(s, c);
  amount(actual);
  const totals = shiftTotals(s, sh);
  if (actual !== totals.expected && !note.trim())
    throw Error("Farq bo‘lsa, izoh majburiy.");
  sh.closedAt = at;
  sh.actual = actual;
  sh.closeNote = note.trim();
  sh.closing = { ...totals };
  return sh;
}
export function addServices(s: CashState, id: string, ids: string[]) {
  const i = s.invoices.find((i) => i.id === id);
  if (!i) throw Error("Hisob topilmadi.");
  if (s.payments.some((p) => p.invoiceId === id))
    throw Error("To‘lov yozilgan hisob xizmatlari o‘zgartirilmaydi.");
  const candidates = ids.map((id) => catalog.services.find((x) => x.id === id));
  if (candidates.some((x) => !x || !x.active || !x.branchIds.includes("main")))
    throw Error("Faol xizmatni tanlang.");
  for (const c of candidates) {
    if (!c || i.lines.some((l) => l.serviceId === c.id)) continue;
    i.lines.push({
      id: `${i.id}:${c.id}`,
      serviceId: c.id,
      name: c.name,
      provider: "Katalog xizmati",
      qty: 1,
      price: c.price,
    });
  }
}
export function setQuantity(
  s: CashState,
  id: string,
  lineId: string,
  qty: number,
) {
  const i = s.invoices.find((i) => i.id === id);
  if (!i || s.payments.some((p) => p.invoiceId === id))
    throw Error("To‘lov yozilgan hisob o‘zgartirilmaydi.");
  const l = i.lines.find((l) => l.id === lineId);
  if (!l || !Number.isInteger(qty) || qty < 1 || qty > 999)
    throw Error("Miqdor 1–999 oralig‘ida bo‘lsin.");
  l.qty = qty;
  invoiceTotals(s, i);
}
export type PaymentInput = {
  invoiceId: string;
  token: string;
  discount: Discount;
  partial: boolean;
  amount: number;
  cash: number;
  card: number;
  tendered: number;
  terminalConfirmed: boolean;
};
export function pay(
  s: CashState,
  x: PaymentInput,
  c: Cashier,
  at = new Date().toISOString(),
) {
  const existing = s.payments.find((p) => p.token === x.token);
  if (existing) return existing;
  const sh = active(s, c),
    i = s.invoices.find((i) => i.id === x.invoiceId);
  if (!i) throw Error("Hisob topilmadi.");
  if (
    s.payments.some((p) => p.invoiceId === i.id) &&
    JSON.stringify(i.discount) !== JSON.stringify(x.discount)
  )
    throw Error("Oldingi to‘lovdan keyin chegirma o‘zgartirilmaydi.");
  const t = invoiceTotals(s, i, x.discount);
  [x.amount, x.cash, x.card, x.tendered].forEach(amount);
  if (
    !x.token ||
    x.amount <= 0 ||
    x.amount > t.debt ||
    (!x.partial && x.amount !== t.debt)
  )
    throw Error(
      "To‘lov qolgan summaga teng bo‘lsin yoki qisman to‘lovni yoqing.",
    );
  if (x.cash + x.card !== x.amount)
    throw Error("Naqd va karta qismlari to‘lov summasiga teng bo‘lsin.");
  if (x.tendered < x.cash || (!x.cash && x.tendered !== 0))
    throw Error("Berilgan naqd pul naqd qismdan kam bo‘lmasin.");
  if (x.card && !x.terminalConfirmed)
    throw Error("Terminal to‘lovini lokal tasdiqlang.");
  const weights = t.charges.map((n, k) => Math.max(0, n - t.paidByLine[k]!));
  const allocation = allocate(x.amount, weights);
  const p: Payment = {
    id: next(
      "CH-",
      s.payments.map((p) => p.id),
    ),
    token: x.token,
    invoiceId: i.id,
    patientId: i.patientId,
    patientName: i.patientName,
    phone: i.phone,
    shiftId: sh.id,
    at,
    cashier: { ...c },
    amount: x.amount,
    cash: x.cash,
    card: x.card,
    tendered: x.tendered,
    change: x.tendered - x.cash,
    gross: t.gross,
    discount: t.discount,
    total: t.total,
    paidBefore: t.paid,
    debtAfter: t.debt - x.amount,
    lines: i.lines.map((l) => ({ ...l })),
    allocations: Object.fromEntries(
      i.lines.map((l, k) => [l.id, allocation[k]!]),
    ),
  };
  i.discount = { ...x.discount };
  s.payments.push(p);
  return p;
}
export function refundAvailable(s: CashState, p: Payment) {
  const prior = s.refunds.filter((r) => r.paymentId === p.id);
  return {
    amount: p.amount - sum(prior.map((r) => r.amount)),
    cash: p.cash - sum(prior.map((r) => r.cash)),
    card: p.card - sum(prior.map((r) => r.card)),
    lines: p.lines.map((l) => ({
      ...l,
      available:
        (p.allocations[l.id] ?? 0) -
        prior.reduce(
          (n, r) =>
            n +
            r.lines
              .filter((x) => x.id === l.id)
              .reduce((a, b) => a + b.amount, 0),
          0,
        ),
    })),
  };
}
export type RefundInput = {
  paymentId: string;
  token: string;
  lines: { id: string; amount: number }[];
  cash: number;
  card: number;
  reason: Refund["reason"];
  note: string;
};
export function refund(
  s: CashState,
  x: RefundInput,
  c: Cashier,
  at = new Date().toISOString(),
) {
  const duplicate = s.refunds.find((r) => r.token === x.token);
  if (duplicate) return duplicate;
  const sh = active(s, c),
    p = s.payments.find((p) => p.id === x.paymentId);
  if (!p) throw Error("Asl to‘lov topilmadi.");
  const a = refundAvailable(s, p);
  if (!["cancel", "duplicate", "other"].includes(x.reason) || !x.token)
    throw Error("Qaytarish sababini tanlang.");
  if (x.reason === "other" && !x.note.trim())
    throw Error("Boshqa sabab uchun izoh majburiy.");
  if (
    !x.lines.length ||
    new Set(x.lines.map((l) => l.id)).size !== x.lines.length
  )
    throw Error("Xizmatlarni bir martadan tanlang.");
  for (const l of x.lines) {
    amount(l.amount);
    if (
      l.amount <= 0 ||
      l.amount > (a.lines.find((x) => x.id === l.id)?.available ?? 0)
    )
      throw Error("Xizmat bo‘yicha qaytarish to‘langan qismdan oshmasin.");
  }
  const total = sum(x.lines.map((l) => l.amount));
  [x.cash, x.card].forEach(amount);
  if (
    total > a.amount ||
    x.cash + x.card !== total ||
    x.cash > a.cash ||
    x.card > a.card
  )
    throw Error(
      "Qaytarish asl to‘lovning qolgan naqd/karta qismlaridan oshmasin.",
    );
  if (x.cash > shiftTotals(s, sh).expected)
    throw Error("Kassada qaytarish uchun yetarli naqd pul yo‘q.");
  const r: Refund = {
    id: next(
      "QT-",
      s.refunds.map((r) => r.id),
    ),
    token: x.token,
    paymentId: p.id,
    invoiceId: p.invoiceId,
    patientId: p.patientId,
    patientName: p.patientName,
    shiftId: sh.id,
    at,
    cashier: { ...c },
    amount: total,
    cash: x.cash,
    card: x.card,
    lines: x.lines.map((l) => ({
      ...l,
      name: p.lines.find((x) => x.id === l.id)!.name,
    })),
    reason: x.reason,
    note: x.note.trim(),
  };
  const i = s.invoices.find((i) => i.id === p.invoiceId)!;
  const t = invoiceTotals(s, i);
  const cancels =
    x.reason === "cancel"
      ? x.lines
          .map((l) => {
            const k = i.lines.findIndex((x) => x.id === l.id);
            return {
              id: `${r.id}:${l.id}`,
              invoiceId: i.id,
              lineId: l.id,
              amount: t.charges[k]!,
              refundId: r.id,
            };
          })
          .filter((x) => x.amount > 0)
      : [];
  s.refunds.push(r);
  s.cancellations.push(...cancels);
  return r;
}
export type HistoryFilter = {
  from: string;
  to: string;
  query: string;
  method: string;
  cashier: string;
};
export function filterHistory<T extends Payment | Refund>(
  items: T[],
  f: HistoryFilter,
) {
  const q = f.query.trim().toLocaleLowerCase();
  return items
    .filter(
      (x) =>
        (!f.from || x.at.slice(0, 10) >= f.from) &&
        (!f.to || x.at.slice(0, 10) <= f.to) &&
        (!f.method || method(x) === f.method) &&
        (!f.cashier || x.cashier.id === f.cashier) &&
        (!q ||
          `${x.id} ${x.invoiceId} ${x.patientName} ${"paymentId" in x ? x.paymentId : ""}`
            .toLocaleLowerCase()
            .includes(q)),
    )
    .sort((a, b) => b.at.localeCompare(a.at));
}
const csvCell = (v: unknown) =>
  '"' +
  String(v)
    .replace(/^[=+\-@\t\r]/, "'$&")
    .replaceAll('"', '""') +
  '"';
export function historyCsv(items: (Payment | Refund)[]) {
  return (
    "\uFEFF" +
    [
      [
        "Raqam",
        "Sana",
        "Bemor",
        "Hisob",
        "Asl chek",
        "Summa",
        "Naqd",
        "Karta",
        "Kassir",
        "Sabab",
      ],
      ...items.map((x) => [
        x.id,
        x.at,
        x.patientName,
        x.invoiceId,
        "paymentId" in x ? x.paymentId : "",
        x.amount,
        x.cash,
        x.card,
        x.cashier.name,
        "reason" in x ? x.reason : "",
      ]),
    ]
      .map((r) => r.map(csvCell).join(","))
      .join("\r\n")
  );
}
export function createCashState(date = localDate()): CashState {
  const s: CashState = {
    schema: 1,
    revision: 0,
    invoices: [],
    payments: [],
    refunds: [],
    cancellations: [],
    shifts: [],
  };
  const names = [
    "Aliyeva Mohira",
    "Usmonov Dilshod",
    "Karimova Nigora",
    "Rahimov Sardor",
  ];
  const make = (
    id: string,
    name: string,
    services: [number, number][],
    discount = 0,
  ) => {
    const i: Invoice = {
      id,
      patientId: "P-" + String(124 + s.invoices.length).padStart(5, "0"),
      patientName: name,
      phone: "+998 90 123 45 67",
      date,
      branch: cashierConfig.branch,
      discount: { kind: "amount", value: discount },
      lines: services.map(([n, qty]) => {
        const v = catalog.services[n - 1]!;
        return {
          id: id + ":" + v.id,
          serviceId: v.id,
          name: v.name,
          provider:
            v.type === "consultation"
              ? "Aziz Karimov"
              : v.type === "diagnostic"
                ? "Madina Sobirova"
                : "Laboratoriya",
          price: v.price,
          qty,
        };
      }),
    };
    s.invoices.push(i);
    return i;
  };
  make("HIS-00125", names[0]!, [
    [1, 1],
    [7, 1],
    [4, 1],
  ]);
  make("HIS-00126", names[1]!, [[7, 1]]);
  make("HIS-00127", names[2]!, [[16, 1]]);
  make("HIS-00128", names[3]!, [[8, 1]]);
  const c = { id: "demo-kamron", name: "Kamron" },
    d = { id: "demo-dilnoza", name: "Dilnoza" };
  const shift = beginShift(
    s,
    200000,
    "Oldingi namunaviy smena",
    c,
    `${date}T08:30:00`,
  );
  const seeds: [string, [number, number][], number, number, number][] = [
    [
      "Aliyeva Mohira",
      [
        [1, 1],
        [7, 1],
        [4, 1],
      ],
      0,
      330000,
      0,
    ],
    ["Usmonov Dilshod", [[7, 1]], 0, 0, 120000],
    [
      "Karimova Nigora",
      [
        [1, 1],
        [7, 1],
        [4, 1],
      ],
      0,
      100000,
      230000,
    ],
    ["Rahimov Sardor", [[8, 1]], 0, 70000, 0],
    [
      "Saidova Malika",
      [
        [1, 1],
        [2, 1],
      ],
      0,
      0,
      250000,
    ],
    ["Yusupov Aziz", [[6, 1]], 0, 35000, 0],
    ["Ahmedova Dilnoza", [[11, 1]], 15000, 115000, 0],
  ];
  seeds.forEach(([name, lines, disc, cash, card], k) => {
    const i = make(
      `HIS-${String(115 + k).padStart(5, "0")}`,
      name,
      lines,
      disc,
    );
    const p = pay(
      s,
      {
        invoiceId: i.id,
        token: `seed-payment-${k}`,
        discount: i.discount,
        partial: false,
        amount: cash + card,
        cash,
        card,
        tendered: cash,
        terminalConfirmed: true,
      },
      c,
      `${date}T${String(10 + Math.floor(k / 3)).padStart(2, "0")}:${String(10 + k * 5).padStart(2, "0")}:00`,
    );
    if (k % 2) p.cashier = d;
  });
  for (const [k, serviceId, amountValue, cash, card] of [
    [1, "service-7", 120000, 0, 120000],
    [0, "service-4", 60000, 60000, 0],
    [3, "service-8", 70000, 70000, 0],
    [5, "service-6", 35000, 35000, 0],
  ] as const) {
    const p = s.payments[k]!,
      l = p.lines.find((l) => l.serviceId === serviceId)!;
    refund(
      s,
      {
        paymentId: p.id,
        token: `seed-refund-${k}`,
        lines: [{ id: l.id, amount: amountValue }],
        cash,
        card,
        reason: "cancel",
        note: "Namunaviy xizmat bekor qilindi",
      },
      c,
      `${date}T14:${String(k * 5).padStart(2, "0")}:00`,
    );
  }
  closeShift(
    s,
    shiftTotals(s, shift).expected,
    "Namunaviy yopilgan smena",
    c,
    `${date}T16:00:00`,
  );
  return s;
}
export function validateCashState(s: CashState) {
  if (
    s.schema !== 1 ||
    !Number.isInteger(s.revision) ||
    s.revision < 0 ||
    !Array.isArray(s.invoices) ||
    !Array.isArray(s.payments) ||
    !Array.isArray(s.refunds) ||
    !Array.isArray(s.cancellations) ||
    !Array.isArray(s.shifts)
  )
    throw Error("Lokal kassa formati mos emas.");
  for (const items of [s.invoices, s.payments, s.refunds, s.shifts])
    if (new Set(items.map((x) => x.id)).size !== items.length)
      throw Error("Lokal yozuv raqamlari takrorlangan.");
  if (s.shifts.filter((x) => !x.closedAt).length > 1)
    throw Error("Bir nechta ochiq smena topildi.");
  for (const p of s.payments) {
    if (
      !s.invoices.some((i) => i.id === p.invoiceId) ||
      !s.shifts.some((x) => x.id === p.shiftId) ||
      p.cash + p.card !== p.amount ||
      p.change !== p.tendered - p.cash ||
      sum(Object.values(p.allocations)) !== p.amount
    )
      throw Error("Lokal to‘lov bog‘lanishi mos emas.");
    [p.amount, p.cash, p.card, p.tendered, p.change].forEach(amount);
  }
  for (const r of s.refunds) {
    if (
      !s.payments.some((p) => p.id === r.paymentId) ||
      !s.shifts.some((x) => x.id === r.shiftId) ||
      r.cash + r.card !== r.amount ||
      sum(r.lines.map((x) => x.amount)) !== r.amount
    )
      throw Error("Lokal qaytarish bog‘lanishi mos emas.");
    [r.amount, r.cash, r.card].forEach(amount);
  }
  for (const p of s.payments) {
    const a = refundAvailable(s, p);
    if (
      a.amount < 0 ||
      a.cash < 0 ||
      a.card < 0 ||
      a.lines.some((l) => l.available < 0)
    )
      throw Error("Lokal qaytarish limiti buzilgan.");
  }
  for (const i of s.invoices) invoiceTotals(s, i);
  return s;
}
export type StoragePort = Pick<Storage, "getItem" | "setItem">;
export function loadCash(storage: StoragePort, key: string) {
  const raw = storage.getItem(key);
  return raw
    ? validateCashState(JSON.parse(raw) as CashState)
    : createCashState();
}
/** Write-before-publish: failed quota/storage writes leave current UI/ledger untouched. */
export function commitCash(
  s: CashState,
  storage: StoragePort,
  key: string,
  mutate: (draft: CashState) => unknown,
) {
  const disk = storage.getItem(key);
  if (
    disk &&
    validateCashState(JSON.parse(disk) as CashState).revision !== s.revision
  )
    throw Error("Lokal kassa boshqa oynada yangilangan. Sahifani yangilang.");
  const draft = JSON.parse(JSON.stringify(s)) as CashState;
  mutate(draft);
  draft.revision++;
  validateCashState(draft);
  storage.setItem(key, JSON.stringify(draft));
  return draft;
}
