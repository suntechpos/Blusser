<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from "vue";
import axios from "axios";
import ReceptionDialog from "./ReceptionDialog.vue";
import ReceptionPatientSearch from "./ReceptionPatientSearch.vue";
import ReceptionPatientForm from "./ReceptionPatientForm.vue";
import { canReception } from "../../services/receptionAccess";
import { can, session } from "../../services/session";
import { clinicRequest } from "../../services/api";
import { bookingCatalog, bookingError, findBookingPatients, patientChoice, localDay, localTime, dayRange, type BookingDoctor, type BookingAppointment } from "../../services/receptionBooking";
import type { Patient } from "../../services/operations";
import type { PatientDraft, ReceptionPatient } from "../../mocks/reception";

type Mode = "scheduled" | "walk_in" | "patient";
type Appointment = BookingAppointment & { version: number };
type Attendance = {
  appointment?: { id: string; status: string };
  visit: { id: string; status: string };
  ticket: { id: string; number: number; kind: string; status: string };
};
const emit = defineEmits<{
  close: [];
  saved: [result: { kind: Mode; number?: number }];
}>();
const branches = computed(() => session.branches.filter((b) => b.active));
const branchId = ref(session.branch || branches.value[0]?.id || "");
const zone = computed(() => branches.value.find((b) => b.id === branchId.value)?.timezone || "");
const today = computed(() => zone.value ? localDay(Date.now(), zone.value) : "");
const mode = ref<Mode>("walk_in");
const selected = ref<ReceptionPatient | null>(null);
const creating = ref(false);
const draft = ref<PatientDraft>({
  lastName: "", firstName: "", middleName: "", phone: "", dob: "", gender: "",
});
const errors = ref<Record<string, string>>({});
const error = ref("");
const saving = ref(false);
const completed = ref(false);
const confirmation = ref("");
const doctors = ref<BookingDoctor[]>([]);
const doctorId = ref("");
const serviceId = ref("");
const catalogLoading = ref(false);
const catalogError = ref("");
const appointments = ref<Appointment[]>([]);
const appointmentId = ref("");
const appointmentsLoading = ref(false);
const appointmentsError = ref("");
let alive = true;
let appointmentsSequence = 0;
let catalogSequence = 0;

const doctor = computed(() => doctors.value.find((d) => d.id === doctorId.value));
const services = computed(() => doctor.value?.services ?? []);
const selectedAppointment = computed(() => appointments.value.find((a) => a.id === appointmentId.value));
const canSubmit = computed(() => {
  if (saving.value || completed.value || !branchId.value) return false;
  if (creating.value) return canReception("patient");
  if (!selected.value) return false;
  if (mode.value === "patient") return canReception("patient");
  if (mode.value === "walk_in") return canReception("walkIn") && !!doctor.value && !!serviceId.value;
  return canReception("checkIn") && selectedAppointment.value?.status === "scheduled";
});

onBeforeUnmount(() => {
  alive = false;
  appointmentsSequence++;
  catalogSequence++;
});
watch(() => session.epoch, () => emit("close"));
watch(branchId, () => {
  doctorId.value = "";
  serviceId.value = "";
  appointmentId.value = "";
  error.value = "";
  completed.value = false;
  confirmation.value = "";
});
watch(doctorId, () => { serviceId.value = ""; });
watch(mode, () => {
  error.value = "";
  errors.value = {};
  completed.value = false;
  confirmation.value = "";
});
watch(selected, () => {
  appointmentId.value = "";
  error.value = "";
  errors.value = {};
  completed.value = false;
  confirmation.value = "";
});

watch([branchId, mode], async () => {
  const serial = ++catalogSequence;
  doctors.value = [];
  catalogError.value = "";
  if (mode.value === "patient" || !branchId.value) return;
  catalogLoading.value = true;
  try {
    const result = await bookingCatalog(branchId.value);
    if (alive && serial === catalogSequence) doctors.value = result.doctors;
  } catch (e) {
    if (alive && serial === catalogSequence) catalogError.value = bookingError(e);
  } finally {
    if (alive && serial === catalogSequence) catalogLoading.value = false;
  }
}, { immediate: true });

watch([() => selected.value?.id, branchId, mode, zone], async () => {
  const serial = ++appointmentsSequence;
  appointments.value = [];
  appointmentId.value = "";
  appointmentsError.value = "";
  const patientId = selected.value?.id;
  if (mode.value !== "scheduled" || !patientId || !branchId.value || !zone.value) return;
  appointmentsLoading.value = true;
  try {
    const rows: Appointment[] = [];
    for (let page = 1; ; page++) {
      const result = await clinicRequest<{ data: Appointment[]; meta: { total: number } }>(
        "patients/" + patientId + "/appointments",
        { params: { branch_id: branchId.value, ...dayRange(today.value, zone.value), page, per_page: 100 } },
      );
      rows.push(...result.data.data);
      if (rows.length >= result.data.meta.total || !result.data.data.length) break;
    }
    if (alive && serial === appointmentsSequence) appointments.value = rows;
  } catch (e) {
    if (alive && serial === appointmentsSequence) appointmentsError.value = bookingError(e);
  } finally {
    if (alive && serial === appointmentsSequence) appointmentsLoading.value = false;
  }
}, { immediate: true });

