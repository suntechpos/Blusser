<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { AlertTriangle, Ban, CalendarDays, ChevronDown, Ellipsis, FastForward, Megaphone, Play, RefreshCw, Search, Stethoscope, UserRound } from "lucide-vue-next";
import { can, session } from "../services/session";
import { clinicRequest, errorText } from "../services/api";
import { getDoctorWorkspace, type DoctorWorkspaceFeed, type WorkspacePatientRow } from "../services/doctorWorkspace";
import { resolveDoctorStartSlot } from "../services/doctorVisitStart";
import DoctorEncounterPreview from "../components/doctor/DoctorEncounterPreview.vue";
import "../components/employees/employees.css";
import "../components/doctor/doctor.css";

const feed = ref<DoctorWorkspaceFeed | null>(null);
const date = ref("");
const doctorId = ref("");
const branchId = ref("");
const queueTab = ref<"waiting" | "unresolved" | "completed">("waiting");
const query = ref("");
const selectedKey = ref("");
const loading = ref(true);
const calling = ref(false);
const overdueError = ref("");
const openMenuKey = ref("");
interface RebookingSlot {
  room_id: string;
  starts_at: string;
  ends_at: string;
}
const appointmentCancelPreview = ref<QueueDisplayRow | null>(null);
const appointmentCancelSaving = ref(false);
const appointmentCancelError = ref("");
function openAppointmentCancel(row: QueueDisplayRow) {
  openMenuKey.value = "";
  if (row.source !== "appointment" || row.status !== "scheduled" ||
    !can("appointments.view") || !can("appointments.cancel")) return;
  appointmentCancelError.value = "";
  appointmentCancelPreview.value = { ...row };
}
async function cancelScheduledAppointment() {
  const row = appointmentCancelPreview.value;
  if (!row || row.status !== "scheduled" || !row.version || appointmentCancelSaving.value) return;
  appointmentCancelSaving.value = true;
  appointmentCancelError.value = "";
  try {
    await clinicRequest("appointments/" + encodeURIComponent(row.id) + "/cancel", {
      method: "POST",
      body: { expected_version: row.version },
    });
    appointmentCancelPreview.value = null;
    await load();
  } catch (cause) {
    appointmentCancelError.value = errorText(cause);
  } finally {
    appointmentCancelSaving.value = false;
  }
}
const appointmentPreview = ref<QueueDisplayRow | null>(null);
const appointmentDate = ref("");
const appointmentSlotKey = ref("");
const appointmentSlots = ref<RebookingSlot[]>([]);
const appointmentBusySlots = ref<RebookingSlot[]>([]);
const appointmentTimezone = ref("");
const appointmentLoading = ref(false);
const appointmentSaving = ref(false);
const appointmentError = ref("");
let appointmentSequence = 0;
const appointmentChoices = computed(() => [
  ...appointmentSlots.value.map(slot => ({ slot, busy: false })),
  ...appointmentBusySlots.value.map(slot => ({ slot, busy: true })),
].sort((a, b) => a.slot.starts_at.localeCompare(b.slot.starts_at)));
function appointmentTime(slot: RebookingSlot) {
  const format = new Intl.DateTimeFormat("en-GB", {
    timeZone: appointmentTimezone.value || timezone.value,
    hour: "2-digit", minute: "2-digit", hour12: false,
  });
  return format.format(new Date(slot.starts_at)) + "–" + format.format(new Date(slot.ends_at));
}
function closeAppointmentPreview() {
  if (appointmentSaving.value) return;
  appointmentSequence++;
  appointmentPreview.value = null;
  appointmentError.value = "";
}
function openAppointmentPreview(row: QueueDisplayRow) {
  openMenuKey.value = "";
  if (row.source !== "appointment" || row.status !== "scheduled" ||
      !can("appointments.view") || !can("appointments.update") ||
      !can("patients.view")) return;
  appointmentPreview.value = { ...row };
  appointmentDate.value = "";
  appointmentSlotKey.value = "";
  appointmentSlots.value = [];
  appointmentBusySlots.value = [];
  appointmentError.value = "";
}
watch([appointmentPreview, appointmentDate], async () => {
  const request = ++appointmentSequence;
  const row = appointmentPreview.value;
  appointmentSlotKey.value = "";
  appointmentSlots.value = [];
  appointmentBusySlots.value = [];
  if (!row || !/^\d{4}-\d{2}-\d{2}$/.test(appointmentDate.value)) return;
  appointmentLoading.value = true;
  try {
    const result = await clinicRequest<{
      timezone: string; slots: RebookingSlot[]; busy_slots?: RebookingSlot[];
    }>("appointments/" + encodeURIComponent(row.id) + "/doctor-rebooking-options", {
      params: { local_date: appointmentDate.value },
    });
    if (request !== appointmentSequence) return;
    appointmentTimezone.value = result.data.timezone;
    appointmentSlots.value = result.data.slots;
    appointmentBusySlots.value = result.data.busy_slots ?? [];
  } catch (cause) {
    if (request === appointmentSequence) appointmentError.value = errorText(cause);
  } finally {
    if (request === appointmentSequence) appointmentLoading.value = false;
  }
});
async function submitAppointmentReschedule() {
  const row = appointmentPreview.value;
  const slot = appointmentSlots.value.find(item => slotKey(item) === appointmentSlotKey.value);
  if (appointmentSaving.value) return;
  if (!row || !slot) {
    appointmentError.value = "Bo‘sh vaqtni qayta tanlang.";
    return;
  }
  if (!Number.isInteger(row.version) || !row.version || row.version < 1) {
    appointmentError.value = "Qabul versiyasi yuklanmadi. Sahifani yangilang.";
    return;
  }
  appointmentSaving.value = true;
  appointmentError.value = "";
  try {
    await clinicRequest("appointments/" + encodeURIComponent(row.id) + "/reschedule", {
      method: "POST",
      body: {
        doctor_id: doctorId.value,
        branch_id: branchId.value,
        room_id: slot.room_id,
        starts_at: slot.starts_at,
        ends_at: slot.ends_at,
        expected_version: row.version,
      },
    });
    appointmentSaving.value = false;
    closeAppointmentPreview();
    await load();
  } catch (cause) {
    appointmentError.value = errorText(cause);
    // Refresh the availability after a possible concurrent reservation.
    appointmentDate.value = "";
  } finally {
    appointmentSaving.value = false;
  }
}
const actionPreview = ref<{
  name: string;
  row: QueueDisplayRow;
  action: "skip" | "cancel";
} | null>(null);
const previewReason = ref("");
const previewComment = ref("");
const previewReschedule = ref(false);
const previewDate = ref("");
const previewTime = ref("");
const actionSaving = ref(false);
const actionError = ref("");
const actionNotice = ref("");
const optionsLoading = ref(false);
const optionsError = ref("");
const rebookingSlots = ref<RebookingSlot[]>([]);
const busyRebookingSlots = ref<RebookingSlot[]>([]);
const nextAvailableSuggestion = ref<{ date: string; slot: RebookingSlot } | null>(null);
const suggestionLoading = ref(false);
const suggestionError = ref("");
const rebookingTimezone = ref("");
let optionsSequence = 0;

const starting = ref(false);
const startError = ref("");
const startErrorFor = ref("");
const error = ref("");
let sequence = 0;

