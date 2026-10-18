<script setup lang="ts">
import { ref } from "vue";
import { Copy, Plus, Clock3, Info, Trash2 } from "lucide-vue-next";
import EmployeeDialog from "./EmployeeDialog.vue";
import ShiftFields from "./ShiftFields.vue";
import DateField from "./DateField.vue";
import {
  blankSchedule,
  blankShift,
  clone,
  weekdays,
  scheduleError,
  fullName,
  type Employee,
  type Schedule,
} from "../../mocks/employees";
const props = defineProps<{ employee: Employee }>();
const emit = defineEmits<{ close: []; save: [Schedule] }>();
const draft = ref(clone(props.employee.schedule ?? blankSchedule()));
const error = ref("");
const copyDay = ref<number | null>(null);
const targets = ref<number[]>([]);
const addOpen = ref(false);
const addDay = ref(0);
const doctor = props.employee.position === "Shifokor";
function copy() {
  if (copyDay.value === null) return;
  for (const target of targets.value)
    draft.value.days[target] = clone(draft.value.days[copyDay.value]!);
  copyDay.value = null;
  targets.value = [];
}
function add() {
  const d = draft.value.days[addDay.value]!;
  d.enabled = true;
  d.shifts.push({ ...blankShift(), start: "18:00", end: "20:00" });
  addOpen.value = false;
}
function save() {
  error.value = scheduleError(draft.value, doctor);
  if (!error.value) emit("save", clone(draft.value));
}
</script>
<template>
  <EmployeeDialog
    title="Jadvalni tahrirlash"
    :subtitle="fullName(employee) + (doctor ? ' · ' + employee.specialty : '')"
    wide
    @close="emit('close')"
    ><div class="schedule-start">
      <label
        >Amal qilish sanasi <em>*</em
        ><DateField v-model="draft.effective" label="Amal qilish sanasi"
      /></label>
      <div v-if="doctor" class="e-info">
        <Clock3 :size="23" /><span
          >Qabul davomiyligi:<strong
            >{{ employee.duration }} daqiqa</strong
          ></span
        >
      </div>
    </div>
    <div class="e-table-scroll">
      <table class="schedule-editor">
        <thead>
          <tr>
            <th>Kun</th>
            <th>Ish kuni</th>
            <th>Filial</th>
            <th v-if="doctor">Kabinet</th>
            <th>Ish vaqti</th>
            <th>Tanaffus</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <template v-for="(day, index) in draft.days" :key="index"
            ><tr v-for="(shift, row) in day.shifts" :key="row">
              <td>{{ weekdays[index] }}</td>
              <td>
                <button
                  v-if="row === 0"
                  class="e-switch"
                  :class="{ on: day.enabled }"
                  role="switch"
                  :aria-checked="day.enabled"
                  :aria-label="weekdays[index] + ' ish kuni'"
                  @click="day.enabled = !day.enabled"
                >
                  <i />
                </button>
              </td>
              <ShiftFields
                :shift="shift"
                :doctor="doctor"
                :disabled="!day.enabled"
              />
              <td>
                <button
                  v-if="row > 0"
                  class="e-icon"
                  aria-label="Vaqt oralig‘ini olib tashlash"
                  @click="day.shifts.splice(row, 1)"
                >
                  <Trash2 :size="17" /></button
                ><button
                  v-else
                  class="e-icon copy-trigger"
                  :disabled="!day.enabled"
                  :aria-label="weekdays[index] + ' jadvalidan nusxalash'"
                  @click="
                    copyDay = copyDay === index ? null : index;
                    targets = [];
                  "
                >
                  <Copy :size="19" />
                </button>
              </td></tr
          ></template>
        </tbody>
      </table>
    </div>
    <div
      v-if="copyDay !== null"
      class="e-popover"
      role="group"
      aria-label="Nusxalash kunlari"
    >
      <strong>{{ weekdays[copyDay] }} jadvalini nusxalash</strong
      ><label v-for="(day, i) in weekdays" :key="day"
        ><template v-if="i !== copyDay"
          ><input v-model="targets" type="checkbox" :value="i" />{{
            day
          }}</template
        ></label
      ><button
        class="e-button primary"
        :disabled="!targets.length"
        @click="copy"
      >
        Qo‘llash</button
      ><button class="e-button" @click="copyDay = null">Bekor qilish</button>
    </div>
    <p class="e-hint">
      <Copy :size="18" /> Nusxalash belgisi orqali kun jadvalini boshqa kunlarga
      qo‘llang.
    </p>
    <div class="schedule-add">
      <button class="e-button" @click="addOpen = !addOpen">
        <Plus :size="18" />Vaqt oralig‘i qo‘shish</button
      ><span>Bir kunda bir nechta filial yoki smenada ishlash uchun.</span>
    </div>
    <div v-if="addOpen" class="e-popover">
      <label
        >Kun<select v-model="addDay">
          <option v-for="(d, i) in weekdays" :key="d" :value="i">
            {{ d }}
          </option>
        </select></label
      ><button class="e-button primary" @click="add">Qo‘shish</button>
    </div>
    <p v-if="error" class="e-error" role="alert">{{ error }}</p>
    <footer>
      <div class="e-info">
        <Info :size="22" />O‘zgarishlar faqat demo holatiga saqlanadi. Kabinet
        bandligi va mavjud qabullar tekshirilmaydi.
      </div>
      <button class="e-button" @click="emit('close')">Bekor qilish</button
      ><button class="e-button primary" @click="save">
        O‘zgarishlarni saqlash
      </button>
    </footer></EmployeeDialog
  >
</template>
