<script setup lang="ts">
import {
  UserRound,
  FlaskConical,
  Activity,
  Syringe,
  Folder,
} from "lucide-vue-next";
import type { Category } from "../../mocks/servicesCatalog";
defineProps<{ category: Category }>();
const icons: Record<string, typeof Folder> = {
  "cat-1": UserRound,
  "cat-2": FlaskConical,
  "cat-3": Activity,
  "cat-4": Syringe,
};
</script>
<template>
  <span class="sv-category" :class="category.color"
    ><component :is="icons[category.id] ?? Folder" :size="17" /><span
      class="sv-category-name"
      :title="category.name"
      >{{ category.name }}</span
    ></span
  >
</template>
