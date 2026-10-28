<script setup lang="ts">
import { ref, nextTick, computed } from "vue";
import { Plus, Trash2, Clock3, Link, Printer, FileText } from "lucide-vue-next";
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
  doctorName: string;
  readOnly?: boolean;
}>();
const emit = defineEmits<{ close: []; save: [Prescription]; print: [] }>();

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
  if (props.saving || props.readOnly) return;
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
     :title="readOnly ? 'Retseptni ko‘rish' : prescription ? 'Retseptni tahrirlash' : 'Retsept yozish'"
    subtitle="Dori vositalari va qabul qilish tartibini kiriting"
    :dirty="dirty"
    @close="emit('close')"
  >
    <div class="dw-modal-body">
      <PatientStrip :encounter="encounter" :doctor-name="doctorName" context />
      <div class="dw-allergy">
        <Clock3 :size="18" /><span
          ><b>Allergiya:</b>
          {{ encounter.allergy || "Ma’lumot kiritilmagan" }}</span
        >
      </div>
      <div v-if="readOnly" class="dw-rx-view">
        <h3>Dori vositalari</h3>
        <div class="dw-rx-table-wrap">
          <table class="dw-rx-table">
            <thead><tr><th>№</th><th>Dori nomi</th><th>Shakli</th><th>Doza</th><th>Qabul tartibi</th><th>Davomiyligi</th><th>Ko‘rsatma</th></tr></thead>
            <tbody><tr v-for="(m,i) in medicines" :key="i">
              <td>{{ i+1 }}</td><td><strong>{{ m.name }}</strong><small>{{ m.strength }}</small></td>
              <td>{{ m.form || '—' }}</td><td>{{ m.dose }} {{ m.unit }}</td>
              <td>{{ m.frequency }}<small>{{ m.route }}</small></td><td>{{ m.duration }}</td>
              <td>{{ m.instruction || '—' }}</td>
            </tr></tbody>
          </table>
        </div>
        <div class="dw-rx-view-note"><strong>Umumiy izoh</strong><p>{{ note || 'Izoh kiritilmagan' }}</p></div>
      </div>
      <form v-else id="dw-rx-form" novalidate @submit.prevent="save">
        <fieldset class="dw-rx-fields" :disabled="readOnly || saving">
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
        <p v-if="!medicines.length && !readOnly" class="dw-error">
          Kamida bitta to‘liq dori kiriting.
        </p>
        <button v-if="!readOnly" class="e-button" type="button" @click="add">
          <Plus :size="19" />Dori qo‘shish</button
        ><label class="dw-field dw-rx-note"
          >Umumiy izoh<textarea
            v-model="note"
            rows="2"
            placeholder="Bemorga qo‘shimcha ko‘rsatmalar..."
          />
        </label>
        </fieldset>
      </form>
    </div>
    <template #footer="{ close }"
      ><footer>
        <span v-if="error" role="alert" class="dw-error">{{ error }}</span><span class="dw-footnote"
          ><Link :size="17" />{{ medicines.length }} ta dori · Bitta qabulga bog‘langan retsept</span
        ><button class="e-button" @click="close" type="button">{{ readOnly ? "Yopish" : "Bekor qilish" }}</button>
        ><button v-if="prescription" type="button" class="e-button" @click="emit('print')"><Printer :size="17"/> Chop etish / PDF</button><button v-if="!readOnly" form="dw-rx-form" class="e-button primary" :disabled="saving">
          Retseptni saqlash
        </button>
      </footer></template
    ></WorkspaceDialog
  >
</template>

<style scoped>
.dw-rx-view{display:grid;gap:12px;color:#17345a}
.dw-rx-view h3{margin:0;font-size:16px}
.dw-rx-table-wrap{max-width:100%;overflow-x:auto;border:1px solid #d8e4f6;border-radius:10px}
.dw-rx-table{width:100%;border-collapse:collapse;min-width:720px;font-size:13px}
.dw-rx-table th,.dw-rx-table td{padding:10px;text-align:left;border-bottom:1px solid #e1eaf7;vertical-align:top}
.dw-rx-table th{background:#f1f6ff;color:#3d587b}
.dw-rx-table small{display:block;color:#758aab;margin-top:4px}
.dw-rx-view-note{background:#f4f8ff;border-radius:10px;padding:14px}
.dw-rx-view-note p{white-space:pre-wrap;margin:8px 0 0}
.dw-rx-fields{border:0;padding:0;margin:0;min-width:0}
.dw-rx-fields:disabled .dw-medicine button{display:none}
.dw-rx-fields:disabled input,.dw-rx-fields:disabled select,.dw-rx-fields:disabled textarea{opacity:1;color:#17345a;background:#f5f8fd}
</style>
