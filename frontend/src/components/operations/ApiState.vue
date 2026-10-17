<script setup lang="ts">
defineProps<{ loading?: boolean; error?: string; empty?: boolean }>();
defineEmits<{ retry: [] }>();
</script>
<template>
  <p v-if="loading" role="status">Yuklanmoqda…</p>
  <p v-else-if="error" role="alert">
    {{ error }}
    <button type="button" @click="$emit('retry')">Qayta urinish</button>
  </p>
  <p v-else-if="empty" role="status">Ma’lumot topilmadi.</p>
</template>
