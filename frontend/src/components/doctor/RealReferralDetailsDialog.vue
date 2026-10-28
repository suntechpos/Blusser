<script setup lang="ts">
import { ref } from "vue";
import { FileText, Printer } from "lucide-vue-next";
import WorkspaceDialog from "./WorkspaceDialog.vue";
import type { VisitReferral } from "../../services/visitReferrals";

const props = defineProps<{
  referral: VisitReferral;
  patientName: string;
  patientNumber: string;
  doctorName: string;
}>();
const emit = defineEmits<{ close: [] }>();
const printError = ref("");
const typeLabels: Record<string,string> = {
  laboratory: "Laboratoriya", diagnostic: "Diagnostika/UZI", consultation: "Mutaxassis konsultatsiyasi",
};
const location = () => props.referral.location === "internal"
  ? (props.referral.target_branch_name || "Ichki yo‘llanma")
  : (props.referral.external_clinic || "Boshqa klinika");
const date = () => {
  const time = Date.parse(props.referral.created_at);
  return Number.isNaN(time) ? "—" : new Intl.DateTimeFormat("uz-UZ", { dateStyle: "medium" }).format(time);
};
function escapeHtml(value: unknown) {
  return String(value ?? "").replace(/[&<>"']/g, c =>
    ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c] || c);
}
function printDocument() {
  printError.value = "";
  const entries = [
    ["Yo‘llanma raqami", props.referral.id],
    ["Bemor", props.patientName],
    ["Bemor ID", props.patientNumber],
    ["Shifokor", props.doctorName],
    ["Xizmat", props.referral.service_name],
    ["Yo‘llanma turi", typeLabels[props.referral.referral_type] || props.referral.referral_type],
    ["Klinika / filial", location()],
    ["Sana", date()],
    ["Holati", props.referral.status === "ordered" ? "Buyurilgan" : props.referral.status],
    ["Sabab / izoh", props.referral.note || "—"],
  ];
  const popup = window.open("", "_blank");
  if (!popup) { printError.value = "Brauzer chop etish oynasini blokladi."; return; }
  popup.document.write(`<!doctype html><html lang="uz"><head><meta charset="UTF-8"><title>Yo‘llanma</title>
  <style>body{font:15px Arial,sans-serif;max-width:760px;margin:45px auto;color:#15294b}
  h1{margin-bottom:32px}dl{display:grid;grid-template-columns:190px 1fr;gap:14px}
  dt{color:#61728c}dd{margin:0;overflow-wrap:anywhere}
  @media print{body{margin:20mm auto}}</style></head><body><h1>Blusser — Yo‘llanma</h1>
  <dl>${entries.map(([label,value]) => `<dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd>`).join("")}</dl>
  </body></html>`);
  popup.document.close();
  popup.focus();
  popup.print();
}
</script>
<template>
  <WorkspaceDialog title="Yo‘llanmani ko‘rish" subtitle="Haqiqiy saqlangan yo‘llanma"
    class="dw-referral-details-modal" @close="emit('close')">
    <div class="dw-modal-body">
      <p v-if="printError" role="alert" class="dw-error">{{ printError }}</p>
      <h3>Yo‘llanma № {{ referral.id }}</h3>
      <div class="dw-details-grid">
        <div v-for="item in [
          {name:'Bemor',value:patientName},
          {name:'Bemor ID',value:patientNumber},
          {name:'Yo‘llanma bergan shifokor',value:doctorName},
          {name:'Xizmat',value:referral.service_name},
          {name:'Turi',value:typeLabels[referral.referral_type] || referral.referral_type},
          {name:'Klinika / filial',value:location()},
          {name:'Yaratilgan sana',value:date()},
          {name:'Holati',value:referral.status === 'ordered' ? 'Buyurilgan' : referral.status},
        ]" :key="item.name">
          <small>{{ item.name }}</small><p>{{ item.value }}</p>
        </div>
      </div>
      <label class="dw-field">Yo‘llanma sababi / izoh
        <p class="dw-readonly">{{ referral.note || "Izoh kiritilmagan." }}</p>
      </label>
      <p class="dw-footnote">Bu yo‘llanma mustaqil qabul bronini yoki laboratoriya natijasini yaratmaydi.</p>
    </div>
    <template #footer>
      <footer>
        <button type="button" class="e-button" @click="printDocument"><Printer :size="17"/> Chop etish</button>
        <button type="button" class="e-button" @click="printDocument" title="Chop etish oynasida PDF sifatida saqlang">
          <FileText :size="17"/> PDF saqlash
        </button>
        <button type="button" class="e-button primary" @click="emit('close')">Yopish</button>
      </footer>
    </template>
  </WorkspaceDialog>
</template>
