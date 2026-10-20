<script setup lang="ts">
import { ref, computed, onBeforeUnmount } from "vue";
import DocumentViewer from "../doctor/DocumentViewer.vue";
import type { Attachment } from "../../mocks/diagnostics";
const props = defineProps<{
  files: readonly Readonly<Attachment>[];
  index: number;
  context: string;
}>();
const emit = defineEmits<{ close: [] }>();
const selected = ref(props.index);
const file = computed(() => props.files[selected.value]);
const thumbnails = props.files.map((f) =>
  f.mime.startsWith("image/") ? URL.createObjectURL(f.blob) : "",
);
onBeforeUnmount(() =>
  thumbnails.forEach((url) => {
    if (url) URL.revokeObjectURL(url);
  }),
);
</script>
<template>
  <DocumentViewer
    class="dx-viewer"
    v-if="file"
    :key="file.id"
    :context="context"
    contain-image
    :title="file.name"
    :file="{ name: file.name, blob: file.blob, date: '' }"
    @close="emit('close')"
    ><template #files
      ><nav class="dx-file-nav" aria-label="Fayllar">
        <button :disabled="selected === 0" @click="selected--">
          Oldingi fayl</button
        ><span>{{ selected + 1 }} / {{ files.length }} fayl</span
        ><button :disabled="selected === files.length - 1" @click="selected++">
          Keyingi fayl</button
        ><button
          v-for="(f, i) in files"
          :key="f.id"
          :aria-current="i === selected ? 'true' : undefined"
          @click="selected = i"
        >
          <img
            v-if="thumbnails[i]"
            :src="thumbnails[i]"
            alt=""
            width="55"
            height="38"
            style="object-fit: contain; display: block"
          />{{ f.name }}
        </button>
      </nav></template
    ></DocumentViewer
  >
</template>
