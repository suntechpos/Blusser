<script setup lang="ts">
import { ref } from "vue";
import { Info } from "lucide-vue-next";
import EmployeeDialog from "./EmployeeDialog.vue";
import ShiftFields from "./ShiftFields.vue";
import DateField from "./DateField.vue";
import {
  blankShift,
  clone,
  exceptionError,
  fullName,
  today,
  type Employee,
  type Exception,
} from "../../mocks/employees";
const props = defineProps<{
  employee: Employee;
  exception: Exception | null;
}>();
const emit = defineEmits<{ close: []; save: [Exception] }>();
const draft = ref<Exception>(
  clone(
    props.exception ?? {
      id: crypto.randomUUID(),
      from: today(),
      to: today(),
      kind: "off",
      reason: "Ta’til",
      note: "",
      shift: blankShift(props.employee.branch),
    },
  ),
);
const error = ref("");
function save() {
  error.value = exceptionError(
    draft.value,
    props.employee.position === "Shifokor",
  );
  if (!error.value) emit("save", clone(draft.value));
}
</script>
<template>
  <EmployeeDialog
    :title="exception ? 'Istisnoni tahrirlash' : 'Istisno qo‘shish'"
    :subtitle="
      fullName(employee) +
      (employee.specialty ? ' · ' + employee.specialty : '')
    "
    @close="emit('close')"
    ><div class="e-form-grid">
      <label
        >Boshlanish sanasi <em>*</em
        ><DateField v-model="draft.from" label="Boshlanish sanasi" /></label
      ><label
        >Tugash sanasi <em>*</em
        ><DateField v-model="draft.to" label="Tugash sanasi"
      /></label>
    </div>
    <p class="e-hint">Bitta kun uchun ikkala sanani bir xil belgilang.</p>
    <label class="e-label">Ish holati <em>*</em></label>
    <div class="exception-kinds">
      <label :class="{ selected: draft.kind === 'off' }"
        ><input
          v-model="draft.kind"
          type="radio"
          value="off"
        />Ishlamaydi</label
      ><label :class="{ selected: draft.kind === 'changed' }"
        ><input v-model="draft.kind" type="radio" value="changed" />Ish vaqti
        o‘zgaradi</label
      >
    </div>
    <div v-if="draft.kind === 'changed'" class="e-table-scroll exception-shift">
      <table>
        <thead>
          <tr>
            <th>Filial</th>
            <th v-if="employee.position === 'Shifokor'">Kabinet</th>
            <th>Ish vaqti</th>
            <th>Tanaffus</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <ShiftFields
              :shift="draft.shift"
              :doctor="employee.position === 'Shifokor'"
            />
          </tr>
        </tbody>
      </table>
    </div>
    <label class="e-label"
      >Sabab <em>*</em
      ><select v-model="draft.reason">
        <option>Ta’til</option>
        <option>Dam olish</option>
        <option>Kasallik</option>
        <option>Qisqa ish kuni</option>
        <option>Boshqa</option>
      </select></label
    ><label class="e-label"
      >Izoh (ixtiyoriy)<textarea
        v-model="draft.note"
        placeholder="Qo‘shimcha ma’lumot kiriting."
        rows="3"
      />
    </label>
    <div class="e-info">
      <Info :size="22" />Bu frontend namoyishi. Mavjud qabullar va kabinet
      bandligi tekshirilmaydi, haqiqiy jadval o‘zgarmaydi.
    </div>
    <p v-if="error" class="e-error" role="alert">{{ error }}</p>
    <footer>
      <button class="e-button" @click="emit('close')">Bekor qilish</button
      ><button class="e-button primary" @click="save">Saqlash</button>
    </footer></EmployeeDialog
  >
</template>
