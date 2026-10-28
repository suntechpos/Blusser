<script setup lang="ts">
import { CheckCircle2, Info, UserRound } from "lucide-vue-next";
import WorkspaceDialog from "./WorkspaceDialog.vue";
defineProps<{
  patientName: string;
  patientNumber: string;
  doctorName: string;
  busy: boolean;
  error: string;
}>();
const emit = defineEmits<{ close: []; finish: [] }>();
</script>
<template>
  <WorkspaceDialog class="dw-finish-modal"
    title="Qabulni yakunlash" subtitle="Yakunlashdan oldin ma’lumotlarni tekshiring."
    @close="!busy && emit('close')">
    <div class="dw-modal-body dw-finish-content">
      <div class="dw-finish-patient"><UserRound :size="26"/>
        <div><strong>{{ patientName }}</strong><small>{{ patientNumber }} · Shifokor: {{ doctorName }}</small></div>
      </div>
      <div class="dw-finish-notice"><Info :size="20"/>
        <span>Qabul yakunlangach navbat va tashrif «Yakunlangan» holatiga o‘tadi. Saqlangan klinik yozuvlar o‘zgartirilmaydi.</span>
      </div>
      <p>Saqlanmagan tibbiy yozuvlaringiz bo‘lsa, avval ularni alohida saqlang. Bu amal yozuvlarni avtomatik saqlamaydi.</p>
      <p v-if="error" class="dw-finish-error" role="alert">{{ error }}</p>
    </div>
    <template #footer><footer class="dw-finish-actions">
      <button type="button" class="e-button" :disabled="busy" @click="emit('close')">Ortga qaytish</button>
      <button type="button" class="e-button primary" :disabled="busy" @click="emit('finish')">
        <CheckCircle2 :size="18"/>{{ busy ? "Yakunlanmoqda..." : "Qabulni yakunlash" }}
      </button>
    </footer></template>
  </WorkspaceDialog>
</template>
<style scoped>
.dw-finish-content{display:grid;gap:15px}
.dw-finish-patient{display:flex;align-items:center;gap:14px;padding:17px;border:1px solid #d6e4fa;border-radius:10px;background:#f2f7ff}
.dw-finish-patient svg{color:#176aff}
.dw-finish-patient strong,.dw-finish-patient small{display:block}
.dw-finish-patient small{margin-top:5px;color:#6c83a7}
.dw-finish-notice{display:flex;gap:12px;padding:15px;border-radius:10px;background:#eaf4ff;color:#345c89}
.dw-finish-notice svg{flex-shrink:0;color:#146cf5}
.dw-finish-content>p{font-size:13px;color:#617b9d}
.dw-finish-content .dw-finish-error{color:#b42318}
.dw-finish-actions{display:flex;justify-content:flex-end;flex-wrap:wrap;gap:10px}
</style>