<script setup lang="ts">
import { onMounted, ref } from 'vue'
import LoginView from './views/LoginView.vue'
import ClinicDashboardView from './views/ClinicDashboardView.vue'
import { getCurrentUser, logoutUser } from './services/api'

const authenticated = ref(false)
const checkingSession = ref(true)

function canAccessClinic(user: {
  must_change_password: boolean
  is_platform_admin: boolean
  active_tenant_id: string | null
}) {
  return !user.must_change_password
}

onMounted(async () => {
  try {
    authenticated.value = canAccessClinic(await getCurrentUser())
  } catch {
    authenticated.value = false
  } finally {
    checkingSession.value = false
  }
})

async function handleAuthenticated() {
  try {
    authenticated.value = canAccessClinic(await getCurrentUser())
  } catch {
    authenticated.value = false
  }
}

const loggingOut = ref(false)

async function handleLogout() {
  if (loggingOut.value) return

  loggingOut.value = true

  try {
    await logoutUser()
    authenticated.value = false
    window.history.replaceState({}, '', '/')
  } catch {
    alert('Tizimdan chiqishda xatolik. Qayta urinib ko‘ring.')
  } finally {
    loggingOut.value = false
  }
}

</script>

<template>
  
  <div v-if="checkingSession">Sessiya tekshirilmoqda...</div>
  <ClinicDashboardView v-else-if="authenticated" @logout="handleLogout" />
  <LoginView v-else @authenticated="handleAuthenticated" />

</template>