function validate(): boolean {
  errors.value = {};
  for (const field of ["lastName", "firstName", "dob"] as const) {
    if (!draft.value[field].trim()) errors.value[field] = "To‘ldiring.";
  }
  if (draft.value.dob && today.value && draft.value.dob > today.value)
    errors.value.dob = "Tug‘ilgan sana kelajakda bo‘lmasin.";
  const phone = draft.value.phone.trim().replace(/[\s().-]/g, "");
  if (phone && !/^\+?[0-9]{3,20}$/.test(phone))
    errors.value.phone = "Telefon raqamini tekshiring.";
  return !Object.keys(errors.value).length;
}

async function createPatient() {
  if (saving.value || !canReception("patient") || !validate() || !branchId.value) return;
  saving.value = true;
  error.value = "";
  try {
    const d = draft.value;
    const result = await clinicRequest<Patient>("patients", {
      method: "POST",
      body: {
        name: [d.lastName.trim(), d.firstName.trim(), d.middleName.trim()].filter(Boolean).join(" "),
        date_of_birth: d.dob,
        phone: d.phone.trim() || null,
        gender: d.gender || null,
        branch_id: branchId.value,
      },
    });
    if (!alive) return;
    selected.value = patientChoice(result.data);
    creating.value = false;
    // The selected-patient watcher clears confirmation when selection changes.
    // Wait until it has run before marking patient-only registration complete.
    // Otherwise a successful POST loses its success message and re-enables submit.
    await nextTick();
    if (!alive) return;
    if (mode.value === "patient") {
      completed.value = true;
      confirmation.value = "Bemor bazaga muvaffaqiyatli saqlandi: " + result.data.patient_number;
      emit("saved", { kind: "patient" });
    }
  } catch (e) {
    if (alive) error.value = bookingError(e);
  } finally {
    if (alive) saving.value = false;
  }
}

async function ensureBranch() {
  const patient = selected.value;
  if (!patient || mode.value === "scheduled") return;
  const ids = (patient as ReceptionPatient & { branchIds?: string[] }).branchIds;
  if (ids?.includes(branchId.value)) return;
  if (!can("patients.link_branch")) throw Error("Bemorni bu filialga biriktirish ruxsati kerak.");
  await clinicRequest("patients/" + patient.id + "/branches", {
    method: "POST", body: { branch_id: branchId.value },
  });
  if (ids) ids.push(branchId.value);
}

async function submit() {
  if (!canSubmit.value || !selected.value) return;
  saving.value = true;
  error.value = "";
  try {
    if (mode.value === "patient") {
      // Selecting an existing patient does not create a duplicate.
      completed.value = true;
      confirmation.value = "Bemor allaqachon ro‘yxatga olingan.";
      emit("saved", { kind: "patient" });
      return;
    }
    let result: Attendance;
    if (mode.value === "scheduled") {
      const appointment = selectedAppointment.value;
      if (!appointment || appointment.status !== "scheduled") return;
      const response = await clinicRequest<Attendance>(
        "appointments/" + appointment.id + "/check-in",
        { method: "POST", body: { expected_version: appointment.version } },
      );
      result = response.data;
    } else {
      if (!doctor.value || !services.value.some((s) => s.id === serviceId.value)) return;
      await ensureBranch();
      const response = await clinicRequest<Attendance>("queue-tickets", {
        method: "POST",
        body: {
          patient_id: selected.value.id,
          doctor_id: doctorId.value,
          branch_id: branchId.value,
          service_id: serviceId.value,
        },
      });
      result = response.data;
    }
    if (!alive) return;
    completed.value = true;
    confirmation.value = "Bemor navbatga qo‘shildi. Navbat raqami: " + result.ticket.number;
    emit("saved", { kind: mode.value, number: result.ticket.number });
  } catch (e) {
    if (alive) error.value = axios.isAxiosError(e) && e.response?.status === 409
      ? "Qabul yoki navbat holati o‘zgardi. Ma’lumotlarni yangilang va qayta tekshiring."
      : bookingError(e);
  } finally {
    if (alive) saving.value = false;
  }
}
</script>