async function load(preferredKey = "") {
  const request = ++sequence;
  // A routine refresh of the SAME doctor/branch/date must not unmount the
  // consultation tab: this resets the selected tab and causes a white flash.
  // Switching any scope, or changing identity (see the session watcher below),
  // still removes the old patient's data immediately to avoid stale disclosure.
  const previous = feed.value;
  const sameScope = !!previous &&
    previous.doctor?.id === doctorId.value &&
    previous.branch?.id === branchId.value &&
    previous.date === date.value;
  if (!sameScope) {
    feed.value = null;
    selectedKey.value = "";
  }
  error.value = "";
  loading.value = true;
  try {
    const result = await getDoctorWorkspace({
      ...(date.value ? { date: date.value } : {}),
      ...(doctorId.value ? { doctor_id: doctorId.value } : {}),
      ...(branchId.value ? { branch_id: branchId.value } : {}),
    });
    if (request !== sequence) return;
    const stillSameScope = sameScope &&
      result.doctor?.id === previous?.doctor?.id &&
      result.branch?.id === previous?.branch?.id &&
      result.date === previous?.date;
    // Preserve the patient's current selection even when they click another
    // row while a background refresh is pending. Action handlers can still
    // explicitly select the ticket they just called/started.
    const retainedSelection = stillSameScope ? selectedKey.value : "";
    feed.value = result;
    selectedKey.value = preferredKey || retainedSelection;
    doctorId.value = result.doctor?.id || "";
    branchId.value = result.branch?.id || "";
    date.value = result.date || "";
  } catch (e) {
    if (request !== sequence) return;
    error.value = errorText(e);
  } finally {
    if (request === sequence) loading.value = false;
  }
}

function changeDoctor(e: Event) {
  doctorId.value = (e.target as HTMLSelectElement).value;
  branchId.value = "";
  void load();
}
function changeBranch(e: Event) {
  branchId.value = (e.target as HTMLSelectElement).value;
  void load();
}
function changeDate(e: Event) {
  date.value = (e.target as HTMLInputElement).value;
  void load();
}
function resetDate() {
  // No client-local timezone assumption: server chooses today's clinic date.
  date.value = "";
  void load();
}
watch(
  // Vue compares each source's primitive value independently. Returning a
  // *new array* from one getter falsely triggers this watcher whenever the
  // 15-second revalidation replaces session.user with the same identity.
  // An actual epoch/user/tenant change must still discard the old feed.
  [
    () => session.epoch,
    () => session.user?.id,
    () => session.user?.active_tenant_id,
  ],
  () => {
    ++sequence;
    doctorId.value = "";
    branchId.value = "";
    date.value = "";
    feed.value = null;
    void load();
  },
);
onMounted(() => void load());

type DisplayRow = WorkspacePatientRow & {
  key: string;
  source: "appointment" | "ticket";
};
const rows = computed<DisplayRow[]>(() => {
  const current = feed.value;
  if (!current) return [];
  // A booking must remain visible even when its visit has a ticket for a
  // different local day. On the same day the ticket is authoritative: show
  // only the ticket to avoid duplicating a single patient's appointment.
  const visibleTicketAppointments = new Set(current.tickets
    .map(ticket => ticket.appointment_id).filter((id): id is string => !!id));
  return [
    ...current.scheduled.filter(r => !visibleTicketAppointments.has(r.id))
      .map((r) => ({ ...r, source: "appointment" as const, key: "appointment:" + r.id })),
    ...current.tickets.map((r) => ({ ...r, source: "ticket" as const, key: "ticket:" + r.id })),
  ].sort((a, b) => {
    const aTime = a.starts_at || a.created_at || "";
    const bTime = b.starts_at || b.created_at || "";
    return aTime.localeCompare(bTime) || a.key.localeCompare(b.key);
  });
});
// Actual queue tickets only; a future appointment is not callable before check-in.
// A patient clicked in the list is strictly a read-only detail selection.
const nextTicket = computed(() => feed.value?.tickets
  .filter((row) => row.status === "waiting")
  .sort((a, b) => (a.created_at || "").localeCompare(b.created_at || "") || a.id.localeCompare(b.id))[0] || null);
const clinicToday = computed(() => {
  const zone = feed.value?.branch?.timezone || "Asia/Tashkent";
  const parts = new Intl.DateTimeFormat("en-US", {
    timeZone: zone, year: "numeric", month: "2-digit", day: "2-digit",
  }).formatToParts(new Date());
  const part = (type: string) => parts.find((p) => p.type === type)?.value || "";
  return part("year") + "-" + part("month") + "-" + part("day");
});
const callPermissionMessage = computed(() => {
  if (!can("queues.view")) return "Navbatlarni ko‘rish ruxsati berilmagan.";
  if (!can("queues.call")) return "Shifokorga bemor chaqirish ruxsati berilmagan.";
  if (!can("visits.view")) return "Tashriflarni ko‘rish ruxsati berilmagan.";
  if (nextTicket.value?.appointment_id && !can("appointments.view")) {
    return "Qabullarni ko‘rish ruxsati berilmagan.";
  }
  return "";
});
const activePatient = computed(() => feed.value?.tickets.find(
  (row) => row.status === "called" || row.status === "in_progress",
) || null);
const hasActivePatient = computed(() =>
  !!feed.value?.has_active_patient || !!activePatient.value,
);
const callAllowed = computed(() => !callPermissionMessage.value && !!nextTicket.value
  && !hasActivePatient.value && date.value === clinicToday.value
  && !loading.value && !calling.value);
const callLabel = computed(() => feed.value?.has_called_today ? "Keyingi bemor" : "Bemorni chaqirish");

async function callNext() {
  if (!callAllowed.value || !nextTicket.value) return;
  // Snapshot the oldest waiting ticket, not the selected/search-filtered patient.
  const ticket = nextTicket.value;
  calling.value = true;
  try {
    if (!Number.isInteger(ticket.version) || !ticket.version || ticket.version < 1) {
      throw new Error("Navbat versiyasi mavjud emas. Sahifani yangilang.");
    }
    await clinicRequest("queue-tickets/" + encodeURIComponent(ticket.id) + "/call", {
      method: "POST",
      body: { expected_version: ticket.version },
    });
    query.value = "";
    queueTab.value = "waiting";
    openMenuKey.value = "";
    await load("ticket:" + ticket.id);
  } catch (cause) {
    const message = errorText(cause);
    try {
      await load();
    } finally {
      error.value = message;
    }
  } finally {
    calling.value = false;
  }
}
const overdueTickets = computed(() => feed.value?.overdue ?? []);

async function continueOverdue(row: { id: string; local_date: string; branch_id: string }) {
  // Navigation alone: no queue status or medical record mutation.
  if (loading.value) return;
  queueTab.value = "unresolved";
  query.value = "";
  openMenuKey.value = "";
  overdueError.value = "";
  date.value = row.local_date;
  branchId.value = row.branch_id;
  await load("ticket:" + row.id);
}

