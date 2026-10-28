<script setup lang="ts">
import { computed, ref, watch } from "vue";
import axios from "axios";
import { can } from "../../services/session";
import { errorText } from "../../services/api";
import { getVisitRecommendation, saveVisitRecommendation, type VisitRecommendation } from "../../services/visitRecommendations";

const props = defineProps<{ visitId: string; active: boolean }>();
const value = ref("");
const record = ref<VisitRecommendation | null>(null);
const loading = ref(true);
const loaded = ref(false);
const saving = ref(false);
const conflict = ref(false);
const notice = ref("");
const failure = ref("");
let sequence = 0;
const writable = computed(() => props.active && can("workspace.consult") && can("workspace.view") && can("visits.view"));
const changed = computed(() => (record.value?.recommendation_text ?? "") !== value.value.trim());
const valid = computed(() => writable.value && loaded.value && !loading.value && !saving.value && !conflict.value && changed.value && value.value.trim().length <= 10000);

async function refresh() {
  const id = props.visitId;
  const request = ++sequence;
  loading.value = true; loaded.value = false; failure.value = ""; notice.value = ""; conflict.value = false;
  record.value = null; value.value = "";
  try {
    const next = await getVisitRecommendation(id);
    if (request !== sequence || id !== props.visitId) return;
    record.value = next;
    value.value = next?.recommendation_text ?? "";
    loaded.value = true;
  } catch (cause) {
    if (request === sequence && id === props.visitId) failure.value = errorText(cause);
  } finally {
    if (request === sequence) loading.value = false;
  }
}
watch(() => props.visitId, () => void refresh(), { immediate: true });

async function save() {
  if (!valid.value) return;
  const id = props.visitId;
  const request = sequence;
  saving.value = true; failure.value = ""; notice.value = "";
  try {
    const next = await saveVisitRecommendation(id, record.value?.version ?? 0, value.value.trim());
    if (request !== sequence || id !== props.visitId) return;
    record.value = next;
    value.value = next.recommendation_text;
    notice.value = "Tavsiyalar serverga saqlandi.";
  } catch (cause) {
    if (request !== sequence || id !== props.visitId) return;
    conflict.value = axios.isAxiosError(cause) && cause.response?.status === 409;
    failure.value = conflict.value
      ? "Tavsiyalar boshqa oynada o‘zgartirilgan. Matningizni nusxalab oling, keyin qayta yuklang."
      : errorText(cause);
  } finally {
    if (request === sequence) saving.value = false;
  }
}
</script>

<template>
  <section class="dw-recommendations" aria-label="Tavsiyalar bo‘limi">
    <form @submit.prevent="save">
      <label for="visit-recommendation-text">Tavsiyalar</label>
      <p class="dw-recommendation-hint">Ovqatlanish, gigiyena, davolanish va boshqa ko‘rsatmalarni yozing.</p>
      <textarea id="visit-recommendation-text" v-model="value" rows="5" maxlength="10000"
        aria-label="Tavsiyalar" placeholder="Bemor uchun tavsiyalarni kiriting..."
        :disabled="!writable || !loaded || loading || saving" />
      <div class="dw-recommendation-actions">
        <span v-if="loading" role="status">Tavsiyalar yuklanmoqda...</span>
        <span v-else-if="notice" class="dw-recommendation-success" role="status">{{ notice }}</span>
        <span v-else-if="!writable" class="dw-recommendation-hint">Tavsiyalarni tahrirlash uchun faol qabul va ruxsat kerak.</span>
        <span v-else-if="record" class="dw-recommendation-hint">Versiya: {{ record.version }}</span>
        <button v-if="conflict || (!loaded && !loading)" type="button" class="e-button dw-small" :disabled="loading || saving" @click="refresh">Qayta yuklash</button>
        <button type="submit" class="e-button primary dw-small" :disabled="!valid">{{ saving ? "Saqlanmoqda..." : "Tavsiyalarni saqlash" }}</button>
      </div>
      <p v-if="failure" role="alert" class="dw-recommendation-error">{{ failure }}</p>
    </form>
  </section>
</template>

<style scoped>
.dw-recommendations { margin: 20px 0; }
.dw-recommendations form { display: grid; gap: 9px; }
.dw-recommendations label { font-weight: 600; color: #173864; }
.dw-recommendation-hint { margin: 0; font-size: 13px; color: #6981a2; }
.dw-recommendations textarea { box-sizing: border-box; width: 100%; min-height: 144px; resize: vertical; padding: 13px; font: inherit; color: #112f5a; border: 1px solid #cdddf5; border-radius: 9px; background: white; }
.dw-recommendations textarea:disabled { background: #f4f7fc; }
.dw-recommendation-actions { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 12px; }
.dw-recommendation-actions span { margin-right: auto; }
.dw-recommendation-success { font-size: 13px; color: #18805c; }
.dw-recommendation-error { color: #b42318; font-size: 13px; margin: 0; }
</style>
