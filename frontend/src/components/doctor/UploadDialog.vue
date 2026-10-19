<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { UploadCloud, X, FileText } from "lucide-vue-next";
import DateField from "../employees/DateField.vue";
import WorkspaceDialog from "./WorkspaceDialog.vue";
import PatientStrip from "./PatientStrip.vue";
import {
  DOCTOR,
  doctorKey,
  type Encounter,
  type Referral,
  type DocumentRow,
} from "../../mocks/doctorWorkspace";
import { dateLabel } from "../../mocks/employees";
const props = defineProps<{
  encounter: Encounter;
  encounters: Encounter[];
  referrals: Referral[];
  externalResult?: boolean;
  initialReferral?: string;
}>();
const emit = defineEmits<{ close: []; save: [DocumentRow, boolean] }>();
const file = ref<File>();
const name = ref(""),
  type = ref(props.externalResult ? "Tashqi natija" : ""),
  date = ref(props.encounter.date),
  visit = ref(""),
  referral = ref(props.initialReferral || ""),
  clinic = ref(""),
  note = ref("");
const errors = ref<Record<string, string>>({}),
  busy = ref(false);
for (const [key, value] of Object.entries({
  name,
  type,
  date,
  visit,
  referral,
}))
  watch(value, () => {
    delete errors.value[key];
  });
