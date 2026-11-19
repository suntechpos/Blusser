<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from "vue";
import axios from "axios";
import { CalendarDays, Users, FilePlus, UserRound, Stethoscope, Info, MapPin } from "lucide-vue-next";
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
type PrintableTicket = {
  number: number;
  patientName: string;
  doctorName: string;
  serviceName: string;
  roomName?: string;
  date: string;
  time: string;
};
const emit = defineEmits<{
  close: [];
  saved: [result: { kind: Mode; number?: number; numbers?: number[]; branchName?: string; tickets?: PrintableTicket[] }];
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
const roomNames = ref<Record<string, string>>({});
const appointmentIds = ref<string[]>([]);
const reloadAppointments = ref(0);
const eligibleAppointments = computed(() => appointments.value.filter(a => a.status === "scheduled"));
const selectedAppointments = computed(() => eligibleAppointments.value.filter(a => appointmentIds.value.includes(a.id)));
const allSelected = computed(() => eligibleAppointments.value.length > 0 && selectedAppointments.value.length === eligibleAppointments.value.length);
function toggleAll() { appointmentIds.value = allSelected.value ? [] : eligibleAppointments.value.map(a => a.id); }
const statusLabel = (status: string) => (({ scheduled: "Yozilgan", checked_in: "Kelgan", in_progress: "Qabulda", completed: "Yakunlangan", cancelled: "Bekor qilingan", no_show: "Kelmagan" } as Record<string, string>)[status] ?? status);
const appointmentsLoading = ref(false);
const appointmentsError = ref("");
let alive = true;
let appointmentsSequence = 0;
let catalogSequence = 0;

const doctor = computed(() => doctors.value.find((d) => d.id === doctorId.value));
const services = computed(() => doctor.value?.services ?? []);

const canSubmit = computed(() => {
  if (saving.value || completed.value || !branchId.value) return false;
  if (creating.value) return canReception("patient");
  if (!selected.value) return false;
  if (mode.value === "patient") return canReception("patient");
  if (mode.value === "walk_in") return canReception("walkIn") && !!doctor.value && !!serviceId.value;
  return canReception("checkIn") && selectedAppointments.value.length > 0 && !appointmentsLoading.value && !appointmentsError.value;
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
  appointmentIds.value = [];
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
  appointmentIds.value = [];
  error.value = "";
  errors.value = {};
  completed.value = false;
  confirmation.value = "";
});

let roomSequence = 0;
watch([branchId, mode], async () => {
  const serial = ++roomSequence;
  roomNames.value = {};
  if (mode.value !== "scheduled" || !branchId.value || !can("rooms.view")) return;
  try {
    const names: Record<string, string> = {};
    for (let page = 1; ; page++) {
      const { data } = await clinicRequest<{ data: { id: string; number: string }[]; meta: { total: number } }>("rooms", { params: { branch_id: branchId.value, page, per_page: 100 } });
      if (!alive || serial !== roomSequence) return;
      for (const room of data.data) names[room.id] = room.number;
      if (Object.keys(names).length >= data.meta.total || !data.data.length) break;
    }
    if (alive && serial === roomSequence) roomNames.value = names;
  } catch { /* Room labels are optional; check-in does not require room catalogue access. */ }
}, { immediate: true });

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

watch([() => selected.value?.id, branchId, mode, zone, reloadAppointments], async () => {
  const serial = ++appointmentsSequence;
  appointments.value = [];
  appointmentIds.value = [];
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
      const chosen = [...selectedAppointments.value].sort((a,b) => a.starts_at.localeCompare(b.starts_at));
      const successful: number[] = [];
      const printable: PrintableTicket[] = [];
      const failed: string[] = [];
      for (const appointment of chosen) {
        if (!alive) return;
        try {
          const response = await clinicRequest<Attendance>(
            "appointments/" + appointment.id + "/check-in",
            { method: "POST", body: { expected_version: appointment.version } },
          );
          successful.push(response.data.ticket.number);
          const assignedDoctor = doctors.value.find(d => d.id === appointment.doctor_id);
          printable.push({
            number: response.data.ticket.number,
            patientName: selected.value.name,
            doctorName: assignedDoctor?.name ?? "Shifokor",
            serviceName: assignedDoctor?.services.find(s => s.id === appointment.service_id)?.name ?? "Qabul xizmati",
            roomName: roomNames.value[appointment.room_id] ? (/xona|kabinet/i.test(roomNames.value[appointment.room_id]!) ? roomNames.value[appointment.room_id] : roomNames.value[appointment.room_id] + "-xona") : undefined,
            date: localDay(Date.parse(appointment.starts_at), zone.value),
            time: localTime(appointment.starts_at, zone.value),
          });
          appointments.value = appointments.value.map(a => a.id === appointment.id ? { ...a, status: "checked_in" } : a);
          appointmentIds.value = appointmentIds.value.filter(id => id !== appointment.id);
        } catch (e) { failed.push(localTime(appointment.starts_at, zone.value) + " — " + bookingError(e)); }
      }
      if (!alive) return;
      if (successful.length) {
        confirmation.value = successful.length === 1 ? "Bemor navbatga qo‘shildi. Navbat raqami: " + successful[0] : successful.length + " ta qabul navbatga qo‘shildi. Navbat raqamlari: " + successful.join(", ");
        emit("saved", { kind: "scheduled", number: successful[0]!, numbers: successful, branchName: branches.value.find(b => b.id === branchId.value)?.name ?? "", tickets: printable });
      }
      if (failed.length) {
        error.value = failed.join(" | ") + ". Saqlangan qabullar takror yaratilmaydi.";
        reloadAppointments.value++;
      } else {
        completed.value = true;
        emit("close");
      }
      return;
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
    emit("saved", {
      kind: mode.value,
      number: result.ticket.number,
      branchName: branches.value.find(b => b.id === branchId.value)?.name ?? "",
      tickets: [{
        number: result.ticket.number,
        patientName: selected.value.name,
        doctorName: doctor.value?.name ?? "Shifokor",
        serviceName: services.value.find(s => s.id === serviceId.value)?.name ?? "Qabul xizmati",
        date: today.value,
        time: localTime(Date.now(), zone.value),
      }],
    });
    if (mode.value === "walk_in") emit("close");
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
        <button type="button" :class="{ active: mode === 'scheduled' }" :disabled="saving" @click="mode = 'scheduled'"><CalendarDays :size="18" /> Oldindan yozilgan</button>
        <button type="button" :class="{ active: mode === 'walk_in' }" :disabled="saving" @click="mode = 'walk_in'"><Users :size="18" /> Jonli navbat</button>
        <button type="button" :class="{ active: mode === 'patient' }" :disabled="saving" @click="mode = 'patient'"><FilePlus :size="18" /> Faqat ro‘yxatga olish</button>
      </div>
      <fieldset :disabled="saving || completed" class="rp-arrival-fields">
        <div class="rp-form-grid rp-arrival-branch">
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
            <div class="rp-arrival-section-heading">
              <h3>Bugungi yozilgan qabullar</h3>
              <label v-if="!appointmentsLoading && !appointmentsError && eligibleAppointments.length" class="rp-arrival-select-all">
                <input type="checkbox" :checked="allSelected" :indeterminate.prop="selectedAppointments.length > 0 && !allSelected" @change="toggleAll" />
                <span>Barchasini tanlash</span>
              </label>
            </div>
            <p v-if="appointmentsLoading" role="status">Qabullar yuklanmoqda…</p>
            <div v-else-if="appointmentsError" role="alert" class="rp-error">{{ appointmentsError }}</div>
            <template v-else-if="appointments.length">
              <div class="rp-arrival-cards">
                <label v-for="a in appointments" :key="a.id" class="rp-arrival-appointment" :class="{ selected: appointmentIds.includes(a.id), unavailable: a.status !== 'scheduled' }">
                  <input v-model="appointmentIds" type="checkbox" name="arrival-appointment" :value="a.id" :disabled="a.status !== 'scheduled'" />
                  <div class="rp-arrival-card-main">
                    <div class="rp-arrival-card-top">
                      <strong class="rp-arrival-time">{{ localTime(a.starts_at, zone) }}</strong>
                      <span class="rp-arrival-status" :class="a.status"><CalendarDays :size="14" /> {{ statusLabel(a.status) }}</span>
                    </div>
                    <div class="rp-arrival-card-details">
                      <span class="rp-arrival-doctor"><UserRound :size="16" /><strong>{{ doctors.find(d => d.id === a.doctor_id)?.name ?? 'Shifokor' }}</strong></span>
                      <span class="rp-arrival-service"><Stethoscope :size="16" />{{ doctors.find(d => d.id === a.doctor_id)?.services.find(s => s.id === a.service_id)?.name ?? 'Qabul xizmati' }}</span>
                    <span v-if="roomNames[a.room_id]" class="rp-arrival-room"><MapPin :size="14" />{{ /xona|kabinet/i.test(roomNames[a.room_id]!) ? roomNames[a.room_id] : roomNames[a.room_id] + '-xona' }}</span>
                    </div>
                  </div>
                </label>
              </div>
              <p class="rp-arrival-info"><Info :size="16" /> Bir bemor bir kunda bir nechta yozuvga kelgan deb belgilanishi mumkin.</p>
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
      >{{ saving ? 'Saqlanmoqda…' : creating ? 'Bemorni saqlash' : mode === 'patient' ? 'Ro‘yxatga olish' : mode === 'scheduled' ? (selectedAppointments.length > 1 ? 'Keldi — ' + selectedAppointments.length + ' ta navbatga qo‘shish' : 'Keldi — navbatga qo‘shish') : 'Jonli navbatga qo‘shish' }}</button>
    </template>
  </ReceptionDialog>
</template>
