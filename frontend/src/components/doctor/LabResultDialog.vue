<script setup lang="ts">
import { defineAsyncComponent, computed, ref } from "vue";
import {
  CheckCircle2,
  Clock3,
  ArrowUp,
  ArrowDown,
  FileText,
  Eye,
  Download,
  Printer,
  LockKeyhole,
  Info,
} from "lucide-vue-next";
import { printBlob } from "./localFiles";
import WorkspaceDialog from "./WorkspaceDialog.vue";
import PatientStrip from "./PatientStrip.vue";
const DocumentViewer = defineAsyncComponent(
  () => import("./DocumentViewer.vue"),
);
import {
  LAB_RESULTS,
  type ResultRow,
  type Encounter,
} from "../../mocks/doctorWorkspace";
import { demoPdf } from "./mockFiles";
const props = defineProps<{ encounter: Encounter; result: ResultRow }>();
const emit = defineEmits<{ close: [] }>();
const detail = computed(() => LAB_RESULTS[props.result.id]);
const viewer = ref(false);
const error = ref("");
async function print() {
  try {
    await printBlob(file.blob);
  } catch (e) {
    error.value = (e as Error).message;
  }
}
const file = {
  name: props.result.name + ".pdf",
  date: props.result.date,
  blob: demoPdf(
    [
      props.encounter.name,
      props.result.name,
      ...(detail.value?.values.map(
        (r) => `${r.name}: ${r.value} ${r.unit} [${r.range || "no range"}]`,
      ) || []),
    ].join(" | "),
  ),
};
function download() {
  const u = URL.createObjectURL(file.blob);
  const a = document.createElement("a");
  a.href = u;
  a.download = file.name;
  a.click();
  setTimeout(() => URL.revokeObjectURL(u), 60000);
}
</script>
<template>
  <WorkspaceDialog
    class="dw-lab-modal"
    title="Laboratoriya natijasi"
    :subtitle="result.name"
    @close="emit('close')"
    ><div class="dw-modal-body">
      <span class="dw-badge green dw-lab-ready"
        ><CheckCircle2 :size="18" />Tayyor</span
      >
      <div class="dw-lab-patient">
        <PatientStrip :encounter="encounter" /><span class="dw-example"
          >Namunaviy ma’lumotlar</span
        >
      </div>
      <p v-if="error" class="dw-error" role="alert">{{ error }}</p>
      <template v-if="detail"
        ><div class="dw-lab-meta">
          <div>
            <small>Buyurtma</small><b>{{ detail.order }}</b>
          </div>
          <div>
            <small>Laboratoriya</small><b>{{ detail.laboratory }}</b>
          </div>
          <div>
            <small>Namuna olingan</small><b>{{ detail.sampled }}</b>
          </div>
          <div>
            <small>Natija tayyor</small><b>{{ detail.ready }}</b>
          </div>
        </div>
        <div class="dw-scroll">
          <table class="dw-lab-table">
            <thead>
              <tr>
                <th>Ko‘rsatkich</th>
                <th>Natija</th>
                <th>Birlik</th>
                <th>Laboratoriya me’yori</th>
                <th>Belgi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in detail.values" :key="r.name">
                <td>
                  <b>{{ r.name }}</b>
                </td>
                <td
                  :class="{
                    'dw-abnormal': r.flag === 'high' || r.flag === 'low',
                  }"
                >
                  {{ r.value }}
                </td>
                <td>{{ r.unit || "—" }}</td>
                <td>{{ r.range || "Berilmagan" }}</td>
                <td>
                  <span
                    class="dw-badge"
                    :class="
                      r.flag === 'normal'
                        ? 'green'
                        : r.flag === 'unknown'
                          ? 'gray'
                          : 'yellow'
                    "
                    ><component
                      :is="
                        r.flag === 'high'
                          ? ArrowUp
                          : r.flag === 'low'
                            ? ArrowDown
                            : r.flag === 'normal'
                              ? CheckCircle2
                              : Clock3
                      "
                      :size="16"
                    />{{
                      r.flag === "normal"
                        ? "Me’yorda"
                        : r.flag === "high"
                          ? "Yuqori"
                          : r.flag === "low"
                            ? "Past"
                            : "Baholanmagan"
                    }}</span
                  >
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="dw-footnote">
          <Info :size="17" />Me’yor oraliqlari laboratoriya tomonidan taqdim
          etilgan.
        </p>
        <label class="dw-field"
          >Laboratoriya izohi
          <p class="dw-readonly">{{ detail.comment }}</p></label
        >
        <div class="dw-result-file">
          <FileText class="dw-pdf-icon" :size="30" /><span
            >{{ file.name
            }}<small>{{ Math.ceil(file.blob.size / 1024) }} KB</small></span
          ><button
            class="e-icon"
            aria-label="Laboratoriya faylini ko‘rish"
            @click="viewer = true"
          >
            <Eye :size="20" /></button
          ><button
            class="e-icon"
            aria-label="Laboratoriya faylini yuklab olish"
            @click="download"
          >
            <Download :size="20" />
          </button>
        </div>
        <p class="dw-result-confirmed">
          <CheckCircle2 :size="20" />Tasdiqlagan: {{ detail.confirmedBy }} ·
          {{ detail.ready }}
        </p></template
      >
      <p v-else>Bu tahlil uchun tuzilgan natija mavjud emas.</p>
    </div>
    <template #footer
      ><footer>
        <span class="dw-footnote"
          ><LockKeyhole :size="18" />Natija faqat ko‘rish uchun</span
        ><button class="e-button" @click="print">
          <Printer :size="19" />Chop etish</button
        ><button class="e-button" @click="download">
          <Download :size="19" />Yuklab olish</button
        ><button class="e-button primary" @click="emit('close')">Yopish</button>
      </footer></template
    ><DocumentViewer
      v-if="viewer"
      :encounter="encounter"
      :file="file"
      @close="viewer = false"
  /></WorkspaceDialog>
</template>
