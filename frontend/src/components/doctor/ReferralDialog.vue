<script setup lang="ts">
import { computed, ref, watch } from "vue";
import {
  Building2,
  ExternalLink,
  FlaskConical,
  Monitor,
  UserRound,
  Info,
} from "lucide-vue-next";
import WorkspaceDialog from "./WorkspaceDialog.vue";
import {
  DOCTOR,
  SERVICES,
  SPECIALISTS,
  REFERRAL_TYPES,
  doctorKey,
  referralErrors,
  sanitizeReferral,
  type Encounter,
  type Referral,
} from "../../mocks/doctorWorkspace";
import { dateLabel } from "../../mocks/employees";
const props = defineProps<{ encounter: Encounter }>();
const emit = defineEmits<{ close: []; save: [Referral] }>();
const draft = ref<Referral>({
  id: `Y-${Date.now()}`,
  encounterKey: doctorKey(props.encounter),
  location: "internal",
  type: "Diagnostika",
  service: "",
  branch: "",
  specialist: "",
  clinic: "",
  note: "",
});
const initial = JSON.stringify(draft.value);
const dirty = computed(() => JSON.stringify(draft.value) !== initial);
const busy = ref(false);
const errors = ref<Record<string, string>>({});
const services = computed(() =>
  SERVICES.filter((s) => s.type === draft.value.type),
);
const specialists = computed(() =>
  SPECIALISTS.filter(
    (s) =>
      s.branch === draft.value.branch &&
      s.services.includes(draft.value.service),
  ),
);
const service = computed(() =>
  SERVICES.find((s) => s.id === draft.value.service),
);
watch(
  () => draft.value.type,
  () => {
    draft.value.service = "";
    draft.value.specialist = "";
  },
);
watch([() => draft.value.branch, () => draft.value.service], () => {
  if (!specialists.value.some((s) => s.name === draft.value.specialist))
    draft.value.specialist = "";
});
watch(
  () => draft.value.location,
  () => {
    draft.value.branch = "";
    draft.value.specialist = "";
    draft.value.clinic = "";
    errors.value = {};
  },
);
function cancel(event: MouseEvent) {
  (event.target as HTMLElement)
    .closest("dialog")
    ?.dispatchEvent(new Event("cancel", { cancelable: true }));
}
function save() {
  if (busy.value) return;
  errors.value = referralErrors(draft.value);
  if (!Object.keys(errors.value).length) {
    busy.value = true;
    emit("save", sanitizeReferral({ ...draft.value }));
  }
}
</script>
<template>
  <WorkspaceDialog
    :dirty="dirty"
    class="dw-referral-modal"
    title="Yo‘llanma yaratish"
    subtitle="Bemorni tekshiruv yoki konsultatsiyaga yuborish"
    @close="emit('close')"
    ><div class="dw-modal-body">
      <div class="dw-referral-patient">
        <div class="dw-patient-line">
          <span class="dw-avatar" :class="encounter.color">{{
            encounter.initials
          }}</span>
          <div>
            <h3>{{ encounter.name }}</h3>
            <p>
              {{ encounter.patientId }} · {{ encounter.gender }} ·
              {{ encounter.age }} yosh
            </p>
          </div>
        </div>
        <div class="dw-referral-context">
          <div>
            Yo‘llanma bergan shifokor<strong>{{ DOCTOR.name }}</strong>
          </div>
          <div>
            Qabul<strong
              >{{ dateLabel(encounter.date) }} ·
              {{ encounter.time || "Jonli navbat" }}</strong
            >
          </div>
        </div>
      </div>
      <form novalidate @submit.prevent="save">
        <fieldset>
          <legend>Qayerda o‘tkaziladi? <em>*</em></legend>
          <div class="dw-options">
            <label :class="{ selected: draft.location === 'internal' }"
              ><Building2 :size="24" /><span>Bizning klinikada</span
              ><input
                v-model="draft.location"
                type="radio"
                name="location"
                value="internal" /></label
            ><label :class="{ selected: draft.location === 'external' }"
              ><ExternalLink :size="23" /><span>Boshqa klinikada</span
              ><input
                v-model="draft.location"
                type="radio"
                name="location"
                value="external"
            /></label>
          </div>
        </fieldset>
        <fieldset>
          <legend>Yo‘llanma turi <em>*</em></legend>
          <div class="dw-options types">
            <label
              v-for="(type, i) in REFERRAL_TYPES"
              :key="type"
              :class="{ selected: draft.type === type }"
              ><component
                :is="[FlaskConical, Monitor, UserRound][i]"
                :size="24" /><span>{{ type }}</span
              ><input
                v-model="draft.type"
                type="radio"
                name="referral-type"
                :value="type"
            /></label>
          </div>
        </fieldset>
        <label class="dw-field"
          >Tekshiruv / xizmat <em>*</em
          ><select v-model="draft.service" aria-label="Tekshiruv / xizmat">
            <option value="" disabled>Tekshiruv yoki xizmatni tanlang</option>
            <option v-for="s in services" :key="s.id" :value="s.id">
              {{ s.name }}
            </option></select
          ><small v-if="errors.service" class="dw-error">{{
            errors.service
          }}</small></label
        >
        <div v-if="draft.location === 'internal'" class="dw-grid-2">
          <label class="dw-field"
            >Filial <em>*</em
            ><select v-model="draft.branch" aria-label="Filial">
              <option value="" disabled>Tanlang</option>
              <option>Asosiy filial</option>
              <option>Yunusobod</option></select
            ><small v-if="errors.branch" class="dw-error">{{
              errors.branch
            }}</small></label
          ><label class="dw-field"
            >Mutaxassis<select
              v-model="draft.specialist"
              aria-label="Mutaxassis"
            >
              <option value="">Ixtiyoriy — tanlang</option>
              <option v-for="s in specialists" :key="s.name">
                {{ s.name }}
              </option></select
            ><small>Tanlanmasa, registratura belgilaydi.</small
            ><small v-if="errors.specialist" class="dw-error">{{
              errors.specialist
            }}</small></label
          >
        </div>
        <label v-else class="dw-field"
          >Klinika nomi (ixtiyoriy)<input
            v-model="draft.clinic"
            aria-label="Klinika nomi"
            placeholder="Klinika nomini kiriting"
          /><small
            >Klinika hali tanlanmagan bo‘lsa, bo‘sh qoldirish mumkin.</small
          ></label
        ><label class="dw-field"
          >Yo‘llanma sababi / izoh<textarea
            v-model="draft.note"
            aria-label="Yo‘llanma sababi / izoh"
            rows="2"
            placeholder="Tekshiruv maqsadi va mutaxassisga izoh yozing..."
          />
        </label>
        <div class="dw-info">
          <Info :size="20" /><span v-if="draft.location === 'internal'"
            >Demo yo‘llanma
            {{ service?.department || "tanlangan xizmat" }} uchun xotirada
            saqlanadi. Haqiqiy bo‘limga yuborilmaydi; sana/vaqt band
            qilinmaydi.</span
          ><span v-else
            >Demo yo‘llanmani PDF/chop etish mumkin.<br />Ichki bo‘limlarga
            buyurtma yuborilmaydi.</span
          >
        </div>
        <footer>
          <button class="e-button" type="button" @click="cancel($event)">
            Bekor qilish</button
          ><button class="e-button primary" type="submit">
            Yo‘llanma yaratish
          </button>
        </footer>
      </form>
    </div></WorkspaceDialog
  >
</template>
