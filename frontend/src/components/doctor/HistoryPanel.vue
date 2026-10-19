<script setup lang="ts">
import { computed, ref, watch } from "vue";
import {
  Eye,
  Info,
  ChevronLeft,
  ChevronRight,
  Printer,
  LockKeyhole,
  FileText,
  ClipboardList,
  Stethoscope,
  Paperclip,
  Pill,
  CalendarDays,
  UserRound,
  Building2,
} from "lucide-vue-next";
import EmployeeDialog from "../employees/EmployeeDialog.vue";
import { dateLabel } from "../../mocks/employees";
import { type Encounter, type HistoryVisit } from "../../mocks/doctorWorkspace";
import { fileAction, printDemo } from "./mockFiles";
const props = defineProps<{ encounter: Encounter; visits: HistoryVisit[] }>();
const emit = defineEmits<{ notice: [string] }>();
const from = ref(""),
  to = ref(""),
  doctor = ref(""),
  specialty = ref("");
const page = ref(1);
const detail = ref<HistoryVisit | null>(null);
const filtered = computed(() =>
  props.visits
    .filter(
      (v) =>
        (!from.value || v.date >= from.value) &&
        (!to.value || v.date <= to.value) &&
        (!doctor.value || v.doctor === doctor.value) &&
        (!specialty.value || v.specialty === specialty.value),
    )
    .sort(
      (a, b) => b.date.localeCompare(a.date) || b.time.localeCompare(a.time),
    ),
);
const pages = computed(() => Math.max(1, Math.ceil(filtered.value.length / 5)));
const rows = computed(() =>
  filtered.value.slice((page.value - 1) * 5, page.value * 5),
);
watch([from, to, doctor, specialty], () => (page.value = 1));
function print() {
  if (
    detail.value &&
    !printDemo("Tashrif tafsilotlari", [
      props.encounter.name,
      dateLabel(detail.value.date),
      ...[
        "complaints",
        "anamnesis",
        "examination",
        "diagnosis",
        "recommendations",
      ].map((k) =>
        String(
          detail.value![k as keyof HistoryVisit] || "Ma’lumot kiritilmagan",
        ),
      ),
    ])
  )
    emit("notice", "Chop etish oynasiga brauzer ruxsat bermadi.");
}
</script>
<template>
  <article class="dw-card dw-tab-panel">
    <div class="dw-section-title">
      <h2>Tashriflar tarixi</h2>
      <span>Jami {{ visits.length }} ta tashrif</span>
    </div>
    <div class="dw-history-filters">
      <div class="dw-date-range">
        <input
          v-model="from"
          type="date"
          aria-label="Tashrif boshlanish sanasi"
        /><span>–</span
        ><input v-model="to" type="date" aria-label="Tashrif tugash sanasi" />
      </div>
      <select v-model="doctor" aria-label="Tashrif shifokori">
        <option value="">Barcha shifokorlar</option>
        <option v-for="v in [...new Set(visits.map((x) => x.doctor))]" :key="v">
          {{ v }}
        </option></select
      ><select v-model="specialty" aria-label="Tashrif mutaxassisligi">
        <option value="">Barcha mutaxassisliklar</option>
        <option
          v-for="v in [...new Set(visits.map((x) => x.specialty))]"
          :key="v"
        >
          {{ v }}
        </option>
      </select>
    </div>
    <p v-if="from && to && to < from" class="dw-error" role="alert">
      Sana oralig‘ini tekshiring.
    </p>
    <div class="dw-scroll">
      <table>
        <thead>
          <tr>
            <th>Sana va vaqt</th>
            <th>Shifokor</th>
            <th>Qabul turi</th>
            <th>Tashxis</th>
            <th>Holati</th>
            <th>Amal</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="v in rows" :key="v.id">
            <td>
              <b>{{ dateLabel(v.date) }}</b
              ><small>{{ v.time }}</small>
            </td>
            <td>
              <b>{{ v.doctor }}</b
              ><small>{{ v.specialty }}</small>
            </td>
            <td>{{ v.kind }}</td>
            <td>{{ v.diagnosis || "Ma’lumot kiritilmagan" }}</td>
            <td>
              <span class="dw-badge" :class="v.complete ? 'green' : 'yellow'">{{
                v.complete ? "Yakunlangan" : "Tugallanmagan"
              }}</span>
            </td>
            <td>
              <button
                class="e-button dw-small"
                :aria-label="dateLabel(v.date) + ' tashrifini ko‘rish'"
                @click="detail = v"
              >
                <Eye :size="17" />Ko‘rish
              </button>
            </td>
          </tr>
          <tr v-if="!rows.length">
            <td colspan="6" class="dw-empty">Tashrif topilmadi.</td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="dw-table-footer">
      <span class="dw-footnote"
        ><Info :size="17" />Oldingi qabul yozuvlari ko‘rish rejimida
        ochiladi.</span
      ><span
        >{{ filtered.length ? (page - 1) * 5 + 1 : 0 }}–{{
          Math.min(page * 5, filtered.length)
        }}
        / {{ filtered.length }} ta tashrif</span
      >
      <div class="dw-pages">
        <button
          aria-label="Oldingi tashriflar sahifasi"
          :disabled="page === 1"
          @click="page--"
        >
          <ChevronLeft :size="18" /></button
        ><button
          v-for="n in pages"
          :key="n"
          :class="{ selected: n === page }"
          @click="page = n"
        >
          {{ n }}</button
        ><button
          aria-label="Keyingi tashriflar sahifasi"
          :disabled="page === pages"
          @click="page++"
        >
          <ChevronRight :size="18" />
        </button>
      </div>
    </div>
    <EmployeeDialog
      v-if="detail"
      class="dw-history-modal"
      title="Tashrif tafsilotlari"
      @close="detail = null"
      ><span class="dw-history-readmode dw-badge blue"
        ><Eye :size="17" />Ko‘rish rejimi</span
      >
      <div class="dw-history-identity">
        <span class="dw-avatar" :class="encounter.color">{{
          encounter.initials
        }}</span>
        <div>
          <h3>
            {{ encounter.name }} <small>· {{ encounter.patientId }}</small>
          </h3>
          <p>
            <CalendarDays :size="18" />{{ dateLabel(detail.date) }} ·
            {{ detail.time }} <UserRound :size="18" />{{ detail.doctor }} ·
            {{ detail.specialty }} <Building2 :size="18" />Asosiy filial ·
            201-kabinet
          </p>
        </div>
        <div class="dw-history-status">
          <span class="dw-badge blue">{{ detail.kind }} qabul</span
          ><span
            class="dw-badge"
            :class="detail.complete ? 'green' : 'yellow'"
            >{{ detail.complete ? "Yakunlangan" : "Tugallanmagan" }}</span
          >
        </div>
      </div>
      <div class="dw-grid-2">
        <section
          v-for="part in [
            { key: 'complaints', title: 'Shikoyatlar', icon: FileText },
            { key: 'anamnesis', title: 'Anamnez', icon: ClipboardList },
            {
              key: 'examination',
              title: 'Ko‘rik natijalari',
              icon: Stethoscope,
            },
            { key: 'diagnosis', title: 'Tashxis', icon: FileText },
            { key: 'recommendations', title: 'Tavsiyalar', icon: FileText },
          ]"
          :key="part.key"
          class="dw-detail"
          :class="{
            'dw-span-2': !['complaints', 'anamnesis'].includes(part.key),
          }"
        >
          <component :is="part.icon" :size="24" />
          <div>
            <h3>{{ part.title }}</h3>
            <p>
              {{ detail[part.key as "complaints"] || "Ma’lumot kiritilmagan" }}
            </p>
          </div>
        </section>
        <section class="dw-detail">
          <Pill :size="24" />
          <div>
            <h3>Yo‘llanmalar va retseptlar</h3>
            <p class="dw-empty">Bu tashrifda qayd etilmagan.</p>
          </div>
        </section>
        <section class="dw-detail">
          <Paperclip :size="24" />
          <div>
            <h3>Hujjatlar</h3>
            <div v-for="doc in detail.documents" :key="doc.id" class="dw-file">
              <FileText :size="24" /><span>{{ doc.name }}.pdf</span
              ><button
                class="e-button dw-small"
                @click="
                  fileAction(doc.name, doc.format, 'view').catch(() =>
                    emit('notice', 'Demo faylni ochib bo‘lmadi.'),
                  )
                "
              >
                <Eye :size="17" />Ko‘rish
              </button>
            </div>
            <p v-if="!detail.documents.length">Ma’lumot kiritilmagan</p>
          </div>
        </section>
      </div>
      <footer>
        <span class="dw-footnote"
          ><LockKeyhole :size="20" />Faqat ko‘rish uchun</span
        ><button class="e-button" @click="print">
          <Printer :size="18" />Chop etish</button
        ><button class="e-button primary" @click="detail = null">Yopish</button>
      </footer></EmployeeDialog
    >
  </article>
</template>
