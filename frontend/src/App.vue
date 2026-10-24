<script setup lang="ts">
import { onMounted, ref, onUnmounted } from "vue";
import LoginView from "./views/LoginView.vue";
import LandingView from "./views/LandingView.vue";
import ClinicDashboardView from "./views/ClinicDashboardView.vue";
import SessionGate from "./components/operations/SessionGate.vue";
import {
  session,
  refreshSession,
  revalidateSession,
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
import IdentityLinks from "./components/operations/IdentityLinks.vue";
const linksOpen = ref(false);
const loggingOut = ref(false);
const showLogin = ref(window.location.pathname === "/login");
function openLogin() {
  showLogin.value = true;
  window.history.pushState({}, "", "/login");
}
let rechecking = false;
let accessTimer: ReturnType<typeof setInterval>;
async function regainFocus() {
  if (!session.user || session.checking || session.switching || rechecking)
    return;
  rechecking = true;
  try {
    await revalidateSession();
  } catch (e) {
    session.error = errorText(e);
  } finally {
    rechecking = false;
  }
}
onMounted(() => {
  void handleAuthenticated();
  window.addEventListener("focus", regainFocus);
  accessTimer = setInterval(() => {
    if (!document.hidden) void regainFocus();
  }, 15000);
});
onUnmounted(() => {
  clearInterval(accessTimer);
  window.removeEventListener("focus", regainFocus);
});
async function handleLogout() {
  if (loggingOut.value) return;
  loggingOut.value = true;
  try {
    await logoutUser();
    linksOpen.value = false;
    expire();
    notifyLogout();
    showLogin.value = false;
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
  <LandingView v-else-if="!session.user && !showLogin" @login="openLogin" />
  <LoginView v-else-if="!session.user" @authenticated="handleAuthenticated" />
  <div v-else-if="session.switching">Klinika yuklanmoqda…</div>
  <SessionGate
    v-else-if="
      session.user.must_change_password ||
      session.user.is_platform_admin ||
      !session.user.active_tenant_id
    "
    @links="linksOpen = true"
    @logout="handleLogout"
  /><template v-else
    ><p v-if="session.error" role="alert">
      {{ session.error }}
      <button @click="handleAuthenticated">Qayta urinish</button>
    </p>
    <ClinicDashboardView
      :key="session.user.active_tenant_id"
      @links="linksOpen = true"
      @logout="handleLogout" /></template
  ><IdentityLinks
    v-if="
      linksOpen &&
      session.user &&
      !session.user.must_change_password &&
      !session.user.is_platform_admin
    "
    @close="linksOpen = false"
  />
</template>
