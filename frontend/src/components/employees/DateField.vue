<script setup lang="ts">
import { CalendarDays } from "lucide-vue-next";
import { dateLabel } from "../../mocks/employees";
defineProps<{ label: string; max?: string; min?: string }>();
const value = defineModel<string>({ required: true });
function picker(event: MouseEvent) {
  const input = event.currentTarget as HTMLInputElement;
  if (typeof input.showPicker === "function") input.showPicker();
}
</script>
<template>
  <span class="e-date-field"
    ><span aria-hidden="true" :class="{ placeholder: !value }">{{
      value ? dateLabel(value) : "DD.MM.YYYY"
    }}</span
    ><CalendarDays :size="18" aria-hidden="true" /><input
      v-model="value"
      type="date"
      :aria-label="label"
      :max="max"
      :min="min"
      @click="picker"
  /></span>
</template>
