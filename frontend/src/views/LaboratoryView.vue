<script setup lang="ts">
import { computed, ref, watch, onMounted, onBeforeUnmount } from "vue";
import {
  FileText,
  TestTube,
  Activity,
  Clock,
  CheckCircle2,
  Eye,
  RefreshCw,
  Search,
  ArrowLeft,
  Printer,
  Download,
  Save,
  Send,
} from "lucide-vue-next";
import { session } from "../services/session";
import { localDate } from "../mocks/reception";
import {
  createLabStore,
  filterLabs,
  internalLabs,
  labTests,
  testNames,
  sampleLabCapabilities,
  nextSampleId,
  pendingTypes,
  collectSample,
  startLab,
  cloneLabDraft,
  saveLab,
  submitLab,
  returnLab,
  confirmLab,
  amendLab,
  validateRows,
  labSignature,
  rowFlag,
  ageAt,
  type LabReferral,
  type LabDraft,
  type LabVersion,
  type LabSample,
  type LabCapabilities,
} from "../mocks/laboratory";
import Dialog from "../components/doctor/WorkspaceDialog.vue";
import DateField from "../components/employees/DateField.vue";
import AttachmentGrid from "../components/diagnostics/AttachmentGrid.vue";
import FileViewer from "../components/diagnostics/FileViewer.vue";
import LabRows from "../components/laboratory/LabRows.vue";
import SampleInfo from "../components/laboratory/SampleInfo.vue";
import { timestamp } from "../components/diagnostics/content";
import { downloadBlob, printBlob } from "../components/doctor/localFiles";
import {
  labPdf,
  labelPng,
  specimenBarcode,
} from "../components/laboratory/exports";
import "../components/diagnostics/diagnostics.css";
import "../components/laboratory/laboratory.css";
const props = defineProps<{ capabilities?: LabCapabilities }>();
const caps = computed(() => props.capabilities ?? sampleLabCapabilities);
const store = ref(createLabStore()),
  filter = ref({
    date: localDate(),
    query: "",
    testId: "",
    doctorId: "",
    branchId: "",
    status: "",
  }),
  page = ref(1),
  pageSize = ref(10);
const selected = ref<LabReferral>(),
  dialogReferral = ref<LabReferral>(),
  edit = ref<LabDraft>(),
  signature = ref(""),
  modal = ref(""),
  reason = ref(""),
  errors = ref<Record<string, string>>({}),
  error = ref(""),
  notice = ref(""),
  busy = ref(false),
  exporting = ref(false),
  historyVersion = ref<LabVersion>(),
  labelSample = ref<LabSample>(),
  labelReferral = ref<LabReferral>(),
  viewer = ref<{ files: LabVersion["files"]; index: number }>(),
  collectForm = ref({ type: "", date: "", time: "", note: "" });
let timer: ReturnType<typeof setTimeout> | undefined;
const actor = computed(() => ({
  id: String(session.user?.id ?? "local-session"),
  name: session.user?.login ?? "Lokal xodim",
  specialty: "Laboratoriya xodimi (namuna)",
}));
const job = computed(() =>
    selected.value ? store.value.jobs[selected.value.id] : undefined,
  ),
  current = computed(() => job.value?.versions.at(-1)),
  review = computed(
    () =>
      !edit.value &&
      !!job.value?.draft &&
      (!current.value || reviewAmendment.value),
  ),
  reviewAmendment = ref(false);
const displayed = computed(
  () => edit.value ?? (review.value ? job.value?.draft : current.value),
);
const dirty = computed(
  () => !!edit.value && labSignature(edit.value) !== signature.value,
);
const statuses = ["new", "collected", "in_progress", "review", "completed"];
const labels: Record<string, string> = {
    new: "Yangi",
    collected: "Namuna olindi",
    in_progress: "Jarayonda",
    review: "Tasdiqlash kutilmoqda",
    completed: "Yakunlangan",
  },
  actions: Record<string, string> = {
    new: "Namuna olish",
    collected: "Tahlilni boshlash",
    in_progress: "Natija kiritish",
    review: "Tekshirish",
    completed: "Natijani ko‘rish",
  };
const icons = [FileText, TestTube, Activity, Clock, CheckCircle2];
const rows = computed(() => filterLabs(store.value, filter.value)),
  base = computed(() =>
    filterLabs(store.value, { ...filter.value, status: "" }),
  ),
  visible = computed(() =>
    rows.value.slice(
      (page.value - 1) * pageSize.value,
      page.value * pageSize.value,
    ),
  );
const doctors = computed(() => [
    ...new Map(
      internalLabs(store.value).map((r) => [r.doctorId, r.doctorName]),
    ).entries(),
  ]),
  branches = computed(() => [
    ...new Map(
      internalLabs(store.value).map((r) => [r.branchId, r.branchName]),
    ).entries(),
  ]);
const filled = computed(
    () => displayed.value?.rows.filter((r) => r.value.trim()).length ?? 0,
  ),
  abnormal = computed(
    () =>
      displayed.value?.rows.filter((r) => ["low", "high"].includes(rowFlag(r)))
        .length ?? 0,
  );
