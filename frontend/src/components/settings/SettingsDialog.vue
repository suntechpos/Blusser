<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref } from "vue";
import { X } from "lucide-vue-next";
defineProps<{ title: string }>();
const emit = defineEmits<{ close: [] }>();
const element = ref<HTMLDialogElement>();
let opener: HTMLElement | null = null;
onMounted(() => {
  opener = document.activeElement as HTMLElement;
  element.value?.showModal();
});
onBeforeUnmount(() => {
  element.value?.close();
  opener?.focus();
});
function trap(e: KeyboardEvent) {
  if (e.key !== "Tab") return;
  const nodes = Array.from(
    element.value!.querySelectorAll<HTMLElement>(
      'button:not(:disabled),input:not(:disabled),select:not(:disabled),[tabindex="0"]',
    ),
  ).filter((n) => n.getClientRects().length);
  const first = nodes[0],
    last = nodes.at(-1);
  if (e.shiftKey && document.activeElement === first) {
    e.preventDefault();
    last?.focus();
  } else if (!e.shiftKey && document.activeElement === last) {
    e.preventDefault();
    first?.focus();
  }
}
</script>
<template>
  <dialog
    ref="element"
    class="sr-dialog"
    :aria-label="title"
    @cancel.prevent="emit('close')"
    @keydown="trap"
  >
    <header>
      <h2>{{ title }}</h2>
      <button aria-label="Yopish" @click="emit('close')">
        <X :size="22" />
      </button>
    </header>
    <slot />
  </dialog>
</template>
