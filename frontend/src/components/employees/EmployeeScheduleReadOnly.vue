<script setup lang="ts">
import { ref, watch, onBeforeUnmount } from "vue";
import {
  employeeDoctor,
  scheduleList,
  type Rule,
} from "../../services/employeeSchedule";
import { errorText } from "../../services/api";
import { dateLabel } from "../../mocks/employees";
const props = defineProps<{ membershipId?: string | null; branchId: string }>();
interface Room {
  id: string;
  number: string;
}
interface Exception {
  id: string;
  local_date: string;
  kind: string;
  replacement: { local_start: string; local_end: string } | null;
  reason?: string;
  note?: string;
}
const names = [
  "Dushanba",
  "Seshanba",
  "Chorshanba",
  "Payshanba",
  "Juma",
  "Shanba",
  "Yakshanba",
];
const rules = ref<Rule[]>([]),
  rooms = ref<Room[]>([]),
  exceptions = ref<Exception[]>([]);
const loading = ref(false),
  error = ref("");
let revision = 0;
onBeforeUnmount(() => revision++);
watch(
  () => [props.membershipId, props.branchId],
  async () => {
    const n = ++revision;
    rules.value = [];
    rooms.value = [];
    exceptions.value = [];
    error.value = "";
    loading.value = false;
    if (!props.membershipId || !props.branchId) return;
    loading.value = true;
    try {
      const doctor = await employeeDoctor(props.membershipId);
      if (n !== revision || !doctor) return;
      const filter = { doctor_id: doctor.id, branch_id: props.branchId };
      const [r, rm, ex] = await Promise.all([
        scheduleList<Rule>("schedules", filter),
        scheduleList<Room>("rooms", {
          branch_id: props.branchId,
          status: "all",
        }),
        scheduleList<Exception>("schedule-exceptions", filter),
      ]);
      if (n !== revision) return;
      rules.value = r;
      rooms.value = rm;
      exceptions.value = ex.sort((a, b) =>
        a.local_date.localeCompare(b.local_date),
      );
    } catch (e) {
      if (n === revision) error.value = errorText(e);
    } finally {
      if (n === revision) loading.value = false;
    }
  },
  { immediate: true },
);
function dayRules(day: number) {
  return rules.value
    .filter((r) => r.weekday === day)
    .sort((a, b) => a.local_start.localeCompare(b.local_start));
}
function roomName(id: string) {
  return rooms.value.find((r) => r.id === id)?.number ?? "—";
}
// The current API has no room-usage contract. Never infer exclusive/shared usage.
</script>
<template>
  <div class="employee-schedule-readonly" aria-label="Saqlangan ish jadvali">
    <p v-if="loading" role="status" class="e-card">Ish jadvali yuklanmoqda…</p>
    <p v-else-if="error" role="alert" class="e-card e-error">
      Ish jadvalini yuklab bo‘lmadi: {{ error }}
    </p>
    <template v-else>
      <section class="e-card">
        <h2>Haftalik ish jadvali</h2>
        <p class="e-hint">
          Shifokorning saqlangan haftalik qabul jadvali va ish vaqtlari.
        </p>
        <p v-if="!rules.length" class="e-empty">
          Ish jadvali hali yaratilmagan
        </p>
        <div class="e-table-scroll">
          <table aria-label="Haftalik ish jadvali">
            <thead>
              <tr>
                <th>Hafta kuni</th>
                <th>Ish vaqti</th>
                <th>Qabul davomiyligi</th>
                <th>Qabul xonasi</th>
                <th>Foydalanish tartibi</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="(name, i) in names" :key="name">
                <tr v-for="r in dayRules(i + 1)" :key="r.id">
                  <th scope="row">{{ name }}</th>
                  <td>
                    {{ r.local_start }}–{{ r.local_end
                    }}<small
                      >{{ dateLabel(r.effective_from) }} —
                      {{
                        r.effective_to
                          ? dateLabel(r.effective_to)
                          : "cheklanmagan"
                      }}</small
                    >
                  </td>
                  <td>{{ r.slot_minutes }} daqiqa</td>
                  <td>{{ roomName(r.room_id) }}</td>
                  <td>—</td>
                </tr>
                <tr v-if="!dayRules(i + 1).length" class="no-reception">
                  <th scope="row">{{ name }}</th>
                  <td>Qabul yo‘q</td>
                  <td>—</td>
                  <td>—</td>
                  <td>—</td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </section>
      <section class="e-card">
        <h2>Istisno sanalar</h2>
        <p class="e-hint">Shifokorning haftalik jadvalidan tashqari kunlar.</p>
        <div class="e-table-scroll">
          <table aria-label="Istisno sanalar">
            <thead>
              <tr>
                <th>Sana</th>
                <th>Sabab</th>
                <th>Ish vaqti</th>
                <th>Izoh</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="e in exceptions" :key="e.id">
                <td>{{ dateLabel(e.local_date) }}</td>
                <td>{{ e.reason || "—" }}</td>
                <td>
                  {{
                    e.kind === "closed"
                      ? "Qabul yo‘q"
                      : e.replacement
                        ? `${e.replacement.local_start}–${e.replacement.local_end}`
                        : "—"
                  }}
                </td>
                <td>{{ e.note || "—" }}</td>
              </tr>
              <tr v-if="!exceptions.length">
                <td colspan="4" class="e-empty">Istisno sanalar yo‘q</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>
<style scoped>
.employee-schedule-readonly {
  min-width: 0;
}
h2 {
  margin-bottom: 5px;
}
table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
th,
td {
  border: 1px solid #e1ebf9;
  padding: 10px 14px;
  text-align: left;
  vertical-align: top;
}
thead th {
  background: #eff5fc;
  color: #526f9b;
  font-weight: 500;
}
.no-reception {
  background: #f5f8fc;
  color: #7487a3;
}
small {
  display: block;
  margin-top: 4px;
  color: #7487a3;
  font-size: 11px;
}
.e-table-scroll {
  border: 1px solid #e1ebf9;
  border-radius: 7px;
  overflow-x: auto;
}
@media (max-width: 700px) {
  table {
    min-width: 600px;
  }
  .e-card {
    padding: 14px;
  }
}
</style>
