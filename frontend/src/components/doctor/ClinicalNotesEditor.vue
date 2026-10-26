<script setup lang="ts">
import { computed, ref, watch } from "vue";
import axios from "axios";
import { can } from "../../services/session";
import { errorText } from "../../services/api";
import {
  getVisitClinicalNote, saveVisitClinicalNote, type VisitClinicalNote,
} from "../../services/visitClinicalNotes";

const props = defineProps<{ visitId: string; active: boolean }>();
const complaints = ref("");
const anamnesis = ref("");
const examination = ref("");
const record = ref<VisitClinicalNote | null>(null);
const loading = ref(true);
const loaded = ref(false);
const saving = ref(false);
const notice = ref("");
const failure = ref("");
const conflict = ref(false);
let generation = 0;

const writable = computed(() =>
  props.active && can("workspace.consult") && can("workspace.view") && can("visits.view"),
);
const normalized = computed(() => ({
  complaints: complaints.value.trim(),
  anamnesis: anamnesis.value.trim(),
  examination: examination.value.trim(),
}));
const changed = computed(() => {
  const last = record.value;
  return last
    ? last.complaints !== normalized.value.complaints ||
      last.anamnesis !== normalized.value.anamnesis ||
      last.examination !== normalized.value.examination
    : Object.values(normalized.value).some(Boolean);
});
const valid = computed(() =>
  writable.value && loaded.value && !loading.value && !saving.value && !conflict.value &&
  changed.value && Object.values(normalized.value).every((value) => value.length <= 10000),
);

async function refresh() {
  const requestedVisit = props.visitId;
  const request = ++generation;
  complaints.value = "";
  anamnesis.value = "";
  examination.value = "";
  record.value = null;
  loaded.value = false;
  conflict.value = false;
  notice.value = "";
  failure.value = "";
  loading.value = true;
  try {
    const note = await getVisitClinicalNote(requestedVisit);
    if (generation !== request || props.visitId !== requestedVisit) return;
    record.value = note;
    complaints.value = note?.complaints ?? "";
    anamnesis.value = note?.anamnesis ?? "";
    examination.value = note?.examination ?? "";
    loaded.value = true;
  } catch (cause) {
    if (generation === request && props.visitId === requestedVisit) failure.value = errorText(cause);
  } finally {
    if (generation === request) loading.value = false;
  }
}

watch(() => props.visitId, () => void refresh(), { immediate: true });

async function save() {
  if (!valid.value) return;
  const requestedVisit = props.visitId;
  const request = generation;
  saving.value = true;
  notice.value = "";
  failure.value = "";
  try {
    const note = await saveVisitClinicalNote(requestedVisit, {
      expected_version: record.value?.version ?? 0,
      ...normalized.value,
    });
    if (generation !== request || props.visitId !== requestedVisit) return;
    record.value = note;
    complaints.value = note.complaints;
    anamnesis.value = note.anamnesis;
    examination.value = note.examination;
    notice.value = "Shikoyatlar, anamnez va ko‘rik natijalari serverga saqlandi.";
  } catch (cause) {
    if (generation !== request || props.visitId !== requestedVisit) return;
    conflict.value = axios.isAxiosError(cause) && cause.response?.status === 409;
    failure.value = conflict.value
      ? "Yozuv boshqa oynada o‘zgartirilgan. Matningizni nusxalab oling, so‘ng «Qayta yuklash»ni bosing."
      : errorText(cause);
  } finally {
    if (generation === request) saving.value = false;
  }
}
</script>

<template>
  <section class="dw-clinical-notes" aria-label="Shikoyat anamnez va ko‘rik">
    <form @submit.prevent="save">
      <div class="dw-grid-2 dw-notes-fields">
        <label class="dw-field">
          Shikoyatlar
          <textarea v-model="complaints" rows="3" maxlength="10000"
            aria-label="Shikoyatlar" placeholder="Shikoyatlarni kiriting..."
            :disabled="!writable || !loaded || loading || saving" />
        </label>
        <label class="dw-field">
          Anamnez
          <textarea v-model="anamnesis" rows="3" maxlength="10000"
            aria-label="Anamnez" placeholder="Kasallik tarixini kiriting..."
            :disabled="!writable || !loaded || loading || saving" />
        </label>
      </div>
      <label class="dw-field dw-notes-examination">
        Ko‘rik natijalari
        <textarea v-model="examination" rows="2" maxlength="10000"
          aria-label="Ko‘rik natijalari" placeholder="Ko‘rik natijalarini kiriting..."
          :disabled="!writable || !loaded || loading || saving" />
      </label>
      <div class="dw-notes-actions">
        <span v-if="loading" role="status">Ko‘rik yozuvlari yuklanmoqda...</span>
        <span v-else-if="notice" class="dw-notes-success" role="status">{{ notice }}</span>
        <span v-else-if="!writable" class="dw-notes-hint" role="status">
          «Ko‘rik ma’lumotlarini saqlash» ruxsati kerak.
        </span>
        <span v-else-if="record" class="dw-notes-hint">
          Serverdagi versiya: {{ record.version }}
        </span>
        <span v-else class="dw-notes-hint">Maydonlarni bosqichma-bosqich to‘ldirib saqlashingiz mumkin.</span>
        <button v-if="conflict || (!loaded && !loading)" type="button" class="e-button dw-small"
          :disabled="loading || saving" @click="refresh">Qayta yuklash</button>
        <button type="submit" class="e-button primary dw-small" :disabled="!valid">
          {{ saving ? "Saqlanmoqda..." : record ? "Ko‘rik yozuvlarini yangilash" : "Ko‘rik yozuvlarini saqlash" }}
        </button>
      </div>
      <p v-if="failure" class="dw-notes-error" role="alert">{{ failure }}</p>
    </form>
  </section>
</template>

<style scoped>
.dw-clinical-notes { margin-bottom: 16px; }
.dw-clinical-notes form { display: grid; gap: 13px; }
.dw-clinical-notes .dw-field { display: flex; flex-direction: column; gap: 8px; color: #244571; }
.dw-clinical-notes textarea { width: 100%; box-sizing: border-box; border: 1px solid #d5e2f7; border-radius: 8px; padding: 12px; font: inherit; color: #102b58; background: #fff; resize: vertical; min-height: 86px; }
.dw-clinical-notes textarea:disabled { background: #f4f7fc; cursor: not-allowed; }
.dw-notes-fields { min-width: 0; }
.dw-notes-examination { margin-top: 1px; }
.dw-notes-actions { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 10px; }
.dw-notes-hint, .dw-notes-success { margin-right: auto; font-size: 13px; }
.dw-notes-hint { color: #6680a4; }
.dw-notes-success { color: #18805c; }
.dw-notes-error { margin: 0; color: #b42318; font-size: 13px; }
</style>