// "Qabulni boshlash" is a separate, explicit action on the selected
// *called* ticket. Clicking patient rows never starts a consultation.
const startPermissionMessage = computed(() => {
  if (selected.value?.source !== "ticket" || selected.value.status !== "called") return "";
  if (!can("queues.start")) return "Qabulni boshlash (Navbatlar) ruxsati berilmagan.";
  if (!can("queues.view") || !can("visits.view")) {
    return "Navbatlar va Tashriflarni ko‘rish ruxsatlari kerak.";
  }
  if (session.grants?.["queues.start"] === "own") return "";
  if (!can("patients.view") || session.grants?.["services.view"] !== "entire_clinic" ||
      !can("doctors.view") || !can("rooms.view") || !can("schedules.view")) {
    return "Boshqa shifokor qabuli uchun qo‘shimcha boshqaruv ruxsatlari kerak.";
  }
  if (selected.value.appointment_id &&
    (!can("appointments.view") || !can("appointments.update"))) {
    return "Bronni ko‘rish va tahrirlash ruxsatlari kerak.";
  }
  return "";
});
const anotherConsultation = computed(() => feed.value?.tickets.some((ticket) =>
  ticket.status === "in_progress" && ticket.id !== selected.value?.id,
) ?? false);
const startAllowed = computed(() =>
  selected.value?.source === "ticket" && selected.value.status === "called" &&
  !hasHistoricScope(selected.value) && !startPermissionMessage.value && !anotherConsultation.value &&
  date.value === clinicToday.value && !loading.value && !starting.value,
);
async function startConsultation() {
  const ticket = selected.value;
  const doctor = feed.value?.doctor;
  const branch = feed.value?.branch;
  if (!startAllowed.value || !ticket || !doctor || !branch) return;
  const chosenKey = ticket.key;
  starting.value = true;
  startError.value = "";
  startErrorFor.value = chosenKey;
  error.value = "";
  try {
    if (!Number.isInteger(ticket.version) || (ticket.version ?? 0) < 1) {
      throw new Error("Navbat versiyasi topilmadi. Sahifani yangilang.");
    }
    const slot = await resolveDoctorStartSlot(
      ticket, doctor.id, branch.id, date.value, branch.timezone,
    );
    // If the doctor/date/patient was switched while availability loaded, do
    // not send a mutation for the previously selected patient.
    if (selected.value?.key !== chosenKey ||
      feed.value?.doctor?.id !== doctor.id || feed.value?.branch?.id !== branch.id ||
      date.value !== clinicToday.value) return;
    await clinicRequest("queue-tickets/" + encodeURIComponent(ticket.id) + "/start", {
      method: "POST",
      body: { expected_version: ticket.version, ...slot },
    });
    await load(chosenKey);
  } catch (cause) {
    const message = errorText(cause);
    try {
      await load(chosenKey);
    } finally {
      startError.value = message;
      startErrorFor.value = chosenKey;
    }
  } finally {
    starting.value = false;
  }
}
// Keep historical data in its own tab, but do not fetch a second, more
// privileged patient feed. The overdue projection already enforces access.
type QueueDisplayRow = DisplayRow & {
  historicDate?: string;
  historicBranchId?: string;
  historicBranchName?: string;
};
const unresolvedRows = computed<QueueDisplayRow[]>(() => {
  const todayRows = new Map(rows.value.filter((r) => r.source === "ticket")
    .map((r) => [r.id, r] as const));
  return overdueTickets.value.map((item) => ({
    ...(todayRows.get(item.id) ?? {
      ...item,
      dob: "",
      gender: null,
      phone: null,
      source: "ticket" as const,
      key: "ticket:" + item.id,
    }),
    historicDate: item.local_date,
    historicBranchId: item.branch_id,
    historicBranchName: item.branch_name,
  }));
});
const overdueIds = computed(() => new Set(overdueTickets.value.map((item) => item.id)));
const unresolvedCount = computed(() => unresolvedRows.value.length);
const completed = (row: DisplayRow) => row.status === "completed";
function hasHistoricScope(row: QueueDisplayRow) {
  return !!row.historicDate && (row.historicDate !== date.value ||
    row.historicBranchId !== branchId.value);
}
function toggleRowMenu(key: string) {
  openMenuKey.value = openMenuKey.value === key ? "" : key;
}
function actionAllowed(row: QueueDisplayRow, action: "skip" | "cancel") {
  if (row.source !== "ticket" || !can("queues." + action) ||
      !can("queues.view") || !can("visits.view")) return false;
  if (row.appointment_id && (!can("appointments.view") ||
      (action === "cancel" && !can("appointments.cancel")))) return false;
  return (action === "skip" ? ["waiting", "called"] : ["waiting", "called", "skipped"])
    .includes(row.status);
}
const canRebook = computed(() => can("appointments.create") &&
  can("appointments.view") && can("patients.view"));
const chronologicalRebookingSlots = computed(() => [
  ...rebookingSlots.value.map(slot => ({ slot, busy: false })),
  ...busyRebookingSlots.value.map(slot => ({ slot, busy: true })),
].sort((a, b) => a.slot.starts_at.localeCompare(b.slot.starts_at)
  || a.slot.ends_at.localeCompare(b.slot.ends_at)
  || a.slot.room_id.localeCompare(b.slot.room_id)));
const recommendedRebookingSlot = computed(() => {
  const available = [...rebookingSlots.value].sort((a, b) =>
    a.starts_at.localeCompare(b.starts_at));
  const firstBusy = [...busyRebookingSlots.value].sort((a, b) =>
    a.starts_at.localeCompare(b.starts_at))[0];
  if (!firstBusy) return available[0] ?? null;
  // Offer the first actually free appointment after the occupied interval.
  return available.find(slot => Date.parse(slot.starts_at) >= Date.parse(firstBusy.ends_at))
    ?? available[0] ?? null;
});
const chosenRebookingSlot = computed(() => rebookingSlots.value.find(
  (slot) => slotKey(slot) === previewTime.value,
));
function slotKey(slot: RebookingSlot) {
  return slot.room_id + "|" + slot.starts_at + "|" + slot.ends_at;
}
function rebookingTime(slot: RebookingSlot) {
  const format = new Intl.DateTimeFormat("en-GB", {
    timeZone: rebookingTimezone.value || timezone.value,
    hour: "2-digit", minute: "2-digit", hour12: false,
  });
  return format.format(new Date(slot.starts_at)) + "–" + format.format(new Date(slot.ends_at));
}
function showActionPreview(row: QueueDisplayRow, action: "skip" | "cancel") {
  if (!actionAllowed(row, action) || actionSaving.value) return;
  openMenuKey.value = "";
  actionPreview.value = { name: row.name, row: { ...row }, action };
  previewReason.value = "";
  previewComment.value = "";
  previewReschedule.value = false;
  previewDate.value = "";
  previewTime.value = "";
  actionError.value = "";
  actionNotice.value = "";
}
function closeActionPreview() {
  if (actionSaving.value) return;
  ++optionsSequence;
  actionPreview.value = null;
  rebookingSlots.value = [];
  busyRebookingSlots.value = [];
  optionsError.value = "";
}
async function loadRebookingOptions() {
  const request = ++optionsSequence;
  const preview = actionPreview.value;
  previewTime.value = "";
  rebookingSlots.value = [];
  busyRebookingSlots.value = [];
  optionsError.value = "";
  optionsLoading.value = false;
  if (!preview || !previewReschedule.value || !canRebook.value ||
      !/^\d{4}-\d{2}-\d{2}$/.test(previewDate.value)) return;
  const localDate = previewDate.value;
  optionsLoading.value = true;
  try {
    const response = await clinicRequest<{
      local_date: string; timezone: string; slots: RebookingSlot[]; busy_slots?: RebookingSlot[];
    }>("queue-tickets/" + encodeURIComponent(preview.row.id) + "/rebooking-options", {
      params: { action: preview.action, local_date: localDate },
    });
    if (request !== optionsSequence) return;
    rebookingSlots.value = response.data.slots;
    busyRebookingSlots.value = response.data.busy_slots ?? [];
    if (nextAvailableSuggestion.value?.date === localDate) {
      const key = slotKey(nextAvailableSuggestion.value.slot);
      if (response.data.slots.some(slot => slotKey(slot) === key)) previewTime.value = key;
    }
    rebookingTimezone.value = response.data.timezone;
  } catch (cause) {
    if (request === optionsSequence) optionsError.value = errorText(cause);
  } finally {
    if (request === optionsSequence) optionsLoading.value = false;
  }
}
async function findNextAvailableDate() {
  if (!actionPreview.value || !previewReschedule.value || !previewDate.value ||
      suggestionLoading.value || optionsLoading.value) return;
  const sequenceAtStart = optionsSequence;
  const preview = actionPreview.value;
  suggestionError.value = "";
  nextAvailableSuggestion.value = null;
  suggestionLoading.value = true;
  try {
    for (let offset = 1; offset <= 14; offset++) {
      const day = new Date(previewDate.value + "T00:00:00Z");
      day.setUTCDate(day.getUTCDate() + offset);
      const localDate = day.toISOString().slice(0, 10);
      const response = await clinicRequest<{
        local_date: string; timezone: string; slots: RebookingSlot[];
      }>("queue-tickets/" + encodeURIComponent(preview.row.id) + "/rebooking-options", {
        params: { action: preview.action, local_date: localDate },
      });
      if (optionsSequence !== sequenceAtStart) return;
      if (response.data.slots.length) {
        nextAvailableSuggestion.value = { date: localDate, slot: response.data.slots[0]! };
        previewDate.value = localDate;
        return;
      }
    }
    suggestionError.value = "Keyingi 14 kunda bo‘sh vaqt topilmadi.";
  } catch (cause) {
    if (optionsSequence === sequenceAtStart) suggestionError.value = errorText(cause);
  } finally {
    suggestionLoading.value = false;
  }
}
watch([actionPreview, previewReschedule, previewDate], () => void loadRebookingOptions());
watch([doctorId, branchId, date, () => session.epoch], () => {
  // A tenant/doctor/date switch always discards the previous patient's modal,
  // including when an in-flight command is cancelled by the context gate.
  ++optionsSequence;
  actionPreview.value = null;
  rebookingSlots.value = [];
  busyRebookingSlots.value = [];
  optionsError.value = "";
  actionNotice.value = "";
});
async function submitQueueAction() {
  const preview = actionPreview.value;
  if (!preview || actionSaving.value) return;
  actionError.value = "";
  if (!previewReason.value.trim()) {
    actionError.value = "Sababni tanlang.";
    return;
  }
  if (!actionAllowed(preview.row, preview.action)) {
    actionError.value = "Bu navbat amali uchun ruxsat yo‘q yoki holati mos emas.";
    return;
  }
  if (!Number.isInteger(preview.row.version) || (preview.row.version ?? 0) < 1) {
    actionError.value = "Navbat versiyasi topilmadi. Modalni yoping va navbatni yangilang.";
    return;
  }
  const rebook = previewReschedule.value ? chosenRebookingSlot.value : undefined;
  if (previewReschedule.value && (!canRebook.value || !rebook || optionsLoading.value)) {
    actionError.value = "Yangi sana va mavjud bo‘sh vaqtni tanlang.";
    return;
  }
  actionSaving.value = true;
  try {
    const response = await clinicRequest<{
      rebooked_appointment: { id: string; starts_at: string } | null;
    }>("queue-tickets/" + encodeURIComponent(preview.row.id) + "/" + preview.action, {
      method: "POST",
      body: {
        expected_version: preview.row.version,
        reason_code: previewReason.value.trim(),
        ...(previewComment.value.trim() ? { note: previewComment.value.trim() } : {}),
        ...(rebook ? { rebook } : {}),
      },
    });
    const actionText = preview.action === "skip"
      ? "Navbat o‘tkazib yuborildi." : "Navbat bekor qilindi.";
    const booked = response.data.rebooked_appointment;
    actionNotice.value = actionText + (booked
      ? " Bemor qayta yozildi: " + new Intl.DateTimeFormat("uz-UZ", {
        timeZone: rebookingTimezone.value || timezone.value,
        year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit",
        hourCycle: "h23",
      }).format(new Date(booked.starts_at)) + "."
      : " Bemor qayta yozilmadi.");
    actionPreview.value = null;
    ++optionsSequence;
    await load();
  } catch (cause) {
    actionError.value = errorText(cause);
  } finally {
    actionSaving.value = false;
  }
}
function resumeRow(row: QueueDisplayRow) {
  openMenuKey.value = "";
  if (row.historicDate && row.historicBranchId) {
    void continueOverdue({ id: row.id, local_date: row.historicDate, branch_id: row.historicBranchId });
  } else {
    selectedKey.value = row.key;
  }
}

