<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { CalendarDays, ChevronDown, ChevronRight, Megaphone, Play, RefreshCw, Search, Stethoscope, UserRound } from "lucide-vue-next";
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
const queueTab = ref<"waiting" | "completed">("waiting");
const query = ref("");
const selectedKey = ref("");
const loading = ref(true);
const calling = ref(false);
const resolvingOverdueId = ref("");
const overdueError = ref("");

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
  return [
    ...current.scheduled.map((r) => ({ ...r, source: "appointment" as const, key: "appointment:" + r.id })),
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

function canResolveOverdue(row: { status: string; appointment_id: string | null }, action: "skip" | "cancel" | "complete") {
  if (row.status === "in_progress") {
    return action === "complete" && can("queues.complete") &&
      (!row.appointment_id || can("appointments.update"));
  }
  if (row.status !== "called") return false;
  if (action === "skip") return can("queues.skip");
  return action === "cancel" && can("queues.cancel") &&
    (!row.appointment_id || can("appointments.cancel"));
}

async function continueOverdue(row: { id: string; local_date: string; branch_id: string }) {
  // Navigation alone: no queue status or medical record mutation.
  if (loading.value || resolvingOverdueId.value) return;
  queueTab.value = "waiting";
  query.value = "";
  overdueError.value = "";
  date.value = row.local_date;
  branchId.value = row.branch_id;
  await load("ticket:" + row.id);
}

async function resolveOverdue(
  row: { id: string; name: string; status: string; version: number; appointment_id: string | null },
  action: "skip" | "cancel" | "complete",
) {
  if (resolvingOverdueId.value || loading.value || !canResolveOverdue(row, action)) return;
  const description = action === "complete"
    ? `${row.name} qabuli yakunlansinmi? Oldingi tibbiy yozuvlar saqlanadi, lekin yakunlangan qabulni tahrirlab bo‘lmaydi.`
    : action === "skip"
      ? `${row.name} navbati «O‘tkazib yuborilgan» holatiga o‘tkazilsinmi? Bemor ma’lumotlari o‘chirilmaydi.`
      : `${row.name} navbati bekor qilinsinmi? Bu amal bron va navbat holatiga ham ta’sir qiladi.`;
  if (!window.confirm(description)) return;
  resolvingOverdueId.value = row.id;
  overdueError.value = "";
  try {
    await clinicRequest("queue-tickets/" + encodeURIComponent(row.id) + "/" + action, {
      method: "POST",
      body: { expected_version: row.version, reason_code: "previous_day_manual_resolution" },
    });
    await load();
  } catch (cause) {
    const message = errorText(cause);
    try {
      await load();
    } finally {
      overdueError.value = message;
    }
  } finally {
    resolvingOverdueId.value = "";
  }
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
  !startPermissionMessage.value && !anotherConsultation.value &&
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
const completed = (row: DisplayRow) => row.status === "completed";
const filteredRows = computed(() => rows.value.filter((row) => {
  if (completed(row) !== (queueTab.value === "completed")) return false;
  const search = query.value.trim().toLocaleLowerCase();
  return !search || [row.name, row.patient_number, String(row.number ?? ""), row.service]
    .some((item) => item?.toLocaleLowerCase().includes(search));
}));
const waitingCount = computed(() => rows.value.filter((row) => !completed(row)).length);
const completedCount = computed(() => rows.value.filter(completed).length);
const selected = computed(() => filteredRows.value.find((r) => r.key === selectedKey.value) || filteredRows.value[0] || null);
function setTab(tab: "waiting" | "completed") {
  queueTab.value = tab;
  selectedKey.value = "";
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
    completed: "Yakunlangan", skipped: "O‘tkazib yuborilgan",
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
    <section v-if="feed?.doctor && overdueTickets.length" class="dw-overdue" aria-label="Eski kundan qolgan navbatlar">
      <div class="dw-overdue-header">
        <div>
          <h2>Oldingi kundan yakunlanmagan qabullar</h2>
          <p>Shifokorning avvalgi sanalardagi faol qabullari va chaqirilgan navbatlari.
            Ular hal etilmaguncha yangi bemorni chaqirish mumkin emas.</p>
        </div>
        <strong>{{ overdueTickets.length }} ta</strong>
      </div>
      <p v-if="overdueError" class="dw-overdue-error" role="alert">{{ overdueError }}</p>
      <div class="dw-overdue-list">
        <div v-for="item in overdueTickets" :key="item.id" class="dw-overdue-item">
          <div class="dw-overdue-info">
            <b>{{ item.name }}</b>
            <span>{{ item.local_date }} · {{ item.branch_name }} · {{ item.service }}</span>
            <span class="dw-overdue-status">{{ item.status === "in_progress" ? "Qabulda" : "Chaqirildi" }}</span>
          </div>
          <div class="dw-overdue-actions">
            <button v-if="item.status === 'in_progress'" type="button"
              :disabled="loading || !!resolvingOverdueId" @click="continueOverdue(item)">
              Qabulni davom ettirish
            </button>
            <button v-if="item.status === 'in_progress' && canResolveOverdue(item, 'complete')"
              type="button" class="dw-overdue-danger" :disabled="loading || !!resolvingOverdueId"
              @click="resolveOverdue(item, 'complete')">
              {{ resolvingOverdueId === item.id ? "Bajarilmoqda..." : "Qabulni yakunlash" }}
            </button>
            <button v-if="item.status === 'called' && canResolveOverdue(item, 'skip')"
              type="button" :disabled="loading || !!resolvingOverdueId"
              @click="resolveOverdue(item, 'skip')">
              {{ resolvingOverdueId === item.id ? "Bajarilmoqda..." : "O‘tkazib yuborish" }}
            </button>
            <button v-if="item.status === 'called' && canResolveOverdue(item, 'cancel')"
              type="button" class="dw-overdue-danger" :disabled="loading || !!resolvingOverdueId"
              @click="resolveOverdue(item, 'cancel')">
              {{ resolvingOverdueId === item.id ? "Bajarilmoqda..." : "Navbatni bekor qilish" }}
            </button>
            <span v-if="item.status === 'called' && !canResolveOverdue(item, 'skip') && !canResolveOverdue(item, 'cancel')"
              class="dw-overdue-permission">Navbatni yopish uchun tegishli ruxsat kerak.</span>
            <span v-if="item.status === 'in_progress' && !canResolveOverdue(item, 'complete')"
              class="dw-overdue-permission">Yakunlash uchun «Navbatni yakunlash» ruxsati kerak.</span>
          </div>
        </div>
      </div>
    </section>
    <div class="dw-workspace">
      <aside class="dw-card dw-queue">
        <h2>Bugungi navbat</h2>
        <div class="dw-queue-tabs">
          <button type="button" data-permission="workspace.view" :class="{ selected: queueTab === 'waiting' }" @click="setTab('waiting')">
            Kutilmoqda <b>{{ waitingCount }}</b>
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
            <button v-for="row in filteredRows" :key="row.key" type="button"
              class="dw-queue-person" data-permission="workspace.view" :class="{ selected: selected?.key === row.key }"
              :aria-label="row.name + ' · ' + row.patient_number" @click="selectedKey = row.key">
              <span class="dw-avatar purple">{{ initials(row.name) }}</span>
              <span class="dw-queue-person-info">
                <b>{{ row.name }}</b>
                <small>{{ visitTime(row) }}</small>
                <small>{{ row.service }}</small>
              </span>
              <span class="dw-badge" :class="completed(row) ? 'green' : row.status === 'in_progress' ? 'blue' : 'yellow'">
                {{ statusText(row) }}
              </span>
              <ChevronRight :size="18" />
            </button>
            <p v-if="!filteredRows.length && !error" class="dw-empty">
              {{ rows.length ? "Qidiruv bo‘yicha bemor topilmadi." : "Ushbu sanada bemorlar yo‘q." }}
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
        <template v-if="selected">
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
              <div><dt>Qabul sanasi</dt><dd>{{ date }}</dd></div>
              <div><dt>Vaqt / Navbat</dt><dd>{{ visitTime(selected) }}</dd></div>
              <div><dt>Holati</dt><dd>{{ statusText(selected) }}</dd></div>
            </dl>
          </details>
            <div v-if="selected.source === 'ticket' && selected.status === 'called'" class="dw-start-block">
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
  </section>
</template>

<style scoped>
.dw-overdue { margin: 0 0 16px; border: 1px solid #f3d29a; background: #fffaf1; border-radius: 12px; padding: 16px; }
.dw-overdue-header { display:flex; align-items:flex-start; justify-content:space-between; gap:14px; }
.dw-overdue-header h2 { margin:0 0 6px; font-size:17px; color:#7c4b11; }
.dw-overdue-header p { margin:0 0 12px; font-size:13px; color:#885f2f; line-height:1.5; }
.dw-overdue-header strong { background:#ffedd0; padding:5px 9px; border-radius:6px; color:#86550e; white-space:nowrap; }
.dw-overdue-list { display:grid; gap:9px; }
.dw-overdue-item { display:flex; align-items:center; justify-content:space-between; gap:12px; background:#fff; border:1px solid #f3ddbd; padding:12px; border-radius:9px; }
.dw-overdue-info { display:flex; flex-direction:column; gap:4px; min-width:0; }
.dw-overdue-info b { color:#163664; font-size:14px; }
.dw-overdue-info span { color:#6d7990; font-size:12px; }
.dw-overdue-info .dw-overdue-status { color:#a85a12; font-weight:600; }
.dw-overdue-actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.dw-overdue-actions button { color:#0d66d9; background:#edf5ff; border:1px solid #cddfff; border-radius:7px; padding:9px 12px; font-size:13px; font-weight:600; cursor:pointer; }
.dw-overdue-actions button.dw-overdue-danger { background:#fff1ee; border-color:#f5c9c3; color:#a53024; }
.dw-overdue-actions button:disabled { opacity:.5; cursor:not-allowed; }
.dw-overdue-permission { font-size:12px; color:#876c50; }
.dw-overdue-error { color:#b42318; font-size:13px; margin:2px 0 10px; }
@media(max-width:760px) { .dw-overdue-item { flex-direction:column; align-items:stretch; } }

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
