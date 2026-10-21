<script setup lang="ts">
import { ArrowDown, ArrowUp, CheckCircle2, HelpCircle } from "lucide-vue-next";
import {
  rowFlag,
  referenceLabel,
  labTests,
  type LabRow,
} from "../../mocks/laboratory";
defineProps<{
  rows: readonly Readonly<LabRow>[];
  editable?: boolean;
  errors?: Record<string, string>;
}>();
const emit = defineEmits<{ change: [id: string, value: string] }>();
const labels: Record<string, string> = {
  empty: "—",
  invalid: "Noto‘g‘ri format",
  unknown: "Belgilanmagan",
  normal: "Me’yorda",
  low: "Past",
  high: "Yuqori",
  qualitative: "Sifat natijasi",
};
</script>
<template>
  <div class="lb-results-scroll">
    <table class="lb-results">
      <thead>
        <tr>
          <th>Ko‘rsatkich</th>
          <th>Natija</th>
          <th>Birlik</th>
          <th>Me’yor oralig‘i</th>
          <th>Belgi</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="row in rows"
          :key="row.id"
          :class="{ abnormal: ['low', 'high'].includes(rowFlag(row)) }"
        >
          <td>
            <b>{{ row.name }}</b
            ><span v-if="editable && row.required" class="lb-required"> *</span
            ><small v-if="new Set(rows.map((r) => r.testId)).size > 1">{{
              labTests.find((t) => t.id === row.testId)?.name
            }}</small>
          </td>
          <td>
            <template v-if="editable"
              ><select
                v-if="row.kind === 'qualitative'"
                :aria-label="`${row.name} natijasi`"
                :value="row.value"
                :aria-invalid="!!errors?.[row.id]"
                @change="
                  emit(
                    'change',
                    row.id,
                    ($event.target as HTMLSelectElement).value,
                  )
                "
              >
                <option value="">Tanlang</option>
                <option v-for="option in row.options" :key="option">
                  {{ option }}
                </option></select
              ><input
                v-else
                :aria-label="`${row.name} natijasi`"
                :value="row.value"
                :inputmode="row.kind === 'numeric' ? 'decimal' : 'text'"
                :aria-invalid="!!errors?.[row.id]"
                placeholder="Kiriting"
                maxlength="200"
                @input="
                  emit(
                    'change',
                    row.id,
                    ($event.target as HTMLInputElement).value,
                  )
                "
              /><small
                v-if="errors?.[row.id]"
                class="lb-field-error"
                role="alert"
                >{{ errors[row.id] }}</small
              ></template
            ><b v-else>{{ row.value || "—" }}</b>
          </td>
          <td>{{ row.unit }}</td>
          <td>{{ referenceLabel(row.reference) }}</td>
          <td>
            <span :class="['lb-flag', rowFlag(row)]"
              ><ArrowUp v-if="rowFlag(row) === 'high'" :size="17" /><ArrowDown
                v-else-if="rowFlag(row) === 'low'"
                :size="17"
              /><CheckCircle2
                v-else-if="rowFlag(row) === 'normal'"
                :size="17"
              /><HelpCircle
                v-else-if="rowFlag(row) === 'unknown'"
                :size="17"
              />{{ labels[rowFlag(row)] }}</span
            >
          </td>
        </tr>
      </tbody>
    </table>
  </div>
  <p class="lb-sample-note">
    ⓘ Qiymatlar va me’yorlar — dizayn uchun namuna. Me’yordan tashqari qiymat
    avtomatik xato degani emas.
  </p>
</template>
