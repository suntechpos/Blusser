<script setup lang="ts">
import { Clock3 } from "lucide-vue-next";
defineProps<{ label: string; disabled?: boolean }>();
const value = defineModel<string>({ required: true });
</script>
<template>
  <span class="e-time-field"
    ><input
      v-model="value"
      :aria-label="label"
      :disabled="disabled"
      type="text"
      inputmode="numeric"
      placeholder="HH:mm"
      maxlength="5"
      pattern="([01][0-9]|2[0-3]):[0-5][0-9]" /><Clock3
      :size="15"
      aria-hidden="true"
  /></span>
</template>
