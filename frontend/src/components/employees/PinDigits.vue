<script setup lang="ts">
import { ref, useId } from "vue";
const props = defineProps<{
  modelValue: string;
  label: string;
  inputLabel: string;
  visible: boolean;
  error?: string;
}>();
const emit = defineEmits<{ "update:modelValue": [string] }>();
const id = useId();
const cursor = ref(0);
function update(event: Event) {
  const input = event.target as HTMLInputElement;
  const value = input.value.replace(/[^0-9]/g, "").slice(0, 4);
  input.value = value;
  cursor.value = input.selectionStart ?? value.length;
  emit("update:modelValue", value);
}
function selection(event: Event) {
  cursor.value = (event.target as HTMLInputElement).selectionStart ?? 0;
}
</script>

<template>
  <div
    class="employee-pin-field"
    :class="{
      'has-error': error,
      'is-complete': modelValue.length === 4 && !error,
    }"
  >
    <label :for="id">{{ label }}</label>
    <div class="employee-pin-digits">
      <input
        :id="id"
        :value="modelValue"
        :aria-label="inputLabel"
        :type="visible ? 'text' : 'password'"
        inputmode="numeric"
        autocomplete="new-password"
        :aria-invalid="!!error"
        :aria-describedby="id + '-feedback'"
        @input="update"
        @keyup="selection"
        @click="selection"
        @focus="selection"
      />
      <span
        v-for="(_, index) in 4"
        :key="index"
        aria-hidden="true"
        class="employee-pin-cell"
        :class="{ 'is-current': index === Math.min(cursor, 3) }"
        >{{
          modelValue[index] ? (visible ? modelValue[index] : "•") : ""
        }}</span
      >
    </div>
    <small
      :id="id + '-feedback'"
      class="employee-pin-feedback"
      :class="{ 'e-error': error }"
      aria-live="polite"
      >{{
        error || (modelValue.length === 4 ? "✓ 4 raqam kiritildi" : "\u00a0")
      }}</small
    >
  </div>
</template>
