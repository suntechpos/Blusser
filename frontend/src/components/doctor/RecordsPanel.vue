<script setup lang="ts">
import { defineAsyncComponent, computed, ref, watch } from "vue";
import {
  PlusCircle,
  Search,
  Eye,
  Download,
  Printer,
  FileText,
  Image,
  Info,
  LockKeyhole,
  ChevronLeft,
  ChevronRight,
  CheckCircle2,
  Clock3,
} from "lucide-vue-next";
import LabResultDialog from "./LabResultDialog.vue";
import UploadDialog from "./UploadDialog.vue";
const DocumentViewer = defineAsyncComponent(
  () => import("./DocumentViewer.vue"),
);
import { printBlob, downloadBlob } from "./localFiles";
import ResultDialog from "./ResultDialog.vue";
import { dateLabel } from "../../mocks/employees";
import {
  type Referral,
  type DocumentRow,
  type ResultRow,
  type Encounter,
} from "../../mocks/doctorWorkspace";

const props = defineProps<{
  kind: "results" | "documents";
  previewOnly?: boolean;
  encounter: Encounter;
  results: ResultRow[];
  documents: DocumentRow[];
  encounters: Encounter[];
  referrals: Referral[];
}>();
const emit = defineEmits<{
  notice: [string];
  uploaded: [DocumentRow, boolean];
}>();
const upload = ref(false);
const viewing = ref<DocumentRow | null>(null);
const uploadReferral = computed(
  () =>
    props.referrals.find(
      (r) =>
        r.location === "external" &&
        !props.results.some(
          (x) => x.referral === r.id && x.status === "Tayyor",
        ),
    )?.id,
);
function uploaded(d: DocumentRow, external: boolean) {
  emit("uploaded", d, external);
  upload.value = false;
}

const detail = ref<ResultRow | null>(null);
const query = ref(""),
  type = ref(""),
  status = ref(""),
  from = ref(""),
  to = ref(""),
  page = ref(1);
