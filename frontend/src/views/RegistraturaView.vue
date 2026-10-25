<script setup lang="ts">
import { computed, ref, watch, onBeforeUnmount, nextTick } from "vue";
import {
  Users,
  CalendarDays,
  Stethoscope,
  Clock3,
  CheckCircle2,
  Coins,
  UserRoundPlus,
  Search,
  Eye,
  MoreHorizontal,
  Filter,
  ChevronLeft,
  ChevronRight,
  ClipboardList,
} from "lucide-vue-next";
import BookingDialog from "../components/reception/BookingDialog.vue";
import ArrivalDialog from "../components/reception/ArrivalDialog.vue";
import ReceptionDialog from "../components/reception/ReceptionDialog.vue";
import DateField from "../components/employees/DateField.vue";
import {
  createReceptionState,
  doctors as demoDoctors,
  initials,
  uzDate,
  nextPatient,
  checkIn,
  blankBooking,
  availability,
  type ReceptionPatient,
  type ReceptionAppointment,
  type PatientDraft,
} from "../mocks/reception";
import "../components/reception/reception.css";
import { canReception } from "../services/receptionAccess";
import { can, session } from "../services/session";
import { clinicRequest, errorText } from "../services/api";
import type { Patient } from "../services/operations";
import {
  bookingCatalog,
  bookingAvailability,
  localDay,
  localTime,
  dayRange,
  type BookingDoctor,
  type BookingAppointment,
  type BookingSlot,
  type BookingPreset,
} from "../services/receptionBooking";
// Preserve the separate legacy arrival demo; it never supplies the API table.
const state = ref(createReceptionState());
const branches = computed(() => session.branches.filter((b) => b.active));
const branch = ref(session.branch || branches.value[0]?.id || "");
const zone = computed(
  () => branches.value.find((b) => b.id === branch.value)?.timezone || "",
);
const today = computed(() =>
  zone.value ? localDay(Date.now(), zone.value) : "",
);
const listDate = ref(today.value),
  tab = ref("all"),
  query = ref(""),
  doctor = ref(""),
  status = ref(""),
  page = ref(1),
  toast = ref("");
const doctors = ref<BookingDoctor[]>([]),
  catalogError = ref(""),
  loading = ref(false),
  error = ref(""),
  total = ref(0),
  highlight = ref("");
interface Row extends BookingAppointment {
  date: string;
  time: string;
  patientName: string;
  patientNumber: string;
  phone: string;
  dob: string;
  doctorName: string;
  serviceName: string;
}
const rows = ref<Row[]>([]),
  booking = ref(false),
  arrival = ref(false),
  preset = ref<ReceptionAppointment>(),
  bookingPreset = ref<BookingPreset>({}),
  detail = ref<Row>(),
  menu = ref("");
const counts = ref<Record<string, number>>({}),
  scheduleDoctor = ref(""),
  scheduleService = ref(""),
  scheduleRows = ref<Row[]>([]),
  freeSlots = ref<BookingSlot[]>([]),
  scheduleError = ref(""),
  scheduleLoading = ref(false);
let alive = true,
  listSerial = 0,
  catalogSerial = 0,
  sideSerial = 0,
  searchTimer: ReturnType<typeof setTimeout>;
