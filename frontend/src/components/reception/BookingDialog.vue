<script setup lang="ts">
import { computed, ref, watch, onBeforeUnmount } from "vue";
import axios from "axios";
import { CalendarDays, Search } from "lucide-vue-next";
import DateField from "../employees/DateField.vue";
import BookingConfigurationDialog from "./BookingConfigurationDialog.vue";
import ReceptionDialog from "./ReceptionDialog.vue";
import ReceptionPatientSearch from "./ReceptionPatientSearch.vue";
import { uzDate, type ReceptionPatient } from "../../mocks/reception";
import { canReception } from "../../services/receptionAccess";
import { can, session } from "../../services/session";
import { clinicRequest } from "../../services/api";
import {
  bookingCatalog,
  patientChoice,
  bookingError,
  bookingAvailability,
  findBookingPatients,
  localDay,
  localTime,
  nextDay,
  type BookingDoctor,
  type BookingSlot,
  type BookingPreset,
  type BookingPatient,
  type BookingAppointment,
} from "../../services/receptionBooking";
const props = defineProps<{ preset?: BookingPreset }>();
const emit = defineEmits<{
  close: [];
  saved: [appointment: BookingAppointment, date: string];
}>();
import type { Patient } from "../../services/operations";
const configuring = ref(false),
  catalogRevision = ref(0);
const canConfigure = computed(() =>
  [
    "employees.view",
    "doctors.view",
    "doctors.create",
    "rooms.view",
    "rooms.create",
    "schedules.view",
    "schedules.create",
    "services.view",
    "services.create",
  ].every((code) => session.grants?.[code] === "entire_clinic"),
);
const mode = ref(can("patients.create") ? "new" : "existing");
const demographics = ref({
  lastName: "",
  firstName: "",
  middleName: "",
  phone: "",
  date_of_birth: "",
  gender: "",
});
const createdPatient = ref<BookingPatient>();
const patientPayload = ref<Record<string, unknown>>();
const branches = computed(() => session.branches.filter((b) => b.active));
const patient = ref<ReceptionPatient | null>(null),
  doctors = ref<BookingDoctor[]>([]),
  zone = ref("");
const draft = ref({
  branchId:
    props.preset?.branchId || session.branch || branches.value[0]?.id || "",
  departmentId: "",
  doctorId: "",
  serviceId: "",
  date: props.preset?.date || "",
  time: "",
  note: "",
});
const errors = ref<Record<string, string>>({}),
  error = ref(""),
  catalogError = ref(""),
  slotsError = ref(""),
  loading = ref(false),
  loadingSlots = ref(false),
  saving = ref(false);
const emptySlotReason = ref("");
const slots = ref<BookingSlot[]>([]),
  selectedSlot = ref<BookingSlot>(),
  future = ref(props.preset?.date ? "other" : "today");
let catalogSequence = 0,
  slotSequence = 0,
  alive = true,
  initial = true;
