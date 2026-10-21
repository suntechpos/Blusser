<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import {
  LockKeyhole,
  Wallet,
  History,
  Undo2,
  Search,
  ChevronRight,
  Plus,
  Eye,
  Printer,
  Download,
  ReceiptText,
  Check,
  Delete,
  Building2,
  CalendarDays,
  Phone,
  Coins,
  CreditCard,
} from "lucide-vue-next";
import { session } from "../services/session";
import {
  cashierConfig,
  catalog,
  amount,
  invoiceTotals,
  openShift,
  shiftTotals,
  beginShift,
  closeShift,
  addServices,
  setQuantity,
  pay,
  refund,
  refundAvailable,
  filterHistory,
  historyCsv,
  method,
  methodNames,
  loadCash,
  commitCash,
  type CashState,
  type Payment,
  type Refund,
  type Discount,
  type HistoryFilter,
} from "../mocks/cashier";
import { localDate } from "../mocks/reception";
import { money } from "../mocks/servicesCatalog";
import { timestamp } from "../components/diagnostics/content";
import Dialog from "../components/doctor/WorkspaceDialog.vue";
import DateField from "../components/employees/DateField.vue";
import { downloadBlob } from "../components/doctor/localFiles";
import ReceiptPreview from "../components/cashier/ReceiptPreview.vue";
import MoneyField from "../components/cashier/MoneyField.vue";
import {
  receiptLines,
  receiptPdf,
  printReceipt,
} from "../components/cashier/receipts";
import "../components/diagnostics/diagnostics.css";
import "../components/cashier/cashier.css";
const state = ref<CashState>(),
  verified = ref(false),
  storageError = ref(""),
  error = ref(""),
  modal = ref(""),
  tab = ref("cash"),
  pin = ref(""),
  pinInput = ref<HTMLInputElement>(),
  opening = ref("200000"),
  openingNote = ref(""),
  actual = ref(""),
  closingNote = ref(""),
  selectedId = ref(""),
  query = ref(""),
  saving = ref(false),
  exporting = ref(false),
  receipt = ref<Payment | Refund>(),
  paymentDetail = ref<Payment>(),
  refundDetail = ref<Refund>(),
  success = ref<Payment>(),
  receiptBack = ref(""),
  token = ref(crypto.randomUUID()),
  printCheck = ref(false);
const key = computed(
    () =>
      `${cashierConfig.storagePrefix}.${session.user?.active_tenant_id ?? "sample-clinic"}`,
  ),
  cashier = cashierConfig.cashier;
function load() {
  storageError.value = "";
  try {
    state.value = loadCash(localStorage, key.value);
  } catch {
    state.value = undefined;
    storageError.value =
      "Lokal kassa holatini o‘qib bo‘lmadi. Saqlangan ma’lumotlar o‘chirilmagan.";
  }
}
onMounted(load);
const shift = computed(() =>
    state.value ? openShift(state.value) : undefined,
  ),
  totals = computed(() =>
    state.value && shift.value
      ? shiftTotals(state.value, shift.value)
      : undefined,
  ),
  working = computed(() => verified.value && !!shift.value),
  invoice = computed(() =>
    state.value?.invoices.find((i) => i.id === selectedId.value),
  ),
  pending = computed(
    () =>
      state.value?.invoices.filter(
        (i) => invoiceTotals(state.value!, i).debt > 0,
      ) ?? [],
  ),
  pendingFiltered = computed(() =>
    pending.value.filter((i) =>
      `${i.patientName} ${i.id} ${i.patientId}`
        .toLocaleLowerCase()
        .includes(query.value.trim().toLocaleLowerCase()),
    ),
  );
const discountKind = ref<Discount["kind"]>("amount"),
  discountValue = ref("0"),
  payMode = ref("cash"),
  partial = ref(false),
  partialAmount = ref(""),
  cashPart = ref(""),
  cardPart = ref(""),
  tendered = ref(""),
  terminal = ref(false);
const paidLocked = computed(
  () => !!state.value?.payments.some((p) => p.invoiceId === selectedId.value),
);
function select(id: string) {
  selectedId.value = id;
  const i = state.value?.invoices.find((i) => i.id === id);
  discountKind.value = i?.discount.kind ?? "amount";
  discountValue.value = String(i?.discount.value ?? 0);
  payMode.value = "cash";
  partial.value = false;
  partialAmount.value = "";
  cashPart.value = "";
  cardPart.value = "";
  tendered.value = "";
  terminal.value = false;
  error.value = "";
  token.value = crypto.randomUUID();
}
function currentDiscount() {
  const raw = discountValue.value.trim().replace(/\s/g, "");
  if (!/^\d+(?:\.\d{1,2})?$/.test(raw))
    throw Error("Chegirmani to‘g‘ri kiriting.");
  return {
    kind: discountKind.value,
    value: discountKind.value === "amount" ? amount(raw) : Number(raw),
  };
}
const preview = computed(() => {
  try {
    if (!state.value || !invoice.value) return null;
    return {
      totals: invoiceTotals(state.value, invoice.value, currentDiscount()),
      error: "",
    };
  } catch (e) {
    return { totals: null, error: (e as Error).message };
  }
});
const target = computed(() =>
    partial.value
      ? numberOrZero(partialAmount.value)
      : (preview.value?.totals?.debt ?? 0),
  ),
  enteredCash = computed(() =>
    payMode.value === "card"
      ? 0
      : payMode.value === "mixed"
        ? numberOrZero(cashPart.value)
        : target.value,
  ),
  enteredCard = computed(() =>
    payMode.value === "cash"
      ? 0
      : payMode.value === "mixed"
        ? numberOrZero(cardPart.value)
        : target.value,
  ),
  change = computed(() =>
    Math.max(0, numberOrZero(tendered.value) - enteredCash.value),
  );
