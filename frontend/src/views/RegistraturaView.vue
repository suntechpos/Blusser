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
import { initials, uzDate } from "../mocks/reception";
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
  nextDay,
  type BookingDoctor,
  type BookingAppointment,
  type BookingSlot,
  type BookingPreset,
} from "../services/receptionBooking";
const branches = computed(() => session.branches.filter((b) => b.active));
const branch = ref(session.branch || branches.value[0]?.id || "");
const zone = computed(
  () => branches.value.find((b) => b.id === branch.value)?.timezone || "",
);
const today = computed(() =>
  zone.value ? localDay(Date.now(), zone.value) : "",
);
// Keep the last selected reception date for this browser tab, user, clinic
// and branch. A booking scheduled for tomorrow must not appear to disappear
// simply because the page reloads and defaults back to today's live queue.
function receptionDateKey(branchId: string): string | null {
  const userId = session.user?.id;
  const tenantId = session.user?.active_tenant_id;
  return userId && tenantId && branchId
    ? `blusser.reception.selectedDate.v1:${userId}:${tenantId}:${branchId}`
    : null;
}
function validReceptionDate(value: string | null): value is string {
  if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const parsed = new Date(`${value}T12:00:00Z`);
  return !Number.isNaN(parsed.getTime()) &&
    parsed.toISOString().slice(0, 10) === value;
}
function rememberedReceptionDate(branchId: string): string | null {
  const key = receptionDateKey(branchId);
  if (!key || typeof window === "undefined") return null;
  try {
    const date = window.sessionStorage.getItem(key);
    return validReceptionDate(date) ? date : null;
  } catch {
    return null; // Restricted storage must not prevent registration.
  }
}
const listDate = ref(rememberedReceptionDate(branch.value) ?? today.value),
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
  // Saving a booking on another date must not replace the current live queue.
  recentBooking = ref<{ appointment: BookingAppointment; date: string } | null>(null),
  booking = ref(false),
  arrival = ref(false),
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
interface QueueTicket {
  id: string;
  patient_id: string;
  doctor_id: string;
  branch_id: string;
  number: number;
  status: string;
  kind: "scheduled" | "walk_in";
  local_date: string;
  version: number;
  visit_id: string;
  appointment_id: string | null;
}
interface QueueRow extends QueueTicket {
  patientName: string;
  patientNumber: string;
  doctorName: string;
}
type UnifiedEntry =
  | { source: "appointment"; appointment: Row }
  | { source: "walk_in"; ticket: QueueRow; serviceName: string };
