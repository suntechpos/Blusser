<script setup lang="ts">
import { onMounted, ref, onUnmounted } from "vue";
import LoginView from "./views/LoginView.vue";
import ClinicDashboardView from "./views/ClinicDashboardView.vue";
import SessionGate from "./components/operations/SessionGate.vue";
import {
  session,
  refreshSession,
  expire,
  clearClinic,
  notifyLogout,
} from "./services/session";
import { logoutUser, errorText } from "./services/api";
async function handleAuthenticated() {
  session.checking = true;
  session.error = "";
  try {
    clearClinic();
    await refreshSession();
  } catch (e) {
    session.error = errorText(e);
  } finally {
    session.checking = false;
  }
}
const loggingOut = ref(false);
function regainFocus() {
  if (session.user && !session.checking && !session.switching)
    void handleAuthenticated();
}
onMounted(() => {
  void handleAuthenticated();
  window.addEventListener("focus", regainFocus);
});
onUnmounted(() => window.removeEventListener("focus", regainFocus));
async function handleLogout() {
  if (loggingOut.value) return;
  loggingOut.value = true;
  try {
    await logoutUser();
    expire();
    notifyLogout();
    window.history.replaceState({}, "", "/");
  } catch (e) {
    session.error = errorText(e);
  } finally {
    loggingOut.value = false;
  }
}
</script>
<template>
  <div v-if="session.checking">Sessiya tekshirilmoqda…</div>
  <LoginView v-else-if="!session.user" @authenticated="handleAuthenticated" />
  <div v-else-if="session.switching">Klinika yuklanmoqda…</div>
  <SessionGate
    v-else-if="
      session.user.must_change_password ||
      session.user.is_platform_admin ||
      !session.user.active_tenant_id
    "
    @logout="handleLogout"
  /><template v-else
    ><p v-if="session.error" role="alert">
      {{ session.error }}
      <button @click="handleAuthenticated">Qayta urinish</button>
    </p>
    <ClinicDashboardView
      :key="session.user.active_tenant_id"
      @logout="handleLogout"
  /></template>
</template>