const source = computed(() =>
  props.kind === "results" ? props.results : props.documents,
);
const filtered = computed(() =>
  source.value
    .filter(
      (r) =>
        (!query.value ||
          r.name
            .toLocaleLowerCase()
            .includes(query.value.trim().toLocaleLowerCase())) &&
        (!type.value || r.type === type.value) &&
        (!from.value || r.date >= from.value) &&
        (!to.value || r.date <= to.value) &&
        (!status.value || ("status" in r && r.status === status.value)),
    )
    .sort((a, b) => b.date.localeCompare(a.date)),
);
const pages = computed(() => Math.max(1, Math.ceil(filtered.value.length / 6)));
const rows = computed(() =>
  filtered.value.slice((page.value - 1) * 6, page.value * 6),
);
watch([query, type, status, from, to], () => (page.value = 1));
function resultView(r: ResultRow) {
  if (r.external) {
    const d = props.documents.find((d) => d.id === r.documentId);
    if (d) viewing.value = d;
    else emit("notice", "Ushbu tashqi natija fayli mavjud emas.");
    return;
  }
  if (
    r.type === "Laboratoriya" ||
    (r.type === "Diagnostika" && r.name === "Qorin bo‘shlig‘i UZI")
  ) {
    detail.value = r;
    return;
  }
  emit("notice", "Bu natija uchun tasdiqlangan tafsilot mavjud emas.");
}
function action(d: DocumentRow, kind: "view" | "download" | "print") {
  if (kind === "view") {
    viewing.value = d;
    return;
  }
  if (!d.blob) {
    emit("notice", "Fayl mavjud emas.");
    return;
  }
  if (kind === "download")
    downloadBlob(d.blob, d.name + "." + d.format.toLowerCase());
  else
    void printBlob(d.blob).catch((e) => emit("notice", (e as Error).message));
}
</script>
<template>
  <article class="dw-card dw-tab-panel dw-records">
    <div class="dw-section-title">
      <div>
        <h2>{{ kind === "results" ? "Tekshiruv natijalari" : "Hujjatlar" }}</h2>
        <p>
          {{
            kind === "results"
              ? "Laboratoriya va diagnostika natijalari."
              : "Bemorning tibbiy hujjatlari va biriktirilgan fayllar."
          }}
        </p>
      </div>
      <button
        v-if="!previewOnly"
        class="e-button"
        :class="{ primary: kind === 'documents' }"
        @click="upload = true"
      >
        <PlusCircle :size="20" />{{
          kind === "results" ? "Tashqi natija qo‘shish" : "Hujjat yuklash"
        }}
      </button>
    </div>
    <div class="dw-record-filters">
      <label class="dw-search"
        ><Search :size="19" /><input
          v-model="query"
          :aria-label="
            kind === 'results' ? 'Tekshiruvni qidirish' : 'Hujjatni qidirish'
          "
          :placeholder="
            kind === 'results'
              ? 'Tekshiruvni qidirish...'
              : 'Hujjatni qidirish...'
          " /></label
      ><select v-model="type" aria-label="Tur filtri">
        <option value="">Barcha turlar</option>
        <option v-for="t in [...new Set(source.map((r) => r.type))]" :key="t">
          {{ t }}
        </option>
      </select>
      <div class="dw-date-range">
        <input v-model="from" aria-label="Boshlanish sanasi" type="date" /><span
          >–</span
        ><input v-model="to" aria-label="Tugash sanasi" type="date" />
      </div>
      <select
        v-if="kind === 'results'"
        v-model="status"
        aria-label="Natija holati"
      >
        <option value="">Holati: Barchasi</option>
        <option>Buyurilgan</option>
        <option>Jarayonda</option>
        <option>Tayyor</option>
      </select>
    </div>
    <p v-if="from && to && to < from" class="dw-error" role="alert">
      Sana oralig‘ini tekshiring.
    </p>
    <div class="dw-scroll">
      <table v-if="kind === 'results'" class="dw-results-table">
        <thead>
          <tr>
            <th>Sana</th>
            <th>Tekshiruv nomi</th>
            <th>Turi</th>
            <th>Yo‘llanma bergan</th>
            <th>Holati</th>
            <th>Amal</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rows as ResultRow[]" :key="r.id">
            <td>{{ dateLabel(r.date) }}</td>
            <td>
              {{ r.name }}
              <span v-if="r.external" class="dw-badge blue">Tashqi natija</span>
            </td>
            <td>{{ r.type }}</td>
            <td>{{ r.doctor }}</td>
            <td>
              <span
                class="dw-badge"
                :class="
                  r.status === 'Tayyor'
                    ? 'green'
                    : r.status === 'Jarayonda'
                      ? 'yellow'
                      : 'gray'
                "
                ><CheckCircle2 v-if="r.status === 'Tayyor'" :size="16" /><Clock3
                  v-else
                  :size="16"
                />{{ r.status }}</span
              >
            </td>
            <td>
              <button
                v-if="r.status === 'Tayyor'"
                class="dw-link"
                @click="resultView(r)"
              >
                {{
                  r.external ? "Hujjatni ochish" : "Natijani ko‘rish"
                }}</button
              ><span v-else>—</span>
            </td>
          </tr>
          <tr v-if="!rows.length">
            <td colspan="6" class="dw-empty">Tekshiruv natijasi topilmadi.</td>
          </tr>
        </tbody>
      </table>
      <table v-else class="dw-documents-table">
        <thead>
          <tr>
            <th>Sana</th>
            <th>Hujjat nomi</th>
            <th>Turi</th>
            <th>Qo‘shgan xodim</th>
            <th>Qabul</th>
            <th>Amallar</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="d in rows as DocumentRow[]" :key="d.id">
            <td>{{ dateLabel(d.date) }}</td>
            <td>
              <div class="dw-document-name">
                <component
                  :is="d.format === 'PDF' ? FileText : Image"
                  :size="28"
                  :class="d.format === 'PDF' ? 'dw-pdf-icon' : 'dw-image-icon'"
                />
                <div>
                  <b>{{ d.name }}</b
                  ><small
                    >{{ d.format }} · {{ d.size }}
                    <span v-if="d.external" class="dw-badge blue"
                      >Tashqi hujjat</span
                    ></small
                  >
                </div>
              </div>
            </td>
            <td>{{ d.type }}</td>
            <td>{{ d.author }}</td>
            <td>{{ d.encounter || "—" }}</td>
            <td>
              <div class="dw-doc-actions">
                <button
                  class="e-icon"
                  :aria-label="d.name + ' ko‘rish'"
                  @click="action(d, 'view')"
                >
                  <Eye :size="18" /></button
                ><button
                  class="e-icon"
                  :aria-label="d.name + ' yuklab olish'"
                  @click="action(d, 'download')"
                >
                  <Download :size="18" /></button
                ><button
                  class="e-icon"
                  :aria-label="d.name + ' chop etish'"
                  @click="action(d, 'print')"
                >
                  <Printer :size="18" />
                </button>
              </div>
            </td>
          </tr>
          <tr v-if="!rows.length">
            <td colspan="6" class="dw-empty">Hujjat topilmadi.</td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="dw-table-footer">
      <span
        >{{ filtered.length ? (page - 1) * 6 + 1 : 0 }}–{{
          Math.min(page * 6, filtered.length)
        }}
        / {{ filtered.length }} ta</span
      >
      <div class="dw-pages">
        <button
          aria-label="Oldingi sahifa"
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
          aria-label="Keyingi sahifa"
          :disabled="page === pages"
          @click="page++"
        >
          <ChevronRight :size="18" />
        </button>
      </div>
    </div>
    <div class="dw-info">
      <Info :size="20" /><span>{{ previewOnly
        ? "Bu bo‘limning haqiqiy API integratsiyasi keyingi bosqichda ulanadi."
        : kind === "results"
          ? "Natijalar — faqat lokal frontend namoyishi. Haqiqiy yo‘llanmalar va tibbiy karta bilan bog‘lanmagan."
          : "Hujjatlar sintetik mock fayllar. Haqiqiy tibbiy hujjat yuklanmagan."
      }}</span>
    </div>
    <p class="dw-footnote">
      <LockKeyhole :size="17" />{{ previewOnly
        ? "Ma’lumot qo‘shish va yuklash vaqtincha o‘chirilgan."
        : kind === "results"
          ? "Tayyor natijalar faqat ko‘rish uchun."
          : "Haqiqiy hujjatlar uchun backend ruxsatlari bu namoyishda amalga oshirilmaydi."
      }}
    </p>
    <UploadDialog
      v-if="upload && !previewOnly"
      :encounter="encounter"
      :encounters="encounters"
      :referrals="referrals"
      :external-result="kind === 'results'"
      :initial-referral="kind === 'results' ? uploadReferral : undefined"
      @close="upload = false"
      @save="uploaded"
    />
    <LabResultDialog
      v-if="detail && detail.type === 'Laboratoriya'"
      :encounter="encounter"
      :result="detail"
      @close="detail = null"
    />
    <DocumentViewer
      v-if="viewing"
      :encounter="encounter"
      :file="viewing"
      @close="viewing = null"
    />
    <ResultDialog
      v-if="detail && detail.type !== 'Laboratoriya'"
      :encounter="encounter"
      :result="detail"
      @close="detail = null"
      @notice="emit('notice', $event)"
    />
  </article>
</template>
