<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref } from "vue";
import { CalendarDays, X, UserRoundPlus } from "lucide-vue-next";
defineProps<{ title: string; subtitle?: string; patient?: boolean }>();
const emit = defineEmits<{ close: [] }>();
const root = ref<HTMLDialogElement>();
let opener: HTMLElement | null = null;
onMounted(() => {
  opener = document.activeElement as HTMLElement;
  root.value?.showModal();
});
onBeforeUnmount(() => {
  root.value?.close();
  if (opener?.isConnected) opener.focus();
});
function trap(e: KeyboardEvent) {
  if (e.key !== "Tab") return;
  const items = [
    ...root.value!.querySelectorAll<HTMLElement>(
      'button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),[tabindex="0"]',
    ),
  ].filter((x) => x.getClientRects().length);
  const first = items[0],
    last = items.at(-1);
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
    ref="root"
    class="rp-dialog"
    :aria-label="title"
    @cancel.prevent="emit('close')"
    @keydown="trap"
  >
    <header class="rp-dialog-head">
      <span class="rp-title-icon"
        ><UserRoundPlus v-if="patient" :size="26" /><CalendarDays
          v-else
          :size="26"
      /></span>
      <div>
        <h2>{{ title }}</h2>
        <p v-if="subtitle">{{ subtitle }}</p>
      </div>
      <button
        type="button"
        class="rp-icon"
        aria-label="Yopish"
        @click="emit('close')"
      >
        <X :size="22" />
      </button>
    </header>
    <slot />
    <footer class="rp-dialog-footer"><slot name="footer" /></footer>
  </dialog>
</template>
