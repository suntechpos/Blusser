<script setup lang="ts">
import { onMounted, ref, watch } from "vue";
import { Bold, Italic, List, ListOrdered } from "lucide-vue-next";
import { safeHtml } from "./content";
const props = defineProps<{
  label: string;
  placeholder: string;
  error?: string;
}>();
const model = defineModel<string>({ required: true });
const editor = ref<HTMLDivElement>();
onMounted(() => {
  if (editor.value) editor.value.innerHTML = safeHtml(model.value);
});
watch(model, (value) => {
  if (editor.value && safeHtml(editor.value.innerHTML) !== value)
    editor.value.innerHTML = safeHtml(value);
});
function update() {
  if (editor.value) model.value = safeHtml(editor.value.innerHTML);
}
function command(action: string) {
  editor.value?.focus();
  document.execCommand(action, false);
  update();
}
function paste(e: ClipboardEvent) {
  e.preventDefault();
  document.execCommand(
    "insertText",
    false,
    e.clipboardData?.getData("text/plain") ?? "",
  );
  update();
}
</script>
<template>
  <div class="dx-field">
    <label>{{ label }} <span>*</span></label>
    <div class="dx-editor" :class="{ invalid: error }">
      <div
        class="dx-editor-toolbar"
        role="toolbar"
        :aria-label="`${label} formatlash`"
      >
        <button
          v-for="item in [
            { action: 'bold', name: 'Qalin', icon: Bold },
            { action: 'italic', name: 'Kursiv', icon: Italic },
            {
              action: 'insertUnorderedList',
              name: 'Belgili ro‘yxat',
              icon: List,
            },
            {
              action: 'insertOrderedList',
              name: 'Raqamli ro‘yxat',
              icon: ListOrdered,
            },
          ]"
          :key="item.action"
          type="button"
          :aria-label="`${label}: ${item.name}`"
          @mousedown.prevent
          @click="command(item.action)"
        >
          <component :is="item.icon" :size="18" />
        </button>
      </div>
      <div
        ref="editor"
        contenteditable="true"
        role="textbox"
        aria-multiline="true"
        :aria-label="label"
        :aria-invalid="!!error"
        :data-placeholder="placeholder"
        class="dx-editable"
        @input="update"
        @paste="paste"
      />
    </div>
    <small v-if="error" class="dx-error" role="alert">{{ error }}</small>
  </div>
</template>