<template>
  <ReceptionDialog
    title="Bemorni ro‘yxatga olish"
    subtitle="Oldindan yozilgan, jonli navbat yoki faqat ro‘yxatga olish"
    patient
    @close="!saving && emit('close')"
  >
    <div class="rp-dialog-body">
      <div v-if="confirmation" role="status" class="rp-toast">{{ confirmation }}</div>
      <p v-if="error" role="alert" class="rp-error">{{ error }}</p>
      <div class="rp-segment rp-arrival-modes" role="group" aria-label="Kelish turi">
        <button type="button" :class="{ active: mode === 'scheduled' }" :disabled="saving" @click="mode = 'scheduled'">Oldindan yozilgan</button>
        <button type="button" :class="{ active: mode === 'walk_in' }" :disabled="saving" @click="mode = 'walk_in'">Jonli navbat</button>
        <button type="button" :class="{ active: mode === 'patient' }" :disabled="saving" @click="mode = 'patient'">Faqat ro‘yxatga olish</button>
      </div>
      <fieldset :disabled="saving || completed" class="rp-arrival-fields">
        <div class="rp-form-grid">
          <label>Filial <span>*</span>
            <select v-model="branchId" aria-label="Kelish filiali">
              <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>
          </label>
        </div>
        <template v-if="creating">
          <h3>Yangi bemor qo‘shish</h3>
          <ReceptionPatientForm v-model="draft" :errors="errors" :max-date="today" />
          <button class="rp-link" type="button" @click="creating = false">Qidiruvga qaytish</button>
        </template>
        <template v-else>
          <ReceptionPatientSearch v-model="selected" :lookup="findBookingPatients" />
          <button
            v-if="!selected"
            type="button"
            class="rp-link"
            :disabled="!canReception('patient')"
            @click="creating = true"
          >+ Yangi bemor qo‘shish</button>
        </template>

        <template v-if="selected && mode === 'scheduled' && !creating">
          <section class="rp-section">
            <h3>Bugungi yozilgan qabullar</h3>
            <p v-if="appointmentsLoading" role="status">Qabullar yuklanmoqda…</p>
            <div v-else-if="appointmentsError" role="alert" class="rp-error">{{ appointmentsError }}</div>
            <template v-else-if="appointments.length">
              <label v-for="a in appointments" :key="a.id" class="rp-arrival-appointment">
                <input v-model="appointmentId" type="radio" name="arrival-appointment" :value="a.id" :disabled="a.status !== 'scheduled'" />
                <span>
                  <strong>{{ localTime(a.starts_at, zone) }} · {{ a.status === 'scheduled' ? 'Yozilgan' : a.status }}</strong>
                  <small>{{ doctors.find((d) => d.id === a.doctor_id)?.name ?? 'Shifokor' }} · {{ doctors.find((d) => d.id === a.doctor_id)?.services.find((s) => s.id === a.service_id)?.name ?? 'Qabul xizmati' }}</small>
                </span>
              </label>
            </template>
            <p v-else class="rp-empty">Bu bemorning bugungi yozilgan qabuli yo‘q.</p>
          </section>
        </template>

        <template v-if="selected && mode === 'walk_in' && !creating">
          <section class="rp-section">
            <h3>Jonli navbatga qo‘shish</h3>
            <p v-if="catalogLoading" role="status">Shifokorlar yuklanmoqda…</p>
            <p v-if="catalogError" role="alert" class="rp-error">{{ catalogError }}</p>
            <div class="rp-form-grid">
              <label>Shifokor <span>*</span>
                <select v-model="doctorId" aria-label="Jonli navbat shifokori" :disabled="catalogLoading || !!catalogError">
                  <option value="">Shifokorni tanlang</option>
                  <option v-for="d in doctors" :key="d.id" :value="d.id">{{ d.name }}</option>
                </select>
              </label>
              <label>Qabul xizmati <span>*</span>
                <select v-model="serviceId" aria-label="Jonli navbat xizmati" :disabled="!doctorId">
                  <option value="">Xizmatni tanlang</option>
                  <option v-for="s in services" :key="s.id" :value="s.id">{{ s.name }}{{ s.duration_minutes ? ' · ' + s.duration_minutes + ' daqiqa' : '' }}</option>
                </select>
              </label>
            </div>
            <p v-if="!catalogLoading && !catalogError && !doctors.length" class="rp-empty">Bu filialda faol qabul qiluvchi shifokor topilmadi.</p>
            <p class="rp-hint">Jonli navbat uchun oldindan vaqt tanlash shart emas. Raqam serverda yaratiladi.</p>
          </section>
        </template>
        <p v-if="mode === 'patient' && !creating" class="rp-hint">Faqat bemor ma’lumotlari saqlanadi. Qabul yoki navbat ochilmaydi.</p>
      </fieldset>
    </div>
    <template #footer>
      <button class="rp-button" type="button" :disabled="saving" @click="emit('close')">{{ completed ? 'Yopish' : 'Bekor qilish' }}</button>
      <button
        v-if="!completed"
        type="button"
        class="rp-button rp-primary"
        :disabled="creating ? saving || !canReception('patient') : !canSubmit"
        @click="creating ? createPatient() : submit()"
      >{{ saving ? 'Saqlanmoqda…' : creating ? 'Bemorni saqlash' : mode === 'patient' ? 'Ro‘yxatga olish' : mode === 'scheduled' ? 'Keldi — navbatga qo‘shish' : 'Jonli navbatga qo‘shish' }}</button>
    </template>
  </ReceptionDialog>
</template>