const dirty = computed(
  () =>
    !!file.value ||
    !!name.value ||
    !!clinic.value ||
    !!note.value ||
    !!visit.value ||
    referral.value !== (props.initialReferral || "") ||
    date.value !== props.encounter.date ||
    type.value !== (props.externalResult ? "Tashqi natija" : ""),
);
const matchingReferrals = computed(() =>
  props.referrals.filter(
    (r) =>
      !visit.value ||
      r.encounterKey ===
        doctorKey(props.encounters.find((e) => e.id === visit.value)!),
  ),
);
function visitChange() {
  if (
    referral.value &&
    !matchingReferrals.value.some((r) => r.id === referral.value)
  )
    referral.value = "";
}
function referralChange() {
  const r = props.referrals.find((r) => r.id === referral.value);
  if (r)
    visit.value =
      props.encounters.find((e) => doctorKey(e) === r.encounterKey)?.id || "";
}
function choose(f?: File) {
  errors.value.file = "";
  file.value = undefined;
  if (!f) return;
  const ext = f.name.split(".").pop()?.toLowerCase();
  const expected =
    ext === "pdf"
      ? "application/pdf"
      : ext === "png"
        ? "image/png"
        : ["jpg", "jpeg"].includes(ext || "")
          ? "image/jpeg"
          : "";
  if (!expected || f.type !== expected) {
    errors.value.file = "Faqat PDF, JPG yoki PNG faylni tanlang.";
    return;
  }
  if (f.size > 20 * 1024 * 1024 || f.size === 0) {
    errors.value.file = "Fayl bo‘sh bo‘lmasin va 20 MB dan oshmasin.";
    return;
  }
  file.value = f;
  if (!name.value) name.value = f.name.replace(/\.[^.]+$/, "");
}
if (referral.value) referralChange();
function save() {
  if (busy.value) return;
  const e: Record<string, string> = {};
  if (!file.value) e.file = errors.value.file || "Faylni tanlang.";
  if (!name.value.trim()) e.name = "Hujjat nomini kiriting.";
  if (!type.value) e.type = "Hujjat turini tanlang.";
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date.value)) e.date = "Sanani kiriting.";
  if (visit.value && !props.encounters.some((x) => x.id === visit.value))
    e.visit = "Qabul shu bemorga tegishli bo‘lsin.";
  if (
    referral.value &&
    !matchingReferrals.value.some((r) => r.id === referral.value)
  )
    e.referral = "Qabul va yo‘llanma mos bo‘lsin.";
  errors.value = e;
  if (Object.keys(e).length) return;
  busy.value = true;
  emit(
    "save",
    {
      id: crypto.randomUUID(),
      name: name.value.trim(),
      type: type.value,
      format:
        file.value!.type === "application/pdf"
          ? "PDF"
          : file.value!.type === "image/png"
            ? "PNG"
            : "JPG",
      size: `${Math.ceil(file.value!.size / 1024)} KB`,
      date: date.value,
      author: DOCTOR.name,
      encounter: visit.value,
      external: true,
      blob: file.value,
      referral: referral.value,
      clinic: clinic.value,
      note: note.value,
    },
    props.externalResult || type.value === "Tashqi natija",
  );
}
</script>
<template>
  <WorkspaceDialog
    class="dw-upload-modal"
    title="Hujjat yuklash"
    subtitle="Bemor kartasiga fayl biriktirish"
    :dirty="dirty"
    @close="emit('close')"
    ><div class="dw-modal-body">
      <PatientStrip :encounter="encounter" />
      <form id="dw-upload-form" novalidate @submit.prevent="save">
        <label class="dw-field">Fayl <em>*</em></label>
        <div
          class="dw-dropzone"
          @dragover.prevent
          @drop.prevent="choose($event.dataTransfer?.files[0])"
        >
          <UploadCloud :size="43" />
          <p>Faylni shu yerga tashlang yoki tanlang</p>
          <label class="e-button"
            >Fayl tanlash<input
              type="file"
              aria-label="Fayl tanlash"
              accept=".pdf,.jpg,.jpeg,.png"
              @change="
                choose(($event.target as HTMLInputElement).files?.[0])
              " /></label
          ><small>PDF, JPG, PNG · Maksimal 20 MB</small>
        </div>
        <small v-if="errors.file" class="dw-error" role="alert">{{
          errors.file
        }}</small>
        <div v-if="file" class="dw-result-file">
          <FileText :size="28" /><span
            >{{ file.name
            }}<small>{{ Math.ceil(file.size / 1024) }} KB</small></span
          ><button
            class="e-icon"
            type="button"
            aria-label="Faylni olib tashlash"
            @click="file = undefined"
          >
            <X :size="20" />
          </button>
        </div>
        <label class="dw-field"
          >Hujjat nomi <em>*</em
          ><input v-model="name" aria-label="Hujjat nomi" /><small
            class="dw-error"
            >{{ errors.name }}</small
          ></label
        >
        <div class="dw-grid-2">
          <label class="dw-field"
            >Hujjat turi <em>*</em
            ><select v-model="type" aria-label="Hujjat turi">
              <option value="">Tanlang</option>
              <option
                v-for="t in [
                  'Tashqi xulosa',
                  'Tashqi natija',
                  'Laboratoriya javobi',
                  'UZI natijasi',
                  'Tahlil natijasi',
                  'Konsultatsiya xulosasi',
                  'Boshqa',
                ]"
                :key="t"
              >
                {{ t }}
              </option></select
            ><small v-if="errors.type" class="dw-error">{{
              errors.type
            }}</small></label
          ><label class="dw-field"
            >Hujjat sanasi <em>*</em
            ><DateField v-model="date" label="Hujjat sanasi" /><small
              v-if="errors.date"
              class="dw-error"
              >{{ errors.date }}</small
            ></label
          ><label class="dw-field"
            >Tegishli qabul<select
              v-model="visit"
              aria-label="Tegishli qabul"
              @change="visitChange"
            >
              <option value="">Ixtiyoriy — tanlang</option>
              <option v-for="e in encounters" :key="e.id" :value="e.id">
                {{ dateLabel(e.date) }} · {{ e.time }} · {{ DOCTOR.name }}
              </option></select
            ><small v-if="errors.visit" class="dw-error">{{
              errors.visit
            }}</small></label
          ><label class="dw-field"
            >Tegishli yo‘llanma<select
              v-model="referral"
              aria-label="Tegishli yo‘llanma"
              @change="referralChange"
            >
              <option value="">Ixtiyoriy — tanlang</option>
              <option v-for="r in matchingReferrals" :key="r.id" :value="r.id">
                {{ r.id }}
              </option></select
            ><small v-if="errors.referral" class="dw-error">{{
              errors.referral
            }}</small></label
          >
        </div>
        <small>Faqat shu bemorning qabullari va yo‘llanmalari.</small
        ><label class="dw-field"
          >Klinika / laboratoriya nomi<input v-model="clinic" /></label
        ><label class="dw-field"
          >Izoh<textarea v-model="note" rows="2" />
        </label>
      </form>
    </div>
    <template #footer="{ close }"
      ><footer>
        <button class="e-button" @click="close">Bekor qilish</button
        ><button
          form="dw-upload-form"
          class="e-button primary"
          :disabled="busy"
        >
          Yuklash
        </button>
      </footer></template
    ></WorkspaceDialog
  >
</template>
