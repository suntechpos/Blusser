<script setup lang="ts">
import { computed, ref, watch, onMounted, onBeforeUnmount } from "vue";
import {
  Activity,
  FileText,
  Hourglass,
  CheckCircle2,
  Eye,
  Play,
  ArrowLeft,
  RefreshCw,
  Printer,
  Download,
  Save,
  Check,
  Search,
} from "lucide-vue-next";
import { session } from "../services/session";
import {
  createDiagnosticsStore,
  filteredReferrals,
  internalReferrals,
  tests,
  sampleCapabilities,
  startExam,
  cloneDraft,
  saveDraft,
  confirmResult,
  amendResult,
  draftSignature,
  validateResult,
  diagnosticTemplates,
  plainText,
  type DiagnosticReferral,
  type ResultDraft,
  type ResultVersion,
  type SampleCapabilities,
} from "../mocks/diagnostics";
import { timestamp, age, safeHtml } from "../components/diagnostics/content";
import DateField from "../components/employees/DateField.vue";
import DiagnosticEditor from "../components/diagnostics/DiagnosticEditor.vue";
import AttachmentGrid from "../components/diagnostics/AttachmentGrid.vue";
import FileViewer from "../components/diagnostics/FileViewer.vue";
import Dialog from "../components/doctor/WorkspaceDialog.vue";
import { downloadBlob, printBlob } from "../components/doctor/localFiles";
import { resultPdf } from "../components/diagnostics/report";
import "../components/diagnostics/diagnostics.css";
let noticeTimer: ReturnType<typeof setTimeout> | undefined;
const props = defineProps<{ capabilities?: SampleCapabilities }>();
const caps = computed(() => props.capabilities ?? sampleCapabilities);
const store = ref(createDiagnosticsStore());
const filter = ref({
  date: new Date().toLocaleDateString("en-CA"),
  query: "",
  testId: "",
  doctorId: "",
  branchId: "",
  status: "",
});
// Local calendar date, independent of UTC offset.
filter.value.date = store.value.referrals[0]!.createdAt.slice(0, 10);
const page = ref(1),
  pageSize = ref(10),
  selected = ref<DiagnosticReferral>(),
  detail = ref<DiagnosticReferral>(),
  edit = ref<ResultDraft>(),
  signature = ref(""),
  fileBusy = ref(false),
  error = ref(""),
  notice = ref(""),
  errors = ref<Record<string, string>>({}),
  modal = ref(""),
  reason = ref(""),
  templateId = ref("abdomen"),
  templateQuery = ref(""),
  replace = ref(false),
  historyVersion = ref<ResultVersion>(),
  viewer = ref<{ files: ResultVersion["files"]; index: number }>(),
  exporting = ref(false);
watch(notice, () => {
  clearTimeout(noticeTimer);
  if (notice.value) noticeTimer = setTimeout(() => (notice.value = ""), 4000);
});
const exam = computed(() =>
    selected.value ? store.value.exams[selected.value.examId] : undefined,
  ),
  current = computed(() => exam.value?.versions.at(-1));
