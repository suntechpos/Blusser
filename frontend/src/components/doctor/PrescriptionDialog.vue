<script setup lang="ts">
import { ref, nextTick, computed } from "vue";
import { Plus, Trash2, Clock3, Link } from "lucide-vue-next";
import WorkspaceDialog from "./WorkspaceDialog.vue";
import PatientStrip from "./PatientStrip.vue";
import {
  blankMedicine,
  prescriptionErrors,
  type Prescription,
  type Encounter,
} from "../../mocks/doctorWorkspace";
const props = defineProps<{
  encounter: Encounter;
  prescription?: Prescription;
  saving?: boolean;
  error?: string;
}>();
const emit = defineEmits<{ close: []; save: [Prescription] }>();

const medicines = ref(
  props.prescription
    ? structuredClone(props.prescription.medicines.map((x) => ({ ...x })))
    : [blankMedicine()],
);
const note = ref(props.prescription?.note || "");
const initial = JSON.stringify({
  medicines: medicines.value,
  note: note.value,
});
const dirty = computed(
  () =>
    initial !==
    JSON.stringify({ medicines: medicines.value, note: note.value }),
);
const errors = ref<Record<string, string>[]>([]);

async function add() {
  medicines.value.push(blankMedicine());
  await nextTick();
  document
    .querySelectorAll<HTMLInputElement>(".dw-medicine-name")
    .item(medicines.value.length - 1)
    ?.focus();
}
function save() {
  if (busy.value) return;
  errors.value = prescriptionErrors(medicines.value);
  if (
    !medicines.value.length ||
    errors.value.some((e) => Object.keys(e).length)
  )
    return;
  emit("save", {
    id: props.prescription?.id || "",
    date: props.encounter.date,
    medicines: medicines.value.map((m) => ({ ...m })),
    note: note.value,
  });
}
const fields = [
  { key: "strength", label: "Preparat kuchi", placeholder: "Qiymat va birlik" },
  {
    key: "dose",
    label: "Bir martalik doza",
    placeholder: "Dozani kiriting",
    required: true,
  },
  {
    key: "unit",
    label: "Doza birligi",
    options: ["mg", "g", "ml", "tabletka", "tomchi", "birlik"],
    required: true,
  },
  {
    key: "route",
    label: "Qabul qilish yo‘li",
    options: ["Og‘iz orqali", "Mahalliy", "Inyeksiya", "Boshqa"],
  },
  {
    key: "frequency",
    label: "Qabul tartibi",
    placeholder: "Qabul tartibini kiriting",
    required: true,
  },
  {
    key: "duration",
    label: "Davomiyligi",
    placeholder: "Davomiylikni kiriting",
    required: true,
  },
  {
    key: "instruction",
    label: "Qo‘shimcha ko‘rsatma",
    placeholder: "Ovqatga bog‘liqligi va boshqa izohlar",
  },
] as const;
</script>
<template>
  <WorkspaceDialog
    class="dw-prescription-modal"
    :class="{ 'dw-multiple': medicines.length > 1 }"
    title="Retsept yozish"
    subtitle="Dori vositalari va qabul qilish tartibini kiriting"
    :dirty="dirty"
    @close="emit('close')"
  >
    <div class="dw-modal-body">
      <PatientStrip :encounter="encounter" context />
      <div class="dw-allergy">
        <Clock3 :size="18" /><span
          ><b>Allergiya:</b>
          {{ encounter.allergy || "Ma’lumot kiritilmagan" }}</span
        >
      </div>
      <form id="dw-rx-form" novalidate @submit.prevent="save">
        <section v-for="(m, i) in medicines" :key="i" class="dw-medicine">
          <header>
            <h3>{{ i + 1 }}-dori</h3>
            <button
              type="button"
              class="e-icon"
              :aria-label="`${i + 1}-dorini olib tashlash`"
              @click="medicines.splice(i, 1)"
            >
              <Trash2 :size="20" />
            </button>
          </header>
          <div class="dw-med-grid">
            <label class="dw-field dw-med-name"
              >Dori nomi <em>*</em
              ><input
                v-model="m.name"
                class="dw-medicine-name"
                :aria-label="`${i + 1}-dori nomi`"
                placeholder="Dori nomini qidiring yoki yozing..."
              /><small v-if="errors[i]?.name" class="dw-error">{{
                errors[i]?.name
              }}</small></label
            ><label class="dw-field"
              >Shakli<select v-model="m.form">
                <option value="">Tanlang</option>
                <option>Tabletka</option>
                <option>Kapsula</option>
                <option>Eritma</option>
                <option>Boshqa</option>
              </select></label
            >
            <label
              v-for="f in fields"
              :key="f.key"
              class="dw-field"
              :class="`dw-med-${f.key}`"
              >{{ f.label }} <em v-if="'required' in f">*</em
              ><select
                v-if="'options' in f"
                v-model="m[f.key]"
                :aria-label="`${i + 1}-dori ${f.label}`"
              >
                <option value="">Tanlang</option>
                <option v-for="o in f.options" :key="o">{{ o }}</option></select
              ><input
                v-else
                v-model="m[f.key]"
                :aria-label="`${i + 1}-dori ${f.label}`"
                :placeholder="f.placeholder"
                :list="f.key === 'frequency' ? 'dw-frequency' : undefined"
              /><small v-if="errors[i]?.[f.key]" class="dw-error">{{
                errors[i]?.[f.key]
              }}</small></label
            >
          </div>
        </section>
        <datalist id="dw-frequency">
          <option>Kuniga 1 marta</option>
          <option>Kuniga 2 marta</option>
        </datalist>
        <p v-if="!medicines.length" class="dw-error">
          Kamida bitta to‘liq dori kiriting.
        </p>
        <button class="e-button" type="button" @click="add">
          <Plus :size="19" />Dori qo‘shish</button
        ><label class="dw-field dw-rx-note"
          >Umumiy izoh<textarea
            v-model="note"
            rows="2"
            placeholder="Bemorga qo‘shimcha ko‘rsatmalar..."
          />
        </label>
      </form>
    </div>
    <template #footer="{ close }"
      ><footer>
        <span v-if="error" role="alert" class="dw-error">{{ error }}</span><span class="dw-footnote"
          ><Link :size="17" />{{ medicines.length }} ta dori · Bitta qabulga bog‘langan retsept</span
        ><button class="e-button" @click="close" type="button">
          Bekor qilish</button
        ><button form="dw-rx-form" class="e-button primary" :disabled="saving">
          Retseptni saqlash
        </button>
      </footer></template
    ></WorkspaceDialog
  >
</template>
