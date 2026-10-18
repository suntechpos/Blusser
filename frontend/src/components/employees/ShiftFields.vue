<script setup lang="ts">
import { branches, rooms, type Shift } from "../../mocks/employees";
import TimeField from "./TimeField.vue";
defineProps<{ shift: Shift; doctor: boolean; disabled?: boolean }>();
</script>
<template>
  <td>
    <select
      v-model="shift.branch"
      aria-label="Filial"
      :disabled="disabled"
      @change="shift.room = rooms[shift.branch]?.[0] ?? ''"
    >
      <option v-for="b in branches" :key="b">{{ b }}</option>
    </select>
  </td>
  <td v-if="doctor">
    <select v-model="shift.room" aria-label="Kabinet" :disabled="disabled">
      <option v-for="r in rooms[shift.branch]" :key="r">{{ r }}</option>
    </select>
  </td>
  <td>
    <div class="time-pair">
      <TimeField
        v-model="shift.start"
        label="Ish boshlanishi"
        :disabled="disabled"
      /><span>–</span
      ><TimeField
        v-model="shift.end"
        label="Ish tugashi"
        :disabled="disabled"
      />
    </div>
    <label v-if="shift.end <= shift.start || shift.nextDay" class="next-day"
      ><input v-model="shift.nextDay" type="checkbox" :disabled="disabled" />
      Tugash keyingi kun</label
    >
  </td>
  <td>
    <div v-if="shift.breakStart || shift.breakEnd" class="time-pair">
      <TimeField
        v-model="shift.breakStart"
        label="Tanaffus boshlanishi"
        :disabled="disabled"
      /><span>–</span
      ><TimeField
        v-model="shift.breakEnd"
        label="Tanaffus tugashi"
        :disabled="disabled"
      />
    </div>
    <button
      v-else
      class="e-button"
      :disabled="disabled"
      @click="
        shift.breakStart = '13:00';
        shift.breakEnd = '14:00';
      "
    >
      ＋ Tanaffus
    </button>
  </td>
</template>