const dirty = computed(
  () => !!edit.value && draftSignature(edit.value) !== signature.value,
);
const rows = computed(() => filteredReferrals(store.value, filter.value));
const visible = computed(() =>
  rows.value.slice(
    (page.value - 1) * pageSize.value,
    page.value * pageSize.value,
  ),
);
const base = computed(() =>
  filteredReferrals(store.value, { ...filter.value, status: "" }),
);
const labels: Record<string, string> = {
  new: "Yangi",
  waiting: "Kutilmoqda",
  in_progress: "Tekshiruvda",
  completed: "Yakunlangan",
};
const statuses = ["new", "waiting", "in_progress", "completed"];
const cards = [FileText, Hourglass, Activity, CheckCircle2];
const doctors = computed(() => [
  ...new Map(
    internalReferrals(store.value).map((r) => [r.doctorId, r.doctorName]),
  ).entries(),
]);
const branches = computed(() => [
  ...new Map(
    internalReferrals(store.value).map((r) => [r.branchId, r.branchName]),
  ).entries(),
]);
const actor = computed(() => ({
  id: String(session.user?.id ?? "local-session"),
  name: session.user?.login ?? "Lokal mutaxassis",
  specialty: "Diagnostika mutaxassisi (namuna)",
}));
const templates = computed(() =>
  diagnosticTemplates.filter((t) =>
    t.name
      .toLocaleLowerCase()
      .includes(templateQuery.value.toLocaleLowerCase()),
  ),
);
const template = computed(
  () => diagnosticTemplates.find((t) => t.id === templateId.value)!,
);
watch(filter, () => (page.value = 1), { deep: true });
watch(pageSize, () => (page.value = 1));
function attempt(fn: () => void) {
  error.value = "";
  notice.value = "";
  try {
    fn();
  } catch (e) {
    error.value = (e as Error).message;
  }
}
function open(r: DiagnosticReferral) {
  attempt(() => {
    const e = store.value.exams[r.examId]!;
    if (e.status === "new") {
      detail.value = r;
      return;
    }
    if (e.status === "waiting") startExam(e, actor.value, caps.value);
    selected.value = r;
    edit.value =
      e.status === "in_progress" && e.draft ? cloneDraft(e.draft) : undefined;
    signature.value = edit.value ? draftSignature(edit.value) : "";
    errors.value = {};
  });
}
function leave() {
  if (fileBusy.value) return;
  if (dirty.value) {
    modal.value = "leave";
    return;
  }
  selected.value = undefined;
  edit.value = undefined;
}
function save() {
  if (!exam.value || !edit.value || fileBusy.value) return;
  attempt(() => {
    const saved = saveDraft(exam.value!, edit.value!, caps.value);
    edit.value = cloneDraft(saved);
    signature.value = draftSignature(saved);
    notice.value = "Qoralama lokal saqlandi.";
  });
}
function askConfirm() {
  if (!edit.value || fileBusy.value || !caps.value.confirm) return;
  errors.value = validateResult(edit.value);
  if (Object.keys(errors.value).length) {
    error.value = "Majburiy maydonlarni tekshiring.";
    return;
  }
  modal.value = "confirm";
}
function confirm() {
  if (!exam.value || !edit.value) return;
  attempt(() => {
    confirmResult(exam.value!, edit.value!, actor.value, caps.value);
    edit.value = undefined;
    modal.value = "";
    notice.value = "Natija tasdiqlandi (lokal).";
  });
}
function amend() {
  attempt(() => {
    edit.value = amendResult(
      exam.value!,
      reason.value,
      actor.value,
      caps.value,
    );
    signature.value = draftSignature(edit.value!);
    modal.value = "";
  });
}
function applyTemplate() {
  if (!edit.value) return;
  if (
    (plainText(edit.value.description) || plainText(edit.value.conclusion)) &&
    !replace.value
  ) {
    replace.value = true;
    return;
  }
  edit.value.description = template.value.description;
  edit.value.conclusion = template.value.conclusion;
  modal.value = "";
  replace.value = false;
}
async function report(v: ResultVersion, print = false) {
  if (!selected.value || !exam.value || exporting.value) return;
  error.value = "";
  exporting.value = true;
  try {
    const blob = await resultPdf(
      selected.value,
      exam.value,
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
function beforeUnload(e: BeforeUnloadEvent) {
  if (dirty.value || fileBusy.value) {
    e.preventDefault();
    e.returnValue = "";
  }
}
// Shell navigation is blocked before its click handler; confirmation replays the original action.
let pending: HTMLElement | undefined;
let pendingAction: (() => void) | undefined;
function guardLeave(action: () => void) {
  if (!dirty.value && !fileBusy.value) return false;
  if (!fileBusy.value) {
    pendingAction = action;
    modal.value = "leave";
  }
  return true;
}
defineExpose({ guardLeave });
let bypass = false;
function navigation(e: MouseEvent) {
  const target = (e.target as Element)?.closest<HTMLElement>(
    ".clinic-sidebar button,.clinic-profile-menu button,.clinic-selector select",
  );
  if (!target || bypass || (!dirty.value && !fileBusy.value)) return;
  e.preventDefault();
  e.stopImmediatePropagation();
  if (!fileBusy.value) {
    pending = target;
    modal.value = "leave";
  }
}
function clinicChange(e: Event) {
  const target = e.target as HTMLSelectElement;
  if (
    !target.matches?.(".clinic-selector select") ||
    (!dirty.value && !fileBusy.value)
  )
    return;
  const requested = target.value;
  e.stopImmediatePropagation();
  target.value = String(session.user?.active_tenant_id ?? "");
  guardLeave(() => {
    target.value = requested;
    target.dispatchEvent(new Event("change", { bubbles: true }));
  });
}
function discard() {
  selected.value = undefined;
  edit.value = undefined;
  modal.value = "";
  if (pendingAction) {
    const action = pendingAction;
    pendingAction = undefined;
    action();
  }
  if (pending) {
    const target = pending;
    pending = undefined;
    bypass = true;
    target.click();
    bypass = false;
  }
}
onMounted(() => {
  window.addEventListener("beforeunload", beforeUnload);
  document.addEventListener("click", navigation, true);
  document.addEventListener("change", clinicChange, true);
});
onBeforeUnmount(() => {
  clearTimeout(noticeTimer);
  window.removeEventListener("beforeunload", beforeUnload);
  document.removeEventListener("click", navigation, true);
  document.removeEventListener("change", clinicChange, true);
});
</script>
<template>
  <div class="dx-workspace">
    <p v-if="error" class="dx-error" role="alert">{{ error }}</p>
    <p v-if="notice" class="dx-success" role="status">{{ notice }}</p>
    <template v-if="!selected"
      ><div class="dx-heading">
        <div>
          <h1>UZI va Diagnostika</h1>
          <p>Ichki yo‘llanmalar, tekshiruvlar va natijalar.</p>
        </div>
        <div class="dx-actions">
          <DateField v-model="filter.date" label="Yo‘llanma sanasi" /><button
            class="dx-icon"
            aria-label="Ro‘yxatni yangilash"
            @click="
              notice = 'Lokal ro‘yxat yangilandi. Serverga so‘rov yuborilmadi.'
            "
          >
            <RefreshCw />
          </button>
        </div>
      </div>
      <div class="dx-stats">
        <article v-for="(status, i) in statuses" :key="status" class="dx-card">
          <span :class="['dx-stat-icon', status]"
            ><component :is="cards[i]"
          /></span>
          <div>
            <small>{{
              status === "new" ? "Yangi yo‘llanmalar" : labels[status]
            }}</small
            ><strong>{{
              base.filter((r) => store.exams[r.examId]?.status === status)
                .length
            }}</strong>
          </div>
        </article>
      </div>
      <section class="dx-card dx-list">
        <nav class="dx-tabs">
          <button
            :class="{ active: !filter.status }"
            @click="filter.status = ''"
          >
            Barchasi <span>{{ base.length }}</span></button
          ><button
            v-for="status in statuses"
            :key="status"
            :class="{ active: filter.status === status }"
            @click="filter.status = status"
          >
            {{ labels[status] }}
            <span>{{
              base.filter((r) => store.exams[r.examId]?.status === status)
                .length
            }}</span>
          </button>
        </nav>
        <div class="dx-filters">
          <label class="dx-search"
            ><Search :size="19" /><input
              v-model="filter.query"
              placeholder="Bemor yoki yo‘llanma raqami..."
              aria-label="Bemor yoki yo‘llanma qidirish" /></label
          ><select v-model="filter.testId" aria-label="Tekshiruv">
            <option value="">Barcha tekshiruvlar</option>
            <option v-for="t in tests" :key="t.id" :value="t.id">
              {{ t.name }}
            </option></select
          ><select v-model="filter.doctorId" aria-label="Yo‘llagan shifokor">
            <option value="">Yo‘llagan shifokor</option>
            <option v-for="d in doctors" :key="d[0]" :value="d[0]">
              {{ d[1] }}
            </option></select
          ><select v-model="filter.branchId" aria-label="Filial">
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
                <th>Tekshiruv</th>
                <th>Yo‘llagan shifokor</th>
                <th>Vaqt</th>
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
                <td>{{ r.testName }}</td>
                <td>
                  <b>{{ r.doctorName }}</b
                  ><small>{{ r.doctorSpecialty }}</small>
                </td>
                <td>
                  {{
                    r.scheduledAt
                      ? timestamp(r.scheduledAt).split(" · ")[1]
                      : "Belgilanmagan"
                  }}
                </td>
                <td>
                  <span :class="['dx-badge', store.exams[r.examId]!.status]">{{
                    labels[store.exams[r.examId]!.status]
                  }}</span>
                </td>
                <td>
                  <div class="dx-actions">
                    <button
                      class="dx-button"
                      :class="{
                        'dx-primary': ['waiting', 'in_progress'].includes(
                          store.exams[r.examId]!.status,
                        ),
                      }"
                      :disabled="
                        store.exams[r.examId]!.status === 'waiting' &&
                        !caps.start
                      "
                      @click="open(r)"
                    >
                      {{
                        {
                          new: "Ko‘rish",
                          waiting: "Boshlash",
                          in_progress: "Davom etish",
                          completed: "Natija",
                        }[store.exams[r.examId]!.status]
                      }}</button
                    ><button
                      class="dx-icon"
                      :aria-label="`${r.number} tafsilotlari`"
                      @click="detail = r"
                    >
                      <Eye :size="19" />
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="!visible.length">
                <td colspan="7" class="dx-empty">Yo‘llanma topilmadi</td>
              </tr>
            </tbody>
          </table>
        </div>
        <footer class="dx-pagination">
          <span
            >{{ rows.length ? (page - 1) * pageSize + 1 : 0 }}–{{
              Math.min(page * pageSize, rows.length)
            }}
            / {{ rows.length }} ta yo‘llanma</span
          ><select v-model.number="pageSize" aria-label="Sahifadagi qatorlar">
            <option :value="10">10 ta</option>
            <option :value="5">5 ta</option></select
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
      </section>
      <p class="dx-bottom-note">
        ⓘ Faqat klinikamizga berilgan ichki yo‘llanmalar ko‘rsatiladi.<span
          >Namunaviy ma’lumotlar</span
        >
      </p></template
    >
    <template v-else-if="exam"
      ><button class="dx-link" :disabled="fileBusy" @click="leave">
        <ArrowLeft :size="18" />Ro‘yxatga qaytish</button
      ><small class="dx-breadcrumb"
        >UZI va Diagnostika / {{ selected.number }}</small
      >
      <div class="dx-heading">
        <div>
          <h1>
            {{ selected.testName }}
            <span :class="['dx-badge', exam.status]">{{
              labels[exam.status]
            }}</span>
          </h1>
          <p>
            Yo‘llanma {{ selected.number }} ·
            {{ timestamp(selected.createdAt) }}
          </p>
        </div>
        <div v-if="!edit && current" class="dx-actions">
          <button
            class="dx-button"
            :disabled="exporting"
            @click="report(current, true)"
          >
            <Printer :size="18" />Chop etish</button
          ><button
            class="dx-button dx-primary"
            :disabled="exporting"
            @click="report(current)"
          >
            <Download :size="18" />PDF yuklab olish
          </button>
        </div>
      </div>
      <div v-if="!edit && current" class="dx-success">
        ✓
        {{ current.number > 1 ? "Natija yangilangan" : "Natija tasdiqlangan" }}
        · {{ timestamp(current.confirmedAt) }}
      </div>
      <div v-if="edit && exam.status === 'completed'" class="dx-info">
        Tuzatish qoralamasi · {{ edit.number }}-versiya · {{ edit.reason }}.
        Shifokorga avvalgi tasdiqlangan natija ko‘rinadi.
      </div>
      <section class="dx-card dx-patient">
        <span class="dx-avatar">{{
          selected.patientName
            .split(" ")
            .map((x) => x[0])
            .join("")
        }}</span>
        <div>
          <h2>{{ selected.patientName }}</h2>
          <p>
            {{ selected.patientId }} · {{ selected.gender }} ·
            {{ age(selected.dob) }} yosh · {{ selected.dob }}
          </p>
        </div>
        <span>{{ selected.phone }}</span>
      </section>
      <div class="dx-exam-grid">
        <div>
          <section class="dx-card dx-result">
            <div class="dx-card-heading">
              <h2>Tekshiruv natijasi</h2>
              <button
                v-if="edit"
                class="dx-button"
                @click="
                  modal = 'template';
                  replace = false;
                "
              >
                <FileText :size="18" />Shablondan foydalanish
              </button>
            </div>
            <template v-if="edit"
              ><DiagnosticEditor
                v-model="edit.description"
                label="Tekshiruv tavsifi"
                placeholder="Tekshiruv davomida aniqlangan ma’lumotlarni kiriting..."
                :error="errors.description" /><DiagnosticEditor
                v-model="edit.conclusion"
                label="Xulosa"
                placeholder="Tekshiruv xulosasini kiriting..."
                :error="errors.conclusion" /></template
            ><template v-else-if="current"
              ><h3>Tekshiruv tavsifi</h3>
              <div class="dx-rich" v-html="safeHtml(current.description)" />
              <hr />
              <h3>Xulosa</h3>
              <div class="dx-rich" v-html="safeHtml(current.conclusion)"
            /></template>
          </section>
          <AttachmentGrid
            :files="edit?.files ?? current?.files ?? []"
            :editable="!!edit"
            @change="
              (files) => {
                if (edit) edit.files = files;
              }
            "
            @busy="fileBusy = $event"
            @view="
              viewer = {
                files: edit?.files ?? current?.files ?? [],
                index: $event,
              }
            "
          />
        </div>
        <aside>
          <section class="dx-card">
            <h2>Yo‘llanma ma’lumotlari</h2>
            <dl>
              <dt>Yo‘llagan shifokor</dt>
              <dd>
                <b>{{ selected.doctorName }}</b
                ><small>{{ selected.doctorSpecialty }}</small>
              </dd>
              <dt>Yuborish sababi</dt>
              <dd>{{ selected.reason }}</dd>
              <dt>Shifokor izohi</dt>
              <dd>{{ selected.note }}</dd>
              <dt>Filial</dt>
              <dd>{{ selected.branchName }}</dd>
              <dt>Belgilangan vaqt</dt>
              <dd>{{ timestamp(selected.scheduledAt) }}</dd>
            </dl>
          </section>
          <section class="dx-card">
            <h2>
              {{ edit ? "Bajaruvchi mutaxassis" : "Tasdiqlash ma’lumotlari" }}
            </h2>
            <h3>{{ edit?.author.name ?? current?.author.name }}</h3>
            <p>{{ edit?.author.specialty ?? current?.author.specialty }}</p>
            <dl>
              <dt>Boshlangan vaqt</dt>
              <dd>{{ timestamp(exam.startedAt) }}</dd>
              <template v-if="current"
                ><dt>Tasdiqlangan vaqt</dt>
                <dd>{{ timestamp(current.confirmedAt) }}</dd>
                <dt>Natija versiyasi</dt>
                <dd>{{ current.number }}</dd></template
              >
            </dl>
            <button
              v-if="exam.versions.length"
              class="dx-link"
              @click="
                modal = 'history';
                historyVersion = current;
              "
            >
              Versiyalar tarixi ({{ exam.versions.length }})
            </button>
          </section>
        </aside>
      </div>
      <footer class="dx-sticky">
        <span>{{
          edit
            ? dirty
              ? "Saqlanmagan o‘zgarishlar"
              : "Natija hali tasdiqlanmagan"
            : "Tasdiqlangan natija · Faqat ko‘rish"
        }}</span
        ><template v-if="edit"
          ><button
            class="dx-button"
            :disabled="fileBusy || !caps.draft"
            @click="save"
          >
            <Save :size="18" />Qoralamani saqlash</button
          ><button
            class="dx-button dx-primary"
            :disabled="fileBusy || !caps.confirm"
            @click="askConfirm"
          >
            <Check :size="19" />{{
              exam.status === "completed"
                ? "Tuzatilgan natijani tasdiqlash"
                : "Natijani tasdiqlash"
            }}
          </button></template
        ><template v-else
          ><button
            v-if="caps.amend"
            class="dx-button"
            @click="
              reason = '';
              exam.draft ? amend() : (modal = 'amend');
            "
          >
            {{
              exam.draft ? "Tuzatishni davom ettirish" : "Tuzatish kiritish"
            }}</button
          ><button class="dx-button" @click="leave">
            Ro‘yxatga qaytish
          </button></template
        ><small>Namunaviy ma’lumotlar</small>
      </footer></template
    >
    <Dialog
      v-if="detail"
      class="dx-dialog"
      title="Yo‘llanma tafsilotlari"
      :subtitle="detail.number"
      @close="detail = undefined"
      ><div class="dx-modal-body">
        <span :class="['dx-badge', store.exams[detail.examId]!.status]">{{
          labels[store.exams[detail.examId]!.status]
        }}</span>
        <h2>{{ detail.patientName }}</h2>
        <p>
          {{ detail.patientId }} · {{ detail.gender }} ·
          {{ age(detail.dob) }} yosh · {{ detail.dob }}
        </p>
        <dl>
          <dt>Tekshiruv</dt>
          <dd>{{ detail.testName }}</dd>
          <dt>Filial</dt>
          <dd>{{ detail.branchName }}</dd>
          <dt>Yo‘llagan shifokor</dt>
          <dd>{{ detail.doctorName }} · {{ detail.doctorSpecialty }}</dd>
          <dt>Bajaruvchi mutaxassis</dt>
          <dd>
            {{ store.exams[detail.examId]?.performer?.name ?? "Belgilanmagan" }}
          </dd>
          <dt>Yaratilgan vaqt</dt>
          <dd>{{ timestamp(detail.createdAt) }}</dd>
          <dt>Belgilangan vaqt</dt>
          <dd>{{ timestamp(detail.scheduledAt) }}</dd>
          <dt>Yuborish sababi</dt>
          <dd>{{ detail.reason }}</dd>
          <dt>Shifokor izohi</dt>
          <dd>{{ detail.note }}</dd>
        </dl>
      </div>
      <template #footer
        ><footer>
          <button class="dx-button" @click="detail = undefined">Yopish</button
          ><button
            v-if="
              store.exams[detail.examId]!.status !== 'new' &&
              (store.exams[detail.examId]!.status !== 'waiting' || caps.start)
            "
            class="dx-button dx-primary"
            @click="
              open(detail);
              detail = undefined;
            "
          >
            <Play :size="18" />{{
              {
                waiting: "Tekshiruvni boshlash",
                in_progress: "Davom etish",
                completed: "Natijani ko‘rish",
                new: "Ko‘rish",
              }[store.exams[detail.examId]!.status]
            }}
          </button>
        </footer></template
      ></Dialog
    >
    <Dialog
      v-if="modal === 'template'"
      class="dx-dialog dx-template-dialog"
      title="Shablon tanlash"
      subtitle="Tekshiruvga mos tuzilmani tanlang va to‘ldiring"
      @close="modal = ''"
      ><div class="dx-template-body">
        <aside>
          <input
            v-model="templateQuery"
            placeholder="Shablon qidirish..."
            aria-label="Shablon qidirish"
          /><button
            v-for="t in templates"
            :key="t.id"
            :class="{ active: templateId === t.id }"
            @click="
              templateId = t.id;
              replace = false;
            "
          >
            {{ t.name }}
          </button>
          <p v-if="!templates.length">Shablon topilmadi</p>
        </aside>
        <section>
          <h2>{{ template.name }}</h2>
          <article class="dx-card">
            <h3>Tekshiruv tavsifi</h3>
            <div v-html="safeHtml(template.description)" />
            <hr />
            <h3>Xulosa</h3>
            <div v-html="safeHtml(template.conclusion)" />
          </article>
          <p class="dx-info">
            Shablon tayyor tashxis emas. Maydonlarni tekshiruv natijasiga ko‘ra
            to‘ldiring.
          </p>
          <p v-if="replace" role="alert" class="dx-warning">
            Mavjud tavsif va xulosa almashtiriladi. Fayllar saqlanadi.
          </p>
        </section>
      </div>
      <template #footer
        ><footer>
          <button class="dx-button" @click="modal = ''">Bekor qilish</button
          ><button class="dx-button dx-primary" @click="applyTemplate">
            {{ replace ? "Almashtirishni tasdiqlash" : "Shablonni qo‘llash" }}
          </button>
        </footer></template
      ></Dialog
    >
    <Dialog
      v-if="modal === 'confirm' && selected && edit"
      class="dx-dialog"
      title="Natijani tasdiqlash"
      @close="modal = ''"
      ><div class="dx-modal-body">
        <h2>Tekshiruv natijasini tasdiqlaysizmi?</h2>
        <p>
          Natija yakunlanadi va bemor kartasida yo‘llagan shifokorga ko‘rinadi
          (lokal namuna).
        </p>
        <dl>
          <dt>Bemor</dt>
          <dd>{{ selected.patientName }}</dd>
          <dt>Tekshiruv</dt>
          <dd>{{ selected.testName }}</dd>
          <dt>Yo‘llanma</dt>
          <dd>{{ selected.number }}</dd>
          <dt>Bajaruvchi</dt>
          <dd>{{ actor.name }}</dd>
          <dt>Biriktirilgan fayllar</dt>
          <dd>{{ edit.files.length }} ta fayl</dd>
          <dt>Versiya</dt>
          <dd>{{ edit.number }}</dd>
        </dl>
        <p class="dx-warning">
          Tasdiqlangandan so‘ng natijani o‘zgartirish alohida tuzatish orqali
          amalga oshiriladi.
        </p>
      </div>
      <template #footer
        ><footer>
          <button class="dx-button" @click="modal = ''">Ortga qaytish</button
          ><button class="dx-button dx-primary" @click="confirm">
            Tasdiqlash
          </button>
        </footer></template
      ></Dialog
    >
    <Dialog
      v-if="modal === 'amend'"
      class="dx-dialog"
      title="Tuzatish kiritish"
      subtitle="Tasdiqlangan natija uchun yangi versiya"
      @close="modal = ''"
      ><div class="dx-modal-body">
        <h2>{{ selected?.patientName }} · {{ selected?.number }}</h2>
        <p>{{ selected?.testName }}</p>
        <p>Joriy versiya: {{ current?.number }} · Tasdiqlangan</p>
        <label
          >Tuzatish sababi *<textarea
            v-model="reason"
            aria-label="Tuzatish sababi"
            placeholder="Tuzatish sababini yozing..."
          />
        </label>
        <p class="dx-info">
          Oldingi natija o‘chirilmaydi. Yangi versiya tasdiqlanguncha shifokorga
          amaldagi natija ko‘rinadi.
        </p>
        <p>Yangi qoralama · {{ (current?.number ?? 0) + 1 }}-versiya</p>
        <p v-if="error" role="alert">{{ error }}</p>
      </div>
      <template #footer
        ><footer>
          <button class="dx-button" @click="modal = ''">Bekor qilish</button
          ><button
            class="dx-button dx-primary"
            :disabled="!reason.trim()"
            @click="amend"
          >
            Tuzatishni boshlash
          </button>
        </footer></template
      ></Dialog
    >
    <Dialog
      v-if="modal === 'history' && historyVersion"
      class="dx-dialog dx-template-dialog"
      title="Versiyalar tarixi"
      @close="modal = ''"
      ><div class="dx-template-body">
        <aside>
          <button
            v-for="v in exam?.versions"
            :key="v.number"
            @click="historyVersion = v"
          >
            {{ v.number }}-versiya
            {{ v.number === current?.number ? "· Amaldagi" : ""
            }}<small>{{ timestamp(v.confirmedAt) }}</small>
          </button>
        </aside>
        <section>
          <h2>{{ historyVersion.number }}-versiya · Faqat ko‘rish</h2>
          <p>
            {{ historyVersion.author.name }} ·
            {{ timestamp(historyVersion.confirmedAt) }}
          </p>
          <p v-if="historyVersion.reason">Sabab: {{ historyVersion.reason }}</p>
          <h3>Tekshiruv tavsifi</h3>
          <div v-html="safeHtml(historyVersion.description)" />
          <h3>Xulosa</h3>
          <div v-html="safeHtml(historyVersion.conclusion)" />
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
            @click="report(historyVersion, true)"
          >
            Chop etish</button
          ><button
            class="dx-button"
            :disabled="exporting"
            @click="report(historyVersion)"
          >
            PDF yuklab olish</button
          ><button class="dx-button dx-primary" @click="modal = ''">
            Yopish
          </button>
        </footer></template
      ></Dialog
    >
    <Dialog
      v-if="modal === 'leave'"
      class="dx-dialog"
      title="Saqlanmagan o‘zgarishlar"
      @close="
        modal = '';
        pending = undefined;
        pendingAction = undefined;
      "
      ><div class="dx-modal-body">
        Saqlanmagan o‘zgarishlar bekor qilinsinmi?
      </div>
      <template #footer
        ><footer>
          <button
            class="dx-button"
            @click="
              modal = '';
              pending = undefined;
              pendingAction = undefined;
              pendingAction = undefined;
            "
          >
            Davom etish</button
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
      :context="`${selected?.patientName ?? ''} · ${selected?.number ?? ''}`"
      @close="viewer = undefined"
    />
  </div>
</template>