onBeforeUnmount(() => {
  alive = false;
  listSerial++;
  catalogSerial++;
  sideSerial++;
  clearTimeout(searchTimer);
});
const statusLabels: Record<string, string> = {
  scheduled: "Yozilgan",
  checked_in: "Kelgan",
  in_progress: "Qabulda",
  completed: "Yakunlangan",
  cancelled: "Bekor qilingan",
  no_show: "Kelmagan",
};
const tabs = computed(() => [
  { id: "all", name: "Barcha bemorlar", count: counts.value.all ?? 0 },
  { id: "walk_in", name: "Jonli navbat", count: "—" },
  {
    id: "scheduled",
    name: "Oldindan yozilgan",
    count: counts.value.scheduled ?? 0,
  },
  { id: "in_progress", name: "Qabulda", count: counts.value.in_progress ?? 0 },
  { id: "completed", name: "Yakunlangan", count: counts.value.completed ?? 0 },
]);
const stats = computed(() => [
  {
    name: "Tanlangan sana qabullari",
    value: String(counts.value.all ?? 0),
    icon: Users,
    tone: "blue",
  },
  {
    name: "Oldindan yozilgan",
    value: String(counts.value.scheduled ?? 0),
    icon: CalendarDays,
    tone: "green",
  },
  { name: "Jonli navbat", value: "—", icon: Stethoscope, tone: "amber" },
  {
    name: "Qabulda",
    value: String(counts.value.in_progress ?? 0),
    icon: Clock3,
    tone: "rose",
  },
  {
    name: "Yakunlangan",
    value: String(counts.value.completed ?? 0),
    icon: CheckCircle2,
    tone: "violet",
  },
  { name: "Tushum", value: "—", icon: Coins, tone: "teal" },
]);
const person = (a: Row) => ({
  name: a.patientName,
  id: a.patientNumber,
  phone: a.phone,
  dob: a.dob,
});
const physician = (a: Row) => ({
  name: a.doctorName,
  fullName: a.doctorName,
  specialty: a.serviceName,
});
async function present(appointments: BookingAppointment[]): Promise<Row[]> {
  const patients = new Map<string, Patient>();
  if (can("patients.view"))
    await Promise.all(
      [...new Set(appointments.map((a) => a.patient_id))].map(async (id) => {
        const r = await clinicRequest<Patient>(`patients/${id}`);
        patients.set(id, r.data);
      }),
    );
  return appointments.map((a) => {
    const p = patients.get(a.patient_id),
      d = doctors.value.find((d) => d.id === a.doctor_id),
      tz =
        branches.value.find((b) => b.id === a.branch_id)?.timezone ||
        zone.value;
    return {
      ...a,
      date: localDay(a.starts_at, tz),
      time: localTime(a.starts_at, tz),
      patientName: p?.name ?? "Bemor ma’lumoti uchun ruxsat kerak",
      patientNumber: p?.patient_number ?? "—",
      phone: p?.phone ?? "",
      dob: p?.date_of_birth ?? "",
      doctorName: d?.name ?? "Shifokor ma’lumoti uchun ruxsat kerak",
      serviceName: d?.services.find((s) => s.id === a.service_id)?.name ?? "—",
    };
  });
}
function filters() {
  return {
    branch_id: branch.value,
    ...dayRange(listDate.value, zone.value),
    ...(doctor.value ? { doctor_id: doctor.value } : {}),
    ...(query.value.trim() ? { q: query.value.trim() } : {}),
  };
}
// A view change invalidates a pending response before a debounced read starts.
let listRevision = 0;
let changingList = false;
function updateListState(update: () => void) {
  const previous = changingList;
  changingList = true;
  try {
    update();
  } finally {
    changingList = previous;
  }
}
async function load() {
  const serial = ++listSerial;
  const revision = listRevision;
  loading.value = true;
  error.value = "";
  rows.value = [];
  total.value = 0;
  if (
    !can("appointments.view") ||
    !branch.value ||
    !zone.value ||
    !listDate.value
  ) {
    loading.value = false;
    error.value =
      "Qabullarni ko‘rish uchun ruxsat va filial vaqt mintaqasi kerak.";
    return;
  }
  try {
    const criteria = filters();
    const r = await clinicRequest<{
      data: BookingAppointment[];
      meta: { total: number };
    }>("appointments", {
      params: {
        ...criteria,
        page: page.value,
        per_page: 8,
        ...(status.value || tab.value !== "all"
          ? { status: status.value || tab.value }
          : {}),
      },
    });
    const projected = await present(r.data.data);
    const totals = await Promise.all(
      ["all", "scheduled", "in_progress", "completed"].map(async (status) => {
        const r = await clinicRequest<{ meta: { total: number } }>(
          "appointments",
          {
            params: {
              ...criteria,
              per_page: 1,
              ...(status === "all" ? {} : { status }),
            },
          },
        );
        return [status, r.data.meta.total] as const;
      }),
    );
    if (alive && serial === listSerial && revision === listRevision) {
      rows.value = projected;
      total.value = r.data.meta.total;
      counts.value = Object.fromEntries(totals);
    }
    // Reveal navigation uses its own completed response, not refs temporarily
    // cleared by another load (for example the branch catalog finishing).
    return { rows: projected, total: r.data.meta.total };
  } catch (e) {
    if (alive && serial === listSerial && revision === listRevision)
      error.value = errorText(e);
  } finally {
    if (alive && serial === listSerial && revision === listRevision)
      loading.value = false;
  }
}
watch(
  () => [
    branch.value,
    listDate.value,
    doctor.value,
    status.value,
    tab.value,
    session.epoch,
  ],
  () => {
    listRevision++;
    clearTimeout(searchTimer);
    if (changingList) return;
    updateListState(() => {
      page.value = 1;
    });
    void load();
  },
  { flush: "sync" },
);
watch(
  page,
  () => {
    if (changingList) return;
    listRevision++;
    clearTimeout(searchTimer);
    void load();
  },
  { flush: "sync" },
);
watch(
  query,
  () => {
    listRevision++;
    clearTimeout(searchTimer);
    if (changingList) return;
    searchTimer = setTimeout(() => {
      updateListState(() => {
        page.value = 1;
      });
      void load();
    }, 300);
  },
  { flush: "sync" },
);
watch(
  branch,
  async (bid) => {
    const serial = ++catalogSerial;
    doctors.value = [];
    scheduleDoctor.value = "";
    doctor.value = "";
    catalogError.value = "";
    if (!listDate.value && zone.value) listDate.value = today.value;
    if (
      !bid ||
      !can("doctors.view") ||
      !can("rooms.view") ||
      !can("schedules.view") ||
      !can("services.view") ||
      !can("appointments.view")
    ) {
      void load();
      return;
    }
    try {
      const result = await bookingCatalog(bid);
      if (alive && serial === catalogSerial) {
        doctors.value = result.doctors;
        scheduleDoctor.value = result.doctors[0]?.id ?? "";
        await load();
      }
    } catch (e) {
      if (alive && serial === catalogSerial) catalogError.value = errorText(e);
    }
  },
  { immediate: true },
);
const pageCount = computed(() => Math.max(1, Math.ceil(total.value / 8)));
const sideDoctor = computed(() =>
  doctors.value.find((d) => d.id === scheduleDoctor.value),
);
watch(scheduleDoctor, () => {
  scheduleService.value = sideDoctor.value?.services[0]?.id ?? "";
});
async function loadSide() {
  const serial = ++sideSerial;
  scheduleRows.value = [];
  freeSlots.value = [];
  scheduleError.value = "";
  scheduleLoading.value = true;
  const d = scheduleDoctor.value,
    service = scheduleService.value,
    bid = branch.value,
    date = listDate.value,
    tz = zone.value;
  if (!d || !date || !tz || !can("appointments.view")) {
    scheduleLoading.value = false;
    return;
  }
  try {
    const appointments: BookingAppointment[] = [];
    for (let p = 1; ; p++) {
      const r = await clinicRequest<{
        data: BookingAppointment[];
        meta: { total: number };
      }>("appointments", {
        params: {
          branch_id: bid,
          doctor_id: d,
          ...dayRange(date, tz),
          page: p,
          per_page: 100,
        },
      });
      appointments.push(...r.data.data);
      if (appointments.length >= r.data.meta.total || !r.data.data.length)
        break;
    }
    const projected = await present(appointments);
    const available =
      service && canReception("book")
        ? await bookingAvailability(d, bid, service, date, tz)
        : [];
    if (alive && serial === sideSerial) {
      scheduleRows.value = projected;
      freeSlots.value = available;
    }
  } catch (e) {
    if (alive && serial === sideSerial) scheduleError.value = errorText(e);
  } finally {
    if (alive && serial === sideSerial) scheduleLoading.value = false;
  }
}
watch(
  () => [
    scheduleDoctor.value,
    scheduleService.value,
    listDate.value,
    branch.value,
    session.epoch,
  ],
  loadSide,
);
const schedule = computed(() =>
  [
    ...scheduleRows.value.map((a) => ({
      time: a.time,
      start: a.starts_at,
      appointment: a,
      slot: undefined as BookingSlot | undefined,
      busy: true,
      scheduled: true,
    })),
    ...freeSlots.value.map((s) => ({
      time: localTime(s.starts_at, zone.value),
      start: s.starts_at,
      appointment: undefined as Row | undefined,
      slot: s,
      busy: false,
      scheduled: true,
    })),
  ].sort((a, b) => a.start.localeCompare(b.start)),
);
function openBooking(partial: BookingPreset = {}) {
  if (!canReception("book")) return;
  bookingPreset.value = {
    branchId: branch.value,
    date: listDate.value,
    ...partial,
  };
  booking.value = true;
}
async function saved(appointment: BookingAppointment, date: string) {
  booking.value = false;
  listRevision++;
  clearTimeout(searchTimer);
  // These are one booking-reveal transition, not independent user filter edits.
  updateListState(() => {
    listDate.value = date;
    branch.value = appointment.branch_id;
    tab.value = "all";
    query.value = "";
    doctor.value = "";
    status.value = "";
    page.value = 1;
  });
  rows.value = [];
  total.value = 0;
  error.value = "";
  loading.value = true;
  highlight.value = appointment.id;
  toast.value = "Bemor qabulga muvaffaqiyatli yozildi.";
  await nextTick();
  const revision = listRevision;
  const current = () => alive && revision === listRevision;
  try {
    const range = dayRange(date, zone.value);
    if (Date.parse(appointment.starts_at) > Date.parse(range.from)) {
      const earlier = await clinicRequest<{ meta: { total: number } }>(
        "appointments",
        {
          params: {
            branch_id: appointment.branch_id,
            from: range.from,
            to: appointment.starts_at,
            per_page: 1,
          },
        },
      );
      if (!current()) return;
      updateListState(() => {
        page.value = Math.floor(earlier.data.meta.total / 8) + 1;
      });
    }
    let result = await load();
    if (!current()) return;
    while (
      result &&
      !result.rows.some((row) => row.id === appointment.id) &&
      page.value < Math.ceil(result.total / 8)
    ) {
      updateListState(() => {
        page.value++;
      });
      result = await load();
      if (!current()) return;
    }
    await loadSide();
  } catch (e) {
    if (current()) {
      error.value = errorText(e);
      loading.value = false;
    }
  }
}
function bookSlot(slot: BookingSlot) {
  openBooking({
    doctorId: scheduleDoctor.value,
    departmentId: sideDoctor.value?.specialty_id ?? undefined,
    serviceId: scheduleService.value,
    slot,
  });
}
function showAppointment(_a: ReceptionAppointment) {
  toast.value = "Kelish jarayoni — faqat lokal namuna.";
}
function openArrival(a?: ReceptionAppointment) {
  if (
    a
      ? !canReception("checkIn")
      : !canReception("checkIn") && !canReception("walkIn")
  )
    return;
  preset.value = a;
  arrival.value = true;
  menu.value = "";
}
function createPatient(d: PatientDraft) {
  if (!canReception("patient"))
    throw Error("Bemor qo‘shish uchun ruxsat yo‘q.");
  const p = nextPatient(state.value.patients, d);
  state.value.patients.push(p);
  return p;
}
function arrive(
  p: ReceptionPatient,
  id: string | null,
  doctorId: string,
  branchId: string,
) {
  if (!canReception(id ? "checkIn" : "walkIn")) return;
  if (id) {
    const a = state.value.appointments.find(
      (a) => a.id === id && a.patientId === p.id,
    );
    if (!a || !checkIn(a)) return;
  } else {
    if (
      state.value.appointments.some(
        (a) => a.patientId === p.id && a.date === today.value,
      )
    ) {
      toast.value = "Bugungi mavjud qabulni tanlang. Bemor qayta qo‘shilmadi.";
      return;
    }
    const d = demoDoctors.find(
      (d) => d.id === doctorId && d.branches.includes(branchId),
    );
    if (
      !d ||
      !availability(
        { ...blankBooking(), doctorId, branchId, departmentId: d.departmentId },
        state.value.appointments,
      ).scheduled
    )
      return;
    state.value.appointments.push({
      id: `A-${state.value.appointments.length + 1}`,
      patientId: p.id,
      doctorId,
      branchId,
      departmentId: d.departmentId,
      serviceId: "initial",
      date: today.value,
      time: new Date().toTimeString().slice(0, 5),
      status: "waiting",
      kind: "walk_in",
      note: "",
    });
  }
  const appointment =
    state.value.appointments.find((a) => a.id === id) ??
    state.value.appointments.at(-1);
  if (appointment) showAppointment(appointment);
  arrival.value = false;
  toast.value = "Keldi — navbatga qo‘shildi (faqat lokal namuna).";
}

