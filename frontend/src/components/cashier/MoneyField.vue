<script setup lang="ts">
defineProps<{ label: string; disabled?: boolean; error?: string }>();
const model = defineModel<string>({ required: true });
</script>
<template>
  <label class="cs-money-field"
    ><span>{{ label }}</span
    ><span class="cs-money-input"
      ><input
        v-model="model"
        inputmode="numeric"
        :aria-label="label"
        :disabled="disabled"
        :aria-invalid="!!error"
        autocomplete="off"
      /><small>so‘m</small></span
    ><small v-if="error" class="cs-field-error" role="alert">{{
      error
    }}</small></label
  >
</template>