const modalDirty = computed(() =>
  modal.value === "collect"
    ? !!collectForm.value.note
    : modal.value === "return" || modal.value === "amend"
      ? !!reason.value.trim()
      : false,
);
watch(filter, () => (page.value = 1), { deep: true });
watch(pageSize, () => (page.value = 1));
watch(notice, () => {
  clearTimeout(timer);
  if (notice.value) timer = setTimeout(() => (notice.value = ""), 4500);
});
function attempt(fn: () => void) {
  error.value = "";
  notice.value = "";
  try {
    fn();
  } catch (e) {
    error.value = (e as Error).message;
  }
}
function allowedAction(r: LabReferral) {
  if (store.value.jobs[r.id]?.status === "completed") return true;
  return caps.value[
    (
      {
        new: "collect",
        collected: "start",
        in_progress: "draft",
        review: "review",
        completed: "review",
      } as const
    )[store.value.jobs[r.id]!.status]
  ];
}
function openPage(r: LabReferral, amendment = false) {
  const j = store.value.jobs[r.id]!;
  selected.value = r;
  reviewAmendment.value = amendment;
  edit.value =
    (j.status === "in_progress" ||
      (amendment && j.draft?.phase === "editing")) &&
    j.draft
      ? cloneLabDraft(j.draft)
      : undefined;
  signature.value = edit.value ? labSignature(edit.value) : "";
  errors.value = {};
  modal.value = "";
}
function action(r: LabReferral) {
  const j = store.value.jobs[r.id]!;
  error.value = "";
  dialogReferral.value = r;
  if (j.status === "new") {
    const now = new Date();
    collectForm.value = {
      type: pendingTypes(r, j)[0] ?? "",
      date: localDate(now),
      time: `${String(now.getHours()).padStart(2, "0")}:${String(now.getMinutes()).padStart(2, "0")}`,
      note: "",
    };
    modal.value = "collect";
  } else if (j.status === "collected") modal.value = "start";
  else openPage(r);
}
function collect() {
  if (!dialogReferral.value) return;
  attempt(() => {
    const r = dialogReferral.value!,
      f = collectForm.value,
      s = collectSample(
        store.value,
        r,
        f.type,
        `${f.date}T${f.time}`,
        f.note,
        actor.value,
        caps.value,
      );
    labelSample.value = s;
    labelReferral.value = r;
    modal.value = "";
    notice.value = "Namuna olingani lokal qayd etildi.";
  });
}
function start() {
  if (!dialogReferral.value) return;
  attempt(() => {
    const r = dialogReferral.value!;
    startLab(store.value.jobs[r.id]!, r, actor.value, caps.value);
    openPage(r);
  });
}
function save() {
  if (!job.value || !edit.value || busy.value) return;
  attempt(() => {
    edit.value = saveLab(job.value!, edit.value!, actor.value, caps.value);
    signature.value = labSignature(edit.value!);
    notice.value = "Qoralama lokal saqlandi.";
  });
}
function submit() {
  if (!job.value || !edit.value || busy.value) return;
  errors.value = validateRows(edit.value);
  if (Object.keys(errors.value).length) {
    error.value = "Natijalar formatini va majburiy maydonlarni tekshiring.";
    return;
  }
  attempt(() => {
    submitLab(job.value!, edit.value!, actor.value, caps.value);
    edit.value = undefined;
    reviewAmendment.value = !!current.value;
    notice.value = "Natija tekshirishga yuborildi (lokal).";
  });
}
function confirm() {
  if (!job.value) return;
  attempt(() => {
    confirmLab(job.value!, actor.value, caps.value);
    reviewAmendment.value = false;
    modal.value = "";
    notice.value = "Natija tasdiqlandi (lokal).";
  });
}
function returnWork() {
  if (!job.value) return;
  attempt(() => {
    returnLab(job.value!, reason.value, actor.value, caps.value);
    edit.value = cloneLabDraft(job.value!.draft!);
    signature.value = labSignature(edit.value!);
    modal.value = "";
    notice.value = "Natija qayta ishlashga qaytarildi (lokal).";
  });
}
function amendment() {
  if (!job.value) return;
  attempt(() => {
    const d = amendLab(job.value!, reason.value, actor.value, caps.value);
    if (d.phase === "review") {
      edit.value = undefined;
      reviewAmendment.value = true;
    } else {
      edit.value = d;
      signature.value = labSignature(d);
    }
    modal.value = "";
  });
}
function sampleTypes(r: LabReferral) {
  return [
    ...new Set(
      r.testIds.map((id) => labTests.find((t) => t.id === id)?.sampleType),
    ),
  ].join(", ");
}
function changeRow(id: string, value: string) {
  const r = edit.value?.rows.find((r) => r.id === id);
  if (r && edit.value) {
    r.value = value;
    if (errors.value[id]) {
      const currentError = validateRows(edit.value)[id];
      if (currentError) errors.value[id] = currentError;
      else delete errors.value[id];
    }
    if (!Object.keys(validateRows(edit.value)).length) error.value = "";
  }
}
function closeModal() {
  modal.value = "";
  error.value = "";
  pending = undefined;
}
function leave() {
  if (busy.value) return;
  if (dirty.value) {
    modal.value = "leave";
    return;
  }
  selected.value = undefined;
  edit.value = undefined;
  reviewAmendment.value = false;
}
let pending: (() => void) | undefined;
function guardLeave(action: () => void) {
  if (!dirty.value && !busy.value) return false;
  if (!busy.value) {
    pending = action;
    modal.value = "leave";
  }
  return true;
}
defineExpose({ guardLeave });
function discard() {
  edit.value = undefined;
  selected.value = undefined;
  modal.value = "";
  const action = pending;
  pending = undefined;
  action?.();
}
function navigation(e: MouseEvent) {
  const t = (e.target as Element)?.closest<HTMLElement>(
    ".clinic-sidebar button,.clinic-profile-menu button",
  );
  if (!t || (!dirty.value && !busy.value)) return;
  e.preventDefault();
  e.stopImmediatePropagation();
  guardLeave(() => t.click());
}
function clinicChange(e: Event) {
  const t = e.target as HTMLSelectElement;
  if (!t.matches?.(".clinic-selector select") || (!dirty.value && !busy.value))
    return;
  const next = t.value;
  e.stopImmediatePropagation();
  t.value = String(session.user?.active_tenant_id ?? "");
  guardLeave(() => {
    t.value = next;
    t.dispatchEvent(new Event("change", { bubbles: true }));
  });
}
function unload(e: BeforeUnloadEvent) {
  if (dirty.value || busy.value) {
    e.preventDefault();
    e.returnValue = "";
  }
}
onMounted(() => {
  document.addEventListener("click", navigation, true);
  document.addEventListener("change", clinicChange, true);
  window.addEventListener("beforeunload", unload);
});
onBeforeUnmount(() => {
  clearTimeout(timer);
  document.removeEventListener("click", navigation, true);
  document.removeEventListener("change", clinicChange, true);
  window.removeEventListener("beforeunload", unload);
});
async function exportResult(v: LabVersion, print = false) {
  if (!selected.value || !job.value || exporting.value) return;
  exporting.value = true;
  error.value = "";
  try {
    const blob = await labPdf(
      selected.value,
      job.value,
      v,
      v.number !== current.value?.number,
    );
    if (print) await printBlob(blob);
    else downloadBlob(blob, `${selected.value.number}-v${v.number}.pdf`);
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    exporting.value = false;
  }
}
function showLabel(s: LabSample, r = selected.value ?? dialogReferral.value) {
  if (!r) return;
  labelSample.value = s;
  labelReferral.value = r;
  modal.value = "label";
}
async function printLabel() {
  if (!labelSample.value || !labelReferral.value || exporting.value) return;
  exporting.value = true;
  error.value = "";
  try {
    await printBlob(await labelPng(labelReferral.value, labelSample.value));
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    exporting.value = false;
  }
}
</script>
<template>
  <div class="dx-workspace lb-page">
    <p v-if="error && !modal" class="dx-error" role="alert">{{ error }}</p>
    <p v-if="notice" class="dx-success" role="status">{{ notice }}</p>
    <template v-if="!selected"
      ><div class="dx-heading">
        <div>
          <h1>Laboratoriya</h1>
          <p>Yo‘llanmalar, namunalar va tahlil natijalari</p>
        </div>
        <div class="dx-actions">
          <DateField v-model="filter.date" label="Laboratoriya sanasi" /><button
            class="dx-icon"
            aria-label="Laboratoriya ro‘yxatini yangilash"
            @click="
              notice = 'Lokal ro‘yxat yangilandi. Serverga so‘rov yuborilmadi.'
            "
          >
            <RefreshCw />
          </button>
        </div>
      </div>
      <div class="dx-stats lb-stats">
        <article
          v-for="(status, i) in statuses"
          :key="status"
          :class="['dx-card', status]"
        >
          <span class="dx-stat-icon"><component :is="icons[i]" /></span>
          <div>
            <small>{{ labels[status] }}</small
            ><strong>{{
              base.filter((r) => store.jobs[r.id]?.status === status).length
            }}</strong>
          </div>
        </article>
      </div>
      <div v-if="labelSample && labelReferral" class="lb-last-sample">
        <span>Oxirgi namuna: {{ labelSample.id }}</span
        ><button class="dx-link" @click="modal = 'label'">
          Yorliqni chop etish
        </button>
      </div>
      <section class="dx-card dx-list lb-list">
        <nav class="dx-tabs">
          <button
            :class="{ active: !filter.status }"
            @click="filter.status = ''"
          >
            Barchasi ({{ base.length }})</button
          ><button
            v-for="status in statuses"
            :key="status"
            :class="{ active: filter.status === status }"
            @click="filter.status = status"
          >
            {{ labels[status] }} ({{
              base.filter((r) => store.jobs[r.id]?.status === status).length
            }})
          </button>
        </nav>
        <div class="dx-filters">
          <label class="dx-search"
            ><Search :size="19" /><input
              v-model="filter.query"
              placeholder="Bemor yoki yo‘llanma raqami..."
              aria-label="Laboratoriyada qidirish" /></label
          ><select v-model="filter.testId" aria-label="Tahlil filtri">
            <option value="">Barcha tahlillar</option>
            <option v-for="t in labTests" :key="t.id" :value="t.id">
              {{ t.name }}
            </option></select
          ><select
            v-model="filter.doctorId"
            aria-label="Yo‘llagan shifokor filtri"
          >
            <option value="">Yo‘llagan shifokor</option>
            <option v-for="d in doctors" :key="d[0]" :value="d[0]">
              {{ d[1] }}
            </option></select
          ><select v-model="filter.branchId" aria-label="Laboratoriya filiali">
            <option value="">Barcha filiallar</option>
            <option v-for="b in branches" :key="b[0]" :value="b[0]">
              {{ b[1] }}
            </option>
          </select>
        </div>
        <div class="dx-table-scroll">
          <table>
            <thead>
              <tr>
                <th>Yo‘llanma</th>
                <th>Bemor</th>
                <th>Tahlil</th>
                <th>Yo‘llagan shifokor</th>
                <th>Namuna</th>
                <th>Holat</th>
                <th>Amallar</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in visible" :key="r.id">
                <td>
                  <b>{{ r.number }}</b
                  ><small>{{ timestamp(r.createdAt) }}</small>
                </td>
                <td>
                  <b>{{ r.patientName }}</b
                  ><small>{{ r.patientId }}</small>
                </td>
                <td>{{ testNames(r) }}</td>
                <td>
                  <b>{{ r.doctorName }}</b
                  ><small>{{ r.doctorSpecialty }}</small>
                </td>
                <td>
                  <template v-if="store.jobs[r.id]!.samples.length"
                    ><div v-for="s in store.jobs[r.id]!.samples" :key="s.id">
                      {{ s.type }}<small>{{ s.id }}</small>
                    </div></template
                  ><template v-else
                    >{{ sampleTypes(r) }}<small>Olinmagan</small></template
                  >
                </td>
                <td>
                  <span :class="['lb-badge', store.jobs[r.id]!.status]">{{
                    labels[store.jobs[r.id]!.status]
                  }}</span
                  ><small
                    v-if="
                      store.jobs[r.id]!.draft &&
                      store.jobs[r.id]!.versions.length
                    "
                    >Tuzatish ·
                    {{ store.jobs[r.id]!.draft!.number }}-versiya</small
                  >
                </td>
                <td>
                  <div class="dx-actions">
                    <button
                      class="dx-button"
                      :class="{
                        'dx-primary': store.jobs[r.id]!.status === 'new',
                      }"
                      :disabled="!allowedAction(r)"
                      @click="action(r)"
                    >
                      {{ actions[store.jobs[r.id]!.status] }}</button
                    ><button
                      class="dx-icon"
                      :aria-label="`${r.number} yo‘llanma tafsilotlari`"
                      @click="
                        dialogReferral = r;
                        modal = 'details';
                      "
                    >
                      <Eye :size="18" />
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="!visible.length">
                <td colspan="7" class="dx-empty">
                  {{
                    filter.query ||
                    filter.testId ||
                    filter.doctorId ||
                    filter.branchId
                      ? "Natija topilmadi"
                      : "Bu sana va holat uchun yo‘llanma yo‘q"
                  }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <footer class="dx-pagination">
          <select
            v-model.number="pageSize"
            aria-label="Laboratoriya sahifasidagi yozuvlar"
          >
            <option :value="10">10 ta</option>
            <option :value="5">5 ta</option></select
          ><span
            >{{ rows.length ? (page - 1) * pageSize + 1 : 0 }}–{{
              Math.min(page * pageSize, rows.length)
            }}
            / {{ rows.length }} ta yo‘llanma</span
          ><button class="dx-icon" :disabled="page === 1" @click="page--">
            ‹</button
          ><b>{{ page }}</b
          ><button
            class="dx-icon"
            :disabled="page * pageSize >= rows.length"
            @click="page++"
          >
            ›
          </button>
        </footer>
        <p class="lb-queue-note">
          ⓘ Faqat klinikamizdagi ichki laboratoriya yo‘llanmalari
          ko‘rsatiladi.<span>Namunaviy ma’lumotlar</span>
        </p>
      </section></template
    >
    <template v-else-if="job && displayed"
      ><button class="dx-link" :disabled="busy" @click="leave">
        <ArrowLeft :size="18" />Ro‘yxatga qaytish</button
      ><small class="dx-breadcrumb">Laboratoriya / {{ selected.number }}</small>
      <div class="dx-heading">
        <div>
          <h1>
            {{ review ? "Natijani tekshirish" : testNames(selected) }}
            <span
              :class="[
                'lb-badge',
                review ? 'review' : edit ? 'in_progress' : 'completed',
              ]"
              >{{
                labels[review ? "review" : edit ? "in_progress" : "completed"]
              }}</span
            >
          </h1>
          <p>
            {{ selected.number }} · Namuna
            {{ job.samples.map((s) => s.id).join(", ") }} ·
            {{ displayed.number }}-versiya
          </p>
        </div>
        <div v-if="!edit && !review && current" class="dx-actions">
          <button
            class="dx-button"
            :disabled="exporting"
            @click="exportResult(current, true)"
          >
            <Printer :size="18" />Chop etish</button
          ><button
            class="dx-button dx-primary"
            :disabled="exporting"
            @click="exportResult(current)"
          >
            <Download :size="18" />PDF yuklab olish
          </button>
        </div>
      </div>
      <div v-if="!edit && !review && current" class="dx-success">
        ✓
        {{ current.number > 1 ? "Natija yangilangan" : "Natija tasdiqlangan" }}
        · {{ timestamp(current.confirmedAt) }}
      </div>
      <div v-if="job.draft && current && (edit || review)" class="dx-info">
        Tuzatish {{ review ? "tekshirilmoqda" : "qoralamasi" }} ·
        {{ job.draft.number }}-versiya · {{ job.draft.reason }}. Oldingi
        tasdiqlangan natija amaldagi bo‘lib qoladi.
      </div>
      <div v-if="displayed.returns.length" class="dx-warning">
        <b>Qayta ishlashga qaytarilgan:</b>
        {{ displayed.returns.at(-1)!.reason }} ·
        {{ displayed.returns.at(-1)!.actor.name }} ·
        {{ timestamp(displayed.returns.at(-1)!.at) }}
      </div>
      <div class="lb-content-grid">
        <div>
          <section class="dx-card dx-patient lb-patient">
            <span class="dx-avatar">{{
              selected.patientName
                .split(" ")
                .map((s) => s[0])
                .join("")
            }}</span>
            <div>
              <h2>{{ selected.patientName }}</h2>
              <p>
                {{ selected.patientId }} · {{ selected.gender }} ·
                {{ ageAt(selected.dob) }} yosh · {{ selected.dob }}
              </p>
            </div>
          </section>
          <section class="dx-card">
            <h2>
              Tahlil natijalari
              <small class="lb-demo-pill">Namunaviy ko‘rsatkichlar</small>
            </h2>
            <LabRows
              :rows="displayed.rows"
              :editable="!!edit && caps.draft"
              :errors="errors"
              @change="changeRow"
            /><template v-if="edit"
              ><label class="lb-note"
                >Laborant izohi<textarea
                  v-model="edit.note"
                  aria-label="Laborant izohi"
                  maxlength="500"
                  placeholder="Qo‘shimcha izoh kiriting (ixtiyoriy)..."
                /><small>{{ edit.note.length }}/500</small></label
              >
              <p v-if="errors.note" class="dx-error" role="alert">
                {{ errors.note }}
              </p></template
            >
          </section>
          <section v-if="!edit" class="dx-card">
            <h2>Laborant izohi</h2>
            <p class="lb-read-note">
              {{ displayed.note || "Qo‘shimcha izoh kiritilmagan." }}
            </p>
          </section>
          <AttachmentGrid
            :files="displayed.files"
            :editable="!!edit && caps.draft"
            :max-files="10"
            :max-bytes="50 * 1024 * 1024"
            @change="
              (files) => {
                if (edit) edit.files = files;
              }
            "
            @busy="busy = $event"
            @view="viewer = { files: displayed!.files, index: $event }"
          />
          <p v-if="edit" class="lb-file-limit">
            Lokal qoralama: 10 tagacha fayl, jami 50 MB. Har biri 20 MB gacha.
          </p>
        </div>
        <aside>
          <SampleInfo
            :samples="job.samples"
            :started-at="job.startedAt"
            labels
            @label="showLabel($event)"
          />
          <section class="dx-card">
            <h2>
              {{
                current && !edit && !review
                  ? "Tasdiqlash ma’lumotlari"
                  : "Jarayon ma’lumotlari"
              }}
            </h2>
            <dl>
              <dt>Yo‘llagan shifokor</dt>
              <dd>
                <b>{{ selected.doctorName }}</b
                ><small>{{ selected.doctorSpecialty }}</small>
              </dd>
              <dt>Filial</dt>
              <dd>{{ selected.branchName }}</dd>
              <dt>Natijani kiritgan</dt>
              <dd>
                {{ displayed.enteredBy.name
                }}<small>{{ displayed.enteredBy.specialty }}</small>
              </dd>
              <template
                v-if="'submittedAt' in displayed && displayed.submittedAt"
                ><dt>Tekshirishga yuborilgan</dt>
                <dd>{{ timestamp(displayed.submittedAt) }}</dd></template
              ><template v-if="review"
                ><dt>Tekshiruvchi</dt>
                <dd>
                  {{ actor.name }}<small>{{ actor.specialty }}</small>
                </dd></template
              ><template v-if="!edit && !review && current"
                ><dt>Tasdiqlagan</dt>
                <dd>
                  {{ current.confirmedBy.name
                  }}<small>{{ current.confirmedBy.specialty }}</small>
                </dd>
                <dt>Tasdiqlangan vaqt</dt>
                <dd>{{ timestamp(current.confirmedAt) }}</dd>
                <dt>Versiya</dt>
                <dd>{{ current.number }}</dd></template
              >
            </dl>
            <button
              v-if="job.versions.length"
              class="dx-link"
              @click="
                historyVersion = current;
                modal = 'history';
              "
            >
              Versiyalar tarixi ({{ job.versions.length }})
            </button>
          </section>
        </aside>
      </div>
      <footer class="dx-sticky lb-sticky">
        <span>{{
          edit && dirty
            ? "Saqlanmagan o‘zgarishlar"
            : `${filled} / ${displayed.rows.length} ko‘rsatkich kiritildi`
        }}</span
        ><template v-if="edit"
          ><button
            class="dx-button"
            :disabled="busy || !caps.draft"
            @click="save"
          >
            <Save :size="18" />Qoralamani saqlash</button
          ><button
            class="dx-button dx-primary"
            :disabled="busy || !caps.submit"
            @click="submit"
          >
            <Send :size="18" />Tekshirishga yuborish
          </button></template
        ><template v-else-if="review"
          ><button
            v-if="caps.review"
            class="dx-button lb-return"
            @click="
              reason = '';
              modal = 'return';
            "
          >
            Qayta ishlashga qaytarish</button
          ><button
            v-if="caps.confirm"
            class="dx-button dx-primary"
            @click="modal = 'confirm'"
          >
            Natijani tasdiqlash
          </button></template
        ><template v-else
          ><button
            v-if="caps.amend"
            class="dx-button"
            @click="
              reason = '';
              job.draft ? amendment() : (modal = 'amend');
            "
          >
            {{
              job.draft ? "Tuzatishni davom ettirish" : "Tuzatish kiritish"
            }}</button
          ><button class="dx-button" @click="leave">
            Ro‘yxatga qaytish
          </button></template
        >
      </footer></template
    >
    <Dialog
      v-if="modal === 'details' && dialogReferral"
      class="dx-dialog lb-dialog"
      title="Yo‘llanma tafsilotlari"
      :subtitle="dialogReferral.number"
      @close="closeModal"
      ><div class="dx-modal-body">
        <span :class="['lb-badge', store.jobs[dialogReferral.id]!.status]">{{
          labels[store.jobs[dialogReferral.id]!.status]
        }}</span>
        <div class="lb-dialog-patient">
          <span class="dx-avatar">{{
            dialogReferral.patientName
              .split(" ")
              .map((s) => s[0])
              .join("")
          }}</span>
          <div>
            <h2>{{ dialogReferral.patientName }}</h2>
            <p>
              {{ dialogReferral.patientId }} · {{ dialogReferral.gender }} ·
              {{ dialogReferral.dob }}
            </p>
          </div>
        </div>
        <dl>
          <dt>Buyurilgan tahlil</dt>
          <dd>{{ testNames(dialogReferral) }}</dd>
          <dt>Yo‘llagan shifokor</dt>
          <dd>
            {{ dialogReferral.doctorName
            }}<small>{{ dialogReferral.doctorSpecialty }}</small>
          </dd>
          <dt>Filial</dt>
          <dd>{{ dialogReferral.branchName }}</dd>
          <dt>Yaratilgan vaqt</dt>
          <dd>{{ timestamp(dialogReferral.createdAt) }}</dd>
          <dt>Yuborish sababi</dt>
          <dd>{{ dialogReferral.reason }}</dd>
          <dt>Shifokor izohi</dt>
          <dd>{{ dialogReferral.note }}</dd>
        </dl>
        <SampleInfo
          :samples="store.jobs[dialogReferral.id]!.samples"
          labels
          @label="showLabel($event, dialogReferral)"
        />
        <p v-if="!store.jobs[dialogReferral.id]!.samples.length">
          Namuna turi:
          {{
            pendingTypes(dialogReferral, store.jobs[dialogReferral.id]!).join(
              ", ",
            )
          }}
        </p>
        <dl>
          <dt>Natija kutilgan vaqt</dt>
          <dd>
            {{
              dialogReferral.expectedAt
                ? timestamp(dialogReferral.expectedAt)
                : "Hali belgilanmagan"
            }}
          </dd>
        </dl>
      </div>
      <template #footer
        ><footer>
          <button class="dx-button" @click="closeModal">Yopish</button
          ><button
            class="dx-button dx-primary"
            :disabled="!allowedAction(dialogReferral)"
            @click="action(dialogReferral)"
          >
            {{ actions[store.jobs[dialogReferral.id]!.status] }}
          </button>
        </footer></template
      ></Dialog
    >
    <Dialog
      v-if="modal === 'collect' && dialogReferral"
      class="dx-dialog lb-dialog lb-compact"
      title="Namuna olish"
      subtitle="Olingan namunani bemor va tahlilga bog‘lang"
      :dirty="modalDirty"
      :icon="TestTube"
      @close="closeModal"
      ><div class="dx-modal-body">
        <section class="dx-card">
          <h2>{{ dialogReferral.patientName }}</h2>
          <p>{{ dialogReferral.patientId }} · {{ dialogReferral.dob }}</p>
          <dl>
            <dt>Tahlil</dt>
            <dd>{{ testNames(dialogReferral) }}</dd>
            <dt>Yo‘llanma</dt>
            <dd>{{ dialogReferral.number }}</dd>
          </dl>
        </section>
        <div class="lb-form-grid">
          <label
            >Namuna turi *<select
              v-model="collectForm.type"
              aria-label="Namuna turi"
            >
              <option
                v-for="t in pendingTypes(
                  dialogReferral,
                  store.jobs[dialogReferral.id]!,
                )"
                :key="t"
              >
                {{ t }}
              </option>
            </select></label
          ><label
            >Namuna ID<input
              :value="nextSampleId(store)"
              readonly
              aria-label="Avtomatik namuna ID"
            /><small>Avtomatik yaratiladi.</small></label
          ><label
            >Olingan sana *<DateField
              v-model="collectForm.date"
              label="Namuna olingan sana"
              :max="localDate()" /></label
          ><label
            >Olingan vaqt *<input
              v-model="collectForm.time"
              type="time"
              aria-label="Namuna olingan vaqt"
          /></label>
        </div>
        <label class="lb-note"
          >Olgan xodim<input
            :value="`${actor.name} · ${actor.specialty}`"
            readonly /></label
        ><label class="lb-note"
          >Izoh<textarea
            v-model="collectForm.note"
            aria-label="Namuna izohi"
            maxlength="500"
          />
        </label>
        <p class="dx-info">
          Faqat namuna amalda olingandan keyin tasdiqlang. Bu lokal namuna
          rejimi.
        </p>
        <p v-if="error" class="dx-error" role="alert">{{ error }}</p>
      </div>
      <template #footer="{ close }"
        ><footer>
          <button class="dx-button" @click="close">Bekor qilish</button
          ><button
            class="dx-button dx-primary"
            :disabled="!caps.collect"
            @click="collect"
          >
            Namuna olinganini tasdiqlash
          </button>
        </footer></template
      ></Dialog
    >
    <Dialog
      v-if="modal === 'start' && dialogReferral"
      class="dx-dialog lb-dialog"
      title="Tahlilni boshlash"
      subtitle="Bemor va namuna ma’lumotlarini tekshiring"
      :icon="TestTube"
      @close="closeModal"
      ><div class="dx-modal-body">
        <h2>{{ dialogReferral.patientName }}</h2>
        <p>{{ dialogReferral.patientId }} · {{ dialogReferral.dob }}</p>
        <dl>
          <dt>Tahlil</dt>
          <dd>{{ testNames(dialogReferral) }}</dd>
          <dt>Yo‘llanma</dt>
          <dd>{{ dialogReferral.number }}</dd>
        </dl>
        <SampleInfo :samples="store.jobs[dialogReferral.id]!.samples" />
        <section class="dx-card">
          <h3>Tahlilni bajaruvchi</h3>
          <b>{{ actor.name }}</b>
          <p>{{ actor.specialty }}</p>
        </section>
        <p class="dx-info">
          Boshlangach holat “Jarayonda”ga o‘tadi va natija kiritish sahifasi
          ochiladi.
        </p>
        <p v-if="error" class="dx-error" role="alert">{{ error }}</p>
      </div>
      <template #footer
        ><footer>
          <button class="dx-button" @click="closeModal">Bekor qilish</button
          ><button
            class="dx-button dx-primary"
            :disabled="!caps.start"
            @click="start"
          >
            Tahlilni boshlash
          </button>
        </footer></template
      ></Dialog
    >
    <Dialog
      v-if="(modal === 'return' || modal === 'amend') && selected && job"
      class="dx-dialog lb-dialog"
      :title="
        modal === 'return' ? 'Qayta ishlashga qaytarish' : 'Tuzatish kiritish'
      "
      :subtitle="
        modal === 'amend'
          ? 'Tasdiqlangan laboratoriya natijasi uchun yangi versiya'
          : undefined
      "
      :dirty="modalDirty"
      @close="closeModal"
      ><div class="dx-modal-body">
        <h2>{{ selected.patientName }}</h2>
        <dl>
          <dt>Tahlil</dt>
          <dd>{{ testNames(selected) }}</dd>
          <dt>Yo‘llanma raqami</dt>
          <dd>{{ selected.number }}</dd>
          <dt>Namuna ID</dt>
          <dd>{{ job.samples.map((s) => s.id).join(", ") }}</dd>
          <dt>Joriy versiya</dt>
          <dd>
            {{ current?.number ?? job.draft?.number }} ·
            {{ current ? "Tasdiqlangan" : "Tekshirishda" }}
          </dd>
        </dl>
        <label class="lb-note"
          >{{
            modal === "return" ? "Qaytarish sababi" : "Tuzatish sababi"
          }}
          *<textarea
            v-model="reason"
            :aria-label="
              modal === 'return' ? 'Qaytarish sababi' : 'Tuzatish sababi'
            "
            maxlength="1000"
            placeholder="Sababni yozing..."
          />
        </label>
        <p class="dx-info">
          {{
            modal === "return"
              ? "Qiymatlar saqlanadi. Laborant qayta ochganda sabab ko‘rinadi."
              : "Oldingi natija o‘chirilmaydi. Yangi versiya qayta tekshirish va tasdiqlashdan o‘tadi."
          }}
        </p>
        <p v-if="modal === 'amend'">
          Yangi qoralama · {{ (current?.number ?? 0) + 1 }}-versiya
        </p>
        <p v-if="error" class="dx-error" role="alert">{{ error }}</p>
      </div>
      <template #footer="{ close }"
        ><footer>
          <button class="dx-button" @click="close">Bekor qilish</button
          ><button
            class="dx-button dx-primary"
            :disabled="!reason.trim()"
            @click="modal === 'return' ? returnWork() : amendment()"
          >
            {{ modal === "return" ? "Qaytarish" : "Tuzatishni boshlash" }}
          </button>
        </footer></template
      ></Dialog
    >
    <Dialog
      v-if="modal === 'confirm' && selected && job?.draft"
      class="dx-dialog lb-dialog"
      title="Natijani tasdiqlash"
      :icon="CheckCircle2"
      @close="closeModal"
      ><div class="dx-modal-body">
        <h2>Tahlil natijasini tasdiqlaysizmi?</h2>
        <p>
          Tasdiqlangan natija bemor kartasida yo‘llagan shifokorga ko‘rinadi
          (lokal namuna).
        </p>
        <dl>
          <dt>Bemor</dt>
          <dd>{{ selected.patientName }}</dd>
          <dt>Tahlil</dt>
          <dd>{{ testNames(selected) }}</dd>
          <dt>Yo‘llanma</dt>
          <dd>{{ selected.number }}</dd>
          <dt>Namuna ID</dt>
          <dd>{{ job.samples.map((s) => s.id).join(", ") }}</dd>
          <dt>Ko‘rsatkichlar</dt>
          <dd>{{ filled }} / {{ job.draft.rows.length }} ta</dd>
          <dt>Tasdiqlovchi</dt>
          <dd>{{ actor.name }}</dd>
          <dt>Fayllar</dt>
          <dd>{{ job.draft.files.length }} ta</dd>
        </dl>
        <p v-if="abnormal" class="dx-warning">
          Me’yordan tashqari ko‘rsatkich: {{ abnormal }} ta. Natijalar
          tekshiruvchi tomonidan ko‘rib chiqilgan bo‘lishi kerak.
        </p>
        <p class="dx-info">
          Tasdiqlangandan so‘ng o‘zgarishlar yangi versiya orqali kiritiladi.
        </p>
        <p v-if="error" class="dx-error" role="alert">{{ error }}</p>
      </div>
      <template #footer
        ><footer>
          <button class="dx-button" @click="closeModal">Ortga qaytish</button
          ><button
            class="dx-button dx-primary"
            :disabled="!caps.confirm"
            @click="confirm"
          >
            Tasdiqlash
          </button>
        </footer></template
      ></Dialog
    >
    <Dialog
      v-if="modal === 'history' && historyVersion && job"
      class="dx-dialog dx-template-dialog lb-dialog"
      title="Versiyalar tarixi"
      @close="closeModal"
      ><div class="dx-template-body">
        <aside>
          <button
            v-for="v in job.versions"
            :key="v.number"
            @click="historyVersion = v"
          >
            {{ v.number }}-versiya
            {{ v.number === current?.number ? "· Amaldagi" : ""
            }}<small
              >{{ v.confirmedBy.name }} · {{ timestamp(v.confirmedAt) }}</small
            >
          </button>
        </aside>
        <section>
          <h2>{{ historyVersion.number }}-versiya · Faqat ko‘rish</h2>
          <p>
            {{ historyVersion.confirmedBy.name }} ·
            {{ timestamp(historyVersion.confirmedAt) }}
          </p>
          <p v-if="historyVersion.reason">Sabab: {{ historyVersion.reason }}</p>
          <LabRows :rows="historyVersion.rows" />
          <p>{{ historyVersion.note || "Izoh kiritilmagan." }}</p>
          <AttachmentGrid
            :files="historyVersion.files"
            @view="viewer = { files: historyVersion!.files, index: $event }"
          />
        </section>
      </div>
      <template #footer
        ><footer>
          <button
            class="dx-button"
            :disabled="exporting"
            @click="exportResult(historyVersion, true)"
          >
            Chop etish</button
          ><button
            class="dx-button"
            :disabled="exporting"
            @click="exportResult(historyVersion)"
          >
            PDF yuklab olish</button
          ><button class="dx-button dx-primary" @click="closeModal">
            Yopish
          </button>
        </footer></template
      ></Dialog
    >
    <Dialog
      v-if="modal === 'label' && labelSample && labelReferral"
      class="dx-dialog lb-dialog"
      title="Namuna yorlig‘i"
      @close="closeModal"
      ><div class="dx-modal-body lb-label">
        <h2>{{ labelReferral.patientName }} · {{ labelReferral.patientId }}</h2>
        <p>{{ labelSample.type }} · {{ timestamp(labelSample.collectedAt) }}</p>
        <svg
          :viewBox="`0 0 ${specimenBarcode(labelSample.id).width} 60`"
          role="img"
          :aria-label="`Code 39 shtrix-kod ${labelSample.id}`"
        >
          <path :d="specimenBarcode(labelSample.id).path" />
        </svg>
        <h2>{{ labelSample.id }}</h2>
        <p>Namuna hujjati · Lokal namuna</p>
        <p v-if="error" class="dx-error" role="alert">{{ error }}</p>
      </div>
      <template #footer
        ><footer>
          <button class="dx-button" @click="closeModal">Yopish</button
          ><button
            class="dx-button dx-primary"
            :disabled="exporting"
            @click="printLabel"
          >
            Yorliqni chop etish
          </button>
        </footer></template
      ></Dialog
    >
    <Dialog
      v-if="modal === 'leave'"
      class="dx-dialog lb-dialog"
      title="Saqlanmagan o‘zgarishlar"
      @close="closeModal"
      ><div class="dx-modal-body">
        Saqlanmagan o‘zgarishlar bekor qilinsinmi?
      </div>
      <template #footer
        ><footer>
          <button class="dx-button" @click="closeModal">Davom etish</button
          ><button class="dx-button dx-primary" @click="discard">
            O‘zgarishlarni bekor qilish
          </button>
        </footer></template
      ></Dialog
    >
    <FileViewer
      v-if="viewer"
      :files="viewer.files"
      :index="viewer.index"
      :context="`${selected?.patientName ?? ''} · ${selected ? testNames(selected) : ''}`"
      @close="viewer = undefined"
    />
  </div>
</template>
