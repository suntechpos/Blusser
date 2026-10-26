<script setup lang="ts">
import { computed, ref, watch } from "vue";
import axios from "axios";
import { can } from "../../services/session";
import { errorText } from "../../services/api";
import {
  getVisitDiagnosis, saveVisitDiagnosis, type VisitDiagnosis,
} from "../../services/visitDiagnosis";

const props = defineProps<{ visitId: string; active: boolean }>();

const diagnosisText = ref("");
const diagnosisType = ref<"preliminary" | "final">("preliminary");
const icdCode = ref("");
const record = ref<VisitDiagnosis | null>(null);
const loading = ref(false);
const saving = ref(false);
const notice = ref("");
const failure = ref("");
const conflict = ref(false);
let generation = 0;
const writable = computed(() =>
  props.active && can("workspace.consult") && can("workspace.view") && can("visits.view"),
);
const valid = computed(() =>
  writable.value && !loading.value && !saving.value &&
  diagnosisText.value.trim().length > 0 && diagnosisText.value.trim().length <= 4000 &&
  (!icdCode.value.trim() || /^[A-Za-z][0-9]{2}(\.[0-9A-Za-z]{1,4})?$/.test(icdCode.value.trim())),
);

async function refresh() {
  const requestedVisit = props.visitId;
  const request = ++generation;
  record.value = null;
  diagnosisText.value = "";
  diagnosisType.value = "preliminary";
  icdCode.value = "";
  conflict.value = false;
  notice.value = "";
  failure.value = "";
  loading.value = true;
  try {
    const result = await getVisitDiagnosis(requestedVisit);
    if (generation !== request || props.visitId !== requestedVisit) return;
    record.value = result;
    if (result) {
      diagnosisText.value = result.diagnosis_text;
      diagnosisType.value = result.diagnosis_type;
      icdCode.value = result.icd_code ?? "";
    }
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
  conflict.value = false;
  try {
    const saved = await saveVisitDiagnosis(requestedVisit, {
      expected_version: record.value?.version ?? 0,
      diagnosis_text: diagnosisText.value.trim(),
      diagnosis_type: diagnosisType.value,
      icd_code: icdCode.value.trim().toUpperCase() || null,
    });
    if (generation !== request || props.visitId !== requestedVisit) return;
    record.value = saved;
    icdCode.value = saved.icd_code || "";
    notice.value = "Tashxis serverga saqlandi.";
  } catch (cause) {
    if (generation !== request || props.visitId !== requestedVisit) return;
    conflict.value = axios.isAxiosError(cause) && cause.response?.status === 409;
    failure.value = conflict.value
      ? "Tashxis boshqa oynada o‘zgartirilgan. Yozganingizni nusxalab oling va «Qayta yuklash» tugmasini bosing."
      : errorText(cause);
  } finally {
    if (generation === request) saving.value = false;
  }
}
</script>

<template>
  <section class="dw-diagnosis-editor" aria-label="Tashxis yozuvi">
    <div class="dw-diagnosis-heading">
      <h3>Tashxis</h3>
      <span v-if="record" class="dw-diagnosis-id">{{ record.number }} · v{{ record.version }}</span>
      <span v-else-if="!loading" class="dw-diagnosis-id">Yangi tashxis</span>
    </div>
    <p class="dw-diagnosis-description">
      Shifokor tashxisni o‘zi yozadi. MKB-10 kodi ixtiyoriy;
      avvalgi bemor tashxisi avtomatik ko‘chirilmaydi.
    </p>
    <form class="dw-diagnosis-form" @submit.prevent="save">
      <label class="dw-field">
        Shifokor tashxisi <span aria-hidden="true">*</span>
        <textarea v-model="diagnosisText" rows="3" maxlength="4000"
          aria-label="Shifokor tashxisi" placeholder="Aniqlangan tashxisni batafsil yozing..."
          :disabled="!writable || loading || saving"/>
      </label>
      <div class="dw-diagnosis-fields">
        <label class="dw-field">
          Tashxis turi
          <select v-model="diagnosisType" aria-label="Tashxis turi"
            :disabled="!writable || loading || saving">
            <option value="preliminary">Dastlabki</option>
            <option value="final">Yakuniy</option>
          </select>
        </label>
        <label class="dw-field">
          MKB-10 kodi <span class="dw-optional">(ixtiyoriy)</span>
          <input v-model="icdCode" maxlength="8" aria-label="MKB-10 kodi"
            placeholder="Masalan: K02.1" :disabled="!writable || loading || saving"/>
        </label>
      </div>
      <p class="dw-diagnosis-help">
        Kasalliklar klassifikatoridan qidirish alohida integratsiyada qo‘shiladi.
        Hozir kodni shifokor o‘zi kiritadi va tekshiradi.
      </p>
      <div class="dw-diagnosis-actions">
        <span v-if="loading" role="status">Tashxis yuklanmoqda...</span>
        <span v-else-if="notice" role="status" class="dw-diagnosis-success">{{ notice }}</span>
        <span v-else-if="!writable" role="status">
          Tashxisni saqlash uchun Shifokor kabineti → «Ko‘rik ma’lumotlarini saqlash» ruxsati kerak.
        </span>
        <span v-else class="dw-diagnosis-hint">Faqat ushbu bemorning joriy qabuliga saqlanadi.</span>
        <button v-if="conflict" type="button" class="e-button dw-small"
          :disabled="loading || saving" @click="refresh">Qayta yuklash</button>
        <button type="submit" class="e-button primary dw-small"
          :disabled="!valid || conflict">
          {{ saving ? "Saqlanmoqda..." : record ? "Tashxisni yangilash" : "Tashxisni saqlash" }}
        </button>
      </div>
      <p v-if="failure" role="alert" class="dw-diagnosis-error">{{ failure }}</p>
    </form>
  </section>
</template>

<style scoped>
.dw-diagnosis-editor { border: 1px solid #dbe7f9; border-radius: 10px; padding: 16px; margin: 12px 0 16px; }
.dw-diagnosis-heading { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; }
.dw-diagnosis-heading h3 { margin: 0; color: #102b58; font-size: 17px; }
.dw-diagnosis-id { color: #5676a3; background: #eff5ff; padding: 5px 9px; border-radius: 6px; font-size: 13px; }
.dw-diagnosis-description, .dw-diagnosis-help { color: #6680a4; font-size: 13px; margin: 8px 0 14px; line-height: 1.55; }
.dw-diagnosis-form { display: grid; gap: 12px; }
.dw-diagnosis-fields { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 14px; }
.dw-diagnosis-form .dw-field { display: flex; flex-direction: column; gap: 7px; color: #234470; }
.dw-diagnosis-form textarea, .dw-diagnosis-form input, .dw-diagnosis-form select {
  font: inherit; width: 100%; box-sizing: border-box; border: 1px solid #d5e2f7;
  border-radius: 8px; background: #fff; color: #102b58; padding: 11px 12px; min-height: 42px;
}
.dw-diagnosis-form textarea { resize: vertical; min-height: 100px; }
.dw-diagnosis-form :disabled { background: #f4f7fc; cursor: not-allowed; }
.dw-optional { color: #7388a4; font-size: 12px; }
.dw-diagnosis-actions { display: flex; align-items: center; justify-content: flex-end; gap: 12px; flex-wrap: wrap; }
.dw-diagnosis-hint { margin-right: auto; color: #7388a4; font-size: 12px; }
.dw-diagnosis-success { margin-right: auto; color: #18805c; font-size: 13px; }
.dw-diagnosis-error { color: #b42318; font-size: 13px; margin: 0; }
@media (max-width: 740px) { .dw-diagnosis-fields { grid-template-columns: 1fr; } }
</style>