function numberOrZero(x: string) {
  try {
    return amount(x);
  } catch {
    return 0;
  }
}
function transact(fn: (draft: CashState) => void) {
  if (!state.value || !verified.value)
    throw Error("Avval demo PIN bilan kassaga kiring.");
  state.value = commitCash(state.value, localStorage, key.value, fn);
}
function attempt(fn: () => void) {
  error.value = "";
  try {
    fn();
  } catch (e) {
    error.value = (e as Error).message;
  }
}
function unlock() {
  if (pin.value !== cashierConfig.pin) {
    pin.value = "";
    error.value = "PIN noto‘g‘ri. Qayta kiriting.";
    pinInput.value?.focus();
    return;
  }
  pin.value = "";
  verified.value = true;
  error.value = "";
  if (shift.value) {
    modal.value = "";
    if (!invoice.value) select(pending.value[0]?.id ?? "");
  } else {
    opening.value = "200000";
    openingNote.value = "";
    modal.value = "open";
  }
}
function pinKey(e: KeyboardEvent) {
  if (e.key === "Enter") {
    e.preventDefault();
    if (pin.value.length === 4) unlock();
  }
}
function digit(value: string) {
  if (value === "delete") pin.value = pin.value.slice(0, -1);
  else if (pin.value.length < 4) pin.value += value;
  pinInput.value?.focus();
}
function closeModal() {
  if (modal.value === "pin" || modal.value === "open") {
    verified.value = false;
    pin.value = "";
  }
  modal.value = "";
  error.value = "";
}
function start() {
  attempt(() => {
    transact((s) =>
      beginShift(s, amount(opening.value), openingNote.value, cashier),
    );
    modal.value = "";
    select(pending.value[0]?.id ?? "");
  });
}
function varianceLabel() {
  if (!actual.value) return "Naqd pulni sanab kiriting";
  const n = numberOrZero(actual.value) - (totals.value?.expected ?? 0);
  return n === 0 ? "Farq yo‘q" : n < 0 ? "Kamomad" : "Ortiqcha pul";
}
function close() {
  attempt(() => {
    transact((s) =>
      closeShift(s, amount(actual.value), closingNote.value, cashier),
    );
    verified.value = false;
    pin.value = "";
    modal.value = "";
    selectedId.value = "";
    tab.value = "cash";
    success.value = undefined;
  });
}
function accept() {
  if (saving.value) return;
  saving.value = true;
  attempt(() => {
    if (!invoice.value || !preview.value?.totals)
      throw Error(preview.value?.error ?? "Hisobni tanlang.");
    const id = selectedId.value;
    transact((s) =>
      pay(
        s,
        {
          invoiceId: id,
          token: token.value,
          discount: currentDiscount(),
          partial: partial.value,
          amount: partial.value
            ? amount(partialAmount.value)
            : preview.value!.totals!.debt,
          cash:
            payMode.value === "card"
              ? 0
              : payMode.value === "mixed"
                ? amount(cashPart.value)
                : target.value,
          card:
            payMode.value === "cash"
              ? 0
              : payMode.value === "mixed"
                ? amount(cardPart.value)
                : target.value,
          tendered: payMode.value === "card" ? 0 : amount(tendered.value),
          terminalConfirmed: terminal.value,
        },
        cashier,
      ),
    );
    success.value = state.value!.payments.find((p) => p.token === token.value)!;
    modal.value = "success";
    if (printCheck.value)
      try {
        printReceipt(receiptLines(success.value));
      } catch (e) {
        error.value = (e as Error).message;
      }
  });
  saving.value = false;
}
function nextPatient() {
  modal.value = "";
  success.value = undefined;
  select(pending.value[0]?.id ?? "");
}
const serviceQuery = ref(""),
  category = ref(""),
  chosen = ref<string[]>([]);
const availableServices = computed(() =>
    catalog.services.filter(
      (s) =>
        s.active &&
        s.branchIds.includes("main") &&
        (!category.value || s.categoryId === category.value) &&
        `${s.name} ${s.code}`
          .toLocaleLowerCase()
          .includes(serviceQuery.value.toLocaleLowerCase()),
    ),
  ),
  chosenTotal = computed(() =>
    catalog.services
      .filter((s) => chosen.value.includes(s.id))
      .reduce((n, s) => n + s.price, 0),
  );
function appendServices() {
  attempt(() => {
    transact((s) => addServices(s, selectedId.value, chosen.value));
    chosen.value = [];
    modal.value = "";
  });
}
function quantity(lineId: string, e: Event) {
  const input = e.target as HTMLInputElement;
  const old = invoice.value?.lines.find((l) => l.id === lineId)?.qty;
  attempt(() =>
    transact((s) =>
      setQuantity(s, selectedId.value, lineId, Number(input.value)),
    ),
  );
  input.value = String(
    invoice.value?.lines.find((l) => l.id === lineId)?.qty ?? old ?? 1,
  );
}
const paymentFilter = ref<HistoryFilter>({
    from: localDate(),
    to: localDate(),
    query: "",
    method: "",
    cashier: "",
  }),
  refundFilter = ref<HistoryFilter>({
    from: localDate(),
    to: localDate(),
    query: "",
    method: "",
    cashier: "",
  }),
  historyPage = ref(1),
  historySize = ref(10),
  refundPage = ref(1),
  refundSize = ref(10);
const paymentRows = computed(() =>
    filterHistory(state.value?.payments ?? [], paymentFilter.value),
  ),
  refundRows = computed(() =>
    filterHistory(state.value?.refunds ?? [], refundFilter.value),
  ),
  currentFilter = computed(() =>
    tab.value === "payments" ? paymentFilter.value : refundFilter.value,
  ),
  historyRows = computed(() =>
    tab.value === "payments" ? paymentRows.value : refundRows.value,
  ),
  historyTotals = computed(() =>
    historyRows.value.reduce(
      (t, x) => ({
        amount: t.amount + x.amount,
        cash: t.cash + x.cash,
        card: t.card + x.card,
      }),
      { amount: 0, cash: 0, card: 0 },
    ),
  ),
  historyVisible = computed(() =>
    tab.value === "payments"
      ? paymentRows.value.slice(
          (historyPage.value - 1) * historySize.value,
          historyPage.value * historySize.value,
        )
      : refundRows.value.slice(
          (refundPage.value - 1) * refundSize.value,
          refundPage.value * refundSize.value,
        ),
  ),
  cashiers = computed(() => [
    ...new Map(
      [...(state.value?.payments ?? []), ...(state.value?.refunds ?? [])].map(
        (x) => [x.cashier.id, x.cashier.name],
      ),
    ).entries(),
  ]);
watch([paymentFilter, historySize], () => (historyPage.value = 1), {
  deep: true,
});
watch([refundFilter, refundSize], () => (refundPage.value = 1), { deep: true });
function viewPayment(p: Payment) {
  paymentDetail.value = p;
  modal.value = "payment";
  error.value = "";
}
function viewRefund(r: Refund) {
  refundDetail.value = r;
  modal.value = "refund-detail";
  error.value = "";
}
function showReceipt(r: Payment | Refund, back = modal.value) {
  receipt.value = r;
  receiptBack.value = back;
  modal.value = "receipt";
  error.value = "";
}
function originalPayment(r: Refund) {
  return state.value?.payments.find((p) => p.id === r.paymentId);
}
async function pdf(r: Payment | Refund) {
  if (exporting.value) return;
  exporting.value = true;
  error.value = "";
  try {
    downloadBlob(
      await receiptPdf(r, "paymentId" in r ? originalPayment(r) : undefined),
      `${r.id}.pdf`,
    );
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    exporting.value = false;
  }
}
function print(r: Payment | Refund) {
  attempt(() => {
    printReceipt(
      receiptLines(r, "paymentId" in r ? originalPayment(r) : undefined),
    );
  });
}
function csv() {
  downloadBlob(
    new Blob([historyCsv(historyRows.value)], {
      type: "text/csv;charset=utf-8",
    }),
    tab.value === "payments" ? "tolovlar.csv" : "qaytarishlar.csv",
  );
}
const refundSearch = ref(""),
  refundPaymentId = ref(""),
  refundChosen = ref<Record<string, string>>({}),
  refundReason = ref<Refund["reason"] | "">(""),
  refundNote = ref(""),
  refundCash = ref(""),
  refundCard = ref(""),
  refundToken = ref(crypto.randomUUID());