const unifiedRows = ref<UnifiedEntry[]>([]);
const allDayCount = ref<number | null>(null);
const queueRows = ref<QueueRow[]>([]);
const queueCount = ref<number | null>(null);
let queueCountSequence = 0;
const tabs = computed(() => [
  { id: "all", name: "Barcha bemorlar", count: allDayCount.value ?? counts.value.all ?? 0 },
  { id: "walk_in", name: "Jonli navbat", count: queueCount.value ?? "—" },
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
    name: "Qabullar va navbat",
    value: String(allDayCount.value ?? counts.value.all ?? 0),
    icon: Users,
    tone: "blue",
  },
  {
    name: "Oldindan yozilgan",
    value: String(counts.value.scheduled ?? 0),
    icon: CalendarDays,
    tone: "green",
  },
  { name: "Jonli navbat", value: String(queueCount.value ?? "—"), icon: Stethoscope, tone: "amber" },
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
// Changing the branch restores that branch's own date. An atomic booking-reveal
// updates its date and branch together and must not be overwritten here.
watch(branch, (id, previousId) => {
  if (!id || id === previousId || changingList) return;
  listDate.value = rememberedReceptionDate(id) ?? today.value;
}, { flush: "sync" });
function persistReceptionDate(id: string, date: string): void {
  const key = receptionDateKey(id);
  if (!key || !validReceptionDate(date) || typeof window === "undefined") return;
  try {
    window.sessionStorage.setItem(key, date);
  } catch {
    // Private browsing and disabled storage should not break the UI.
  }
}
// Persist immediately when the date changes (including the "Bugun" action),
// before navigation or reload can observe a stale sessionStorage value.
// Atomic booking updates persist only after branch + date are both finalized.
watch(listDate, (date) => {
  if (!changingList) persistReceptionDate(branch.value, date);
}, { flush: "sync" });
function updateListState(update: () => void) {
  const previous = changingList;
  changingList = true;
  try {
    update();
  } finally {
    changingList = previous;
  }
}
async function loadQueueCount() {
  const serial = ++queueCountSequence;
  queueCount.value = null;
  if (!branch.value || !zone.value || !today.value || !can("queues.view")) return;
  try {
    const r = await clinicRequest<{ meta: { total: number } }>("queue-tickets", {
      params: { branch_id: branch.value, status: "waiting",
        ...dayRange(today.value, zone.value), page: 1, per_page: 1 },
    });
    if (alive && serial === queueCountSequence) queueCount.value = r.data.meta.total;
  } catch {
    if (alive && serial === queueCountSequence) queueCount.value = null;
  }
}
watch([branch, listDate, () => session.epoch], () => {
  allDayCount.value = null;
}, { immediate: true });
// Re-read today's queue count when reception refreshes its selected booking
// date too. The API date remains TODAY; this prevents a stale "0" when the
// initial queue count loaded before a new ticket was registered.
watch([branch, today, listDate, () => session.epoch], () => {
  void loadQueueCount();
}, { immediate: true });

async function loadQueue(serial: number, revision: number) {
  queueRows.value = [];
  if (!can("queues.view") || !branch.value || !zone.value || !today.value) {
    error.value = "Navbatlarni ko‘rish uchun ruxsat va filial kerak.";
    loading.value = false;
    return;
  }
  try {
    const r = await clinicRequest<{ data: QueueTicket[]; meta: { total: number } }>(
      "queue-tickets",
      { params: { branch_id: branch.value,
        ...(doctor.value ? { doctor_id: doctor.value } : {}),
        ...dayRange(today.value, zone.value), page: page.value, per_page: 8 } },
    );
    const patients = await Promise.all(r.data.data.map(async (row) => {
      if (!can("patients.view")) return null;
      const p = await clinicRequest<Patient>("patients/" + row.patient_id);
      return p.data;
    }));
    if (alive && serial === listSerial && revision === listRevision) {
      queueRows.value = r.data.data.map((row, index) => ({
        ...row,
        patientName: patients[index]?.name ?? row.patient_id,
        patientNumber: patients[index]?.patient_number ?? row.patient_id,
        doctorName: doctors.value.find((d) => d.id === row.doctor_id)?.name ?? row.doctor_id,
      }));
      total.value = r.data.meta.total;
    }
  } catch (e) {
    if (alive && serial === listSerial && revision === listRevision) error.value = errorText(e);
  } finally {
    if (alive && serial === listSerial && revision === listRevision) loading.value = false;
  }
}
// Show appointments on the user-selected day alongside TODAY'S walk-in
// queue in this branch. Selecting tomorrow must never hide patients waiting
// at reception now. A scheduled check-in has both an appointment and a ticket,
// so only unbooked walk-ins are added to avoid counting it twice.
async function loadUnified(serial: number, revision: number) {
  unifiedRows.value = [];
  if (!branch.value || !zone.value || !listDate.value || !can("reception.view")) {
    if (alive && serial === listSerial) {
      error.value = "Filial va Registratura ruxsatini tekshiring.";
      loading.value = false;
    }
    return;
  }
  try {
    const response = await clinicRequest<{
      appointments?: BookingAppointment[];
      tickets?: QueueTicket[];
    }>("reception/overview", {
      params: { branch_id: branch.value, local_date: listDate.value },
    });
    const liveResponse = listDate.value === today.value
      ? response
      : await clinicRequest<{
          appointments?: BookingAppointment[];
          tickets?: QueueTicket[];
        }>("reception/overview", {
          params: { branch_id: branch.value, local_date: today.value },
        });
    // An appointment-linked queue ticket is already represented as a booking.
    // Walk-ins are always taken from the current clinic-local day, not the
    // chosen day for scheduled appointments.
    const todaysWalkIns = (liveResponse.data.tickets ?? []).filter((t) =>
      t.kind === "walk_in" && !t.appointment_id,
    );
    const bookings = (response.data.appointments ?? []).filter((a) =>
      (!doctor.value || a.doctor_id === doctor.value) &&
      (!status.value || a.status === status.value),
    );
    const walkIns = todaysWalkIns.filter((t) =>
      (!doctor.value || t.doctor_id === doctor.value) &&
      (!status.value ||
        (status.value === "checked_in" && ["waiting", "called", "skipped"].includes(t.status)) ||
        t.status === status.value),
    );
    // Search patient names, phones and numbers through the existing permission-
    // checked Patients API, rather than querying queue IDs as patient names.
    let visibleBookings = bookings;
    let visibleWalkIns = walkIns;
    const term = query.value.trim();
    if (term) {
      const matched = new Set<string>();
      if (can("patients.view")) {
        for (let currentPage = 1; ; currentPage++) {
          const result = await clinicRequest<{ data: Patient[]; meta: { total: number } }>(
            "patients", { params: { q: term, page: currentPage, per_page: 100, status: "active" } },
          );
          result.data.data.forEach((p) => matched.add(p.id));
          if (!result.data.data.length || currentPage * 100 >= result.data.meta.total) break;
        }
      }
      visibleBookings = bookings.filter((a) => matched.has(a.patient_id));
      visibleWalkIns = walkIns.filter((t) => matched.has(t.patient_id));
    }
    const combined = [
      ...visibleBookings
        .sort((a, b) => a.starts_at.localeCompare(b.starts_at) || a.id.localeCompare(b.id))
        .map((appointment) => ({ source: "appointment" as const, appointment })),
      ...visibleWalkIns
        .sort((a, b) => a.number - b.number || a.id.localeCompare(b.id))
        .map((ticket) => ({ source: "walk_in" as const, ticket })),
    ];
    const selected = combined.slice((page.value - 1) * 8, page.value * 8);
    const appointments = selected.filter((e) => e.source === "appointment").map((e) => e.appointment);
    const projected = await present(appointments);
    const patients = new Map<string, Patient>();
    const live = selected.filter((e) => e.source === "walk_in");
    if (can("patients.view")) {
      await Promise.all([...new Set(live.map((e) => e.ticket.patient_id))].map(async (id) => {
        const r = await clinicRequest<Patient>("patients/" + id);
        patients.set(id, r.data);
      }));
    }
    const visits = new Map<string, string>();
    if (can("visits.view")) {
      await Promise.all(live.map(async ({ ticket }) => {
        try {
          const response = await clinicRequest<{ service_id: string }>(
            "visits/" + ticket.visit_id,
          );
          visits.set(ticket.visit_id, response.data.service_id);
        } catch {
          // Queue visibility does not imply permission to view every visit.
          // Do not hide the valid queue ticket when service detail is unavailable.
        }
      }));
    }
    if (alive && serial === listSerial && revision === listRevision) {
      const mapped = new Map(projected.map((a) => [a.id, a]));
      unifiedRows.value = selected.flatMap((entry): UnifiedEntry[] => {
        if (entry.source === "appointment") {
          const a = mapped.get(entry.appointment.id);
          return a ? [{ source: "appointment", appointment: a }] : [];
        }
        const t = entry.ticket;
        const d = doctors.value.find((d) => d.id === t.doctor_id);
        return [{
          source: "walk_in",
          ticket: {
            ...t,
            patientName: patients.get(t.patient_id)?.name ?? "Bemor ma’lumoti uchun ruxsat kerak",
            patientNumber: patients.get(t.patient_id)?.patient_number ?? "—",
            doctorName: d?.name ?? "Shifokor ma’lumoti uchun ruxsat kerak",
          },
          serviceName: d?.services.find((service) => service.id === visits.get(t.visit_id))?.name ?? "Jonli navbat",
        }];
      });
      rows.value = projected;
      total.value = combined.length;
      allDayCount.value = (response.data.appointments?.length ?? 0) + todaysWalkIns.length;
      counts.value = {
        all: (response.data.appointments?.length ?? 0) + todaysWalkIns.length,
        scheduled: (response.data.appointments ?? []).filter((a) => a.status === "scheduled").length,
        in_progress: (response.data.appointments ?? []).filter((a) => a.status === "in_progress").length +
          todaysWalkIns.filter((t) => t.status === "in_progress").length,
        completed: (response.data.appointments ?? []).filter((a) => a.status === "completed").length +
          todaysWalkIns.filter((t) => t.status === "completed").length,
      };
    }
    return { rows: projected, total: combined.length };
  } catch (e) {
    if (alive && serial === listSerial && revision === listRevision) error.value = errorText(e);
  } finally {
    if (alive && serial === listSerial && revision === listRevision) loading.value = false;
  }
}
async function load() {
  const serial = ++listSerial;
  const revision = listRevision;
  loading.value = true;
  error.value = "";
  rows.value = [];
  unifiedRows.value = [];
  total.value = 0;
  if (tab.value === "all") return await loadUnified(serial, revision);
  if (tab.value === "walk_in") {
    await loadQueue(serial, revision);
    return;
  }
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
async function saved(appointment: BookingAppointment, date: string, reveal = false) {
  booking.value = false;
  // The reception desk is a selected-day view. Saving a future booking
  // must not silently switch away from today's waiting walk-in patients.
  // Offer an explicit link to the booked day instead.
  if (!reveal && (date !== listDate.value || appointment.branch_id !== branch.value)) {
    recentBooking.value = { appointment, date };
    toast.value = `Bemor ${uzDate(date)} sanasiga muvaffaqiyatli yozildi.`;
    highlight.value = "";
    await Promise.all([load(), loadQueueCount(), loadSide()]);
    return;
  }
  recentBooking.value = null;
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
  persistReceptionDate(branch.value, listDate.value);
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
function revealRecentBooking() {
  const pending = recentBooking.value;
  if (pending) void saved(pending.appointment, pending.date, true);
}
function bookSlot(slot: BookingSlot) {
  openBooking({
    doctorId: scheduleDoctor.value,
    departmentId: sideDoctor.value?.specialty_id ?? undefined,
    serviceId: scheduleService.value,
    slot,
  });
}
function openArrival() {
  if (!canReception("patient") && !canReception("checkIn") && !canReception("walkIn")) return;
  arrival.value = true;
  menu.value = "";
}
async function onArrivalSaved(result: { kind: "scheduled" | "walk_in" | "patient"; number?: number }) {
  toast.value = result.kind === "patient"
    ? "Bemor PostgreSQL bazasida saqlandi."
    : "Bemor navbatga qo‘shildi. Navbat raqami: " + result.number;
  await Promise.all([load(), loadQueueCount(), loadSide()]);
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
            title="Qabullar va bemor kelishi server API orqali boshqariladi."
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
          :disabled="!canReception('checkIn') && !canReception('walkIn') && !canReception('patient')"
          @click="openArrival()"
        >
          <UserRoundPlus :size="19" /> Bemorni ro‘yxatga olish
        </button>
      </div>
    </header>

    <div v-if="toast" class="rp-toast" role="status">
      {{ toast }}
      <button
        v-if="recentBooking"
        type="button"
        class="rp-link"
        @click="revealRecentBooking"
      >Yozilgan qabulni ko‘rish ({{ uzDate(recentBooking.date) }})</button>
      <button aria-label="Xabarni yopish" @click="toast = ''; recentBooking = null">×</button>
    </div>
    <div class="rp-stats">
      <article v-for="s in stats" :key="s.name" class="rp-stat" :class="s.tone">
        <span class="rp-stat-icon"><component :is="s.icon" :size="24" /></span>
        <div>
          <p>{{ s.name }}</p>
          <strong>{{ s.value }}</strong
          ><small>{{
            s.name === "Tushum" ? "Hozircha ulanmagan" : s.name === "Jonli navbat" ? "Navbatlar API" : "Qabullar API"
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
            :disabled="t.id === 'walk_in' && !can('queues.view')"
            :title="
              t.id === 'walk_in' && !can('queues.view')
                ? 'Navbatlarni ko‘rish uchun ruxsat kerak'
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
           ><button class="rp-link" @click="listDate = nextDay(today)">Ertaga</button
          ><span>{{
            tab === "all"
              ? uzDate(listDate) + " qabullari + bugungi jonli navbat"
              : tab === "walk_in"
                ? "Bugungi jonli navbat"
                : uzDate(listDate) + " qabullari"
          }}</span>
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
              :disabled="tab === 'walk_in'"
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
          ><select v-model="status" aria-label="Holat" :disabled="tab === 'walk_in'" @change="tab = 'all'">
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
        <div v-if="tab === 'walk_in'" class="rp-table-scroll">
          <table>
            <thead><tr><th>#</th><th>Bemor</th><th>Shifokor</th><th>Navbat</th><th>Holat</th></tr></thead>
            <tbody>
              <tr v-for="q in queueRows" :key="q.id">
                <td>{{ q.number }}</td>
                <td><strong>{{ q.patientName }}</strong><small>{{ q.patientNumber }}</small></td>
                <td>{{ q.doctorName }}</td>
                <td>{{ q.kind === 'scheduled' ? 'Oldindan yozilgan' : 'Jonli navbat' }} · №{{ q.number }}</td>
                <td>{{ q.status === 'waiting' ? 'Kutilmoqda' : q.status === 'called' ? 'Chaqirildi' : q.status === 'in_progress' ? 'Qabulda' : q.status === 'completed' ? 'Yakunlangan' : q.status }}</td>
              </tr>
              <tr v-if="!loading && !error && !queueRows.length">
                <td colspan="5" class="rp-empty">Bugungi jonli navbatda bemor topilmadi.</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else-if="tab === 'all'" class="rp-table-scroll">
          <table>
            <thead>
              <tr>
                <th>#</th><th>Bemor</th><th>Shifokor / Xizmat</th>
                <th>Holat</th><th>Vaqt / Navbat</th><th>Amallar</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(entry, i) in unifiedRows" :key="entry.source === 'appointment' ? entry.appointment.id : entry.ticket.id"
                :class="{ 'rp-new-booking': entry.source === 'appointment' && entry.appointment.id === highlight }">
                <td>{{ (page - 1) * 8 + i + 1 }}</td>
                <template v-if="entry.source === 'appointment'">
                  <td><div class="rp-person">
                    <span class="rp-avatar">{{ initials(entry.appointment.patientName) }}</span>
                    <div><strong>{{ entry.appointment.patientName }}</strong>
                      <small>{{ entry.appointment.patientNumber }} · {{ entry.appointment.phone || 'Telefon kiritilmagan' }}</small>
                    </div>
                  </div></td>
                  <td><strong>{{ entry.appointment.doctorName }}</strong><small>{{ entry.appointment.serviceName }}</small></td>
                  <td><span class="rp-badge" :class="entry.appointment.status">{{ statusLabels[entry.appointment.status] }}</span></td>
                  <td><strong>{{ entry.appointment.time }}</strong></td>
                  <td><button class="rp-button rp-soft" :disabled="!canReception('detail')" @click="detail = entry.appointment"><Eye :size="16" /> Ko‘rish</button></td>
                </template>
                <template v-else>
                  <td><div class="rp-person">
                    <span class="rp-avatar">{{ initials(entry.ticket.patientName) }}</span>
                    <div><strong>{{ entry.ticket.patientName }}</strong><small>{{ entry.ticket.patientNumber }}</small></div>
                  </div></td>
                  <td><strong>{{ entry.ticket.doctorName }}</strong><small>{{ entry.serviceName }}</small></td>
                  <td><span class="rp-badge checked_in">{{ entry.ticket.status === 'waiting' ? 'Navbatda' : entry.ticket.status === 'called' ? 'Chaqirildi' : entry.ticket.status === 'in_progress' ? 'Qabulda' : entry.ticket.status === 'completed' ? 'Yakunlangan' : entry.ticket.status }}</span></td>
                  <td><strong>№{{ entry.ticket.number }}</strong><small>Bugun · Jonli navbat</small></td>
                  <td><button class="rp-button rp-soft" @click="tab = 'walk_in'"><ClipboardList :size="16" /> Navbat</button></td>
                </template>
              </tr>
              <tr v-if="!loading && !error && !unifiedRows.length">
                <td colspan="6" class="rp-empty">
                  Bemor topilmadi. Boshqa sanani tekshiring yoki filtrlarni o‘zgartiring.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="rp-table-scroll">
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
                tab = 'walk_in';
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
      @close="arrival = false"
      @saved="onArrivalSaved"
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
