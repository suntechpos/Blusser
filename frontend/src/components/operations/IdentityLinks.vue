<script setup lang="ts">
import { ref, onMounted } from "vue";
import { api, errorText } from "../../services/api";
import { refreshSession } from "../../services/session";
import SettingsDialog from "../settings/SettingsDialog.vue";
import "../settings/settings.css";
const emit = defineEmits<{ close: [] }>();
const links = ref<
    { id: string; clinic: string; role: string; purpose: string }[]
  >([]),
  error = ref(""),
  password = ref(""),
  busy = ref(false),
  loading = ref(true);
async function load() {
  loading.value = true;
  try {
    links.value = (await api.get("/api/v1/auth/links")).data.data;
    error.value = "";
  } catch (e) {
    error.value = errorText(e);
  } finally {
    loading.value = false;
  }
}
async function resolve(id: string, accept: boolean) {
  if (busy.value) return;
  busy.value = true;
  try {
    await api.post(
      `/api/v1/auth/links/${id}/${accept ? "accept" : "reject"}`,
      accept ? { current_password: password.value } : {},
    );
    await load();
    await refreshSession();
  } catch (e) {
    error.value = errorText(e);
  } finally {
    password.value = "";
    busy.value = false;
  }
}
onMounted(load);
</script>
<template>
  <SettingsDialog title="Klinikaga bog‘lash so‘rovlari" @close="emit('close')"
    ><p v-if="loading" role="status">Yuklanmoqda…</p>
    <p v-else-if="!links.length">Kutilayotgan so‘rov yo‘q.</p>
    <div v-for="link in links" :key="link.id">
      <h3>{{ link.clinic }} — {{ link.role }}</h3>
      <p>
        Bu hisobga yangi klinika a’zoligi faqat sizning roziligingiz bilan
        biriktiriladi.
      </p>
      <label
        >Joriy parolingiz<input
          v-model="password"
          type="password"
          autocomplete="current-password"
          :disabled="busy"
      /></label>
      <footer>
        <button
          class="sr-button"
          :disabled="busy"
          @click="resolve(link.id, false)"
        >
          Rad etish</button
        ><button
          class="sr-button primary"
          :disabled="busy || !password"
          @click="resolve(link.id, true)"
        >
          Bog‘lashni tasdiqlash
        </button>
      </footer>
    </div>
    <p v-if="error" role="alert">{{ error }}</p>
    <button v-if="error" class="sr-button" @click="load">
      Qayta urinish
    </button></SettingsDialog
  >
</template>
