<script setup lang="ts">
import { computed, ref, watch } from "vue";
import ReceptionDialog from "./ReceptionDialog.vue";
import ReceptionPatientSearch from "./ReceptionPatientSearch.vue";
import ReceptionPatientForm from "./ReceptionPatientForm.vue";
import {
  branches,
  doctors,
  localDate,
  availability,
  blankBooking,
  blankPatient,
  validatePatient,
  searchPatients,
  statusLabels,
  type ReceptionPatient,
  type ReceptionAppointment,
  type PatientDraft,
} from "../../mocks/reception";
const props = defineProps<{
  patients: ReceptionPatient[];
  appointments: ReceptionAppointment[];
  preset?: ReceptionAppointment;
  createPatient: (draft: PatientDraft) => ReceptionPatient;
}>();
const emit = defineEmits<{
  close: [];
  arrive: [
    patient: ReceptionPatient,
    appointmentId: string | null,
    doctorId: string,
    branchId: string,
  ];
}>();
const selected = ref<ReceptionPatient | null>(
  props.preset
    ? (props.patients.find((p) => p.id === props.preset!.patientId) ?? null)
    : null,
);
const appointmentId = ref(props.preset?.id ?? ""),
  branchId = ref("main"),
  doctorId = ref("karimov"),
  creating = ref(false),
  person = ref(blankPatient()),
  errors = ref<Record<string, string>>({});
const todayAppointments = computed(() =>
  props.appointments.filter(
    (a) => a.patientId === selected.value?.id && a.date === localDate(),
  ),
);
const eligibleDoctors = computed(() =>
  doctors.filter(
    (d) =>
      d.branches.includes(branchId.value) &&
      availability(
        {
          ...blankBooking(),
          branchId: branchId.value,
          doctorId: d.id,
          departmentId: d.departmentId,
        },
        props.appointments,
      ).scheduled,
  ),
);
watch(selected, () => {
  appointmentId.value = "";
  errors.value = {};
});
watch(branchId, () => {
  if (!eligibleDoctors.value.some((d) => d.id === doctorId.value))
    doctorId.value = "";
});
async function lookup(q: string) {
  await new Promise((resolve) => setTimeout(resolve, 120));
  return searchPatients(props.patients, q);
}
function create() {
  errors.value = validatePatient(person.value);
  if (Object.keys(errors.value).length) return;
  selected.value = props.createPatient({ ...person.value });
  creating.value = false;
}
function arrive() {
  errors.value = {};
  if (!selected.value) {
    errors.value.patient = "Bemorni tanlang.";
    return;
  }
  if (todayAppointments.value.length) {
    const a = todayAppointments.value.find((a) => a.id === appointmentId.value);
    if (!a || a.status !== "booked") {
      errors.value.appointment =
        "Bugungi yozilgan qabulni tanlang. Navbatdagi bemor qayta qo‘shilmaydi.";
      return;
    }
    emit("arrive", selected.value, a.id, a.doctorId, a.branchId);
  } else {
    if (!eligibleDoctors.value.some((d) => d.id === doctorId.value)) {
      errors.value.doctor = "Bugungi jadvali mavjud shifokorni tanlang.";
      return;
    }
    emit("arrive", selected.value, null, doctorId.value, branchId.value);
  }
}
</script>
<template>
  <ReceptionDialog
    title="Bemorni ro‘yxatga olish"
    subtitle="Bugungi qabul yoki jonli navbatni tanlang"
    patient
    @close="emit('close')"
    ><div class="rp-dialog-body">
      <template v-if="creating"
        ><h3>Yangi bemor qo‘shish</h3>
        <ReceptionPatientForm v-model="person" :errors="errors" />
        <p class="rp-hint">Bemor faqat lokal namuna ro‘yxatiga qo‘shiladi.</p>
        <button class="rp-button" @click="creating = false">
          Qidiruvga qaytish
        </button></template
      ><template v-else
        ><ReceptionPatientSearch v-model="selected" :lookup="lookup" /><button
          v-if="!selected"
          class="rp-link"
          @click="creating = true"
        >
          + Yangi bemor qo‘shish
        </button>
        <p v-if="errors.patient" role="alert" class="rp-error">
          {{ errors.patient }}
        </p>
        <section v-if="selected" class="rp-section">
          <h3>Bugungi qabullar</h3>
          <div v-if="todayAppointments.length" class="rp-arrival-list">
            <label
              v-for="a in todayAppointments"
              :key="a.id"
              :class="{ disabled: a.status !== 'booked' }"
              ><input
                v-model="appointmentId"
                type="radio"
                name="arrival-appointment"
                :value="a.id"
                :disabled="a.status !== 'booked'"
              /><span
                ><strong
                  >{{ a.time }} ·
                  {{
                    doctors.find((d) => d.id === a.doctorId)?.fullName
                  }}</strong
                ><small
                  >{{ branches.find((b) => b.id === a.branchId)?.name }} ·
                  {{ statusLabels[a.status]
                  }}{{
                    a.status === "waiting" || a.status === "in_progress"
                      ? " — allaqachon navbatda"
                      : ""
                  }}</small
                ></span
              ></label
            ><small v-if="errors.appointment" role="alert" class="rp-error">{{
              errors.appointment
            }}</small>
          </div>
          <template v-else
            ><p class="rp-hint">
              Bugungi qabul yozuvi yo‘q. Jonli navbatni tanlang.
            </p>
            <div class="rp-form-grid">
              <label
                >Filial <span>*</span
                ><select v-model="branchId">
                  <option v-for="b in branches" :key="b.id" :value="b.id">
                    {{ b.name }}
                  </option>
                </select></label
              ><label
                >Bugungi jonli navbat <span>*</span
                ><select v-model="doctorId">
                  <option value="">Shifokorni tanlang</option>
                  <option
                    v-for="d in eligibleDoctors"
                    :key="d.id"
                    :value="d.id"
                  >
                    {{ d.fullName }} ·
                    {{
                      appointments.filter(
                        (a) =>
                          a.doctorId === d.id &&
                          a.date === localDate() &&
                          a.branchId === branchId &&
                          a.status === "waiting",
                      ).length
                    }}
                    bemor navbatda
                  </option></select
                ><small v-if="errors.doctor" role="alert" class="rp-error">{{
                  errors.doctor
                }}</small></label
              >
            </div>
            <p v-if="!eligibleDoctors.length" class="rp-empty">
              Bugungi kun uchun jonli navbat jadvali belgilanmagan.
            </p></template
          >
        </section>
        <p class="rp-hint rp-local-hint">
          Namuna rejimi: kelish va navbat faqat lokal holatda o‘zgaradi.
        </p></template
      >
    </div>
    <template #footer
      ><button class="rp-button" @click="emit('close')">Bekor qilish</button
      ><button v-if="creating" class="rp-button rp-primary" @click="create">
        Bemorni qo‘shish (lokal)</button
      ><button v-else class="rp-button rp-primary" @click="arrive">
        Keldi — navbatga qo‘shish
      </button></template
    ></ReceptionDialog
  >
</template>