const refundPayment = computed(() =>
    state.value?.payments.find((p) => p.id === refundPaymentId.value),
  ),
  refundable = computed(() =>
    state.value && refundPayment.value
      ? refundAvailable(state.value, refundPayment.value)
      : undefined,
  ),
  refundResults = computed(() => {
    const q = refundSearch.value.trim().toLocaleLowerCase();
    return (
      state.value?.payments
        .filter((p) =>
          `${p.id} ${p.patientName} ${p.phone}`.toLocaleLowerCase().includes(q),
        )
        .slice(0, 10) ?? []
    );
  }),
  refundSum = computed(() =>
    Object.values(refundChosen.value).reduce((n, v) => n + numberOrZero(v), 0),
  );
function choosePayment(p: Payment) {
  refundPaymentId.value = p.id;
  refundChosen.value = {};
  refundCash.value = "0";
  refundCard.value = "0";
  refundReason.value = "";
  refundNote.value = "";
  refundToken.value = crypto.randomUUID();
}
function toggleRefund(id: string, checked: boolean) {
  if (checked)
    refundChosen.value[id] = String(
      refundable.value?.lines.find((l) => l.id === id)?.available ?? 0,
    );
  else delete refundChosen.value[id];
  splitRefund();
}
function splitRefund() {
  const a = refundable.value;
  if (!a) return;
  refundCash.value = String(Math.min(a.cash, refundSum.value));
  refundCard.value = String(
    Math.max(0, refundSum.value - numberOrZero(refundCash.value)),
  );
}
function confirmRefund() {
  if (saving.value) return;
  saving.value = true;
  attempt(() => {
    if (!refundPayment.value || !refundReason.value)
      throw Error("Asl to‘lov va sababni tanlang.");
    const id = refundPaymentId.value;
    transact((s) =>
      refund(
        s,
        {
          paymentId: id,
          token: refundToken.value,
          lines: Object.entries(refundChosen.value).map(([id, value]) => ({
            id,
            amount: amount(value),
          })),
          cash: amount(refundCash.value),
          card: amount(refundCard.value),
          reason: refundReason.value as Refund["reason"],
          note: refundNote.value,
        },
        cashier,
      ),
    );
    refundDetail.value = state.value!.refunds.find(
      (r) => r.token === refundToken.value,
    )!;
    modal.value = "refund-detail";
  });
  saving.value = false;
}
const currentPaymentAccount = computed(() =>
    state.value && paymentDetail.value
      ? state.value.invoices.find(
          (i) => i.id === paymentDetail.value!.invoiceId,
        )
      : undefined,
  ),
  currentPaymentTotals = computed(() =>
    state.value && currentPaymentAccount.value
      ? invoiceTotals(state.value, currentPaymentAccount.value)
      : undefined,
  ),
  refOriginal = computed(() =>
    refundDetail.value ? originalPayment(refundDetail.value) : undefined,
  ),
  refPriorTotal = computed(
    () =>
      state.value?.refunds
        .filter((r) => r.paymentId === refundDetail.value?.paymentId)
        .reduce((n, r) => n + r.amount, 0) ?? 0,
  );
