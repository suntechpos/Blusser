<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount } from "vue";
import { Search, X, Check, UserRoundSearch } from "lucide-vue-next";
import { initials, type ReceptionPatient } from "../../mocks/reception";
const props = defineProps<{
  lookup: (query: string) => Promise<ReceptionPatient[]>;
}>();
const selected = defineModel<ReceptionPatient | null>({ required: true });
const query = ref(""),
  rows = ref<ReceptionPatient[]>([]),
  loading = ref(false),
  error = ref("");
let sequence = 0;
async function search() {
  const token = ++sequence;
  error.value = "";
  loading.value = true;
  try {
    const results = await props.lookup(query.value);
    if (token === sequence) rows.value = results;
  } catch {
    if (token === sequence) {
      rows.value = [];
      error.value = "Qidiruv amalga oshmadi. Qayta urinib ko‘ring.";
    }
  } finally {
    if (token === sequence) loading.value = false;
  }
}
watch(query, () => {
  selected.value = null;
  void search();
});
onMounted(() => void search());
onBeforeUnmount(() => {
  sequence++;
});
function clear() {
  selected.value = null;
  query.value = "";
  void search();
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
            >{{ selected.id }} · {{ selected.dob }} ·
            {{ selected.phone || "Telefon kiritilmagan" }}</small
          >
        </div>
        <Check :size="22" /><span>Tanlangan</span>
      </div>
      <div v-else class="rp-search-results">
        <button
          v-for="p in rows"
          :key="p.id"
          type="button"
          @click="selected = p"
        >
          <span class="rp-avatar">{{ initials(p.name) }}</span
          ><span
            ><strong>{{ p.name }}</strong
            ><small
              >{{ p.id }} · {{ p.dob }} ·
              {{ p.phone || "Telefon kiritilmagan" }}</small
            ></span
          >
        </button>
        <p v-if="!rows.length" class="rp-empty">Bemor topilmadi</p>
      </div>
      <button
        v-if="selected"
        class="rp-link rp-other-patient"
        type="button"
        @click="clear"
      >
        <UserRoundSearch :size="18" /> Boshqa bemorni tanlash</button
      ><slot v-if="!selected && !rows.length" name="empty"
    /></template>
  </section>
</template>
