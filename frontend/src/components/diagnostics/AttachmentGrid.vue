<script setup lang="ts">
import { ref, watch, onBeforeUnmount } from "vue";
import {
  Eye,
  X,
  Download,
  FileText,
  Plus,
  CloudUpload,
  CheckCircle2,
} from "lucide-vue-next";
import { downloadBlob } from "../doctor/localFiles";
import { fileSize } from "./content";
import { readAttachments } from "./files";
import type { Attachment } from "../../mocks/diagnostics";
const props = defineProps<{
  files: readonly Readonly<Attachment>[];
  editable?: boolean;
  busy?: boolean;
  maxBytes?: number;
  maxFiles?: number;
}>();
const emit = defineEmits<{
  change: [files: Attachment[]];
  view: [index: number];
  busy: [busy: boolean];
}>();
const input = ref<HTMLInputElement>(),
  errors = ref<string[]>([]),
  reading = ref(false),
  urls = ref<Record<string, string>>({});
let generation = 0;
watch(
  () => props.files,
  (files) => {
    for (const [id, url] of Object.entries(urls.value))
      if (!files.some((f) => f.id === id)) {
        URL.revokeObjectURL(url);
        delete urls.value[id];
      }
    for (const f of files)
      if (f.mime.startsWith("image/") && !urls.value[f.id])
        urls.value[f.id] = URL.createObjectURL(f.blob);
  },
  { immediate: true, deep: false },
);
onBeforeUnmount(() => {
  generation++;
  for (const url of Object.values(urls.value)) URL.revokeObjectURL(url);
});
async function select(files: File[]) {
  if (!props.editable || reading.value) return;
  const token = ++generation;
  reading.value = true;
  emit("busy", true);
  errors.value = [];
  try {
    const queue: File[] = [],
      budgetErrors: string[] = [];
    let bytes = props.files.reduce((n, f) => n + f.size, 0),
      count = props.files.length;
    for (const file of files) {
      if (
        count >= (props.maxFiles ?? Infinity) ||
        bytes + file.size > (props.maxBytes ?? Infinity)
      ) {
        budgetErrors.push(
          `${file.name}: lokal fayl limiti — ${props.maxFiles ?? "∞"} ta, jami ${Math.round((props.maxBytes ?? Infinity) / 1024 / 1024)} MB.`,
        );
      } else {
        queue.push(file);
        bytes += file.size;
        count++;
      }
    }
    const result = await readAttachments(queue);
    result.errors.unshift(...budgetErrors);
    if (token === generation) {
      errors.value = result.errors;
      emit("change", [
        ...props.files.map((f) => ({ ...f })),
        ...result.accepted,
      ]);
    }
  } catch {
    if (token === generation)
      errors.value = ["Fayllarni o‘qib bo‘lmadi. Qayta tanlang."];
  } finally {
    if (token === generation) {
      reading.value = false;
      emit("busy", false);
      if (input.value) input.value.value = "";
    }
  }
}
function inputFiles(e: Event) {
  void select([...((e.target as HTMLInputElement).files ?? [])]);
}
</script>
<template>
  <section class="dx-card dx-attachments">
    <h2>
      Fayllar va rasmlar <span v-if="files.length">{{ files.length }} ta</span>
    </h2>
    <input
      v-if="editable"
      ref="input"
      type="file"
      class="dx-file-input"
      accept=".jpg,.jpeg,.png,.pdf"
      multiple
      aria-label="Fayl tanlash"
      @change="inputFiles"
    />
    <div
      v-if="!files.length && editable"
      class="dx-drop"
      @dragover.prevent
      @drop.prevent="select([...($event.dataTransfer?.files ?? [])])"
    >
      <CloudUpload :size="39" /><button
        class="dx-link"
        type="button"
        @click="input?.click()"
      >
        Faylni shu yerga tashlang yoki tanlang
      </button>
      <p>JPG, PNG yoki PDF · Har bir fayl 20 MB gacha</p>
    </div>
    <div v-else-if="files.length" class="dx-file-grid">
      <article v-for="(file, i) in files" :key="file.id" class="dx-file-card">
        <button
          class="dx-file-preview"
          :aria-label="`${file.name}ni ko‘rish`"
          @click="emit('view', i)"
        >
          <img
            v-if="urls[file.id]"
            :src="urls[file.id]"
            :alt="file.name"
          /><FileText v-else class="dx-pdf-icon" :size="47" />
        </button>
        <div class="dx-file-actions">
          <button
            class="dx-icon"
            :aria-label="`${file.name} ko‘rish`"
            @click="emit('view', i)"
          >
            <Eye :size="18" /></button
          ><button
            v-if="editable"
            class="dx-icon"
            :disabled="reading"
            :aria-label="`${file.name}ni olib tashlash`"
            @click="
              emit(
                'change',
                files.filter((f) => f.id !== file.id).map((f) => ({ ...f })),
              )
            "
          >
            <X :size="18" /></button
          ><button
            v-else
            class="dx-icon"
            :aria-label="`${file.name}ni yuklab olish`"
            @click="downloadBlob(file.blob, file.name)"
          >
            <Download :size="18" />
          </button>
        </div>
        <div class="dx-file-meta">
          <strong :title="file.name">{{ file.name }}</strong
          ><small>{{ fileSize(file.size) }}</small
          ><span v-if="editable"
            ><CheckCircle2 :size="15" />Tanlangan (lokal)</span
          >
        </div>
      </article>
    </div>
    <p v-else class="dx-empty">Fayl biriktirilmagan</p>
    <div v-if="editable && files.length" class="dx-add-files">
      <button
        class="dx-button dx-outline"
        :disabled="reading"
        @click="input?.click()"
      >
        <Plus :size="21" />Yana fayl qo‘shish</button
      ><small
        >{{ files.length }} ta fayl tanlandi · JPG, PNG, PDF · Har biri 20 MB
        gacha</small
      >
    </div>
    <p v-if="reading" role="status">Fayllar lokal o‘qilmoqda…</p>
    <p v-for="error in errors" :key="error" class="dx-error" role="alert">
      {{ error }}
    </p>
  </section>
</template>