const filteredRows = computed<QueueDisplayRow[]>(() => {
  const source = queueTab.value === "unresolved" ? unresolvedRows.value
    : rows.value.filter((row) => queueTab.value === "completed"
      ? completed(row) : !completed(row) && !overdueIds.value.has(row.id));
  const search = query.value.trim().toLocaleLowerCase();
  return source.filter((row) => !search ||
    [row.name, row.patient_number, String(row.number ?? ""), row.service]
      .some((item) => item?.toLocaleLowerCase().includes(search)));
});
const waitingCount = computed(() => rows.value.filter(
  (row) => !completed(row) && !overdueIds.value.has(row.id),
).length);
const completedCount = computed(() => rows.value.filter(completed).length);
const selected = computed(() => filteredRows.value.find((r) => r.key === selectedKey.value) || filteredRows.value[0] || null);
function setTab(tab: "waiting" | "unresolved" | "completed") {
  queueTab.value = tab;
  selectedKey.value = "";
  openMenuKey.value = "";
}
const timezone = computed(() => feed.value?.branch?.timezone || "Asia/Tashkent");
function visitTime(row: DisplayRow) {
  if (row.kind === "walk_in" && row.number) return "№" + row.number + " · Jonli navbat";
  if (row.starts_at) {
    const value = new Date(row.starts_at);
    if (!Number.isNaN(value.valueOf())) return new Intl.DateTimeFormat("uz-UZ", {
      hour: "2-digit", minute: "2-digit", hourCycle: "h23", timeZone: timezone.value,
    }).format(value);
  }
  if (row.number) return "№" + row.number;
  return "Vaqt belgilanmagan";
}
function statusText(row: DisplayRow) {
  return ({
    scheduled: "Yozilgan", checked_in: "Kelgan", waiting: "Kutmoqda",
    called: "Chaqirildi", in_progress: "Qabulda",
    completed: "Yakunlangan", skipped: "O‘tkazib yuborilgan", cancelled: "Bekor qilingan",
  } as Record<string, string>)[row.status] || row.status;
}
function initials(name: string) {
  return name.split(/\s+/).slice(0, 2).map((w) => w[0] || "").join("").toUpperCase();
}
function age(dob: string | null) {
  if (!dob || !date.value) return null;
  const born = new Date(dob + "T00:00:00Z");
  const asOf = new Date(date.value + "T00:00:00Z");
  if (Number.isNaN(born.valueOf()) || Number.isNaN(asOf.valueOf())) return null;
  let years = asOf.getUTCFullYear() - born.getUTCFullYear();
  if (asOf.getUTCMonth() < born.getUTCMonth() ||
      (asOf.getUTCMonth() === born.getUTCMonth() && asOf.getUTCDate() < born.getUTCDate())) years--;
  return years >= 0 ? years : null;
}
function genderLabel(gender: string | null) {
  if (gender === "male" || gender === "erkak") return "Erkak";
  if (gender === "female" || gender === "ayol") return "Ayol";
  return "—";
}
</script>

