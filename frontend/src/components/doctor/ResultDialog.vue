<script setup lang="ts">
import {
  CalendarDays,
  UsersRound,
  Building2,
  FileText,
  Image,
  CheckCircle2,
  Eye,
  Download,
  Printer,
  LockKeyhole,
} from "lucide-vue-next";
import EmployeeDialog from "../employees/EmployeeDialog.vue";
import { dateLabel } from "../../mocks/employees";
import {
  UZI_DETAILS,
  type ResultRow,
  type Encounter,
  DOCTOR,
} from "../../mocks/doctorWorkspace";
import { fileAction, printDemo } from "./mockFiles";
const props = defineProps<{ encounter: Encounter; result: ResultRow }>();
const emit = defineEmits<{ close: []; notice: [string] }>();
function action(name: string, format: string, kind: "view" | "download") {
  void fileAction(name, format, kind).catch(() =>
    emit("notice", "Demo faylni yaratib bo‘lmadi."),
  );
}
function print() {
  if (
    !printDemo(UZI_DETAILS.name, [
      props.encounter.name,
      dateLabel(props.result.date),
      UZI_DETAILS.description,
      UZI_DETAILS.conclusion,
      "Faqat namunaviy ma’lumotlar.",
    ])
  )
    emit("notice", "Chop etish oynasiga brauzer ruxsat bermadi.");
}
</script>
<template>
  <EmployeeDialog
    class="dw-result-modal"
    title="Tekshiruv natijasi"
    :subtitle="result.name"
    @close="emit('close')"
    ><span class="dw-result-status dw-badge green"
      ><CheckCircle2 :size="18" />Tayyor</span
    >
    <div class="dw-result-patient dw-patient-line">
      <span class="dw-avatar purple">{{ encounter.initials }}</span>
      <div>
        <h3>{{ encounter.name }}</h3>
        <p>
          {{ encounter.patientId }} · {{ encounter.gender }} ·
          {{ encounter.age }} yosh
        </p>
      </div>
    </div>
    <div class="dw-result-metadata">
      <div
        v-for="item in [
          {
            icon: CalendarDays,
            label: 'Tekshiruv sanasi',
            value: dateLabel(result.date) + ' · ' + UZI_DETAILS.time,
          },
          {
            icon: UsersRound,
            label: 'Mutaxassis',
            value: UZI_DETAILS.specialist,
          },
          { icon: Building2, label: 'Filial', value: UZI_DETAILS.branch },
          {
            icon: FileText,
            label: 'Yo‘llanma',
            value: UZI_DETAILS.referral + ' · ' + DOCTOR.name,
          },
        ]"
        :key="item.label"
      >
        <component :is="item.icon" :size="22" /><span
          ><small>{{ item.label }}</small
          ><b>{{ item.value }}</b></span
        >
      </div>
    </div>
    <section class="dw-result-field">
      <h3>Tekshiruv tavsifi</h3>
      <p>{{ UZI_DETAILS.description }}</p>
    </section>
    <section class="dw-result-field">
      <header>
        <h3>Xulosa</h3>
        <span class="dw-example">Namunaviy ma’lumotlar</span>
      </header>
      <p>{{ UZI_DETAILS.conclusion }}</p>
    </section>
    <section class="dw-result-files">
      <h3>Biriktirilgan fayllar</h3>
      <div
        v-for="file in UZI_DETAILS.attachments"
        :key="file.name"
        class="dw-result-file"
      >
        <component
          :is="file.format === 'PDF' ? FileText : Image"
          :size="30"
          :class="file.format === 'PDF' ? 'dw-pdf-icon' : 'dw-image-icon'"
        /><span
          >{{ file.name }}<small>{{ file.size }}</small></span
        ><button
          class="e-icon"
          :aria-label="file.name + ' ko‘rish'"
          @click="action(file.name, file.format, 'view')"
        >
          <Eye :size="19" /></button
        ><button
          class="e-icon"
          :aria-label="file.name + ' yuklab olish'"
          @click="action(file.name, file.format, 'download')"
        >
          <Download :size="19" />
        </button>
      </div>
    </section>
    <p class="dw-result-confirmed">
      <CheckCircle2 :size="23" /><span
        >Tasdiqlagan: {{ UZI_DETAILS.specialist }} ·
        {{ dateLabel(result.date) }}, {{ UZI_DETAILS.confirmedTime }}</span
      >
    </p>
    <footer>
      <span class="dw-footnote"
        ><LockKeyhole :size="20" />Natija faqat ko‘rish uchun</span
      ><button class="e-button" @click="print">
        <Printer :size="19" />Chop etish</button
      ><button
        class="e-button"
        @click="action(UZI_DETAILS.name, 'PDF', 'download')"
      >
        <Download :size="19" />Yuklab olish</button
      ><button class="e-button primary" @click="emit('close')">Yopish</button>
    </footer></EmployeeDialog
  >
</template>