onBeforeUnmount(() => {
  alive = false;
  catalogSequence++;
  slotSequence++;
});
const departments = computed(() => [
  ...new Map(
    doctors.value.map((d) => [
      d.specialty_id,
      { id: d.specialty_id, name: d.specialty_name },
    ]),
  ).values(),
]);
const eligibleDoctors = computed(() =>
  doctors.value.filter((d) => d.specialty_id === draft.value.departmentId),
);
const chosenDoctor = computed(() =>
  doctors.value.find((d) => d.id === draft.value.doctorId),
);
const services = computed(
  () =>
    chosenDoctor.value?.services.map((s) => ({
      id: s.id,
      name: s.name,
      duration: s.duration_minutes,
    })) ?? [],
);
const durationLabel = computed(() => {
  const override = services.value.find(
    (s) => s.id === draft.value.serviceId,
  )?.duration;
  if (override) return override + " daqiqa";
  const durations = [
    ...new Set(
      slots.value.map(
        (s) => (Date.parse(s.ends_at) - Date.parse(s.starts_at)) / 60000,
      ),
    ),
  ];
  return durations.length
    ? durations.join(" / ") + " daqiqa"
    : "Jadval bo‘yicha";
});
const today = computed(() =>
  zone.value ? localDay(Date.now(), zone.value) : "",
);
const patientWarning = computed(() =>
  mode.value === "existing" &&
  patient.value &&
  !(patient.value as BookingPatient).branchIds?.includes(draft.value.branchId)
    ? "Bemor tanlangan filialga biriktirilmagan. Filial biriktirishini alohida tekshiring."
    : "",
);
watch(
  () => [draft.value.branchId, catalogRevision.value] as const,
  async ([branch], previous) => {
    const retained = previous?.[0] === branch ? { ...draft.value } : undefined;
    const serial = ++catalogSequence;
    doctors.value = [];
    slots.value = [];
    selectedSlot.value = undefined;
    draft.value.departmentId = "";
    draft.value.doctorId = "";
    draft.value.serviceId = "";
    zone.value = branches.value.find((b) => b.id === branch)?.timezone || "";
    loading.value = true;
    catalogError.value = "";
    if (!branch) {
      loading.value = false;
      return;
    }
    try {
      const result = await bookingCatalog(branch);
      if (!alive || serial !== catalogSequence) return;
      doctors.value = result.doctors;
      zone.value = result.timezone;
      if (!draft.value.date)
        draft.value.date = localDay(Date.now(), zone.value);
      if (retained) {
        const retainedDoctor = result.doctors.find(
          (d) => d.id === retained.doctorId,
        );
        if (retainedDoctor) {
          draft.value.departmentId = retainedDoctor.specialty_id;
          draft.value.doctorId = retainedDoctor.id;
          if (retainedDoctor.services.some((s) => s.id === retained.serviceId))
            draft.value.serviceId = retained.serviceId;
        }
      }
      if (initial) {
        const doctor = doctors.value.find(
          (d) => d.id === props.preset?.doctorId,
        );
        if (doctor) {
          draft.value.departmentId = doctor.specialty_id;
          draft.value.doctorId = doctor.id;
          if (doctor.services.some((s) => s.id === props.preset?.serviceId))
            draft.value.serviceId = props.preset!.serviceId!;
        }
        future.value =
          draft.value.date === today.value
            ? "today"
            : draft.value.date === nextDay(today.value)
              ? "tomorrow"
              : "other";
        initial = false;
      }
    } catch (e) {
      if (alive && serial === catalogSequence)
        catalogError.value = bookingError(e);
    } finally {
      if (alive && serial === catalogSequence) loading.value = false;
    }
  },
  { immediate: true },
);
watch(
  () => draft.value.departmentId,
  () => {
    if (!eligibleDoctors.value.some((d) => d.id === draft.value.doctorId))
      draft.value.doctorId = "";
  },
);
watch(
  () => draft.value.doctorId,
  () => {
    if (!services.value.some((s) => s.id === draft.value.serviceId))
      draft.value.serviceId = "";
  },
);
async function loadSlots() {
  const serial = ++slotSequence;
  slots.value = [];
  selectedSlot.value = undefined;
  draft.value.time = "";
  slotsError.value = "";
  emptySlotReason.value = "";
  const d = { ...draft.value },
    tz = zone.value;
  if (!d.doctorId || !d.serviceId || !d.date || !tz || d.date < today.value) {
    loadingSlots.value = false;
    return;
  }
  loadingSlots.value = true;
  try {
    const result = await bookingAvailability(
      d.doctorId,
      d.branchId,
      d.serviceId,
      d.date,
      tz,
    );
    if (!alive || serial !== slotSequence) return;
    slots.value = result;
    emptySlotReason.value =
      (
        {
          no_schedule: "Bu sana uchun ish jadvali sozlanmagan.",
          schedule_closed: "Bu sanada ish jadvali yopilgan.",
          room_unavailable:
            "Jadvaldagi xona faol yoki foydalanish uchun ochiq emas.",
          configuration_unavailable:
            "Shifokor yoki xizmat biriktirishi faol emas.",
        } as Record<string, string>
      )[result.unavailableReason ?? ""] ??
      "Jadval, xona va mavjud bandlikni tekshiring.";
    if (props.preset?.slot && !error.value) {
      const preset = result.find(
        (s) =>
          s.starts_at === props.preset?.slot?.starts_at &&
          s.room_id === props.preset?.slot?.room_id,
      );
      if (preset) chooseSlot(preset);
    }
  } catch (e) {
    if (alive && serial === slotSequence) slotsError.value = bookingError(e);
  } finally {
    if (alive && serial === slotSequence) loadingSlots.value = false;
  }
}
watch(
  () => [
    draft.value.branchId,
    draft.value.doctorId,
    draft.value.serviceId,
    draft.value.date,
    zone.value,
  ],
  loadSlots,
);
function chooseSlot(slot: BookingSlot) {
  selectedSlot.value = slot;
  draft.value.time = localTime(slot.starts_at, zone.value);
}
function dateMode(next: string) {
  future.value = next;
  if (next !== "other" && zone.value)
    draft.value.date = nextDay(today.value, next === "tomorrow" ? 1 : 0);
  else if (next === "other") {
    const el = document.querySelector<HTMLInputElement>(
      "#reception-booking input[type=date]",
    );
    el?.focus();
    try {
      el?.showPicker?.();
    } catch {
      /* Keyboard entry remains available. */
    }
  }
}
async function save() {
  if (!canReception("book") || saving.value) return;
  errors.value = {};
  if (mode.value === "existing" && !patient.value)
    errors.value.patient = "Bemorni tanlang.";
  if (mode.value === "new") {
    if (!can("patients.create")) return;
    if (!patientPayload.value) {
      for (const field of ["lastName", "firstName", "date_of_birth"] as const)
        if (!demographics.value[field].trim())
          errors.value[field] = "To‘ldiring.";
      if (
        demographics.value.date_of_birth > new Date().toISOString().slice(0, 10)
      )
        errors.value.date_of_birth = "Tug‘ilgan sana kelajakda bo‘lmasin.";
    }
  }
  for (const field of [
    "branchId",
    "departmentId",
    "doctorId",
    "serviceId",
  ] as const)
    if (!draft.value[field]) errors.value[field] = "Tanlang.";
  if (!draft.value.date || draft.value.date < today.value)
    errors.value.date = "O‘tgan sanaga qabul yaratib bo‘lmaydi.";
  if (
    !selectedSlot.value ||
    Date.parse(selectedSlot.value.starts_at) <= Date.now()
  )
    errors.value.time = "Bo‘sh vaqtni tanlang.";
  if (Object.keys(errors.value).length || patientWarning.value) return;
  const submittedDate = draft.value.date;
  saving.value = true;
  error.value = "";
  try {
    if (mode.value === "new" && !createdPatient.value) {
      const d = demographics.value;
      patientPayload.value ??= {
        name: [d.lastName.trim(), d.firstName.trim(), d.middleName.trim()]
          .filter(Boolean)
          .join(" "),
        date_of_birth: d.date_of_birth,
        phone: d.phone.trim() || null,
        gender: d.gender || null,
        branch_id: draft.value.branchId,
      };
      const result = await clinicRequest<Patient>("patients", {
        method: "POST",
        body: patientPayload.value,
      });
      createdPatient.value = patientChoice(result.data);
    }
    const bookedPatient =
      mode.value === "new" ? createdPatient.value : patient.value;
    const response = await clinicRequest<BookingAppointment>("appointments", {
      method: "POST",
      body: {
        patient_id: bookedPatient!.id,
        doctor_id: draft.value.doctorId,
        branch_id: draft.value.branchId,
        service_id: draft.value.serviceId,
        room_id: selectedSlot.value!.room_id,
        starts_at: selectedSlot.value!.starts_at,
        ends_at: selectedSlot.value!.ends_at,
        note: draft.value.note || null,
      },
    });
    if (alive && response.status === 201)
      emit("saved", response.data, submittedDate);
  } catch (e) {
    if (!alive) return;
    if (
      !createdPatient.value &&
      axios.isAxiosError(e) &&
      e.response?.status === 422
    )
      patientPayload.value = undefined;
    if (
      (mode.value === "existing" || createdPatient.value) &&
      axios.isAxiosError(e) &&
      e.response?.status === 409
    ) {
      error.value = "Bu vaqt hozir band qilindi. Boshqa vaqtni tanlang.";
      await loadSlots();
    } else error.value = bookingError(e);
  } finally {
    if (alive) saving.value = false;
  }
}
const summary = computed(() => chosenDoctor.value?.name);
</script>
<template>
  <ReceptionDialog
    title="Qabulga yozish"
    subtitle="Bemor, shifokor va qulay vaqtni tanlang"
    @close="!saving && emit('close')"
    ><form
      id="reception-booking"
      data-permission="appointments.create"
      class="rp-dialog-body"
      novalidate
      @submit.prevent="save"
    >
      <fieldset :disabled="saving" class="rp-booking-fields">
        <div class="rp-segment" aria-label="Bemor turi">
          <button
            type="button"
            :disabled="saving || !can('patients.create')"
            :class="{ active: mode === 'new' }"
            :aria-pressed="mode === 'new'"
            data-permission="patients.view"
            @click="can('patients.create') && (mode = 'new')"
          >
            Yangi bemor
          </button>
          <button
            type="button"
            :class="{ active: mode === 'existing' }"
            :aria-pressed="mode === 'existing'"
            :disabled="saving"
            @click="mode = 'existing'"
            data-permission="patients.view"
          >
            <Search :size="18" /> Oldin kelgan bemor
          </button>
        </div>

        <p v-if="error" class="rp-error" role="alert">{{ error }}</p>
        <button
          v-if="canConfigure"
          class="rp-button"
          type="button"
          :disabled="saving || !draft.branchId"
          data-permission="schedules.create"
          @click="configuring = true"
        >
          Qabulni sozlash
        </button>
        <p v-if="catalogError" class="rp-error" role="alert">
          {{ catalogError }}
        </p>
        <fieldset
          v-if="mode === 'new'"
          :disabled="saving || !!patientPayload"
          class="rp-section rp-new-patient"
        >
          <div class="rp-form-grid">
            <label
              >Familiya <span>*</span
              ><input
                v-model="demographics.lastName"
                aria-label="Familiya *"
                maxlength="80"
              /><small v-if="errors.lastName" class="rp-error">{{
                errors.lastName
              }}</small></label
            >
            <label
              >Ism <span>*</span
              ><input
                v-model="demographics.firstName"
                aria-label="Ism *"
                maxlength="80"
              /><small v-if="errors.firstName" class="rp-error">{{
                errors.firstName
              }}</small></label
            >
            <label
              >Telefon<input
                v-model="demographics.phone"
                aria-label="Telefon"
                type="tel"
                maxlength="32"
            /></label>
            <label
              >Tug‘ilgan sana <span>*</span
              ><DateField
                v-model="demographics.date_of_birth"
                label="Tug‘ilgan sana"
                :max="new Date().toISOString().slice(0, 10)"
              /><small v-if="errors.date_of_birth" class="rp-error">{{
                errors.date_of_birth
              }}</small></label
            >
            <label
              >Otasining ismi<input
                v-model="demographics.middleName"
                aria-label="Otasining ismi"
                maxlength="80"
            /></label>
            <label
              >Jinsi<select v-model="demographics.gender" aria-label="Jinsi">
                <option value="">Tanlang</option>
                <option value="female">Ayol</option>
                <option value="male">Erkak</option>
                <option value="unspecified">Ko‘rsatilmagan</option>
              </select></label
            >
          </div>
        </fieldset>
        <p
          v-if="mode === 'new' && createdPatient"
          class="rp-hint"
          role="status"
        >
          Bemor saqlandi: {{ createdPatient.patientNumber }}. Qabulni qayta
          saqlashda bemor takror yaratilmaydi.
        </p>
        <ReceptionPatientSearch
          v-if="mode === 'existing'"
          v-model="patient"
          :lookup="findBookingPatients"
        />
        <p v-if="patientWarning" class="rp-error" role="alert">
          {{ patientWarning }}
        </p>
        <p
          v-if="!loading && !catalogError && !doctors.length"
          class="rp-hint"
          role="status"
        >
          Tanlangan filialda faol shifokor, mutaxassislik va xizmat biriktirishi
          topilmadi. Administrator qabulni sozlashi kerak.
        </p>
        <p v-if="loading" role="status">Qabul ma’lumotlari yuklanmoqda…</p>
        <p v-if="errors.patient" class="rp-error" role="alert">
          {{ errors.patient }}
        </p>
        <section class="rp-section">
          <h3>Qabul ma’lumotlari</h3>
          <div class="rp-form-grid">
            <label
              >Filial <span>*</span
              ><select
                v-model="draft.branchId"
                aria-label="Filial *"
                :disabled="saving || (mode === 'new' && !!patientPayload)"
              >
                <option v-for="b in branches" :key="b.id" :value="b.id">
                  {{ b.name }}
                </option></select
              ><small v-if="errors.branchId" class="rp-error">{{
                errors.branchId
              }}</small></label
            ><label
              >Bo‘lim <span>*</span
              ><select v-model="draft.departmentId" aria-label="Bo‘lim *">
                <option value="">Bo‘limni tanlang</option>
                <option v-for="d in departments" :key="d.id" :value="d.id">
                  {{ d.name }}
                </option></select
              ><small v-if="errors.departmentId" class="rp-error">{{
                errors.departmentId
              }}</small></label
            ><label
              >Shifokor <span>*</span
              ><select v-model="draft.doctorId" aria-label="Shifokor *">
                <option value="">Shifokorni tanlang</option>
                <option v-for="d in eligibleDoctors" :key="d.id" :value="d.id">
                  {{ d.name }}
                </option></select
              ><small v-if="errors.doctorId" class="rp-error">{{
                errors.doctorId
              }}</small></label
            ><label
              >Qabul turi <span>*</span
              ><select v-model="draft.serviceId" aria-label="Qabul turi *">
                <option value="">Qabul turini tanlang</option>
                <option v-for="s in services" :key="s.id" :value="s.id">
                  {{ s.name }} ·
                  {{ s.duration ? s.duration + " daqiqa" : "Jadval bo‘yicha" }}
                </option></select
              ><small v-if="errors.serviceId" class="rp-error">{{
                errors.serviceId
              }}</small></label
            >
          </div>
        </section>
        <section class="rp-section rp-date-slots">
          <div>
            <h3>Qabul sanasi <span>*</span></h3>
            <div class="rp-date-modes">
              <button
                v-for="d in [
                  ['today', 'Bugun'],
                  ['tomorrow', 'Ertaga'],
                  ['other', 'Boshqa sana'],
                ]"
                :key="d[0]"
                type="button"
                :class="{ active: future === d[0] }"
                @click="dateMode(d[0]!)"
              >
                {{ d[1] }}
              </button>
            </div>
            <DateField
              label="Qabul sanasi"
              v-model="draft.date"
              :min="today"
              @update:model-value="future = 'other'"
            />
            <p class="rp-hint">
              Kalendardan istalgan kelajakdagi sanani tanlang
            </p>
            <small v-if="errors.date" class="rp-error">{{ errors.date }}</small>
          </div>
          <div>
            <h3>Bo‘sh vaqtlar <span>*</span></h3>
            <p class="rp-hint">
              {{ uzDate(draft.date) }} ·
              {{ durationLabel }}
            </p>
            <p v-if="loadingSlots" class="rp-empty" role="status">
              Bo‘sh vaqtlar yuklanmoqda…
            </p>
            <p v-else-if="slotsError" class="rp-error" role="alert">
              {{ slotsError }}
              <button type="button" class="rp-link" @click="loadSlots">
                Qayta urinish
              </button>
            </p>
            <p v-else-if="!draft.doctorId || !draft.serviceId" class="rp-empty">
              Shifokor va qabul turini tanlang.
            </p>
            <p v-else-if="!slots.length" class="rp-empty">
              Bu sanada bo‘sh vaqt yo‘q. {{ emptySlotReason }}
            </p>
            <div v-else class="rp-slots">
              <button
                v-for="s in slots"
                :key="s.starts_at + s.room_id"
                type="button"
                :aria-label="localTime(s.starts_at, zone)"
                :aria-pressed="selectedSlot === s"
                :class="{ active: selectedSlot === s }"
                :disabled="saving"
                @click="chooseSlot(s)"
              >
                {{ localTime(s.starts_at, zone) }}
                <span v-if="selectedSlot === s">✓</span>
              </button>
            </div>
            <small v-if="errors.time" class="rp-error">{{ errors.time }}</small>
          </div>
        </section>
        <label class="rp-note"
          >Izoh<textarea
            v-model="draft.note"
            placeholder="Qo‘shimcha ma’lumot"
            maxlength="2000"
          />
        </label>
        <div v-if="draft.time" class="rp-summary">
          <CalendarDays :size="21" /><span
            >{{ uzDate(draft.date) }} · {{ draft.time }} · {{ summary }}</span
          >
        </div>
        <p class="rp-hint">
          Bo‘sh vaqtlar joriy bandlikka bog‘liq. Saqlash vaqtida qayta
          tekshiriladi.
        </p>
      </fieldset>
    </form>
    <template #footer
      ><button
        class="rp-button"
        type="button"
        :disabled="saving"
        @click="emit('close')"
      >
        Bekor qilish</button
      ><button
        class="rp-button rp-primary"
        type="submit"
        form="reception-booking"
        data-permission="appointments.create"
        :disabled="
          !canReception('book') ||
          saving ||
          loading ||
          loadingSlots ||
          !!patientWarning
        "
      >
        <CalendarDays :size="19" />
        {{ saving ? "Saqlanmoqda…" : "Qabulga yozish" }}
      </button></template
    ></ReceptionDialog
  >
  <BookingConfigurationDialog
    v-if="configuring"
    :branch-id="draft.branchId"
    :date="draft.date || today"
    @close="configuring = false"
    @saved="catalogRevision++"
  />
</template>
