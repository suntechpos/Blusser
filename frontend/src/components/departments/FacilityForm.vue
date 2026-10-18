<script setup lang="ts">
import { computed, ref, watch } from "vue";
import EmployeeDialog from "../employees/EmployeeDialog.vue";
import {
  FACILITY_BRANCHES,
  ROOM_TYPES,
  departmentErrors,
  roomErrors,
  type Department,
  type Room,
} from "../../mocks/departmentsRooms";
const props = defineProps<{
  kind: "department" | "room";
  item: Department | Room | null;
  departments: Department[];
  rooms: Room[];
}>();
const emit = defineEmits<{
  close: [];
  saveDepartment: [Department];
  saveRoom: [Room];
}>();
const roomMode = props.kind === "room";
const draft = ref({
  id: "",
  name: "",
  branch: "",
  note: "",
  active: true,
  icon: "building",
  departmentId: "",
  type: "",
  floor: "",
  ...props.item,
});
const errors = ref<Record<string, string>>({});
const available = computed(() =>
  props.departments.filter((d) => d.branch === draft.value.branch),
);
watch(
  () => draft.value.branch,
  () => {
    if (!available.value.some((d) => d.id === draft.value.departmentId))
      draft.value.departmentId = "";
  },
);
function save() {
  errors.value = roomMode
    ? roomErrors(draft.value, props.rooms, props.departments)
    : departmentErrors(draft.value, props.departments, props.rooms);
  if (Object.keys(errors.value).length) return;
  if (roomMode) {
    const { id, name, branch, departmentId, type, floor, active, note } =
      draft.value;
    emit("saveRoom", {
      id,
      name: name.trim(),
      branch,
      departmentId,
      type,
      floor,
      active,
      note,
    });
  } else {
    const { id, name, branch, note, active, icon } = draft.value;
    emit("saveDepartment", {
      id,
      name: name.trim(),
      branch,
      note,
      active,
      icon,
    });
  }
}
</script>
<template>
  <EmployeeDialog
    :class="{ 'dr-room-modal': roomMode }"
    :title="
      roomMode
        ? item
          ? 'Xonani tahrirlash'
          : 'Xona qo‘shish'
        : item
          ? 'Bo‘limni tahrirlash'
          : 'Bo‘lim qo‘shish'
    "
    :subtitle="
      roomMode
        ? 'Yangi xona ma’lumotlarini kiriting.'
        : 'Yangi bo‘lim ma’lumotlarini kiriting.'
    "
    @close="emit('close')"
    ><form class="dr-form" novalidate @submit.prevent="save">
      <label class="dr-field"
        >{{ roomMode ? "Xona nomi yoki raqami" : "Bo‘lim nomi" }} <em>*</em
        ><input
          v-model="draft.name"
          :aria-label="roomMode ? 'Xona nomi yoki raqami' : 'Bo‘lim nomi'"
          aria-required="true"
          :aria-invalid="!!errors.name"
          :placeholder="
            roomMode
              ? 'Xona nomi yoki raqamini kiriting'
              : 'Bo‘lim nomini kiriting'
          "
        /><small v-if="errors.name" class="dr-error">{{
          errors.name
        }}</small></label
      >
      <div :class="{ 'dr-field-grid': roomMode }">
        <label class="dr-field"
          >Filial <em>*</em
          ><select
            v-model="draft.branch"
            aria-label="Filial"
            aria-required="true"
            :aria-invalid="!!errors.branch"
          >
            <option value="" disabled>Tanlang</option>
            <option v-for="b in FACILITY_BRANCHES" :key="b">
              {{ b }}
            </option></select
          ><small v-if="errors.branch" class="dr-error">{{
            errors.branch
          }}</small></label
        ><label v-if="roomMode" class="dr-field"
          >Bo‘lim <em>*</em
          ><select
            v-model="draft.departmentId"
            aria-label="Bo‘lim"
            aria-required="true"
            :disabled="!draft.branch"
            :aria-invalid="!!errors.departmentId"
          >
            <option value="" disabled>Tanlang</option>
            <option v-for="d in available" :key="d.id" :value="d.id">
              {{ d.name }}
            </option></select
          ><small class="dr-help">Faqat tanlangan filial bo‘limlari.</small
          ><small v-if="errors.departmentId" class="dr-error">{{
            errors.departmentId
          }}</small></label
        >
      </div>
      <div v-if="roomMode" class="dr-field-grid">
        <label class="dr-field"
          >Xona turi <em>*</em
          ><select
            v-model="draft.type"
            aria-label="Xona turi"
            aria-required="true"
            :aria-invalid="!!errors.type"
          >
            <option value="" disabled>Tanlang</option>
            <option v-for="type in ROOM_TYPES" :key="type">
              {{ type }}
            </option></select
          ><small v-if="errors.type" class="dr-error">{{
            errors.type
          }}</small></label
        ><label class="dr-field"
          >Qavat<input
            v-model="draft.floor"
            aria-label="Qavat"
            inputmode="numeric"
            placeholder="Qavatni kiriting"
          /><small v-if="errors.floor" class="dr-error">{{
            errors.floor
          }}</small></label
        >
      </div>
      <label v-if="!roomMode" class="dr-field"
        >Izoh (ixtiyoriy)<textarea
          v-model="draft.note"
          aria-label="Izoh (ixtiyoriy)"
          placeholder="Bo‘lim haqida qisqacha ma’lumot kiriting."
          rows="3"
        /></label
      ><label class="dr-field"
        >Holati<span class="dr-status-select" :class="{ active: draft.active }"
          ><i /><select v-model="draft.active" aria-label="Holati">
            <option :value="true">Faol</option>
            <option :value="false">Faol emas</option>
          </select></span
        ></label
      ><label v-if="roomMode" class="dr-field"
        >Izoh (ixtiyoriy)<textarea
          v-model="draft.note"
          aria-label="Izoh (ixtiyoriy)"
          placeholder="Xona haqida qo‘shimcha ma’lumot kiriting."
          rows="3"
        />
      </label>
      <footer>
        <button type="button" class="e-button" @click="emit('close')">
          Bekor qilish</button
        ><button type="submit" class="e-button primary">Saqlash</button>
      </footer>
    </form></EmployeeDialog
  >
</template>
