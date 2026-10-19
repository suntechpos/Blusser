<script setup lang="ts">
import {
  ref,
  onMounted,
  onBeforeUnmount,
  watch,
  computed,
  nextTick,
} from "vue";
import {
  getDocument,
  GlobalWorkerOptions,
  type PDFDocumentProxy,
  type RenderTask,
} from "pdfjs-dist";
import workerUrl from "pdfjs-dist/build/pdf.worker.min.mjs?url";
import {
  ChevronLeft,
  ChevronRight,
  Minus,
  Plus,
  Maximize,
  Download,
  Printer,
  LockKeyhole,
  FileText,
} from "lucide-vue-next";
import { printBlob } from "./localFiles";
import WorkspaceDialog from "./WorkspaceDialog.vue";
import { type Encounter } from "../../mocks/doctorWorkspace";
import { dateLabel } from "../../mocks/employees";
GlobalWorkerOptions.workerSrc = workerUrl;
const props = defineProps<{
  encounter: Encounter;
  file: { name: string; blob?: Blob; date: string; external?: boolean };
}>();
const emit = defineEmits<{ close: [] }>();
const canvas = ref<HTMLCanvasElement>();
const viewport = ref<HTMLElement>();
const page = ref(1),
  count = ref(0),
  scale = ref(1),
  error = ref(""),
  loading = ref(true);
const isPdf = computed(() => props.file.blob?.type === "application/pdf");
let pdf: PDFDocumentProxy | undefined;
let task: ReturnType<typeof getDocument> | undefined;
let renderTask: RenderTask | undefined;
let url = "";
const imageUrl = ref("");
let disposed = false;
async function render() {
  if (!pdf || !canvas.value) return;
  renderTask?.cancel();
  try {
    const p = await pdf.getPage(page.value);
    if (disposed) return;
    const v = p.getViewport({ scale: scale.value });
    canvas.value.width = v.width;
    canvas.value.height = v.height;
    renderTask = p.render({ canvas: canvas.value, viewport: v });
    await renderTask.promise;
  } catch (e) {
    if ((e as Error).name !== "RenderingCancelledException")
      error.value = "PDF sahifasini ochib bo‘lmadi.";
  }
}
onMounted(async () => {
  try {
    if (!props.file.blob) throw Error("missing");
    url = URL.createObjectURL(props.file.blob);
    if (isPdf.value) {
      task = getDocument({
        data: new Uint8Array(await props.file.blob.arrayBuffer()),
        isEvalSupported: false,
      });
      pdf = await task.promise;
      if (disposed) {
        await pdf.destroy();
        return;
      }
      count.value = pdf.numPages;
      await nextTick();
      await render();
    } else if (["image/jpeg", "image/png"].includes(props.file.blob.type))
      imageUrl.value = url;
    else throw Error("format");
  } catch {
    error.value =
      "Fayl yo‘q yoki uni ochib bo‘lmadi. Boshqa hujjat bilan almashtirilmaydi.";
  } finally {
    loading.value = false;
  }
});
watch([page, scale], () => void render());
onBeforeUnmount(() => {
  disposed = true;
  renderTask?.cancel();
  void task?.destroy();
  if (url) URL.revokeObjectURL(url);
});
function download() {
  if (!url) return;
  const a = document.createElement("a");
  a.href = url;
  a.download = props.file.name;
  a.click();
}
async function print() {
  if (!props.file.blob) return;
  try {
    await printBlob(props.file.blob);
  } catch (e) {
    error.value = (e as Error).message;
  }
}
async function fit() {
  if (pdf) {
    const p = await pdf.getPage(page.value);
    scale.value = Math.max(
      0.2,
      Math.min(
        3,
        ((viewport.value?.clientWidth || 650) - 40) /
          p.getViewport({ scale: 1 }).width,
      ),
    );
  } else scale.value = 1;
}
</script>
<template>
  <WorkspaceDialog
    class="dw-viewer-modal"
    title="Hujjatni ko‘rish"
    @close="emit('close')"
    ><div class="dw-modal-body">
      <div class="dw-viewer-title">
        <FileText :size="30" />
        <div>
          <h3>{{ file.name }}</h3>
          <p>
            {{ encounter.name }} · {{ encounter.patientId }} ·
            {{ dateLabel(file.date) }}
          </p>
        </div>
        <span v-if="file.external" class="dw-badge blue">Tashqi hujjat</span>
      </div>
      <div class="dw-viewer-toolbar">
        <template v-if="isPdf && count"
          ><button
            class="e-icon"
            aria-label="Oldingi PDF sahifa"
            :disabled="page === 1"
            @click="page--"
          >
            <ChevronLeft /></button
          ><span>{{ page }} / {{ count }}</span
          ><button
            class="e-icon"
            aria-label="Keyingi PDF sahifa"
            :disabled="page === count"
            @click="page++"
          >
            <ChevronRight /></button></template
        ><button
          class="e-icon"
          aria-label="Masshtabni kamaytirish"
          :disabled="scale <= 0.25"
          @click="scale = Math.max(0.25, scale - 0.25)"
        >
          <Minus /></button
        ><span>{{ Math.round(scale * 100) }}%</span
        ><button
          class="e-icon"
          aria-label="Masshtabni oshirish"
          :disabled="scale >= 3"
          @click="scale = Math.min(3, scale + 0.25)"
        >
          <Plus /></button
        ><button class="e-icon" aria-label="Konteynerga moslash" @click="fit">
          <Maximize />
        </button>
      </div>
      <p v-if="loading" role="status">Fayl ochilmoqda…</p>
      <p v-if="error" class="dw-error" role="alert">{{ error }}</p>
      <div ref="viewport" class="dw-viewer-stage">
        <canvas
          v-if="isPdf"
          ref="canvas"
          aria-label="Tanlangan PDF sahifasi"
        /><img
          v-else-if="imageUrl"
          :src="imageUrl"
          :alt="file.name"
          :style="{ width: `${scale * 100}%` }"
        />
      </div>
    </div>
    <template #footer
      ><footer>
        <span class="dw-footnote"
          ><LockKeyhole :size="17" />Faqat ko‘rish uchun</span
        ><button class="e-button" :disabled="!file.blob" @click="download">
          <Download :size="18" />Yuklab olish</button
        ><button class="e-button" :disabled="!file.blob" @click="print">
          <Printer :size="18" />Chop etish</button
        ><button class="e-button primary" @click="emit('close')">Yopish</button>
      </footer></template
    ></WorkspaceDialog
  >
</template>