</script>
<template>
  <div class="dx-workspace cs-page">
    <div class="dx-heading">
      <h1>Kassa</h1>
      <div class="dx-actions">
        <span :class="['cs-shift', working ? 'open' : '']"
          >● {{ working ? "Kassa ochiq" : "Kassa yopiq" }} · Kassir:
          {{ verified ? cashier.name : "—" }}</span
        ><button
          v-if="working"
          class="dx-button"
          @click="
            actual = '';
            closingNote = '';
            error = '';
            modal = 'close';
          "
        >
          <Wallet :size="18" />Kassani yopish
        </button>
      </div>
    </div>
    <template v-if="!working"
      ><section class="dx-card cs-gate">
        <LockKeyhole :size="65" />
        <h2>Kassaga kirish</h2>
        <p>Kassa bilan ishlash uchun demo PIN orqali kiring.</p>
        <p v-if="storageError" class="dx-error" role="alert">
          {{ storageError }}
        </p>
        <button v-if="storageError" class="dx-button" @click="load">
          Qayta o‘qish</button
        ><button
          v-else
          class="dx-button dx-primary"
          :disabled="!state"
          @click="
            pin = '';
            error = '';
            modal = 'pin';
          "
        >
          <LockKeyhole :size="19" />Kassaga kirish</button
        ><small>Lokal namuna rejimi · Haqiqiy autentifikatsiya emas</small>
      </section></template
    >
    <template v-else-if="state"
      ><nav class="cs-tabs">
        <button
          :class="{ active: tab === 'cash' }"
          @click="
            tab = 'cash';
            error = '';
          "
        >
          <Wallet />Kassa</button
        ><button
          :class="{ active: tab === 'payments' }"
          @click="
            tab = 'payments';
            error = '';
          "
        >
          <History />To‘lovlar tarixi</button
        ><button
          :class="{ active: tab === 'refunds' }"
          @click="
            tab = 'refunds';
            error = '';
          "
        >
          <Undo2 />Qaytarishlar
        </button>
      </nav>
      <template v-if="tab === 'cash'"
        ><div class="cs-columns">
          <section class="dx-card cs-pending">
            <h2>
              To‘lov kutilmoqda <span>{{ pending.length }}</span>
            </h2>
            <label class="dx-search"
              ><Search :size="19" /><input
                v-model="query"
                placeholder="Bemor yoki hisob..."
                aria-label="Bemor yoki hisob qidirish"
            /></label>
            <p v-if="!pendingFiltered.length" class="dx-empty">
              {{ query ? "Hisob topilmadi" : "To‘lov kutilayotgan hisob yo‘q" }}
            </p>
            <button
              v-for="i in pendingFiltered"
              :key="i.id"
              :class="['cs-invoice', { selected: selectedId === i.id }]"
              @click="select(i.id)"
            >
              <span class="dx-avatar">{{
                i.patientName
                  .split(" ")
                  .map((s) => s[0])
                  .join("")
              }}</span
              ><span
                ><b>{{ i.patientName }}</b
                ><small>{{ i.patientId }} · {{ i.id }}</small
                ><strong>{{
                  money(invoiceTotals(state, i).debt)
                }}</strong></span
              ><ChevronRight :size="20" />
            </button>
          </section>
          <section class="dx-card cs-account">
            <h2>Bemor hisobi</h2>
            <template v-if="invoice"
              ><div class="cs-patient">
                <span class="dx-avatar">{{
                  invoice.patientName
                    .split(" ")
                    .map((s) => s[0])
                    .join("")
                }}</span>
                <div>
                  <b>{{ invoice.patientName }}</b
                  ><small>{{ invoice.patientId }}</small
                  ><small><Phone :size="14" />{{ invoice.phone }}</small>
                </div>
                <div>
                  <b>{{ invoice.id }}</b
                  ><span class="cs-badge">{{
                    invoiceTotals(state, invoice).status
                  }}</span>
                </div>
              </div>
              <button
                class="dx-button"
                :disabled="paidLocked"
                @click="
                  chosen = [];
                  serviceQuery = '';
                  category = '';
                  modal = 'services';
                "
              >
                <Plus :size="20" />Xizmat qo‘shish
              </button>
              <p v-if="paidLocked" class="cs-muted">
                To‘lov yozilgan hisobning xizmatlari va chegirmasi
                o‘zgartirilmaydi.
              </p>
              <div class="cs-table-scroll">
                <table class="cs-services">
                  <thead>
                    <tr>
                      <th>Xizmat</th>
                      <th>Miqdor</th>
                      <th>Narx</th>
                      <th>Summa</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="l in invoice.lines" :key="l.id">
                      <td>
                        {{ l.name }}<small>{{ l.provider }}</small>
                      </td>
                      <td>
                        <input
                          :value="l.qty"
                          type="number"
                          min="1"
                          max="999"
                          :disabled="paidLocked"
                          :aria-label="`${l.name} miqdori`"
                          @change="quantity(l.id, $event)"
                        />
                      </td>
                      <td>{{ money(l.price) }}</td>
                      <td>{{ money(l.price * l.qty) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <footer>
                <CalendarDays :size="18" />{{ invoice.date }} ·
                {{ invoice.branch }}
              </footer></template
            >
            <p v-else class="dx-empty">To‘lov uchun hisobni tanlang</p>
          </section>
          <section class="dx-card cs-payment">
            <h2>To‘lov</h2>
            <template v-if="invoice"
              ><dl>
                <dt>Xizmatlar summasi</dt>
                <dd>{{ money(preview?.totals?.gross ?? 0) }}</dd>
              </dl>
              <div class="cs-discount">
                <span>Chegirma</span
                ><input
                  v-model="discountValue"
                  aria-label="Chegirma qiymati"
                  inputmode="decimal"
                  :disabled="paidLocked"
                /><button
                  :class="{ active: discountKind === 'percent' }"
                  :disabled="paidLocked"
                  @click="discountKind = 'percent'"
                >
                  %</button
                ><button
                  :class="{ active: discountKind === 'amount' }"
                  :disabled="paidLocked"
                  @click="discountKind = 'amount'"
                >
                  so‘m</button
                ><b>{{ money(preview?.totals?.discount ?? 0) }}</b>
              </div>
              <dl class="cs-summary">
                <dt>Jami</dt>
                <dd>{{ money(preview?.totals?.total ?? 0) }}</dd>
                <dt>Oldin to‘langan (sof)</dt>
                <dd>{{ money(preview?.totals?.paid ?? 0) }}</dd>
                <dt>Qolgan summa</dt>
                <dd>{{ money(preview?.totals?.debt ?? 0) }}</dd>
                <template v-if="preview?.totals?.credit"
                  ><dt>Hisobdagi ortiqcha to‘lov</dt>
                  <dd>{{ money(preview.totals.credit) }}</dd></template
                >
              </dl>
              <label class="cs-label">To‘lov usuli</label>
              <div class="cs-segments">
                <button
                  v-for="m in ['cash', 'card', 'mixed'] as const"
                  :key="m"
                  :class="{ active: payMode === m }"
                  @click="
                    payMode = m;
                    terminal = false;
                  "
                >
                  {{ methodNames[m] }}
                </button>
              </div>
              <label class="cs-check cs-partial"
                ><input v-model="partial" type="checkbox" />Qisman to‘lov</label
              ><MoneyField
                v-if="partial"
                v-model="partialAmount"
                label="Hisobga to‘lanadigan summa"
              /><template v-if="payMode === 'mixed'"
                ><MoneyField
                  v-model="cashPart"
                  label="Naqd to‘lov" /><MoneyField
                  v-model="cardPart"
                  label="Karta to‘lovi" /></template
              ><MoneyField
                v-if="payMode !== 'card'"
                v-model="tendered"
                label="Qabul qilingan naqd"
              />
              <div v-if="payMode === 'card'" class="cs-total">
                To‘lov summasi <b>{{ money(target) }}</b>
              </div>
              <label v-if="payMode !== 'cash'" class="cs-check"
                ><input v-model="terminal" type="checkbox" />Terminal to‘lovi
                bajarildi (lokal tasdiq)</label
              ><small v-if="payMode !== 'cash'" class="cs-muted"
                >Bank yoki terminalga ulanmagan.</small
              >
              <div v-if="partial" class="cs-debt">
                To‘lovdan keyin qarz
                <b>{{
                  money(Math.max(0, (preview?.totals?.debt ?? 0) - target))
                }}</b>
              </div>
              <div v-if="payMode === 'mixed'" class="cs-total">
                Jami kiritildi <b>{{ money(enteredCash + enteredCard) }}</b>
              </div>
              <div v-if="payMode !== 'card'" class="cs-change">
                Qaytim <b>{{ money(change) }}</b>
              </div>
              <p v-if="error || preview?.error" class="dx-error" role="alert">
                {{ error || preview?.error }}
              </p>
              <button
                class="dx-button dx-primary cs-pay"
                :disabled="saving || !preview?.totals?.debt"
                @click="accept"
              >
                <ChevronRight :size="22" />{{
                  partial
                    ? `${money(target)} qabul qilish`
                    : "To‘lovni qabul qilish"
                }}</button
              ><label class="cs-check"
                ><input v-model="printCheck" type="checkbox" />Chekni chop
                etish</label
              ><small class="cs-demo">Namunaviy ma’lumotlar</small></template
            >
            <p v-else class="dx-empty">Hisob tanlanmagan</p>
          </section>
        </div></template
      >
      <template v-else
        ><div v-if="tab === 'refunds'" class="dx-heading">
          <div>
            <h2>Qaytarishlar</h2>
            <p>To‘lovlarga bog‘langan qaytarish operatsiyalari</p>
          </div>
          <button
            class="dx-button dx-primary"
            @click="
              refundSearch = '';
              refundPaymentId = '';
              refundChosen = {};
              refundReason = '';
              refundNote = '';
              error = '';
              modal = 'refund-create';
            "
          >
            <Undo2 :size="18" />Qaytarish yaratish
          </button>
        </div>
        <div class="cs-stat-grid">
          <article class="dx-card">
            <Coins />
            <div>
              <small>{{
                tab === "payments" ? "Qabul qilingan" : "Jami qaytarildi"
              }}</small
              ><strong>{{ money(historyTotals.amount) }}</strong>
            </div>
          </article>
          <article class="dx-card">
            <Wallet />
            <div>
              <small>Naqd</small
              ><strong>{{ money(historyTotals.cash) }}</strong>
            </div>
          </article>
          <article class="dx-card">
            <CreditCard />
            <div>
              <small>Karta</small
              ><strong>{{ money(historyTotals.card) }}</strong>
            </div>
          </article>
        </div>
        <section class="dx-card cs-history">
          <div class="cs-filters">
            <DateField
              v-model="currentFilter.from"
              label="Boshlanish sanasi"
            /><DateField
              v-model="currentFilter.to"
              label="Tugash sanasi"
            /><input
              v-model="currentFilter.query"
              :aria-label="
                tab === 'payments' ? 'To‘lov qidirish' : 'Qaytarish qidirish'
              "
              placeholder="Bemor, chek yoki hisob..."
            /><select v-model="currentFilter.method" aria-label="Usul filtri">
              <option value="">Barcha usullar</option>
              <option v-for="(name, id) in methodNames" :key="id" :value="id">
                {{ name }}
              </option></select
            ><select v-model="currentFilter.cashier" aria-label="Kassir filtri">
              <option value="">Barcha kassirlar</option>
              <option v-for="c in cashiers" :key="c[0]" :value="c[0]">
                {{ c[1] }}
              </option></select
            ><button class="dx-button" @click="csv">
              <Download :size="18" />Eksport
            </button>
          </div>
          <p
            v-if="
              currentFilter.from &&
              currentFilter.to &&
              currentFilter.from > currentFilter.to
            "
            class="dx-error"
            role="alert"
          >
            Sana oralig‘i noto‘g‘ri.
          </p>
          <div class="cs-table-scroll">
            <table class="cs-history-table">
              <thead>
                <tr>
                  <th>{{ tab === "payments" ? "Chek №" : "Qaytarish №" }}</th>
                  <th>Sana va vaqt</th>
                  <th>
                    Bemor / {{ tab === "payments" ? "Hisob" : "Asl chek" }}
                  </th>
                  <th>Summa</th>
                  <th>Usul</th>
                  <th v-if="tab === 'refunds'">Sabab</th>
                  <th>Kassir</th>
                  <th>Amallar</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in historyVisible" :key="r.id">
                  <td>{{ r.id }}</td>
                  <td>{{ timestamp(r.at) }}</td>
                  <td>
                    <b>{{ r.patientName }}</b
                    ><small>{{
                      "paymentId" in r ? r.paymentId : r.invoiceId
                    }}</small>
                  </td>
                  <td>
                    <b>{{ money(r.amount) }}</b
                    ><small v-if="method(r) === 'mixed'"
                      >Naqd {{ money(r.cash) }} · Karta
                      {{ money(r.card) }}</small
                    >
                  </td>
                  <td>
                    <span class="cs-method">{{ methodNames[method(r)] }}</span>
                  </td>
                  <td v-if="'reason' in r">
                    {{
                      {
                        cancel: "Xizmat bekor qilindi",
                        duplicate: "Takroriy to‘lov",
                        other: "Boshqa",
                      }[r.reason]
                    }}
                  </td>
                  <td>{{ r.cashier.name }}</td>
                  <td>
                    <div class="dx-actions">
                      <button
                        class="dx-button"
                        @click="
                          'paymentId' in r ? viewRefund(r) : viewPayment(r)
                        "
                      >
                        <Eye :size="17" />Ko‘rish</button
                      ><button
                        class="dx-icon"
                        :aria-label="`${r.id} chek`"
                        @click="showReceipt(r, '')"
                      >
                        <ReceiptText :size="19" />
                      </button>
                    </div>
                  </td>
                </tr>
                <tr v-if="!historyVisible.length">
                  <td colspan="8" class="dx-empty">Yozuv topilmadi</td>
                </tr>
              </tbody>
            </table>
          </div>
          <footer class="dx-pagination">
            <span
              >{{ historyRows.length }} ta
              {{ tab === "payments" ? "to‘lov" : "qaytarish" }} ·
              {{ tab === "payments" ? historyPage : refundPage }}-sahifa</span
            ><select
              v-if="tab === 'payments'"
              v-model.number="historySize"
              aria-label="Sahifadagi to‘lovlar"
            >
              <option :value="10">10 ta</option>
              <option :value="5">5 ta</option></select
            ><select
              v-else
              v-model.number="refundSize"
              aria-label="Sahifadagi qaytarishlar"
            >
              <option :value="10">10 ta</option>
              <option :value="2">2 ta</option></select
            ><button
              class="dx-icon"
              :disabled="(tab === 'payments' ? historyPage : refundPage) === 1"
              @click="tab === 'payments' ? historyPage-- : refundPage--"
            >
              ‹</button
            ><button
              class="dx-icon"
              :disabled="
                (tab === 'payments'
                  ? historyPage * historySize
                  : refundPage * refundSize) >= historyRows.length
              "
              @click="tab === 'payments' ? historyPage++ : refundPage++"
            >
              ›
            </button>
          </footer>
          <p class="cs-muted">
            {{
              tab === "payments"
                ? "Har bir to‘lov alohida yozuv."
                : "Asl to‘lov saqlanadi. Har bir qaytarish unga bog‘lanadi."
            }}
            · Namunaviy ma’lumotlar
          </p>
        </section></template
      ></template
    >
    <Dialog
      v-if="modal === 'pin'"
      class="dx-dialog cs-dialog cs-pin-dialog"
      title="Kassaga kirish"
      subtitle="Shaxsiy demo PIN-kodingizni kiriting"
      :icon="LockKeyhole"
      @close="closeModal"
      ><div class="dx-modal-body cs-pin-body">
        <div class="cs-clinic">
          <Building2 :size="23" />{{ cashierConfig.clinic }} ·
          {{ cashierConfig.register }}
        </div>
        <input
          ref="pinInput"
          v-model="pin"
          type="password"
          inputmode="numeric"
          maxlength="4"
          aria-label="4 xonali demo PIN"
          autocomplete="off"
          autofocus
          @input="pin = pin.replace(/\D/g, '').slice(0, 4)"
          @keydown="pinKey"
        />
        <div class="cs-pin-dots" aria-hidden="true">
          <span v-for="n in 4" :key="n">{{ pin.length >= n ? "●" : "" }}</span>
        </div>
        <div class="cs-keypad">
          <button
            v-for="n in [
              '1',
              '2',
              '3',
              '4',
              '5',
              '6',
              '7',
              '8',
              '9',
              '',
              '0',
              'delete',
            ]"
            :key="n"
            :disabled="!n"
            :aria-label="
              n === 'delete' ? 'PIN raqamini o‘chirish' : n || 'Bo‘sh'
            "
            @click="digit(n)"
          >
            <Delete v-if="n === 'delete'" :size="21" /><template v-else>{{
              n
            }}</template>
          </button>
        </div>
        <p v-if="error" class="dx-error" role="alert">{{ error }}</p>
        <p class="cs-muted">
          PIN faqat lokal namuna uchun. Backend himoyasi emas.
        </p>
      </div>
      <template #footer
        ><footer>
          <button class="dx-button" @click="closeModal">Bekor qilish</button
          ><button
            class="dx-button dx-primary"
            :disabled="pin.length !== 4"
            @click="unlock"
          >
            Kirish
          </button>
        </footer></template
      ></Dialog
    >
    <Dialog
      v-if="modal === 'open'"
      class="dx-dialog cs-dialog"
      title="Kassani ochish"
      subtitle="To‘lovlarni qabul qilish uchun ish kunini boshlang"
      :icon="Wallet"
      @close="closeModal"
      ><div class="dx-modal-body">
        <div class="cs-clinic">
          <Building2 />{{ cashierConfig.clinic }} · {{ cashierConfig.branch }} ·
          {{ cashierConfig.register }}
        </div>
        <dl>
          <dt>Kassir</dt>
          <dd>{{ cashier.name }}</dd>
          <dt>Sana va vaqt</dt>
          <dd>{{ timestamp(new Date().toISOString()) }}</dd>
        </dl>
        <MoneyField v-model="opening" label="Boshlang‘ich naqd pul" /><label
          class="cs-label"
          >Izoh<textarea
            v-model="openingNote"
            aria-label="Smena ochish izohi"
            maxlength="500"
          />
        </label>
        <p class="dx-info">
          Bu summa tushum hisoblanmaydi. Kassa qoldig‘iga qo‘shiladi.
        </p>
        <p v-if="error" class="dx-error" role="alert">{{ error }}</p>
      </div>
      <template #footer
        ><footer>
          <button class="dx-button" @click="closeModal">Bekor qilish</button
          ><button class="dx-button dx-primary" @click="start">
            Kassani ochish
          </button>
        </footer></template
      ></Dialog
    >
    <Dialog
      v-if="modal === 'services' && invoice"
      class="dx-dialog cs-dialog cs-wide"
      title="Xizmat qo‘shish"
      subtitle="Mavjud katalogdan bemor hisobiga xizmat tanlang"
      :dirty="chosen.length > 0"
      @close="closeModal"
      ><div class="dx-modal-body">
        <h2>{{ invoice.patientName }}</h2>
        <p>{{ invoice.patientId }} · Hisob {{ invoice.id }}</p>
        <div class="cs-filters">
          <input
            v-model="serviceQuery"
            placeholder="Xizmat nomi yoki kodi..."
            aria-label="Xizmat qidirish"
          /><select v-model="category" aria-label="Xizmat kategoriyasi">
            <option value="">Barcha kategoriyalar</option>
            <option
              v-for="c in catalog.categories.filter((c) => c.active)"
              :key="c.id"
              :value="c.id"
            >
              {{ c.name }}
            </option>
          </select>
        </div>
        <div class="cs-table-scroll">
          <table class="cs-services">
            <thead>
              <tr>
                <th>Tanlash</th>
                <th>Xizmat</th>
                <th>Davomiylik</th>
                <th>Narx</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in availableServices" :key="s.id">
                <td>
                  <input
                    v-model="chosen"
                    type="checkbox"
                    :value="s.id"
                    :disabled="invoice.lines.some((l) => l.serviceId === s.id)"
                    :aria-label="s.name"
                    :checked="
                      invoice.lines.some((l) => l.serviceId === s.id) ||
                      chosen.includes(s.id)
                    "
                  />
                </td>
                <td>
                  {{ s.name
                  }}<small
                    v-if="invoice.lines.some((l) => l.serviceId === s.id)"
                    >Hisobda mavjud</small
                  >
                </td>
                <td>{{ s.duration ? `${s.duration} daqiqa` : "—" }}</td>
                <td>{{ money(s.price) }}</td>
              </tr>
              <tr v-if="!availableServices.length">
                <td colspan="4">Xizmat topilmadi</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="dx-info">
          Hisobdagi xizmatlar qayta qo‘shilmaydi. Miqdor hisob jadvalida
          o‘zgartiriladi.
        </p>
        <p>
          Tanlandi: {{ chosen.length }} ta xizmat · Jami:
          {{ money(chosenTotal) }}
        </p>
        <p v-if="error" class="dx-error" role="alert">{{ error }}</p>
      </div>
      <template #footer="{ close }"
        ><footer>
          <button class="dx-button" @click="close">Bekor qilish</button
          ><button
            class="dx-button dx-primary"
            :disabled="!chosen.length"
            @click="appendServices"
          >
            Hisobga qo‘shish
          </button>
        </footer></template
      ></Dialog
    >
    <Dialog
      v-if="modal === 'success' && success"
      class="dx-dialog cs-dialog cs-success-dialog"
      title="To‘lov qabul qilindi"
      subtitle="Hisob lokal namuna rejimida yangilandi"
      @close="closeModal"
      ><div class="dx-modal-body">
        <div class="cs-success-icon"><Check :size="60" /></div>
        <div class="cs-clinic">
          <b>{{ success.patientName }}</b
          ><span>{{ success.invoiceId }} · Chek {{ success.id }}</span>
        </div>
        <dl>
          <dt>Ushbu to‘lov</dt>
          <dd>{{ money(success.amount) }}</dd>
          <dt>To‘lov usuli</dt>
          <dd>{{ methodNames[method(success)] }}</dd>
          <dt>Naqd qism</dt>
          <dd>{{ money(success.cash) }}</dd>
          <dt>Karta qism</dt>
          <dd>{{ money(success.card) }}</dd>
          <dt>Qabul qilingan naqd</dt>
          <dd>{{ money(success.tendered) }}</dd>
          <dt>Qolgan qarz (to‘lov paytida)</dt>
          <dd>{{ money(success.debtAfter) }}</dd>
        </dl>
        <div class="cs-change">
          Qaytim <b>{{ money(success.change) }}</b>
        </div>
        <p>Kassir: {{ success.cashier.name }} · {{ timestamp(success.at) }}</p>
        <p v-if="error" class="dx-error" role="alert">{{ error }}</p>
      </div>
      <template #footer
        ><footer>
          <button class="dx-button" @click="showReceipt(success, 'success')">
            <Eye :size="18" />Chekni ko‘rish</button
          ><button class="dx-button" @click="print(success)">Chop etish</button
          ><button class="dx-button dx-primary" @click="nextPatient">
            Keyingi bemor
          </button>
        </footer></template
      ></Dialog
    >
    <Dialog
      v-if="modal === 'receipt' && receipt"
      class="dx-dialog cs-dialog cs-receipt-dialog"
      title="Chekni ko‘rish"
      :subtitle="`${receipt.id} · ${receipt.invoiceId}`"
      @close="
        modal = receiptBack;
        error = '';
      "
      ><div class="dx-modal-body">
        <ReceiptPreview
          :record="receipt"
          :payment="
            'paymentId' in receipt ? originalPayment(receipt) : undefined
          "
        />
        <p v-if="error" class="dx-error" role="alert">{{ error }}</p>
      </div>
      <template #footer
        ><footer>
          <button class="dx-button" :disabled="exporting" @click="pdf(receipt)">
            PDF yuklab olish</button
          ><button class="dx-button dx-primary" @click="print(receipt)">
            <Printer :size="18" />Chop etish
          </button>
        </footer></template
      ></Dialog
    >
    <Dialog
      v-if="modal === 'payment' && paymentDetail"
      class="dx-dialog cs-dialog cs-wide"
      title="To‘lov tafsilotlari"
      :subtitle="`Chek ${paymentDetail.id} · Hisob ${paymentDetail.invoiceId}`"
      @close="closeModal"
      ><div class="dx-modal-body">
        <div class="cs-clinic">
          <b>{{ paymentDetail.patientName }}</b
          ><span>{{ paymentDetail.patientId }}</span>
        </div>
        <h2>Ushbu to‘lov</h2>
        <div class="cs-total">
          <strong>{{ money(paymentDetail.amount) }}</strong
          ><span
            >{{ methodNames[method(paymentDetail)] }} ·
            {{ timestamp(paymentDetail.at) }} ·
            {{ paymentDetail.cashier.name }}</span
          >
        </div>
        <dl>
          <dt>Naqd / Karta</dt>
          <dd>
            {{ money(paymentDetail.cash) }} / {{ money(paymentDetail.card) }}
          </dd>
          <dt>Berilgan naqd / Qaytim</dt>
          <dd>
            {{ money(paymentDetail.tendered) }} /
            {{ money(paymentDetail.change) }}
          </dd>
        </dl>
        <h3>To‘lov paytidagi xizmatlar</h3>
        <table class="cs-services">
          <tbody>
            <tr v-for="l in paymentDetail.lines" :key="l.id">
              <td>{{ l.name }}</td>
              <td>{{ l.qty }}</td>
              <td>{{ money(l.price * l.qty) }}</td>
            </tr>
          </tbody>
        </table>
        <h3>Hisobning joriy holati</h3>
        <dl>
          <dt>Jami hisob (joriy)</dt>
          <dd>{{ money(currentPaymentTotals?.total ?? 0) }}</dd>
          <dt>Jami sof to‘langan (joriy)</dt>
          <dd>{{ money(currentPaymentTotals?.paid ?? 0) }}</dd>
          <dt>Qolgan qarz (joriy)</dt>
          <dd>{{ money(currentPaymentTotals?.debt ?? 0) }}</dd>
          <dt>Hisobdagi ortiqcha to‘lov (joriy)</dt>
          <dd>{{ money(currentPaymentTotals?.credit ?? 0) }}</dd>
        </dl>
        <p class="cs-muted">
          Qarz hisobning joriy holati bo‘yicha. Chek esa o‘sha operatsiyaning
          o‘zgarmas nusxasi.
        </p>
        <p v-if="error" class="dx-error" role="alert">{{ error }}</p>
      </div>
      <template #footer
        ><footer>
          <button class="dx-button" @click="closeModal">Yopish</button
          ><button
            class="dx-button"
            @click="showReceipt(paymentDetail, 'payment')"
          >
            Chekni ko‘rish</button
          ><button class="dx-button dx-primary" @click="print(paymentDetail)">
            Chop etish
          </button>
        </footer></template
      ></Dialog
    >
    <Dialog
      v-if="modal === 'refund-create'"
      class="dx-dialog cs-dialog cs-wide"
      title="Qaytarish yaratish"
      subtitle="Asl to‘lov bo‘yicha mablag‘ni qaytarish"
      :dirty="!!Object.keys(refundChosen).length || !!refundNote"
      @close="closeModal"
      ><div class="dx-modal-body">
        <label class="cs-label"
          >Asl to‘lovni topish<input
            v-model="refundSearch"
            aria-label="Asl to‘lovni qidirish"
            placeholder="Chek, bemor yoki telefon..."
        /></label>
        <div v-if="!refundPayment" class="cs-refund-search">
          <button
            v-for="p in refundResults"
            :key="p.id"
            @click="choosePayment(p)"
          >
            {{ p.id }} · {{ p.patientName }} · {{ money(p.amount) }} ·
            {{ methodNames[method(p)] }}
          </button>
          <p v-if="!refundResults.length">Asl to‘lov topilmadi</p>
        </div>
        <template v-if="refundPayment && refundable"
          ><div class="cs-clinic">
            <b
              >{{ refundPayment.patientName }} ·
              {{ refundPayment.patientId }}</b
            ><span
              >{{ refundPayment.id }} · {{ timestamp(refundPayment.at) }} ·
              {{ refundPayment.cashier.name }} ·
              {{ methodNames[method(refundPayment)] }}</span
            ><button
              class="dx-link"
              @click="
                refundPaymentId = '';
                refundChosen = {};
              "
            >
              Boshqa to‘lovni tanlash
            </button>
          </div>
          <div class="cs-refund-summary">
            <span
              >To‘langan<b>{{ money(refundPayment.amount) }}</b></span
            ><span
              >Avval qaytarilgan<b>{{
                money(refundPayment.amount - refundable.amount)
              }}</b></span
            ><span
              >Qaytarish mumkin<b>{{ money(refundable.amount) }}</b></span
            >
          </div>
          <h3>Qaytariladigan xizmatlar</h3>
          <div class="cs-table-scroll">
            <table class="cs-services">
              <thead>
                <tr>
                  <th>Tanlash</th>
                  <th>Xizmat</th>
                  <th>Qaytarish mumkin</th>
                  <th>Qaytarish summasi</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="l in refundable.lines" :key="l.id">
                  <td>
                    <input
                      type="checkbox"
                      :disabled="l.available <= 0"
                      :checked="l.id in refundChosen"
                      :aria-label="`${l.name}ni qaytarish`"
                      @change="
                        toggleRefund(
                          l.id,
                          ($event.target as HTMLInputElement).checked,
                        )
                      "
                    />
                  </td>
                  <td>{{ l.name }}</td>
                  <td>{{ money(l.available) }}</td>
                  <td>
                    <input
                      v-if="l.id in refundChosen"
                      v-model="refundChosen[l.id]"
                      inputmode="numeric"
                      :aria-label="`${l.name} qaytarish summasi`"
                      @input="splitRefund"
                    /><span v-else>—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="cs-form-grid">
            <label class="cs-label"
              >Qaytarish sababi *<select
                v-model="refundReason"
                aria-label="Qaytarish sababi"
              >
                <option value="">Tanlang</option>
                <option value="cancel">Xizmat bekor qilindi</option>
                <option value="duplicate">Takroriy to‘lov</option>
                <option value="other">Boshqa</option>
              </select></label
            >
            <div>
              <h4>
                Asl to‘lov usuli: {{ methodNames[method(refundPayment)] }}
              </h4>
              <MoneyField
                v-model="refundCash"
                label="Naqd qaytarish"
                :disabled="!refundable.cash"
              /><MoneyField
                v-model="refundCard"
                label="Kartaga qaytarish"
                :disabled="!refundable.card"
              />
            </div>
          </div>
          <label class="cs-label"
            >Izoh {{ refundReason === "other" ? "*" : ""
            }}<textarea
              v-model="refundNote"
              aria-label="Qaytarish izohi"
              maxlength="1000"
            />
          </label>
          <p class="dx-info">
            Xizmat bekor qilinsa uning qolgan narxi alohida bekor qilish
            yozuviga tushadi; qaytarish asl to‘lovni o‘zgartirmaydi. Boshqa
            sabablarda xizmat faol qoladi va hisob qarzi qayta paydo bo‘lishi
            mumkin.
          </p>
          <div class="cs-total">
            Jami qaytariladi <strong>{{ money(refundSum) }}</strong>
          </div></template
        >
        <p v-if="error" class="dx-error" role="alert">{{ error }}</p>
      </div>
      <template #footer="{ close }"
        ><footer>
          <button class="dx-button" @click="close">Bekor qilish</button
          ><button
            class="dx-button dx-primary"
            :disabled="saving || !refundPayment || !refundSum"
            @click="confirmRefund"
          >
            Qaytarishni tasdiqlash
          </button>
        </footer></template
      ></Dialog
    >
    <Dialog
      v-if="modal === 'refund-detail' && refundDetail && refOriginal"
      class="dx-dialog cs-dialog cs-refund-dialog"
      title="Qaytarish tafsilotlari"
      :subtitle="`${refundDetail.id} · Asl to‘lov: ${refundDetail.paymentId}`"
      @close="closeModal"
      ><div class="dx-modal-body cs-refund-detail">
        <section>
          <span class="cs-refunded">✓ Qaytarildi</span>
          <div class="cs-clinic">
            <b>{{ refundDetail.patientName }}</b
            ><span>{{ refundDetail.patientId }}</span>
          </div>
          <dl>
            <dt>Qaytarilgan vaqt</dt>
            <dd>{{ timestamp(refundDetail.at) }}</dd>
            <dt>Kassir</dt>
            <dd>{{ refundDetail.cashier.name }}</dd>
            <dt>Qaytarish usuli</dt>
            <dd>{{ methodNames[method(refundDetail)] }}</dd>
            <dt>Naqd / Karta</dt>
            <dd>
              {{ money(refundDetail.cash) }} / {{ money(refundDetail.card) }}
            </dd>
            <dt>Asl to‘lov sanasi</dt>
            <dd>{{ timestamp(refOriginal.at) }}</dd>
          </dl>
          <h3>Qaytarilgan xizmatlar</h3>
          <table class="cs-services">
            <tbody>
              <tr v-for="l in refundDetail.lines" :key="l.id">
                <td>{{ l.name }}</td>
                <td>{{ money(l.amount) }}</td>
              </tr>
            </tbody>
          </table>
          <p>
            Sabab:
            {{
              {
                cancel: "Xizmat bekor qilindi",
                duplicate: "Takroriy to‘lov",
                other: "Boshqa",
              }[refundDetail.reason]
            }}
          </p>
          <p>Izoh: {{ refundDetail.note || "—" }}</p>
          <dl>
            <dt>Asl to‘lov</dt>
            <dd>{{ money(refOriginal.amount) }}</dd>
            <dt>Jami qaytarilgan (joriy)</dt>
            <dd>{{ money(refPriorTotal) }}</dd>
            <dt>Qaytarilmagan to‘lov qismi</dt>
            <dd>{{ money(refOriginal.amount - refPriorTotal) }}</dd>
          </dl>
          <button class="dx-link" @click="viewPayment(refOriginal)">
            <Eye :size="18" />Asl to‘lovni ko‘rish
          </button>
        </section>
        <section>
          <h2>Qaytarish cheki</h2>
          <ReceiptPreview :record="refundDetail" :payment="refOriginal" />
        </section>
        <p v-if="error" class="dx-error" role="alert">{{ error }}</p>
      </div>
      <template #footer
        ><footer>
          <button class="dx-button" @click="closeModal">Yopish</button
          ><button
            class="dx-button"
            :disabled="exporting"
            @click="pdf(refundDetail)"
          >
            PDF yuklash</button
          ><button class="dx-button dx-primary" @click="print(refundDetail)">
            Chekni chop etish
          </button>
        </footer></template
      ></Dialog
    >
    <Dialog
      v-if="modal === 'close' && shift && totals"
      class="dx-dialog cs-dialog cs-close-dialog"
      title="Kassani yopish"
      subtitle="Smena yakunidagi naqd pulni tekshiring"
      :icon="Wallet"
      @close="closeModal"
      ><div class="dx-modal-body">
        <div class="cs-clinic">
          {{ cashierConfig.clinic }} · {{ cashierConfig.branch }} ·
          {{ cashierConfig.register }}
        </div>
        <dl>
          <dt>Kassir</dt>
          <dd>{{ shift.cashier.name }}</dd>
          <dt>Ochildi</dt>
          <dd>{{ timestamp(shift.openedAt) }}</dd>
        </dl>
        <h3>Smena hisoboti</h3>
        <dl>
          <dt>Boshlang‘ich naqd pul</dt>
          <dd>{{ money(shift.opening) }}</dd>
          <dt>Naqd tushum</dt>
          <dd>{{ money(totals.cash) }}</dd>
          <dt>Naqd qaytarishlar</dt>
          <dd>−{{ money(totals.cashRefund) }}</dd>
          <dt>Kutilayotgan naqd pul</dt>
          <dd>{{ money(totals.expected) }}</dd>
        </dl>
        <h3>Karta operatsiyalari</h3>
        <dl>
          <dt>Karta tushumi</dt>
          <dd>{{ money(totals.card) }}</dd>
          <dt>Kartaga qaytarilgan</dt>
          <dd>{{ money(totals.cardRefund) }}</dd>
          <dt>Karta bo‘yicha sof tushum</dt>
          <dd>{{ money(totals.cardNet) }}</dd>
        </dl>
        <p class="cs-muted">Karta summalari naqd qoldiqqa qo‘shilmaydi.</p>
        <MoneyField v-model="actual" label="Amaldagi naqd pul" />
        <div
          :class="
            numberOrZero(actual) === totals.expected ? 'cs-change' : 'cs-debt'
          "
        >
          {{ varianceLabel()
          }}<b>{{ money(numberOrZero(actual) - totals.expected) }}</b>
        </div>
        <label class="cs-label"
          >Izoh {{ numberOrZero(actual) !== totals.expected ? "*" : ""
          }}<textarea
            v-model="closingNote"
            aria-label="Smena yopish izohi"
            maxlength="1000"
          />
        </label>
        <p class="cs-muted">
          Yopilgach, PIN holati tozalanadi. Keyingi smena alohida ochiladi.
        </p>
        <p v-if="error" class="dx-error" role="alert">{{ error }}</p>
      </div>
      <template #footer
        ><footer>
          <button class="dx-button" @click="closeModal">Bekor qilish</button
          ><button class="dx-button dx-primary" @click="close">
            Kassani yopish
          </button>
        </footer></template
      ></Dialog
    >
  </div>
</template>
