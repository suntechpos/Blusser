<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { Clock3, LockKeyhole, Plus } from "lucide-vue-next";
import HistoryPanel from "./HistoryPanel.vue";
import RecordsPanel from "./RecordsPanel.vue";
import DiagnosisEditor from "./DiagnosisEditor.vue";
import ClinicalNotesEditor from "./ClinicalNotesEditor.vue";
import type { Encounter } from "../../mocks/doctorWorkspace";
import type { WorkspacePatientRow, WorkspaceDoctor, WorkspaceBranch } from "../../services/doctorWorkspace";

const props = defineProps<{
  patient: WorkspacePatientRow;
  doctor: WorkspaceDoctor;
  branch: WorkspaceBranch;
  date: string;
}>();

const tabs = [
  "Bugungi qabul",
  "Tashriflar tarixi",
  "Tekshiruv natijalari",
  "Hujjatlar",
] as const;
const tab = ref<(typeof tabs)[number]>("Bugungi qabul");

// The adapter contains real, server-projected identifiers and demographics,
// never the old createDoctorQueue()/createHistory()/createResults() fixtures.
const encounter = computed<Encounter>(() => {
  const p = props.patient;
  const dob = p.dob || "";
  let age = 0;
  if (dob && /^\d{4}-\d{2}-\d{2}$/.test(dob)) {
    const yyyy = Number(dob.slice(0, 4));
    const mmdd = dob.slice(5);
    age = Math.max(0, Number(props.date.slice(0, 4)) - yyyy -
      (props.date.slice(5) < mmdd ? 1 : 0));
  }
  return {
    id: p.visit_id || p.id,
    patientId: p.patient_number,
    name: p.name,
    initials: p.name.split(/\s+/).slice(0, 2).map((part) => part[0] || "").join("").toUpperCase(),
    gender: p.gender || "",
    age, dob, phone: p.phone || "",
    time: p.starts_at || p.started_at || "",
    ticket: p.number ? "№" + p.number : p.id,
    date: props.date,
    startedAt: p.started_at || "",
    status: "in_progress",
    color: "purple",
    allergy: null, // Unknown is not a negative clinical finding.
  };
});
const startTime = computed(() => {
  if (!props.patient.started_at) return "Vaqt qayd etilmagan";
  const instant = new Date(props.patient.started_at);
  if (Number.isNaN(instant.getTime())) return "Vaqt qayd etilmagan";
  return new Intl.DateTimeFormat("uz-UZ", {
    timeZone: props.branch.timezone,
    hour: "2-digit", minute: "2-digit", hourCycle: "h23",
  }).format(instant);
});
watch(() => props.patient.visit_id, () => { tab.value = "Bugungi qabul"; });
</script>

<template>
  <div class="dw-content dw-live-encounter" role="region" aria-label="Boshlangan qabul interfeysi">
    <div class="dw-tabs" role="tablist" aria-label="Bemor tafsilotlari">
      <button v-for="t in tabs" :key="t" type="button" role="tab"
        :aria-selected="tab === t" :class="{ selected: tab === t }" @click="tab = t">
        {{ t }}
      </button>
    </div>

    <article v-show="tab === 'Bugungi qabul'" class="dw-card dw-current" role="tabpanel">
      <div class="dw-section-title">
        <h2>Qabul yozuvi</h2>
        <span class="dw-start">
          <Clock3 :size="17" /> Qabul boshlandi: {{ startTime }}
        </span>
      </div>
      <p class="dw-preview-notice" role="status">
        Shikoyatlar, anamnez, ko‘rik natijalari va tashxis serverga alohida saqlanadi.
        Tavsiyalar, retsept va yo‘llanmalar keyingi bosqichda ulanadi.
      </p>
      <ClinicalNotesEditor v-if="patient.visit_id" :key="patient.visit_id"
        :visit-id="patient.visit_id" :active="patient.status === 'in_progress'" />
      <DiagnosisEditor v-if="patient.visit_id" :key="patient.visit_id"
        :visit-id="patient.visit_id" :active="patient.status === 'in_progress'" />
      <fieldset disabled aria-label="Keyingi taskda ulanadigan tavsiyalar" class="dw-readonly-fields">
        <label class="dw-field">
          Tavsiyalar
          <textarea aria-label="Tavsiyalar" placeholder="Tavsiyalarni kiriting..." rows="2" />
        </label>
      </fieldset>
      <div class="dw-current-actions" aria-label="Keyingi tasklarda ulanadigan amallar">
        <button type="button" class="e-button dw-small" disabled title="Yo‘llanmalar API keyingi taskda ulanadi">
          <Plus :size="20" /> Yo‘llanma
        </button>
        <button type="button" class="e-button dw-small" disabled title="Retseptlar API keyingi taskda ulanadi">
          <Plus :size="20" /> Retsept
        </button>
        <span />
        <button type="button" class="e-button dw-small" disabled title="Tibbiy yozuvlar hali serverga ulanmagan">
          Qoralamani saqlash
        </button>
        <button type="button" class="e-button primary dw-small" disabled title="Qabulni yakunlash Task #03">
          Qabulni yakunlash
        </button>
      </div>
      <p class="dw-footnote"><LockKeyhole :size="17" />
        Ko‘rik yozuvlari va tashxis alohida saqlanadi. Tavsiyalar hamda qolgan amallar keyingi tasklarda ulanadi.
      </p>
    </article>

    <template v-if="tab === 'Tashriflar tarixi'">
      <p class="dw-preview-notice dw-preview-tab-notice" role="status">
        Tashriflar tarixi API hali ulanmagan. Quyida faqat eski veryostka ko‘rsatilgan.
      </p>
      <HistoryPanel :key="patient.patient_id" :encounter="encounter"
        :visits="[]" />
    </template>

    <template v-else-if="tab === 'Tekshiruv natijalari' || tab === 'Hujjatlar'">
      <p class="dw-preview-notice dw-preview-tab-notice" role="status">
        {{ tab }} API hali ulanmagan. Real natija yoki hujjat mavjud deb ko‘rsatilmaydi.
      </p>
      <RecordsPanel :key="patient.patient_id + tab" preview-only
        :kind="tab === 'Tekshiruv natijalari' ? 'results' : 'documents'"
        :encounter="encounter" :results="[]" :documents="[]" :encounters="[]" :referrals="[]" />
    </template>
  </div>
</template>

<style scoped>
.dw-preview-notice {
  background: #f2f7ff;
  border-radius: 9px;
  color: #476488;
  padding: 12px 15px;
  margin: 0 0 18px;
  line-height: 1.5;
}
.dw-preview-tab-notice { margin-bottom: 10px; }
.dw-readonly-fields { min-width: 0; border: 0; padding: 0; margin: 0; }
.dw-readonly-fields :disabled { cursor: not-allowed; opacity: .72; }
.dw-current-actions button:disabled { opacity: .5; cursor: not-allowed; }
</style>
