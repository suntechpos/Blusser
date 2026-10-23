<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount } from "vue";
import { Search, X, Check, UserRoundSearch } from "lucide-vue-next";
import { bookingError } from "../../services/receptionBooking";
import type { PatientResults } from "../../services/receptionBooking";
import { initials, type ReceptionPatient } from "../../mocks/reception";
const props = defineProps<{
  lookup: (
    query: string,
    page?: number,
  ) => Promise<ReceptionPatient[] | PatientResults>;
}>();
const selected = defineModel<ReceptionPatient | null>({ required: true });
const query = ref(""),
  rows = ref<ReceptionPatient[]>([]),
  loading = ref(false),
  error = ref("");
const resultPage = ref(1),
  total = ref(0),
  perPage = ref(8);
let timer: ReturnType<typeof setTimeout>;
let sequence = 0;
const number = (p: ReceptionPatient) =>
  (p as ReceptionPatient & { patientNumber?: string }).patientNumber ?? p.id;
async function search() {
  const token = ++sequence;
  error.value = "";
  loading.value = true;
  try {
    const results = await props.lookup(query.value, resultPage.value);
    if (token === sequence) {
      rows.value = Array.isArray(results) ? results : results.data;
      total.value = Array.isArray(results) ? results.length : results.total;
      perPage.value = Array.isArray(results)
        ? Math.max(results.length, 1)
        : results.perPage;
    }
  } catch (e) {
    if (token === sequence) {
      rows.value = [];
      error.value = bookingError(e);
    }
  } finally {
    if (token === sequence) loading.value = false;
  }
}
watch(query, () => {
  selected.value = null;
  resultPage.value = 1;
  sequence++;
  rows.value = [];
  loading.value = true;
  clearTimeout(timer);
  timer = setTimeout(() => void search(), 300);
});
onMounted(() => void search());
onBeforeUnmount(() => {
  sequence++;
  clearTimeout(timer);
});
function clear() {
  selected.value = null;
  resultPage.value = 1;
  if (query.value) query.value = "";
  else void search();
}
</script>
<template>
  <section class="rp-patient-search">
    <h3>Bemorni qidirish</h3>
    <div class="rp-search">
      <Search :size="19" /><input
        v-model="query"
        aria-label="Bemorni qidirish"
        placeholder="F.I.Sh., telefon yoki bemor ID"
      /><button
        v-if="query"
        type="button"
        class="rp-icon"
        aria-label="Qidiruvni tozalash"
        @click="clear"
      >
        <X :size="18" />
      </button>
    </div>
    <p class="rp-hint">F.I.Sh., telefon raqami yoki bemor ID orqali qidiring</p>
    <p v-if="loading" role="status" class="rp-empty">Qidirilmoqda…</p>
    <div v-else-if="error" role="alert" class="rp-search-error">
      {{ error }}
      <button class="rp-button" type="button" @click="search">
        Qayta urinish
      </button>
    </div>
    <template v-else
      ><div v-if="selected" class="rp-selected-patient">
        <span class="rp-avatar">{{ initials(selected.name) }}</span>
        <div>
          <strong>{{ selected.name }}</strong
          ><small
            >{{ number(selected) }} · {{ selected.dob }} ·
            {{ selected.phone || "Telefon kiritilmagan" }}</small
          >
        </div>
        <Check :size="22" /><span>Tanlangan</span>
      </div>
      <div v-else class="rp-search-results">
        <button
          data-permission="patients.view"
          v-for="p in rows"
          :key="p.id"
          type="button"
          @click="selected = p"
        >
          <span class="rp-avatar">{{ initials(p.name) }}</span
          ><span
            ><strong>{{ p.name }}</strong
            ><small
              >{{ number(p) }} · {{ p.dob }} ·
              {{ p.phone || "Telefon kiritilmagan" }}</small
            ></span
          >
        </button>
        <p v-if="!rows.length" class="rp-empty">Bemor topilmadi</p>
      </div>
      <div v-if="!selected && total > perPage" class="rp-pagination">
        <button
          type="button"
          class="rp-button"
          :disabled="resultPage === 1 || loading"
          @click="
            resultPage--;
            search();
          "
        >
          Oldingi bemorlar
        </button>
        <span>{{ resultPage }} / {{ Math.ceil(total / perPage) }}</span>
        <button
          type="button"
          class="rp-button"
          :disabled="resultPage * perPage >= total || loading"
          @click="
            resultPage++;
            search();
          "
        >
          Keyingi bemorlar
        </button>
      </div>
      <button
        v-if="selected"
        data-permission="patients.view"
        class="rp-link rp-other-patient"
        type="button"
        @click="clear"
      >
        <UserRoundSearch :size="18" /> Boshqa bemorni tanlash</button
      ><slot v-if="!selected && !rows.length" name="empty"
    /></template>
  </section>
</template>
