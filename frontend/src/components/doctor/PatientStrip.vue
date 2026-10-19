<script setup lang="ts">
import { CalendarDays, UserRound } from "lucide-vue-next";
import { DOCTOR, type Encounter } from "../../mocks/doctorWorkspace";
import { dateLabel } from "../../mocks/employees";
defineProps<{ encounter: Encounter; context?: boolean }>();
</script>
<template>
  <div class="dw-modal-patient">
    <span class="dw-avatar" :class="encounter.color">{{
      encounter.initials
    }}</span>
    <div>
      <h3>{{ encounter.name }}</h3>
      <p>
        {{ encounter.patientId }} · {{ encounter.gender }} ·
        {{ encounter.age }} yosh
      </p>
    </div>
    <template v-if="context"
      ><div class="dw-strip-context">
        <UserRound :size="23" /><span
          >Shifokor:<b>{{ DOCTOR.name }}</b></span
        >
      </div>
      <div class="dw-strip-context">
        <CalendarDays :size="23" /><span
          >Qabul:<b
            >{{ dateLabel(encounter.date) }} ·
            {{ encounter.time || "Jonli navbat" }}</b
          ></span
        >
      </div></template
    >
  </div>
</template>
