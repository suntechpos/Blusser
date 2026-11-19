<script setup lang="ts">
import { computed } from "vue";
import { Printer, X } from "lucide-vue-next";
import ReceptionDialog from "./ReceptionDialog.vue";

type PrintableTicket = {
  number: number;
  patientName: string;
  doctorName: string;
  serviceName: string;
  roomName?: string;
  date: string;
  time: string;
};
const props = defineProps<{
  clinicName: string;
  branchName: string;
  tickets: PrintableTicket[];
}>();
const emit = defineEmits<{ close: [] }>();
const heading = computed(() => props.tickets.length > 1 ? "Navbat talonlari" : "Navbat taloni");

function escapeHtml(value: string): string {
  return value.replace(/[&<>"']/g, char => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
  })[char]!);
}

function printTickets() {
  const popup = window.open("", "_blank", "width=420,height=720");
  if (!popup) {
    window.alert("Chop etish oynasi ochilmadi. Brauzerda popup oynalarga ruxsat bering.");
    return;
  }
  const cards = props.tickets.map(ticket => `
    <article class="ticket">
      <div class="brand">${escapeHtml(props.clinicName)}</div>
      ${props.branchName ? `<div class="branch">${escapeHtml(props.branchName)}</div>` : ""}
      <div class="divider"></div>
      <div class="label">SIZNING NAVBATINGIZ</div>
      <div class="number">${ticket.number}</div>
      <div class="patient">${escapeHtml(ticket.patientName)}</div>
      <div class="divider"></div>
      <div class="row"><span>Shifokor</span><strong>${escapeHtml(ticket.doctorName)}</strong></div>
      <div class="row"><span>Xizmat</span><strong>${escapeHtml(ticket.serviceName)}</strong></div>
      ${ticket.roomName ? `<div class="row"><span>Kabinet</span><strong>${escapeHtml(ticket.roomName)}</strong></div>` : ""}
      <div class="row"><span>Sana</span><strong>${escapeHtml(ticket.date)}</strong></div>
      <div class="row"><span>Vaqt</span><strong>${escapeHtml(ticket.time)}</strong></div>
      <div class="divider"></div>
      <div class="thanks">Sog‘lig‘ingiz biz uchun muhim!</div>
      <div class="powered">Blusser Medical Software</div>
    </article>`).join("");
  popup.document.write(`<!doctype html>
<html lang="uz"><head><meta charset="utf-8"><title>Navbat taloni</title>
<style>
@page{size:80mm auto;margin:4mm}
*{box-sizing:border-box}
body{margin:0;font-family:Arial,sans-serif;color:#111;width:72mm}
.ticket{width:100%;padding:3mm 1mm 5mm;break-after:page}
.ticket:last-child{break-after:auto}
.brand{text-align:center;font-size:17px;font-weight:800}
.branch{text-align:center;font-size:11px;margin-top:3px}
.divider{border-top:1px dashed #555;margin:12px 0}
.label{text-align:center;font-size:10px;font-weight:700;letter-spacing:1px}
.number{text-align:center;font-size:58px;font-weight:900;line-height:1.15;margin:6px 0}
.patient{text-align:center;font-size:15px;font-weight:700;overflow-wrap:anywhere}
.row{display:flex;justify-content:space-between;gap:8px;font-size:11px;margin:6px 0}
.row span{color:#555}.row strong{text-align:right;max-width:60%;overflow-wrap:anywhere}
.thanks{text-align:center;font-size:11px;font-weight:700}
.powered{text-align:center;font-size:9px;color:#555;margin-top:5px}
@media screen{body{margin:12px auto}.ticket{border:1px dashed #bbb;margin-bottom:12px}}
</style></head><body>${cards}<script>window.onload=()=>{window.focus();window.print()}<\/script></body></html>`);
  popup.document.close();
  popup.focus();
}
</script>

<template>
  <ReceptionDialog :title="heading" subtitle="Bemor uchun navbat taloni" patient @close="emit('close')">
    <div class="rp-dialog-body rp-ticket-preview">
      <article v-for="ticket in tickets" :key="ticket.number" class="rp-ticket-card">
        <div class="rp-ticket-brand">{{ clinicName }}</div>
        <p v-if="branchName" class="rp-ticket-branch">{{ branchName }}</p>
        <div class="rp-ticket-divider"></div>
        <p class="rp-ticket-label">SIZNING NAVBATINGIZ</p>
        <strong class="rp-ticket-number">{{ ticket.number }}</strong>
        <strong class="rp-ticket-patient">{{ ticket.patientName }}</strong>
        <div class="rp-ticket-divider"></div>
        <div class="rp-ticket-row"><span>Shifokor</span><strong>{{ ticket.doctorName }}</strong></div>
        <div class="rp-ticket-row"><span>Xizmat</span><strong>{{ ticket.serviceName }}</strong></div>
        <div v-if="ticket.roomName" class="rp-ticket-row"><span>Kabinet</span><strong>{{ ticket.roomName }}</strong></div>
        <div class="rp-ticket-row"><span>Sana</span><strong>{{ ticket.date }}</strong></div>
        <div class="rp-ticket-row"><span>Vaqt</span><strong>{{ ticket.time }}</strong></div>
        <div class="rp-ticket-divider"></div>
        <p class="rp-ticket-thanks">Sog‘lig‘ingiz biz uchun muhim!</p>
        <p class="rp-ticket-powered">Powered by <b>Blusser</b></p>
      </article>
    </div>
    <template #footer>
      <button class="rp-button" type="button" @click="emit('close')"><X :size="17" /> Yopish</button>
      <button class="rp-button rp-primary" type="button" @click="printTickets"><Printer :size="17" /> Chop etish</button>
    </template>
  </ReceptionDialog>
</template>

<style scoped>
.rp-ticket-preview{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;max-height:min(62vh,620px);overflow:auto;padding-top:16px}
.rp-ticket-card{width:min(100%,320px);margin:0 auto;padding:20px 18px;border:1px dashed #9aabc4;border-radius:8px;background:#fff;color:#15294b;text-align:center}
.rp-ticket-brand{font-size:18px;font-weight:800}
.rp-ticket-branch{font-size:12px;color:#607da7;margin:4px 0 0}
.rp-ticket-divider{border-top:1px dashed #b8c5d8;margin:14px 0}
.rp-ticket-label{font-size:10px;letter-spacing:1.2px;color:#607da7;font-weight:700;margin:0}
.rp-ticket-number{display:block;font-size:58px;line-height:1.1;color:#0866ff;font-weight:900;font-variant-numeric:tabular-nums}
.rp-ticket-patient{display:block;font-size:15px;overflow-wrap:anywhere;margin-top:5px}
.rp-ticket-row{display:flex;justify-content:space-between;gap:12px;text-align:left;font-size:12px;margin:8px 0}
.rp-ticket-row span{color:#607da7}.rp-ticket-row strong{text-align:right;overflow-wrap:anywhere}
.rp-ticket-thanks{font-size:12px;font-weight:700;margin:0}
.rp-ticket-powered{font-size:10px;color:#607da7;margin:5px 0 0}
.rp-dialog-footer .rp-button{display:inline-flex;align-items:center;gap:7px}
</style>
