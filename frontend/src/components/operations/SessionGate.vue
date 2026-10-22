<script setup lang="ts">
import { ref } from "vue";
import { session, refreshSession, selectClinic } from "../../services/session";
import { api, errorText } from "../../services/api";
defineEmits<{ logout: []; links: [] }>();
const current = ref(""),
  password = ref(""),
  confirmation = ref(""),
  busy = ref(false),
  error = ref("");
async function change() {
  busy.value = true;
  error.value = "";
  try {
    await api.post("/api/v1/auth/password", {
      current_password: current.value,
      password: password.value,
      password_confirmation: confirmation.value,
    });
    await refreshSession();
  } catch (e) {
    error.value = errorText(e);
  } finally {
    current.value = "";
    password.value = "";
    confirmation.value = "";
    busy.value = false;
  }
}
</script>
<template>
  <main class="session-gate">
    <h1>Blusser</h1>
    <p v-if="error || session.error" role="alert">
      {{ error || session.error }}
    </p>
    <form v-if="session.user?.must_change_password" @submit.prevent="change">
      <h2>Vaqtinchalik parolni almashtiring</h2>
      <label
        >Joriy parol<input
          v-model="current"
          type="password"
          autocomplete="current-password"
          required /></label
      ><label
        >Yangi parol<input
          v-model="password"
          type="password"
          minlength="12"
          maxlength="128"
          autocomplete="new-password"
          required /></label
      ><label
        >Yangi parolni tasdiqlang<input
          v-model="confirmation"
          type="password"
          autocomplete="new-password"
          required /></label
      ><button :disabled="busy">Parolni almashtirish</button>
    </form>
    <template v-else-if="session.user?.is_platform_admin"
      ><h2>Platform administratori</h2>
      <p>
        Klinik ma’lumotlarga avtomatik ruxsat berilmaydi. Platform boshqaruv
        interfeysi bu bosqichga kirmaydi.
      </p></template
    ><template v-else
      ><h2>Klinikani tanlang</h2>
      <p v-if="!session.clinics.length">Faol klinika a’zoligi yo‘q.</p>
      <button
        v-for="c in session.clinics"
        :key="c.id"
        :disabled="session.switching"
        @click="selectClinic(c.id).catch((e) => (error = errorText(e)))"
      >
        {{ c.name }} · {{ c.role }}
      </button></template
    ><button
      v-if="
        !session.user?.must_change_password && !session.user?.is_platform_admin
      "
      @click="$emit('links')"
    >
      Klinikaga bog‘lash so‘rovlari</button
    ><button @click="$emit('logout')">Tizimdan chiqish</button>
  </main>
</template>
<style scoped>
.session-gate {
  max-width: 550px;
  margin: 8vh auto;
  padding: 30px;
  color: #102045;
  background: #f7faff;
  border: 1px solid #e1e9f7;
  border-radius: 12px;
}
.session-gate form,
.session-gate label {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.session-gate button,
.session-gate input {
  padding: 12px;
  border: 1px solid #dce6f5;
  border-radius: 8px;
  margin: 8px 0;
}
.session-gate button {
  background: #176dff;
  color: white;
  cursor: pointer;
}
</style>
