<script setup lang="ts">
import type { LabSample } from "../../mocks/laboratory";
import { timestamp } from "../diagnostics/content";
defineProps<{
  samples: readonly LabSample[];
  startedAt?: string | null;
  labels?: boolean;
}>();
const emit = defineEmits<{ label: [sample: LabSample] }>();
</script>
<template>
  <section class="dx-card lb-sample-info">
    <h2>Namuna ma’lumotlari</h2>
    <p v-if="!samples.length">Namuna olinmagan</p>
    <article v-for="s in samples" :key="s.id">
      <dl>
        <dt>Namuna turi</dt>
        <dd>{{ s.type }}</dd>
        <dt>Namuna ID</dt>
        <dd>{{ s.id }}</dd>
        <dt>Olingan vaqt</dt>
        <dd>{{ timestamp(s.collectedAt) }}</dd>
        <dt>Olgan xodim</dt>
        <dd>
          {{ s.collector.name }}<small>{{ s.collector.specialty }}</small>
        </dd>
        <template v-if="s.note"
          ><dt>Izoh</dt>
          <dd>{{ s.note }}</dd></template
        >
      </dl>
      <button v-if="labels" class="dx-link" @click="emit('label', s)">
        Yorliqni chop etish · {{ s.id }}
      </button>
    </article>
    <dl v-if="startedAt">
      <dt>Boshlangan vaqt</dt>
      <dd>{{ timestamp(startedAt) }}</dd>
    </dl>
  </section>
</template>
