<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, type Component } from "vue";
import { X } from "lucide-vue-next";
const props = defineProps<{
  title: string;
  subtitle?: string;
  dirty?: boolean;
  icon?: Component;
}>();
const emit = defineEmits<{ close: [] }>();
const root = ref<HTMLDialogElement>();
let opener: HTMLElement | null = null;
const discard = ref(false);
function close() {
  if (props.dirty) discard.value = true;
  else emit("close");
}
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
    class="employee-dialog dw-workflow-modal"
    :aria-label="title"
    @cancel.prevent="close"
    @keydown="trap"
  >
    <header>
      <span v-if="icon" class="workflow-title-icon"
        ><component :is="icon" :size="32"
      /></span>
      <div>
        <h2>{{ title }}</h2>
        <p v-if="subtitle">{{ subtitle }}</p>
      </div>
      <button class="e-icon" aria-label="Yopish" @click="close">
        <X :size="22" />
      </button>
    </header>
    <div v-if="discard" class="dw-discard" role="alert">
      <b>Saqlanmagan o‘zgarishlar bekor qilinsinmi?</b
      ><button class="e-button" @click="discard = false">Davom etish</button
      ><button class="e-button" @click="emit('close')">
        O‘zgarishlarni bekor qilish
      </button>
    </div>
    <slot /><slot name="footer" :close="close" />
  </dialog>
</template>
