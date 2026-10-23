<script setup lang="ts">
import { computed, ref, watch, onBeforeUnmount } from "vue";
import axios from "axios";
import { CalendarDays, Search } from "lucide-vue-next";
import DateField from "../employees/DateField.vue";
import ReceptionDialog from "./ReceptionDialog.vue";
import ReceptionPatientSearch from "./ReceptionPatientSearch.vue";
import { uzDate, type ReceptionPatient } from "../../mocks/reception";
import { canReception } from "../../services/receptionAccess";
import { session } from "../../services/session";
import { clinicRequest, errorText } from "../../services/api";
import {
  bookingCatalog,
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
  patient.value &&
  !(patient.value as BookingPatient).branchIds?.includes(draft.value.branchId)
    ? "Bemor tanlangan filialga biriktirilmagan. Filial biriktirishini alohida tekshiring."
    : "",
);
watch(
  () => draft.value.branchId,
  async (branch) => {
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
        catalogError.value = errorText(e);
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
    if (props.preset?.slot && !error.value) {
      const preset = result.find(
        (s) =>
          s.starts_at === props.preset?.slot?.starts_at &&
          s.room_id === props.preset?.slot?.room_id,
      );
      if (preset) chooseSlot(preset);
    }
  } catch (e) {
    if (alive && serial === slotSequence) slotsError.value = errorText(e);
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
  if (!patient.value) errors.value.patient = "Bemorni tanlang.";
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
    const response = await clinicRequest<BookingAppointment>("appointments", {
      method: "POST",
      body: {
        patient_id: patient.value!.id,
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
    if (axios.isAxiosError(e) && e.response?.status === 409) {
      error.value = "Bu vaqt hozir band qilindi. Boshqa vaqtni tanlang.";
      await loadSlots();
    } else error.value = errorText(e);
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
      <div class="rp-segment" aria-label="Bemor turi">
        <button
          type="button"
          disabled
          title="Yangi bemorni ro‘yxatga olish keyingi bosqichda"
        >
          Yangi bemor
        </button>
        <button
          type="button"
          class="active"
          aria-pressed="true"
          data-permission="patients.view"
        >
          <Search :size="18" /> Oldin kelgan bemor
        </button>
      </div>
      <p class="rp-hint">Yangi bemorni ro‘yxatga olish keyingi bosqichda.</p>
      <p v-if="error" class="rp-error" role="alert">{{ error }}</p>
      <p v-if="catalogError" class="rp-error" role="alert">
        {{ catalogError }}
      </p>
      <ReceptionPatientSearch v-model="patient" :lookup="findBookingPatients" />
      <p v-if="patientWarning" class="rp-error" role="alert">
        {{ patientWarning }}
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
            ><select v-model="draft.branchId" aria-label="Filial *">
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
          <p class="rp-hint">Kalendardan istalgan kelajakdagi sanani tanlang</p>
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
            Bu sanada qabul mavjud emas.
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
</template>
