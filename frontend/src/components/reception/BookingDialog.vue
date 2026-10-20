<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { CalendarDays, Search } from "lucide-vue-next";
import DateField from "../employees/DateField.vue";
import ReceptionDialog from "./ReceptionDialog.vue";
import ReceptionPatientForm from "./ReceptionPatientForm.vue";
import ReceptionPatientSearch from "./ReceptionPatientSearch.vue";
import {
  blankPatient,
  blankBooking,
  branches,
  departments,
  doctors,
  services,
  availability,
  validatePatient,
  validateBooking,
  localDate,
  uzDate,
  addDays,
  searchPatients,
  type ReceptionPatient,
  type ReceptionAppointment,
  type PatientDraft,
  type BookingDraft,
} from "../../mocks/reception";
const props = defineProps<{
  patients: ReceptionPatient[];
  appointments: ReceptionAppointment[];
  preset?: Partial<BookingDraft>;
}>();
const emit = defineEmits<{
  close: [];
  save: [patient: ReceptionPatient | PatientDraft, draft: BookingDraft];
}>();
const mode = ref("new"),
  patient = ref<ReceptionPatient | null>(null),
  person = ref(blankPatient()),
  draft = ref({ ...blankBooking(), ...props.preset }),
  errors = ref<Record<string, string>>({});
const eligibleDoctors = computed(() =>
  doctors.filter(
    (d) =>
      d.branches.includes(draft.value.branchId) &&
      d.departmentId === draft.value.departmentId,
  ),
);
const schedule = computed(() => availability(draft.value, props.appointments));
const future = ref("today");
watch(
  () => draft.value.branchId,
  () => {
    if (!eligibleDoctors.value.some((d) => d.id === draft.value.doctorId))
      draft.value.doctorId = "";
  },
);
watch(
  () => draft.value.departmentId,
  () => {
    if (!eligibleDoctors.value.some((d) => d.id === draft.value.doctorId))
      draft.value.doctorId = "";
  },
);
watch(
  () => [
    draft.value.branchId,
    draft.value.departmentId,
    draft.value.doctorId,
    draft.value.date,
    draft.value.serviceId,
  ],
  () => {
    if (
      !schedule.value.slots.some((s) => s.time === draft.value.time && !s.busy)
    )
      draft.value.time = "";
  },
);
function dateMode(next: string) {
  future.value = next;
  if (next !== "other")
    draft.value.date = addDays(localDate(), next === "tomorrow" ? 1 : 0);
}
function save() {
  errors.value = {
    ...validateBooking(draft.value, props.appointments),
    ...(mode.value === "new"
      ? validatePatient(person.value)
      : !patient.value
        ? { patient: "Bemorni tanlang." }
        : {}),
  };
  if (!Object.keys(errors.value).length)
    emit("save", mode.value === "new" ? { ...person.value } : patient.value!, {
      ...draft.value,
    });
}
const matching = computed(() =>
  person.value.phone.replace(/\D/g, "").length >= 7
    ? searchPatients(props.patients, person.value.phone)
    : [],
);
async function lookup(q: string) {
  await new Promise((resolve) => setTimeout(resolve, 120));
  return searchPatients(props.patients, q);
}
const summary = computed(
  () => doctors.find((d) => d.id === draft.value.doctorId)?.fullName,
);
</script>
<template>
  <ReceptionDialog
    title="Qabulga yozish"
    subtitle="Bemor, shifokor va qulay vaqtni tanlang"
    @close="emit('close')"
    ><form
      id="reception-booking"
      class="rp-dialog-body"
      novalidate
      @submit.prevent="save"
    >
      <div class="rp-segment" aria-label="Bemor turi">
        <button
          type="button"
          :aria-pressed="mode === 'new'"
          :class="{ active: mode === 'new' }"
          @click="mode = 'new'"
        >
          Yangi bemor</button
        ><button
          type="button"
          :aria-pressed="mode === 'existing'"
          :class="{ active: mode === 'existing' }"
          @click="mode = 'existing'"
        >
          <Search :size="18" /> Oldin kelgan bemor
        </button>
      </div>
      <section v-if="mode === 'new'">
        <h3>Bemor ma’lumotlari</h3>
        <ReceptionPatientForm v-model="person" :errors="errors" />
        <div v-if="matching.length" class="rp-match" role="status">
          Shu telefon bilan mos bemorlar bor. Bu aynan shu bemor degani
          emas.<button class="rp-link" type="button" @click="mode = 'existing'">
            Mos bemorlarni tanlash</button
          ><button
            v-for="p in matching"
            :key="p.id"
            class="rp-link"
            type="button"
            @click="
              patient = p;
              mode = 'existing';
            "
          >
            {{ p.name }} · {{ p.id }}
          </button>
        </div>
      </section>
      <ReceptionPatientSearch v-else v-model="patient" :lookup="lookup" />
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
                {{ d.fullName }}
              </option></select
            ><small v-if="errors.doctorId" class="rp-error">{{
              errors.doctorId
            }}</small></label
          ><label
            >Qabul turi <span>*</span
            ><select v-model="draft.serviceId" aria-label="Qabul turi *">
              <option v-for="s in services" :key="s.id" :value="s.id">
                {{ s.name }} · {{ s.duration }} daqiqa
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
            :min="localDate()"
            @update:model-value="future = 'other'"
          />
          <p class="rp-hint">Kalendardan istalgan kelajakdagi sanani tanlang</p>
          <small v-if="errors.date" class="rp-error">{{ errors.date }}</small>
        </div>
        <div>
          <h3>Bo‘sh vaqtlar <span>*</span></h3>
          <p class="rp-hint">
            {{ uzDate(draft.date) }} ·
            {{ services.find((s) => s.id === draft.serviceId)?.duration }}
            daqiqa
          </p>
          <p v-if="!schedule.scheduled" class="rp-empty">
            Bu kun uchun shifokor jadvali belgilanmagan.
          </p>
          <template v-else
            ><p v-if="!schedule.slots.some((s) => !s.busy)" class="rp-empty">
              Bo‘sh joy qolmagan. Boshqa sana yoki shifokorni tanlang.
            </p>
            <div class="rp-slots">
              <button
                v-for="s in schedule.slots"
                :key="s.time"
                type="button"
                :disabled="s.busy"
                :aria-pressed="draft.time === s.time"
                :aria-label="`${s.time}${s.busy ? ' — Band' : ''}`"
                :class="{ active: draft.time === s.time }"
                @click="draft.time = s.time"
              >
                {{ s.time }} <span v-if="draft.time === s.time">✓</span>
              </button>
            </div>
            <p class="rp-hint">
              ○ Bo‘sh <span class="rp-busy-dot">● Band</span>
            </p></template
          ><small v-if="errors.time" class="rp-error">{{ errors.time }}</small>
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
      <p class="rp-hint rp-local-hint">
        Namuna rejimi: yozuv faqat shu sahifa xotirasida saqlanadi.
      </p>
    </form>
    <template #footer
      ><button class="rp-button" type="button" @click="emit('close')">
        Bekor qilish</button
      ><button
        class="rp-button rp-primary"
        type="submit"
        form="reception-booking"
      >
        <CalendarDays :size="19" /> Qabulga yozish
      </button></template
    ></ReceptionDialog
  >
</template>
