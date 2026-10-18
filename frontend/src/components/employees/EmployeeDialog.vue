<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref } from "vue";
import { X } from "lucide-vue-next";
defineProps<{ title: string; subtitle?: string; wide?: boolean }>();
const emit = defineEmits<{ close: [] }>();
const dialog = ref<HTMLDialogElement>();
let opener: HTMLElement | null = null;
function trapFocus(event: KeyboardEvent) {
  if (event.key !== "Tab" || !dialog.value) return;
  const items = Array.from(
    dialog.value.querySelectorAll<HTMLElement>(
      'button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex="0"]',
    ),
  ).filter((el) => el.tabIndex >= 0 && el.getClientRects().length > 0);
  const first = items[0],
    last = items.at(-1);
  if (!first || !last) return;
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
}
onMounted(() => {
  opener = document.activeElement as HTMLElement;
  dialog.value?.showModal();
});
onBeforeUnmount(() => {
  dialog.value?.close();
  opener?.focus();
});
</script>
<template>
  <dialog
    ref="dialog"
    class="employee-dialog"
    :class="{ wide }"
    aria-labelledby="employee-modal-title"
    @cancel.prevent="emit('close')"
    @keydown="trapFocus"
  >
    <header>
      <div>
        <h2 id="employee-modal-title">{{ title }}</h2>
        <p v-if="subtitle">{{ subtitle }}</p>
      </div>
      <button class="e-icon" aria-label="Yopish" @click="emit('close')">
        <X :size="22" />
      </button>
    </header>
    <slot />
  </dialog>
</template>
