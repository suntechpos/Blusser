<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { CalendarDays, ChevronRight, RefreshCw, Search, Stethoscope, UserRound } from "lucide-vue-next";
import { session } from "../services/session";
import { errorText } from "../services/api";
import { getDoctorWorkspace, type DoctorWorkspaceFeed, type WorkspacePatientRow } from "../services/doctorWorkspace";
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
const error = ref("");
let sequence = 0;

async function load() {
  const request = ++sequence;
  // Never show the previous doctor's patients while a different identity/doctor loads.
  feed.value = null;
  selectedKey.value = "";
  error.value = "";
  loading.value = true;
  try {
    const result = await getDoctorWorkspace({
      ...(date.value ? { date: date.value } : {}),
      ...(doctorId.value ? { doctor_id: doctorId.value } : {}),
      ...(branchId.value ? { branch_id: branchId.value } : {}),
    });
    if (request !== sequence) return;
    feed.value = result;
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
  () => [session.epoch, session.user?.id, session.user?.active_tenant_id],
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
        <button type="button" class="dw-live-reload" aria-label="Navbatni yangilash" title="Yangilash" @click="load">
          <RefreshCw :size="18" />
        </button>
      </div>
    </header>

    <p v-if="error" class="dw-notice" role="alert">{{ error }}</p>
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
          </article>
          <article class="dw-card dw-live-visit">
            <h2><Stethoscope :size="21" /> Qabul tafsilotlari</h2>
            <dl>
              <div><dt>Shifokor</dt><dd>{{ feed?.doctor?.name }}</dd></div>
              <div><dt>Xizmat</dt><dd>{{ selected.service }}</dd></div>
              <div><dt>Qabul sanasi</dt><dd>{{ date }}</dd></div>
              <div><dt>Vaqt / Navbat</dt><dd>{{ visitTime(selected) }}</dd></div>
              <div><dt>Holati</dt><dd>{{ statusText(selected) }}</dd></div>
            </dl>
            <p class="dw-live-warning">
              Qabulning tibbiy yozuvlari hali haqiqiy API bilan ulanmagan. Bu sahifa hozircha
              faqat bemorlar va navbatning serverdagi holatini ko‘rsatadi; demo shikoyat,
              tashxis yoki retseptlar haqiqiy bemorga biriktirilmaydi.
            </p>
          </article>
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
.dw-live-visit { padding: 24px; }
.dw-live-visit h2 { display: flex; align-items: center; gap: 9px; margin: 0 0 16px; }
.dw-live-visit dl { display: grid; gap: 12px; margin: 0; }
.dw-live-visit dl > div { display: grid; grid-template-columns: 160px 1fr; gap: 16px; border-bottom: 1px solid #e8eff8; padding-bottom: 12px; }
.dw-live-visit dt { color: #647b99; }
.dw-live-visit dd { margin: 0; font-weight: 600; }
.dw-live-warning { background: #f2f7ff; padding: 14px; border-radius: 10px; color: #476488; margin-top: 18px; line-height: 1.5; }
.dw-live-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 18px; color: #7790b1; min-height: 250px; }
.dw-live-footer { color: #7890a9; padding: 10px 16px; font-size: 12px; }
@media (max-width: 1050px) { .dw-live-filters { width: 100%; } .dw-live-visit dl > div { grid-template-columns: 110px 1fr; } }
</style>