<template>
  <section class="employees-ui dw-ui dw-live-ui" aria-label="Shifokor kabineti">
    <header class="dw-heading">
      <div>
        <h1>Shifokor kabineti</h1>
        <p v-if="feed?.doctor">
          {{ feed.doctor.name }} · {{ feed.doctor.specialty }} ·
          {{ feed.branch?.name }}<template v-if="feed.doctor.room"> · {{ feed.doctor.room }}-xona</template>
        </p>
        <p v-else-if="loading">Shifokor ma’lumotlari yuklanmoqda...</p>
        <p v-else>Shifokor profili topilmadi.</p>
      </div>
      <div class="dw-live-filters">
        <label v-if="feed?.manager && feed.doctors.length" class="dw-live-select">
          <span>Shifokor</span>
          <select aria-label="Shifokorni tanlash" :value="doctorId" @change="changeDoctor">
            <option v-for="doctor in feed.doctors" :key="doctor.id" :value="doctor.id">{{ doctor.name }}</option>
          </select>
        </label>
        <label v-if="feed?.doctor?.branches && feed.doctor.branches.length > 1" class="dw-live-select">
          <span>Filial</span>
          <select aria-label="Shifokor filiali" :value="branchId" @change="changeBranch">
            <option v-for="branch in feed.doctor.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
          </select>
        </label>
        <label class="dw-live-date">
          <CalendarDays :size="18" />
          <input type="date" aria-label="Qabul sanasi" :value="date" @change="changeDate" />
        </label>
        <button type="button" class="dw-live-today" @click="resetDate">Bugun</button>
        <button type="button" class="dw-live-reload" aria-label="Navbatni yangilash" title="Yangilash" @click="load()">
          <RefreshCw :size="18" />
        </button>
      </div>
    </header>

    <p v-if="error" class="dw-notice" role="alert">{{ error }}</p>
    <p v-if="actionNotice" class="dw-notice" role="status">{{ actionNotice }}</p>
    <section v-if="feed?.doctor && overdueTickets.length" class="dw-overdue" aria-label="Eski kundan qolgan navbatlar">
      <span class="dw-overdue-alert-icon" aria-hidden="true"><AlertTriangle :size="20" /></span>
      <div class="dw-overdue-heading">
        <h2>Oldingi kundan {{ overdueTickets.length }} ta yakunlanmagan qabul mavjud</h2>
        <p>Eski qabullarni «Yakunlanmagan» tabida ko‘rib chiqishingiz mumkin.
          Ular hal etilmaguncha yangi bemorni chaqirib bo‘lmaydi.</p>
      </div>
      <span class="dw-overdue-count">{{ overdueTickets.length }} ta</span>
    </section>
    <p v-if="overdueError" class="dw-overdue-error" role="alert">{{ overdueError }}</p>
    <div class="dw-workspace">
      <aside class="dw-card dw-queue">
        <h2>Bugungi navbat</h2>
        <div class="dw-queue-tabs">
          <button type="button" data-permission="workspace.view" :class="{ selected: queueTab === 'waiting' }" @click="setTab('waiting')">
            Kutilmoqda <b>{{ waitingCount }}</b>
          </button>
          <button type="button" data-permission="workspace.view" :class="{ selected: queueTab === 'unresolved' }" @click="setTab('unresolved')">
            Yakunlanmagan <b>{{ unresolvedCount }}</b>
          </button>
          <button type="button" data-permission="workspace.view" :class="{ selected: queueTab === 'completed' }" @click="setTab('completed')">
            Yakunlangan <b>{{ completedCount }}</b>
          </button>
        </div>
        <label class="dw-search">
          <Search :size="19" />
          <input v-model="query" aria-label="Navbatdagi bemorni qidirish" placeholder="Bemor qidirish..." />
        </label>
        <div class="dw-queue-list">
          <p v-if="loading" class="dw-empty" role="status">Navbat yuklanmoqda...</p>
          <template v-else>
            <div v-for="row in filteredRows" :key="row.key" class="dw-queue-entry">
              <button type="button" class="dw-queue-person" data-permission="workspace.view"
                :class="{ selected: selected?.key === row.key }"
                :aria-label="row.name + ' · ' + row.patient_number"
                @click="selectedKey = row.key; openMenuKey = ''">
                <span class="dw-avatar purple">{{ initials(row.name) }}</span>
                <span class="dw-queue-person-info">
                  <b>{{ row.name }}</b>
                  <small v-if="row.historicDate">№{{ row.number || '—' }} · {{ row.historicDate }}</small>
                  <small v-else>{{ visitTime(row) }}</small>
                  <small>{{ row.service }}<template v-if="row.historicBranchName"> · {{ row.historicBranchName }}</template></small>
                </span>
                <span class="dw-badge" :class="completed(row) ? 'green' : row.status === 'in_progress' ? 'blue' : 'yellow'">
                  {{ statusText(row) }}
                </span>
              </button>
              <button v-if="(row.source === 'appointment' && row.status === 'scheduled' || row.source === 'ticket' && ['waiting', 'called', 'skipped', 'in_progress'].includes(row.status))" type="button" class="dw-queue-menu-trigger" data-permission="workspace.view"
                :aria-label="'Bemor amallari: ' + row.name"
                :aria-expanded="openMenuKey === row.key" aria-haspopup="menu"
                @click="toggleRowMenu(row.key)">
                <Ellipsis :size="20" />
              </button>
              <div v-if="openMenuKey === row.key" class="dw-queue-popup" role="menu"
                :aria-label="row.name + ' uchun amallar'">
                <button v-if="row.status === 'in_progress'" type="button" role="menuitem"
                  @click="resumeRow(row)">
                  <Play :size="17" /> Qabulni davom ettirish
                </button>
                <template v-else-if="row.source === 'appointment'">
                  <button type="button" role="menuitem" :disabled="!can('appointments.update') || !can('appointments.view') || !can('patients.view')" @click="openAppointmentPreview(row)">
                    <CalendarDays :size="17" /> Boshqa kunga ko‘chirish
                  </button>
                  <button type="button" role="menuitem" class="dw-menu-danger"
                    :disabled="!can('appointments.cancel') || !can('appointments.view')"
                    @click="openAppointmentCancel(row)">
                    <Ban :size="17" /> Navbatni bekor qilish
                  </button>
                </template>
                <template v-else>
                  <button v-if="row.status !== 'skipped'" type="button" role="menuitem" data-permission="workspace.view" :disabled="!actionAllowed(row, 'skip')" @click="showActionPreview(row, 'skip')">
                    <FastForward :size="17" /> O‘tkazib yuborish
                  </button>
                  <button type="button" role="menuitem" class="dw-menu-danger" data-permission="workspace.view" :disabled="!actionAllowed(row, 'cancel')"
                    @click="showActionPreview(row, 'cancel')">
                    <Ban :size="17" /> Navbatni bekor qilish
                  </button>
                  <p v-if="(row.status !== 'skipped' && !actionAllowed(row, 'skip')) || !actionAllowed(row, 'cancel')" class="dw-menu-permission">Ba’zi amallar uchun ruxsat yetishmaydi. Klinikangiz administratoriga murojaat qiling.</p>
                </template>
              </div>
            </div>
            <p v-if="!filteredRows.length && !error" class="dw-empty">
              {{ query.trim() ? "Qidiruv bo‘yicha bemor topilmadi." :
                queueTab === "unresolved" ? "Oldingi kundan qolgan ochiq navbat yo‘q." :
                queueTab === "completed" ? "Yakunlangan qabul topilmadi." :
                "Ushbu sanada kutayotgan bemorlar yo‘q." }}
            </p>
          </template>
        </div>
        <button v-if="feed?.doctor" type="button" class="dw-call-next"
          data-permission="workspace.view" :disabled="!callAllowed" @click="callNext">
          <Megaphone :size="18" />
          {{ calling ? "Chaqirilmoqda..." : callLabel }}
        </button>
        <p v-if="feed?.doctor && callPermissionMessage" class="dw-call-hint">
          {{ callPermissionMessage }}
        </p>
        <p v-else-if="feed?.doctor && hasActivePatient" class="dw-call-hint" role="status">
          {{ activePatient?.name || "Oldingi bemor" }} hali
          {{ activePatient?.status === "in_progress" ? "qabulda" : "chaqirilgan" }}.
          Qabul yakunlanmaguncha keyingi bemor chaqirilmaydi.
        </p>
        <p v-else-if="feed?.doctor && date !== clinicToday" class="dw-call-hint">
          Bemorlarni faqat bugungi navbatdan chaqirish mumkin.
        </p>
        <p v-else-if="feed?.doctor && !nextTicket && !loading" class="dw-call-hint">
          Navbatda chaqiriladigan bemor yo‘q.
        </p>
        <p class="dw-live-footer">Ro‘yxat registratura ma’lumotlari asosida ko‘rsatilmoqda.</p>
      </aside>

      <section class="dw-live-details">
        <article v-if="selected && hasHistoricScope(selected)" class="dw-card dw-live-history-preview">
          <div class="dw-patient-line">
            <span class="dw-avatar large purple">{{ initials(selected.name) }}</span>
            <div>
              <h2>{{ selected.name }}</h2>
              <p>{{ selected.patient_number }} · {{ selected.historicDate }} · {{ selected.service }}</p>
              <p>{{ selected.historicBranchName }}</p>
            </div>
            <span class="dw-badge" :class="selected.status === 'in_progress' ? 'blue' : 'yellow'">
              {{ statusText(selected) }}
            </span>
          </div>
          <p class="dw-live-warning">
            Bu oldingi sanadagi navbat. Qabulning haqiqiy tibbiy yozuvlarini ko‘rish
            uchun avval eski sanani oching.
          </p>
          <button v-if="selected.status === 'in_progress'" type="button"
            class="dw-start-button" @click="resumeRow(selected)">
            <Play :size="18" /> Qabulni davom ettirish
          </button>
        </article>
        <template v-else-if="selected">
          <article class="dw-card dw-patient-card">
            <div class="dw-patient-line">
              <span class="dw-avatar large purple">{{ initials(selected.name) }}</span>
              <div>
                <h2>{{ selected.name }}</h2>
                <p>{{ selected.patient_number }} · {{ genderLabel(selected.gender) }}
                  <template v-if="age(selected.dob) !== null"> · {{ age(selected.dob) }} yosh</template>
                  <template v-if="selected.dob"> · {{ selected.dob }}</template>
                </p>
                <p>{{ selected.phone || "Telefon kiritilmagan" }}</p>
              </div>
              <span class="dw-badge blue">{{ statusText(selected) }}</span>
            </div>
          <details class="dw-visit-disclosure" :key="selected.key">
            <summary>
              <Stethoscope :size="18" />
              <span>Qabul tafsilotlari</span>
              <ChevronDown :size="18" class="dw-visit-chevron" />
            </summary>
            <dl>
              <div><dt>Shifokor</dt><dd>{{ feed?.doctor?.name }}</dd></div>
              <div><dt>Xizmat</dt><dd>{{ selected.service }}</dd></div>
              <div><dt>Qabul sanasi</dt><dd>{{ selected.historicDate || date }}</dd></div>
              <div><dt>Vaqt / Navbat</dt><dd>{{ visitTime(selected) }}</dd></div>
              <div><dt>Holati</dt><dd>{{ statusText(selected) }}</dd></div>
            </dl>
          </details>
            <div v-if="selected.source === 'ticket' && selected.status === 'called' && !hasHistoricScope(selected)" class="dw-start-block">
              <button type="button" class="dw-start-button" data-permission="workspace.view"
                :disabled="!startAllowed" @click="startConsultation">
                <Play :size="18" />
                {{ starting ? "Qabul boshlanmoqda..." : "Qabulni boshlash" }}
              </button>
              <p v-if="startError && startErrorFor === selected.key" class="dw-start-error" role="alert">
                {{ startError }}
              </p>
              <p v-if="startPermissionMessage" class="dw-start-hint" role="status">
                {{ startPermissionMessage }}
              </p>
              <p v-else-if="anotherConsultation" class="dw-start-hint" role="status">
                Oldingi qabul yakunlanmaguncha boshqa bemorni qabulga olib bo‘lmaydi.
              </p>
              <p v-else-if="date !== clinicToday" class="dw-start-hint" role="status">
                Faqat bugungi chaqirilgan bemorning qabulini boshlash mumkin.
              </p>
            </div>
            <p v-if="selected.status !== 'in_progress'" class="dw-live-warning">
              Qabulning tibbiy yozuvlari hali haqiqiy API bilan ulanmagan.
              Tibbiy karta interfeysi qabul boshlangandan keyin, faqat ko‘rish rejimida ochiladi.
            </p>
          </article>
          <DoctorEncounterPreview
            v-if="selected.source === 'ticket' && selected.status === 'in_progress'
              && feed?.doctor && feed?.branch"
            :key="selected.visit_id || selected.id"
            :patient="selected" :doctor="feed.doctor" :branch="feed.branch" :date="date"
          />
        </template>
        <article v-else class="dw-card dw-live-empty">
          <UserRound :size="32" />
          <p v-if="loading">Bemor ma’lumotlari yuklanmoqda...</p>
          <p v-else-if="error">Server ma’lumotlarini qayta yuklang.</p>
          <p v-else>Ko‘rish uchun navbatdagi bemorni tanlang.</p>
        </article>
      </section>
    </div>

    <div v-if="appointmentCancelPreview" class="dw-preview-backdrop" @click.self="!appointmentCancelSaving && (appointmentCancelPreview = null)">
      <section class="dw-preview-dialog" role="dialog" aria-modal="true" aria-label="Yozilgan qabulni bekor qilish">
        <div class="dw-preview-heading">
          <div>
            <h2>Navbatni bekor qilish</h2>
            <p>{{ appointmentCancelPreview.name }}</p>
          </div>
          <button type="button" class="dw-preview-close" :disabled="appointmentCancelSaving"
            @click="appointmentCancelPreview = null">×</button>
        </div>
        <p class="dw-preview-hint">Bu bemorning rejalashtirilgan qabuli bekor qilinadi va vaqt boshqa bemorlarga bo‘shaydi. Davom etasizmi?</p>
        <p v-if="appointmentCancelError" class="dw-action-error" role="alert">{{ appointmentCancelError }}</p>
        <div class="dw-preview-footer">
          <button type="button" class="dw-preview-dismiss" :disabled="appointmentCancelSaving"
            @click="appointmentCancelPreview = null">Yopish</button>
          <button type="button" class="dw-preview-confirm" :disabled="appointmentCancelSaving"
            @click="cancelScheduledAppointment">{{ appointmentCancelSaving ? 'Saqlanmoqda...' : 'Bekor qilishni tasdiqlash' }}</button>
        </div>
      </section>
    </div>
    <div v-if="appointmentPreview" class="dw-preview-backdrop" @click.self="closeAppointmentPreview">
      <section class="dw-preview-dialog" role="dialog" aria-modal="true" aria-label="Qabulni boshqa kunga ko‘chirish">
        <div class="dw-preview-heading">
          <div>
            <h2>Qabulni boshqa kunga ko‘chirish</h2>
            <p>{{ appointmentPreview.name }}</p>
          </div>
          <button type="button" class="dw-preview-close" @click="closeAppointmentPreview">×</button>
        </div>
        <p class="dw-preview-hint">Faqat shu shifokorning band bo‘lmagan haqiqiy jadval vaqtlari tanlanadi.</p>
        <p v-if="appointmentError" class="dw-action-error" role="alert">{{ appointmentError }}</p>
        <div class="dw-preview-datetime">
          <label class="dw-preview-field">Yangi sana
            <input v-model="appointmentDate" aria-label="Ko‘chirish sanasi" type="date" :min="clinicToday" :disabled="appointmentSaving" />
          </label>
          <label class="dw-preview-field">Bo‘sh vaqt
            <select v-model="appointmentSlotKey" aria-label="Ko‘chirish vaqti" :disabled="appointmentSaving || appointmentLoading || !appointmentSlots.length">
              <option value="">Vaqtni tanlang</option>
              <option v-for="entry in appointmentChoices" :key="slotKey(entry.slot)" :value="slotKey(entry.slot)" :disabled="entry.busy">
                {{ appointmentTime(entry.slot) }}{{ entry.busy ? ' — Band' : '' }}
              </option>
            </select>
          </label>
          <p v-if="appointmentLoading">Vaqtlar tekshirilmoqda...</p>
          <p v-else-if="appointmentDate && !appointmentError && !appointmentSlots.length">Bu sanada bo‘sh vaqt yo‘q. Boshqa sanani tanlang.</p>
        </div>
        <div class="dw-preview-footer">
          <button type="button" class="dw-preview-dismiss" @click="closeAppointmentPreview">Yopish</button>
          <button type="button" class="dw-preview-confirm" :disabled="appointmentSaving || !appointmentSlotKey || appointmentLoading" @click="submitAppointmentReschedule">
            {{ appointmentSaving ? 'Saqlanmoqda...' : 'Tasdiqlash' }}
          </button>
        </div>
      </section>
    </div>
    <div v-if="actionPreview" class="dw-preview-backdrop" @click.self="closeActionPreview">
      <section class="dw-preview-dialog" role="dialog" aria-modal="true"
        :aria-label="actionPreview.action === 'skip' ? 'O‘tkazib yuborish' : 'Navbatni bekor qilish'">
        <div class="dw-preview-heading">
          <div>
            <h2>{{ actionPreview.action === 'skip' ? 'O‘tkazib yuborish' : 'Navbatni bekor qilish' }}</h2>
            <p>{{ actionPreview.name }}</p>
          </div>
          <button type="button" class="dw-preview-close" data-permission="workspace.view" aria-label="Modalni yopish" :disabled="actionSaving" @click="closeActionPreview">×</button>
        </div>
        <p class="dw-preview-hint">
          Sababni tanlang. Bemorni boshqa sana va vaqtga qayta yozish ixtiyoriy.
        </p>
        <p v-if="actionError" class="dw-action-error" role="alert">{{ actionError }}</p>
        <label class="dw-preview-field">Sabab *
          <select v-model="previewReason" aria-label="Sabab" required :disabled="actionSaving">
            <option value="">Sababni tanlang</option>
            <option value="patient_late">Bemor kechikdi</option>
            <option value="patient_absent">Bemor kelmadi</option>
            <option value="patient_requested">Bemorning iltimosi</option>
            <option value="other">Boshqa sabab</option>
          </select>
        </label>
        <label class="dw-preview-field">Izoh (ixtiyoriy)
          <textarea v-model="previewComment" aria-label="Izoh (ixtiyoriy)" rows="3" maxlength="2000"
            :disabled="actionSaving" placeholder="Qisqa izoh..."></textarea>
        </label>
        <label class="dw-preview-switch">
          <input v-model="previewReschedule" type="checkbox" :disabled="actionSaving || !canRebook" />
          Bemorni boshqa kunga qayta yozish (ixtiyoriy)
        </label>
        <p v-if="!canRebook" class="dw-action-helper">
          Qayta yozish uchun qabul yaratish, qabullar va bemorlarni ko‘rish ruxsatlari kerak.
        </p>
        <div v-if="previewReschedule" class="dw-preview-datetime">
          <label class="dw-preview-field">Yangi sana
            <input v-model="previewDate" type="date" aria-label="Yangi sana" :min="clinicToday" :disabled="actionSaving" />
          </label>
          <label class="dw-preview-field">Bo‘sh vaqt
            <select v-model="previewTime" aria-label="Bo‘sh vaqt"
              :disabled="actionSaving || optionsLoading || !rebookingSlots.length">
              <option value="">Vaqtni tanlang</option>
              <option v-for="entry in chronologicalRebookingSlots" :key="(entry.busy ? 'busy-' : 'free-') + slotKey(entry.slot)"
                :value="slotKey(entry.slot)" :disabled="entry.busy">
                {{ rebookingTime(entry.slot) }}{{ entry.busy ? ' — Band' : '' }}
              </option>
            </select>
          </label>
          <p v-if="optionsLoading" role="status">Bo‘sh vaqtlar yuklanmoqda...</p>
          <p v-else-if="optionsError" class="dw-action-error" role="alert">{{ optionsError }}</p>
          <p v-else-if="previewDate && busyRebookingSlots.length && !rebookingSlots.length" role="status">
            Bu sanadagi barcha mavjud vaqtlar band. Boshqa sanani tanlang.
          </p>
          <p v-else-if="previewDate && !rebookingSlots.length" role="status">
            Ushbu sanada shifokor uchun bo‘sh vaqt topilmadi. Boshqa sanani tanlang.
          </p>
          <p v-else-if="busyRebookingSlots.length" role="status">
            Band vaqtlar «Band» deb belgilangan va tanlanmaydi. Tavsiya: {{ recommendedRebookingSlot ? rebookingTime(recommendedRebookingSlot) : '' }} yoki ro‘yxatdagi boshqa bo‘sh vaqtni tanlang.
          </p>
          <p v-else>Vaqtlar shifokorning haqiqiy jadvalidan, klinika vaqt mintaqasida ko‘rsatiladi.</p>
          <p v-if="nextAvailableSuggestion?.date === previewDate" role="status">
            Tavsiya qilingan eng yaqin sana va vaqt: {{ nextAvailableSuggestion.date }},
            {{ rebookingTime(nextAvailableSuggestion.slot) }}. Bo‘sh vaqt avtomatik tanlandi.
          </p>
          <button v-if="previewDate && !optionsLoading && !optionsError && !rebookingSlots.length"
            type="button" class="dw-next-slot" :disabled="actionSaving || suggestionLoading"
            @click="findNextAvailableDate">
            {{ suggestionLoading ? 'Keyingi bo‘sh sana qidirilmoqda...' : 'Keyingi bo‘sh sanani topish (14 kun)' }}
          </button>
          <p v-if="suggestionError" class="dw-action-error" role="alert">{{ suggestionError }}</p>
        </div>
        <div class="dw-preview-footer">
          <button type="button" class="dw-preview-dismiss" :disabled="actionSaving" @click="closeActionPreview">Yopish</button>
          <button type="button" class="dw-preview-confirm" :data-permission="'queues.' + actionPreview.action" :disabled="actionSaving || !previewReason ||
            (previewReschedule && (!chosenRebookingSlot || optionsLoading || !canRebook))"
            @click="submitQueueAction">
            {{ actionSaving ? 'Saqlanmoqda...' : 'Tasdiqlash' }}
          </button>
        </div>
      </section>
    </div>
  </section>
