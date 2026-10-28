<script setup lang="ts">
import { ref } from "vue";
import { FileText, Printer, UserRound, CalendarDays, Stethoscope, Building2, ClipboardList, Info, Clock3 } from "lucide-vue-next";
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
    <div class="dw-modal-body dw-real-referral">
      <div class="dw-ref-headline">
        <div><FileText :size="26"/><span>Yo‘llanma ma’lumotlari</span></div>
        <div class="dw-ref-tags"><span class="dw-ref-number">№ {{ referral.id.slice(0,8) }}</span>
          <span class="dw-ref-status">{{ referral.status === 'ordered' ? '● Buyurilgan' : referral.status }}</span></div>
      </div>
      <p v-if="printError" role="alert" class="dw-error">{{ printError }}</p>
      <section class="dw-ref-patient">
        <span class="dw-ref-avatar">{{ patientName.split(/\s+/).slice(0,2).map(x=>x[0]).join('').toUpperCase() }}</span>
        <div><small>Bemor</small><h3>{{ patientName }}</h3><p>ID: {{ patientNumber }}</p></div>
      </section>
      <div class="dw-ref-card dw-ref-two">
        <div><UserRound :size="22"/><div><small>Yo‘llanma bergan shifokor</small><strong>{{ doctorName }}</strong></div></div>
        <div><CalendarDays :size="22"/><div><small>Yo‘llanma sanasi</small><strong>{{ date() }}</strong></div></div>
      </div>
      <div class="dw-ref-card dw-ref-three">
        <div><Stethoscope :size="22"/><div><small>Yo‘llanma turi</small><strong>{{ typeLabels[referral.referral_type] || referral.referral_type }}</strong></div></div>
        <div><FileText :size="22"/><div><small>Xizmat</small><strong>{{ referral.service_name }}</strong></div></div>
        <div><Building2 :size="22"/><div><small>Klinika / filial</small><strong>{{ location() }}</strong></div></div>
      </div>
      <section class="dw-ref-note"><div><ClipboardList :size="20"/><strong>Yo‘llanma sababi / izoh</strong></div><p>{{ referral.note || "Izoh kiritilmagan." }}</p></section>
      <div class="dw-ref-info"><Info :size="21"/><span>Bu yo‘llanma mustaqil qabul bronini yoki laboratoriya natijasini yaratmaydi.</span></div>
      <p class="dw-ref-fullid"><Clock3 :size="16"/> Yo‘llanma ID: {{ referral.id }}</p>
    </div>
    <template #footer><footer class="dw-ref-footer">
      <button type="button" class="e-button" @click="printDocument"><Printer :size="17"/> Chop etish</button>
      <button type="button" class="e-button" @click="printDocument" title="Chop etish oynasida PDF sifatida saqlang"><FileText :size="17"/> PDF saqlash</button>
      <button type="button" class="e-button primary" @click="emit('close')">Yopish</button>
    </footer></template>
  </WorkspaceDialog>
</template>
<style scoped>
.dw-real-referral{display:grid;gap:13px;color:#102c54}
.dw-ref-headline,.dw-ref-headline>div,.dw-ref-tags{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.dw-ref-headline{justify-content:space-between}.dw-ref-headline svg{color:#1165ed}
.dw-ref-headline span{font-weight:600}.dw-ref-number{background:#eaf2ff;color:#37629c;padding:8px 12px;border-radius:9px}
.dw-ref-status{background:#e4f8ee;color:#118657;padding:8px 12px;border-radius:9px}
.dw-ref-patient,.dw-ref-card,.dw-ref-note,.dw-ref-info{border:1px solid #d7e5fa;border-radius:12px;padding:17px}
.dw-ref-patient{display:flex;gap:16px;align-items:center;background:#f5f9ff}
.dw-ref-avatar{display:grid;place-items:center;width:65px;height:65px;border-radius:50%;background:#eee7ff;color:#6637e9;font-size:25px;font-weight:700;flex-shrink:0}
.dw-real-referral small{color:#7185a7;display:block;margin-bottom:5px}
.dw-ref-patient h3,.dw-ref-patient p{margin:0}.dw-ref-patient h3{font-size:21px}.dw-ref-patient p{margin-top:5px;color:#6880a3}
.dw-ref-two,.dw-ref-three{display:grid;gap:15px}.dw-ref-two{grid-template-columns:repeat(2,minmax(0,1fr))}.dw-ref-three{grid-template-columns:repeat(3,minmax(0,1fr))}
.dw-ref-card>div{display:flex;gap:12px;min-width:0}.dw-ref-card svg,.dw-ref-note svg{color:#1165ed;flex-shrink:0}
.dw-ref-card strong{display:block;overflow-wrap:anywhere}.dw-ref-note>div{display:flex;align-items:center;gap:9px}
.dw-ref-note p{background:#f2f7ff;border-radius:9px;padding:13px;margin:12px 0 0;white-space:pre-wrap;overflow-wrap:anywhere}
.dw-ref-info{display:flex;gap:10px;align-items:center;background:#f2f7ff;color:#3d5c86;font-size:14px}
.dw-ref-info svg{flex-shrink:0;color:#1165ed}.dw-ref-fullid{color:#7185a7;font-size:12px;display:flex;gap:7px;align-items:center;overflow-wrap:anywhere;margin:0}
.dw-ref-footer{display:flex;justify-content:flex-end;flex-wrap:wrap;gap:10px}
@media(max-width:680px){.dw-ref-two,.dw-ref-three{grid-template-columns:1fr}.dw-ref-footer button{flex:1 1 auto}.dw-ref-patient{padding:12px}}
</style>