function focusSearch() {
  document.getElementById("reception-query")?.focus();
}
</script>
<template>
  <section class="rp-page">
    <header class="rp-heading">
      <div>
        <h1>
          Registratura
          <small
            class="rp-demo-badge"
            title="Qabullar serverdan olinadi. Kelish va jonli navbat alohida lokal namuna."
            >Qabullar API</small
          >
        </h1>
        <p>Bemorlarni ro‘yxatga olish, qabulga yozish va navbatni boshqarish</p>
      </div>
      <div class="rp-heading-actions">
        <button
          class="rp-button rp-outline"
          data-permission="appointments.create"
          :disabled="!canReception('book')"
          @click="openBooking()"
        >
          <CalendarDays :size="19" /> Qabulga yozish</button
        ><button
          class="rp-button rp-primary"
          data-permission="reception.view"
          :disabled="!canReception('checkIn') && !canReception('walkIn')"
          @click="openArrival()"
        >
          <UserRoundPlus :size="19" /> Bemorni ro‘yxatga olish
        </button>
      </div>
    </header>

    <div v-if="toast" class="rp-toast" role="status">
      {{ toast }}
      <button aria-label="Xabarni yopish" @click="toast = ''">×</button>
    </div>
    <div class="rp-stats">
      <article v-for="s in stats" :key="s.name" class="rp-stat" :class="s.tone">
        <span class="rp-stat-icon"><component :is="s.icon" :size="24" /></span>
        <div>
          <p>{{ s.name }}</p>
          <strong>{{ s.value }}</strong
          ><small>{{
            ["Tushum", "Jonli navbat"].includes(s.name)
              ? "Hozircha ulanmagan"
              : "Qabullar API"
          }}</small>
        </div>
      </article>
    </div>
    <div class="rp-layout">
      <section class="rp-panel rp-list">
        <nav class="rp-tabs" aria-label="Qabul holatlari">
          <button
            v-for="t in tabs"
            :key="t.id"
            :class="{ active: tab === t.id }"
            :aria-pressed="tab === t.id"
            data-permission="reception.view"
            :disabled="t.id === 'walk_in'"
            :title="
              t.id === 'walk_in'
                ? 'Jonli navbat bu bosqichda ulanmagan'
                : undefined
            "
            @click="
              tab = t.id;
              status = '';
            "
          >
            {{ t.name }} <span>{{ t.count }}</span>
          </button>
        </nav>
        <div class="rp-date-context">
          <label
            >Sana filtri
            <DateField v-model="listDate" label="Qabullar sanasi" /></label
          ><button class="rp-link" @click="listDate = today">Bugun</button
          ><span>{{ uzDate(listDate) }} qabullari</span>
        </div>
        <p v-if="catalogError" role="alert" class="rp-error">
          {{ catalogError }}
        </p>
        <p v-if="error" role="alert" class="rp-error">
          {{ error }}
          <button type="button" class="rp-link" @click="load">
            Qayta urinish
          </button>
        </p>
        <p v-if="loading" role="status" class="rp-empty">
          Qabullar yuklanmoqda…
        </p>
        <div class="rp-filters">
          <label class="rp-search"
            ><Search :size="18" /><input
              id="reception-query"
              v-model="query"
              aria-label="Ro‘yxatdan bemor qidirish"
              placeholder="Bemor qidirish..."
          /></label>
          <select v-model="branch" aria-label="Qabullar filiali">
            <option v-for="b in branches" :key="b.id" :value="b.id">
              {{ b.name }}
            </option></select
          ><select v-model="doctor" aria-label="Shifokor">
            <option value="">Shifokor</option>
            <option v-for="d in doctors" :key="d.id" :value="d.id">
              {{ d.name }}
            </option></select
          ><select v-model="status" aria-label="Holat" @change="tab = 'all'">
            <option value="">Holat</option>
            <option v-for="(label, id) in statusLabels" :key="id" :value="id">
              {{ label }}
            </option></select
          ><button
            class="rp-icon rp-filter-reset"
            title="Filtrlarni tozalash"
            aria-label="Filtrlarni tozalash"
            @click="
              query = '';
              doctor = '';
              status = '';
              tab = 'all';
            "
          >
            <Filter :size="19" />
          </button>
        </div>
        <div class="rp-table-scroll">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Bemor</th>
                <th>Shifokor / Xizmat</th>
                <th>Holat</th>
                <th>Vaqt</th>
                <th>Amallar</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(a, i) in rows"
                :key="a.id"
                :class="{ 'rp-new-booking': a.id === highlight }"
              >
                <td>{{ (page - 1) * 8 + i + 1 }}</td>
                <td>
                  <div class="rp-person">
                    <span class="rp-avatar">{{
                      initials(person(a).name)
                    }}</span>
                    <div>
                      <strong>{{ person(a).name }}</strong
                      ><small
                        >{{ person(a).id }} ·
                        {{ person(a).phone || "Telefon kiritilmagan" }}</small
                      >
                    </div>
                  </div>
                </td>
                <td>
                  <strong>{{ physician(a).name }}</strong
                  ><small>{{ physician(a).specialty }}</small>
                </td>
                <td>
                  <span class="rp-badge" :class="a.status"
                    ><CalendarDays
                      v-if="a.status === 'scheduled'"
                      :size="16"
                    /><Clock3
                      v-else-if="
                        a.status === 'checked_in' || a.status === 'in_progress'
                      "
                      :size="16"
                    /><CheckCircle2 v-else :size="16" />{{
                      statusLabels[a.status]
                    }}</span
                  >
                </td>
                <td>
                  <strong>{{ a.time }}</strong>
                </td>
                <td>
                  <div class="rp-row-actions">
                    <button
                      class="rp-button rp-soft"
                      :disabled="!canReception('detail')"
                      data-permission="appointments.view"
                      @click="detail = a"
                    >
                      <Eye :size="16" /> Ko‘rish
                    </button>
                    <div class="rp-more">
                      <button
                        class="rp-icon"
                        :aria-label="`${person(a).name} amallari`"
                        :aria-expanded="menu === a.id"
                        @click="menu = menu === a.id ? '' : a.id"
                      >
                        <MoreHorizontal :size="18" />
                      </button>
                      <div v-if="menu === a.id" class="rp-menu">
                        <button
                          data-permission="appointments.view"
                          @click="
                            detail = a;
                            menu = '';
                          "
                        >
                          Qabulni ko‘rish</button
                        ><button @click="menu = ''">Yopish</button>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
              <tr v-if="!loading && !error && !rows.length">
                <td colspan="6" class="rp-empty">
                  Bemor topilmadi. Qidiruv yoki filtrlarni o‘zgartiring.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <footer class="rp-pagination">
          <span
            >{{ total ? (page - 1) * 8 + 1 : 0 }}–{{
              Math.min(page * 8, total)
            }}
            / {{ total }} ta bemor</span
          >
          <div>
            <button
              class="rp-icon"
              :disabled="page === 1"
              data-permission="reception.view"
              aria-label="Oldingi sahifa"
              @click="page--"
            >
              <ChevronLeft :size="18" /></button
            ><button
              class="rp-button rp-primary"
              data-permission="reception.view"
              :aria-label="`Sahifa ${page}`"
            >
              {{ page }}</button
            ><button
              class="rp-icon"
              :disabled="page === pageCount"
              data-permission="reception.view"
              aria-label="Keyingi sahifa"
              @click="page++"
            >
              <ChevronRight :size="18" />
            </button>
          </div>
        </footer>
      </section>
      <aside class="rp-side">
        <section class="rp-panel rp-schedule">
          <h2>
            {{
              listDate === today
                ? "Bugungi qabullar"
                : "Belgilangan sana qabullari"
            }}
          </h2>
          <p class="rp-schedule-date">
            <CalendarDays :size="16" />{{ uzDate(listDate) }}
          </p>
          <label class="rp-doctor"
            ><span class="rp-avatar">{{
              initials(sideDoctor?.name || "")
            }}</span>
            <div>
              <select
                v-model="scheduleDoctor"
                aria-label="Qabul jadvali shifokori"
              >
                <option v-for="d in doctors" :key="d.id" :value="d.id">
                  {{ d.name }}
                </option></select
              ><small>{{ sideDoctor?.specialty_name }}</small>
            </div></label
          >
          <label class="rp-doctor"
            >Qabul turi<select
              v-model="scheduleService"
              aria-label="Jadval qabul turi"
            >
              <option
                v-for="s in sideDoctor?.services ?? []"
                :key="s.id"
                :value="s.id"
              >
                {{ s.name }}
              </option>
            </select></label
          >
          <p v-if="scheduleLoading" role="status" class="rp-empty">
            Jadval yuklanmoqda…
          </p>
          <p v-if="scheduleError" role="alert" class="rp-error">
            {{ scheduleError }}
          </p>
          <p
            v-if="!scheduleLoading && !scheduleError && !schedule.length"
            class="rp-empty"
          >
            Bu kun uchun shifokor jadvali belgilanmagan.
          </p>
          <div
            class="rp-timeline"
            tabindex="0"
            aria-label="Bugungi shifokor jadvali"
          >
            <div
              v-for="s in schedule"
              :key="s.start + (s.slot?.room_id ?? s.appointment?.id)"
              class="rp-timeline-row"
              :class="s.appointment?.status"
            >
              <span>{{ s.time }}</span>
              <div v-if="s.appointment">
                <strong>{{ person(s.appointment).name }}</strong
                ><small>{{ person(s.appointment).id }}</small>
              </div>
              <span v-else class="rp-slot-empty">{{
                !s.scheduled ? "Jadval yo‘q" : s.busy ? "Band" : "Bo‘sh"
              }}</span
              ><span
                v-if="s.appointment"
                class="rp-badge"
                :class="s.appointment.status"
                >{{ statusLabels[s.appointment.status] }}</span
              ><button
                v-else-if="!s.busy && s.scheduled"
                class="rp-button rp-outline"
                :aria-label="`${s.time} vaqtiga yozish`"
                data-permission="appointments.create"
                :disabled="!canReception('book')"
                @click="s.slot && bookSlot(s.slot)"
              >
                + Yozish
              </button>
            </div>
          </div>
        </section>
        <section class="rp-panel rp-quick">
          <h2>Tezkor amallar</h2>
          <div>
            <button @click="focusSearch">
              <Search :size="23" />Bemor qidirish</button
            ><button
              data-permission="appointments.create"
              :disabled="!canReception('book')"
              @click="openBooking()"
            >
              <CalendarDays :size="23" />Qabulga yozish</button
            ><button
              @click="
                toast = 'Jonli navbat bu bosqichda ulanmagan.';
                doctor = '';
                status = '';
                query = '';
              "
            >
              <ClipboardList :size="23" />Navbatni ko‘rish
            </button>
          </div>
        </section>
      </aside>
    </div>
    <BookingDialog
      v-if="booking"
      :preset="bookingPreset"
      @close="booking = false"
      @saved="saved"
    /><ArrivalDialog
      v-if="arrival"
      :patients="state.patients"
      :appointments="state.appointments"
      :preset="preset"
      :create-patient="createPatient"
      @close="arrival = false"
      @arrive="arrive"
    /><ReceptionDialog
      v-if="detail"
      title="Qabul ma’lumotlari"
      subtitle="Qabullar API · faqat ko‘rish uchun"
      @close="detail = undefined"
      ><div class="rp-dialog-body rp-details">
        <h3>{{ person(detail).name }}</h3>
        <p>
          {{ person(detail).id }} · {{ person(detail).dob }} ·
          {{ person(detail).phone }}
        </p>
        <p><b>Shifokor:</b> {{ physician(detail).fullName }}</p>
        <p>
          <b>Filial:</b>
          {{ branches.find((b) => b.id === detail!.branch_id)?.name }}
        </p>
        <p>
          <b>Sana va vaqt:</b> {{ detail.date }} · {{ detail.time }}–{{
            localTime(detail.ends_at, zone)
          }}
        </p>
        <p><b>Holati:</b> {{ statusLabels[detail.status] }}</p>
        <p><b>Izoh:</b> {{ detail.note || "Izoh kiritilmagan" }}</p>
      </div>
      <template #footer
        ><button class="rp-button rp-primary" @click="detail = undefined">
          Yopish
        </button></template
      ></ReceptionDialog
    >
  </section>
</template>