</template>

<style scoped>


/* Compact prior-day warning; patient rows now belong to the left tab. */
.dw-overdue {
  display: flex; align-items: center; gap: 12px;
  margin: 0 0 15px; padding: 11px 15px;
  border: 1px solid #f4d4aa; border-radius: 11px;
  background: #fff8f0; color: #17325a;
}
.dw-overdue-alert-icon {
  flex: 0 0 34px; width: 34px; height: 34px;
  display: grid; place-items: center;
  color: #d96b15; background: #ffead1; border-radius: 50%;
}
.dw-overdue-heading { flex: 1; min-width: 0; }
.dw-overdue-heading h2 {
  margin: 0; font-size: 15px; line-height: 1.35; font-weight: 750; color: #754813;
}
.dw-overdue-heading p {
  margin: 3px 0 0; font-size: 12px; line-height: 1.4; color: #846445;
}
.dw-overdue-count {
  flex: 0 0 auto; border-radius: 7px; background: #ffebcf;
  color: #925504; padding: 6px 10px; font-size: 13px; font-weight: 700;
}
.dw-overdue-error {
  margin: 0 0 10px; padding: 10px 12px; border-radius: 8px;
  background: #fff0f0; color: #b42318; font-size: 13px;
}
/* The third tab fits the existing narrow doctor queue panel. */
.dw-ui .dw-queue-tabs button {
  font-size: 12px; gap: 5px; padding-inline: 2px;
  min-width: 0; letter-spacing: -0.15px;
}
.dw-ui .dw-queue-tabs b {
  min-width: 21px; width: auto; height: 22px; padding-inline: 4px; font-size: 12px;
}
/* Selection stays a semantic button. The more-menu is a sibling, not nested. */
.dw-queue-entry { position: relative; min-width: 0; }
.dw-ui .dw-queue-entry > .dw-queue-person {
  padding-right: 51px; cursor: pointer;
}
.dw-ui .dw-queue-entry > .dw-queue-person .dw-queue-person-info {
  min-width: 0;
}
.dw-queue-entry > .dw-queue-menu-trigger {
  position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
  display: flex; align-items: center; justify-content: center;
  width: 32px; height: 32px; border-radius: 8px;
  border: 1px solid #dae6f9; background: #fff; color: #436b9e;
  cursor: pointer; z-index: 1;
}
.dw-queue-entry > .dw-queue-menu-trigger:hover,
.dw-queue-entry > .dw-queue-menu-trigger[aria-expanded="true"] {
  background: #edf5ff; border-color: #9ec6ff; color: #0b6cf5;
}
.dw-queue-entry > .dw-queue-menu-trigger:focus-visible {
  outline: 2px solid #1476ff; outline-offset: 2px;
}
.dw-queue-popup {
  position: absolute; z-index: 10; top: calc(50% + 17px); right: 8px;
  width: 226px; display: grid; padding: 6px;
  border: 1px solid #d9e6f7; border-radius: 10px; background: #fff;
  box-shadow: 0 8px 28px rgb(25 48 92 / 15%);
}
.dw-queue-popup button {
  display: flex; align-items: center; gap: 9px;
  padding: 11px 10px; border: 0; border-radius: 7px;
  background: #fff; font: inherit; color: #264978;
  text-align: left; font-size: 13px; cursor: pointer;
}
.dw-queue-popup button:hover { background: #edf5ff; color: #0768ee; }
.dw-queue-popup button.dw-menu-danger { color: #bd3340; }
.dw-queue-popup button.dw-menu-danger:hover { background: #fff0f2; }
.dw-queue-popup button:disabled { opacity: .48; cursor: not-allowed; }
.dw-menu-permission { margin: 5px 10px 8px; color: #775d37; font-size: 12px; line-height: 1.4; }
/* Modal is visually complete, but intentionally has no write action. */
.dw-preview-backdrop {
  position: fixed; inset: 0; z-index: 90;
  display: grid; place-items: center; padding: 16px;
  background: rgb(13 30 56 / 45%);
}
.dw-preview-dialog {
  width: min(100%, 515px); max-height: min(90vh, 780px);
  overflow: auto; padding: 23px; background: white; border-radius: 14px;
  border: 1px solid #dce6f5; box-shadow: 0 18px 65px rgb(13 34 69 / 22%);
}
.dw-preview-heading { display: flex; align-items: start; justify-content: space-between; gap: 12px; }
.dw-preview-heading h2 { margin: 0 0 5px; color: #0b2552; font-size: 20px; }
.dw-preview-heading p { margin: 0; color: #6b83a3; }
.dw-preview-close {
  cursor: pointer; border: 0; border-radius: 7px; background: #eef5ff;
  color: #46658f; width: 32px; height: 32px; font-size: 24px;
}
.dw-preview-hint {
  margin: 17px 0; padding: 12px; border-radius: 9px;
  background: #edf5ff; color: #47688f; font-size: 13px; line-height: 1.5;
}
.dw-preview-field {
  display: flex; flex-direction: column; gap: 7px; margin: 13px 0;
  font-size: 13px; font-weight: 600; color: #264978;
}
.dw-preview-field select, .dw-preview-field textarea, .dw-preview-field input {
  width: 100%; box-sizing: border-box; border: 1px solid #d2e1f5;
  border-radius: 8px; padding: 10px 12px; font: inherit; font-weight: 400;
  background: white; color: #143461;
}
.dw-preview-switch { display: flex; align-items: center; gap: 9px; margin: 16px 0; font-size: 13px; }
.dw-preview-switch input { accent-color: #1673ff; width: 18px; height: 18px; }
.dw-preview-datetime { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.dw-preview-datetime > p {
  grid-column: 1 / -1; margin: 0;
  color: #9a631d; font-size: 12px; line-height: 1.4;
}
.dw-preview-footer {
  display: flex; flex-wrap: wrap; justify-content: flex-end;
  gap: 9px; margin-top: 20px;
}
.dw-preview-footer button {
  border-radius: 8px; min-height: 39px; padding: 10px 13px; font: inherit;
  font-size: 13px; font-weight: 600;
}
.dw-preview-dismiss {
  border: 1px solid #d7e4f6; background: #f6f9ff;
  color: #335680; cursor: pointer;
}
.dw-preview-confirm {
  border: 1px solid #1673ff; background: #1673ff; color: white; cursor: pointer;
}
.dw-preview-confirm:disabled { opacity: .5; cursor: not-allowed; }
.dw-action-error { color: #b4233b; font-size: 13px; line-height: 1.5; }
.dw-preview-datetime > .dw-action-error { color: #b4233b; }
.dw-next-slot {
  grid-column: 1 / -1; border: 1px solid #bfd5f5; border-radius: 8px;
  background: #eef5ff; padding: 10px 12px; color: #1555ab;
  font: inherit; cursor: pointer;
}
.dw-next-slot:disabled { opacity: .6; cursor: not-allowed; }
.dw-action-helper { color: #6b83a3; font-size: 12px; line-height: 1.5; }
.dw-live-history-preview { padding: 20px; }
.dw-live-history-preview .dw-start-button { margin-top: 14px; }
@media (max-width: 1100px) {
  .dw-ui .dw-queue-tabs button { font-size: 11px; gap: 3px; }
}
@media (max-width: 700px) {
  .dw-overdue-heading h2 { font-size: 13px; }
  .dw-overdue-heading p { font-size: 11px; }
  .dw-queue-popup { left: 12px; right: auto; }
  .dw-preview-datetime { grid-template-columns: 1fr; }
}
.dw-live-ui { min-height: 66vh; }
.dw-live-filters { display: flex; flex-wrap: wrap; align-items: end; gap: 10px; }
.dw-live-date, .dw-live-select { min-height: 42px; border: 1px solid #d5e3f8; border-radius: 10px; background: white; display: flex; align-items: center; gap: 7px; padding: 8px 12px; color: #234371; }
.dw-live-date input, .dw-live-select select { border: 0; background: transparent; color: #19375f; font: inherit; max-width: 180px; outline: none; }
.dw-live-select { display: flex; flex-direction: column; align-items: stretch; gap: 1px; padding: 3px 10px; }
.dw-live-select span { font-size: 11px; color: #647fa2; }
.dw-live-select select { min-width: 135px; }
.dw-live-today, .dw-live-reload { cursor: pointer; border: none; border-radius: 10px; background: #e7f1ff; color: #0875f9; padding: 11px 13px; font: inherit; }
.dw-live-reload { display: flex; align-items: center; }
.dw-live-details { min-width: 0; display: flex; flex-direction: column; gap: 16px; }
.dw-visit-disclosure { margin-top: 15px; border-top: 1px solid #e8eff8; }
.dw-visit-disclosure summary { padding: 12px 2px 2px; cursor: pointer; display: flex; align-items: center; gap: 9px; color: #426492; font-weight: 600; list-style: none; }
.dw-visit-disclosure summary::-webkit-details-marker { display: none; }
.dw-visit-disclosure .dw-visit-chevron { margin-left: auto; transition: transform 160ms; }
.dw-visit-disclosure[open] .dw-visit-chevron { transform: rotate(180deg); }
.dw-visit-disclosure dl { display: grid; gap: 11px; margin: 14px 2px 0; }
.dw-visit-disclosure dl > div { display: grid; grid-template-columns: 145px 1fr; gap: 14px; border-bottom: 1px solid #e8eff8; padding-bottom: 11px; }
.dw-visit-disclosure dt { color: #647b99; }
.dw-visit-disclosure dd { margin: 0; font-weight: 600; }
.dw-start-block { margin-top: 18px; }
.dw-start-button { min-height: 44px; padding: 10px 18px; display: inline-flex; align-items: center; justify-content: center; gap: 9px; border: 0; border-radius: 9px; background: #146dff; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
.dw-start-button:disabled { opacity: 0.5; cursor: not-allowed; }
.dw-start-hint { margin: 8px 0 0; color: #647b99; font-size: 13px; }
.dw-start-error { margin: 10px 0 0; color: #b42318; font-size: 13px; line-height: 1.5; }
.dw-live-warning { background: #f2f7ff; padding: 14px; border-radius: 10px; color: #476488; margin-top: 18px; line-height: 1.5; }
.dw-live-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 18px; color: #7790b1; min-height: 250px; }
.dw-call-next { margin: auto 6px 0; width: calc(100% - 12px); min-height: 48px; display: flex; align-items: center; justify-content: center; gap: 10px; background: #146dff; color: white; border: 1px solid #146dff; border-radius: 9px; font: inherit; font-weight: 700; cursor: pointer; }
.dw-call-next:disabled { opacity: 0.5; cursor: not-allowed; }
.dw-call-hint { margin: 8px 8px 0 !important; text-align: center; font-size: 12px; }
.dw-live-footer { color: #7890a9; padding: 10px 16px; font-size: 12px; }
@media (max-width: 1050px) { .dw-live-filters { width: 100%; } .dw-live-visit dl > div { grid-template-columns: 110px 1fr; } }
</style>
