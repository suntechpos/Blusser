<script setup lang="ts">
import { Folder } from "lucide-vue-next";
import type { Category } from "../../services/serviceCatalog";
defineProps<{ category: Category }>();
</script>
<template>
  <span class="sv-category" :class="category.color"
    ><Folder :size="17" /><span
      class="sv-category-name"
      :title="category.name"
      >{{ category.name }}</span
    ></span
  >
</template>
