<script setup lang="ts">
import { computed, ref } from "vue";
import {
  CheckCircle2,
  Info,
  LockKeyhole,
  Printer,
  FileText,
} from "lucide-vue-next";
import WorkspaceDialog from "./WorkspaceDialog.vue";
import PatientStrip from "./PatientStrip.vue";
import {
  DOCTOR,
  SERVICES,
  type Encounter,
  type Referral,
} from "../../mocks/doctorWorkspace";
import { dateLabel } from "../../mocks/employees";
import { demoPdf, printDemo } from "./mockFiles";
const props = defineProps<{
  encounter: Encounter;
  referral: Referral;
  created?: boolean;
  status?: string;
}>();
const emit = defineEmits<{ close: [] }>();
const printError = ref("");
function print() {
  if (!printDemo("Yo‘llanma", lines.value))
    printError.value = "Chop etish oynasiga brauzer ruxsat bermadi.";
}
const service = computed(
  () => SERVICES.find((x) => x.id === props.referral.service)?.name || "—",
);
const lines = computed(() => [
  props.referral.id,
  props.encounter.name,
  service.value,
  props.referral.type,
  props.referral.location === "external"
    ? "Boshqa klinikada"
    : "Bizning klinikada",
  props.referral.clinic || props.referral.branch,
  props.referral.specialist || "Mutaxassis tanlanmagan",
  DOCTOR.name,
  dateLabel(props.encounter.date),
  props.referral.note,
]);
function pdf() {
  const u = URL.createObjectURL(demoPdf(lines.value.join(" | ")));
  const a = document.createElement("a");
  a.href = u;
  a.download = props.referral.id + ".pdf";
  a.click();
  setTimeout(() => URL.revokeObjectURL(u), 60000);
}
</script>
<template>
  <WorkspaceDialog
    class="dw-referral-details-modal"
    title="Yo‘llanmani ko‘rish"
    @close="emit('close')"
    ><div class="dw-modal-body">
      <p v-if="printError" class="dw-error" role="alert">{{ printError }}</p>
      <div v-if="created" class="dw-created">
        <CheckCircle2 :size="23" />Yo‘llanma yaratildi
      </div>
      <h3>
        Yo‘llanma № {{ referral.id }}
        <span
          class="dw-badge"
          :class="referral.location === 'external' ? 'yellow' : 'blue'"
          >{{
            status ||
            (referral.location === "external"
              ? "Natija kutilmoqda"
              : "Buyurilgan")
          }}</span
        >
      </h3>
      <PatientStrip :encounter="encounter" />
      <div class="dw-details-grid">
        <div
          v-for="item in [
            { label: 'Xizmat', value: service },
            { label: 'Yo‘llanma turi', value: referral.type },
            {
              label: 'Qayerda o‘tkaziladi?',
              value:
                referral.location === 'external'
                  ? 'Boshqa klinikada'
                  : 'Bizning klinikada',
            },
            {
              label:
                referral.location === 'external'
                  ? 'Klinika nomi'
                  : 'Filial / mutaxassis',
              value:
                referral.location === 'external'
                  ? referral.clinic || 'Belgilanmagan'
                  : referral.branch +
                    ' / ' +
                    (referral.specialist || 'Tanlanmagan'),
            },
            { label: 'Yo‘llanma bergan shifokor', value: DOCTOR.name },
            {
              label: 'Yaratilgan sana',
              value:
                dateLabel(encounter.date) +
                ' · ' +
                (referral.createdTime || '—'),
            },
          ]"
          :key="item.label"
        >
          <small>{{ item.label }}</small>
          <p>{{ item.value }}</p>
        </div>
      </div>
      <label class="dw-field"
        >Tegishli qabul
        <p>
          {{ dateLabel(encounter.date) }} · {{ encounter.time }} —
          {{ DOCTOR.name }}
        </p></label
      ><label class="dw-field"
        >Yo‘llanma sababi / izoh
        <p class="dw-readonly">
          {{ referral.note || "Izoh kiritilmagan." }}
        </p></label
      >
      <div class="dw-info">
        <Info :size="20" /><span>{{
          referral.location === "external"
            ? "Bemor ushbu yo‘llanma bilan boshqa klinikaga murojaat qilishi mumkin. Tashqi natija keyinchalik shu yo‘llanmaga biriktiriladi."
            : "Ichki yo‘llanma buyurilgan. Bu lokal namoyish; haqiqiy bo‘limga yuborilmagan."
        }}</span>
      </div>
    </div>
    <template #footer
      ><footer>
        <span class="dw-footnote"
          ><LockKeyhole :size="16" />Faqat ko‘rish uchun</span
        ><button class="e-button" @click="print">
          <Printer :size="17" />Chop etish</button
        ><button class="e-button" @click="pdf">
          <FileText :size="17" />PDF olish</button
        ><button class="e-button primary" @click="emit('close')">Yopish</button>
      </footer></template
    ></WorkspaceDialog
  >
</template>
