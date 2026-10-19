<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import {
  Search,
  ChevronRight,
  Megaphone,
  FileText,
  Clock3,
  Plus,
  Eye,
  Download,
  Printer,
} from "lucide-vue-next";
import DateField from "../components/employees/DateField.vue";
import ReferralDialog from "../components/doctor/ReferralDialog.vue";
import HistoryPanel from "../components/doctor/HistoryPanel.vue";
import RecordsPanel from "../components/doctor/RecordsPanel.vue";
import { fileAction, printDemo } from "../components/doctor/mockFiles";
import "../components/employees/employees.css";
import {
  DEMO_DAY,
  DOCTOR,
  createDoctorQueue,
  createHistory,
  createResults,
  createDocuments,
  doctorKey,
  emptyDraft,
  SERVICES,
  type Encounter,
  type Draft,
  type HistoryVisit,
  type ResultRow,
  type DocumentRow,
  type Referral,
} from "../mocks/doctorWorkspace";
import { today, dateLabel } from "../mocks/employees";
const queue = ref(createDoctorQueue()),
  date = ref(DEMO_DAY),
  queueTab = ref("waiting"),
  query = ref(""),
  selectedId = ref("Q-1"),
  tab = ref("Bugungi qabul");
const notice = ref(""),
  referralOpen = ref(false);
const tabs = [
  "Bugungi qabul",
  "Tashriflar tarixi",
  "Tekshiruv natijalari",
  "Hujjatlar",
];
const selected = computed(() =>
  queue.value.find((e) => e.id === selectedId.value && e.date === date.value),
);
const queued = computed(() =>
  queue.value.filter(
    (e) =>
      e.date === date.value &&
      (queueTab.value === "completed"
        ? e.status === "completed"
        : e.status !== "completed") &&
      (!query.value ||
        e.name
          .toLocaleLowerCase()
          .includes(query.value.trim().toLocaleLowerCase()) ||
        e.ticket.toLocaleLowerCase().includes(query.value.toLocaleLowerCase())),
  ),
);
const waitingCount = computed(
  () =>
    queue.value.filter(
      (e) =>
        e.date === date.value &&
        !["completed", "in_progress"].includes(e.status),
    ).length,
);
const completedCount = computed(
  () =>
    queue.value.filter((e) => e.date === date.value && e.status === "completed")
      .length,
);
const drafts = reactive<Record<string, Draft>>({});
const referrals = reactive<Record<string, Referral[]>>({});
type PatientRecords = {
  history: HistoryVisit[];
  results: ResultRow[];
  documents: DocumentRow[];
};
const records = reactive<Record<string, PatientRecords>>({});
function prepare(e: Encounter) {
  const key = doctorKey(e);
  drafts[key] ??= emptyDraft();
  referrals[key] ??= [];
  records[e.patientId] ??=
    e.patientId === "P-00124"
      ? {
          history: createHistory(),
          results: createResults(),
          documents: createDocuments(),
        }
      : { history: [], results: [], documents: [] };
}
prepare(queue.value[0]!);
const draft = computed(() =>
  selected.value ? drafts[doctorKey(selected.value)]! : null,
);
const patientRecords = computed(() =>
  selected.value ? records[selected.value.patientId]! : null,
);
const currentReferrals = computed(() =>
  selected.value ? referrals[doctorKey(selected.value)]! : [],
);
const dirty = computed(() =>
  draft.value
    ? JSON.stringify({ ...draft.value, saved: "" }) !== draft.value.saved
    : false,
);
function choose(e: Encounter) {
  prepare(e);
  selectedId.value = e.id;
  notice.value = "";
  referralOpen.value = false;
}
function queueKind(value: string) {
  queueTab.value = value;
  query.value = "";
  const first = queue.value.find(
    (e) =>
      e.date === date.value &&
      (value === "completed"
        ? e.status === "completed"
        : e.status !== "completed"),
  );
  if (first) choose(first);
  else selectedId.value = "";
}
watch(date, () => {
  referralOpen.value = false;
  query.value = "";
  queueKind(queueTab.value);
  notice.value = "";
});
function next() {
  const e = queue.value.find(
    (e) => e.date === date.value && ["waiting", "walk_in"].includes(e.status),
  );
  if (!e) {
    notice.value = "Chaqirish uchun kutilayotgan bemor yo‘q.";
    return;
  }
  e.status = "called";
  queueTab.value = "waiting";
  query.value = "";
  choose(e);
  notice.value = `Demo: ${e.name} navbatda chaqirildi. Haqiqiy chaqiruv yuborilmadi.`;
}
function saveDraft() {
  if (!draft.value) return;
  draft.value.saved = JSON.stringify({ ...draft.value, saved: "" });
  notice.value = "Qoralama faqat frontend xotirasida saqlandi.";
}
function saveReferral(r: Referral) {
  if (!selected.value || r.encounterKey !== doctorKey(selected.value)) return;
  referrals[r.encounterKey]!.push(r);
  if (r.location === "internal") {
    const s = SERVICES.find((x) => x.id === r.service)!;
    patientRecords.value!.results.unshift({
      id: r.id,
      name: s.name,
      date: date.value,
      type: r.type as ResultRow["type"],
      doctor: DOCTOR.name,
      status: "Buyurilgan",
      external: false,
      referral: r.id,
    });
  }
  referralOpen.value = false;
  notice.value =
    "Demo yo‘llanma lokal ro‘yxatga qo‘shildi. Haqiqiy buyurtma yuborilmadi.";
}
function referralFile(r: Referral, action: "view" | "download" | "print") {
  const title = SERVICES.find((s) => s.id === r.service)?.name || "Yo‘llanma";
  if (action === "print") {
    if (
      !printDemo(title, [
        selected.value?.name || "",
        r.location === "external" ? "Tashqi yo‘llanma" : "Ichki yo‘llanma",
        title,
        r.note,
      ])
    )
      notice.value = "Chop etish oynasiga brauzer ruxsat bermadi.";
  } else
    void fileAction(title, "PDF", action).catch(
      () => (notice.value = "Demo faylni yaratib bo‘lmadi."),
    );
}
const statusLabel = (e: Encounter) =>
  ({
    in_progress: "Qabulda",
    waiting: "Kutmoqda",
    walk_in: "Jonli navbat",
    called: "Chaqirildi",
    completed: "Yakunlangan",
  })[e.status];
</script>
<template>
  <section
    class="employees-ui dw-ui"
    aria-description="Frontend namoyishi. Haqiqiy tibbiy yozuvlar yoki API integratsiyasi yo‘q."
  >
    <header class="dw-heading">
      <div>
        <h1>Shifokor kabineti</h1>
        <p>
          {{ DOCTOR.name }} · {{ DOCTOR.specialty }} · {{ DOCTOR.branch }} ·
          {{ DOCTOR.room }}
        </p>
      </div>
      <div class="dw-day">
        <DateField v-model="date" label="Qabul sanasi" /><button
          @click="date = today()"
        >
          Bugun
        </button>
      </div>
    </header>
    <p v-if="notice" class="dw-notice" role="status">{{ notice }}</p>
    <div class="dw-workspace">
      <aside class="dw-card dw-queue">
        <h2>Bugungi navbat</h2>
        <div class="dw-queue-tabs">
          <button
            :class="{ selected: queueTab === 'waiting' }"
            @click="queueKind('waiting')"
          >
            Kutilmoqda <b>{{ waitingCount }}</b></button
          ><button
            :class="{ selected: queueTab === 'completed' }"
            @click="queueKind('completed')"
          >
            Yakunlangan <b>{{ completedCount }}</b>
          </button>
        </div>
        <label class="dw-search"
          ><Search :size="19" /><input
            v-model="query"
            aria-label="Navbatdagi bemorni qidirish"
            placeholder="Bemor qidirish..."
        /></label>
        <div class="dw-queue-list">
          <button
            v-for="e in queued"
            :key="e.id"
            class="dw-queue-person"
            :class="{ selected: selectedId === e.id }"
            :aria-label="e.name + ' · ' + e.id"
            @click="choose(e)"
          >
            <span class="dw-avatar" :class="e.color">{{ e.initials }}</span
            ><span class="dw-queue-person-info"
              ><b>{{ e.name }}</b
              ><small>{{ e.time ? e.time + " · " : "" }}{{ e.ticket }}</small
              ><small>{{ e.age }} yosh</small></span
            ><span
              class="dw-badge"
              :class="
                e.status === 'walk_in'
                  ? 'purple'
                  : e.status === 'waiting'
                    ? 'yellow'
                    : e.status === 'completed'
                      ? 'green'
                      : 'blue'
              "
              >{{ statusLabel(e) }}</span
            ><ChevronRight :size="18" />
          </button>
          <p v-if="!queued.length" class="dw-empty">
            Bu sana yoki qidiruv uchun bemor topilmadi.
          </p>
        </div>
        <button class="e-button dw-call" @click="next">
          <Megaphone :size="21" />Keyingi bemorni chaqirish
        </button>
      </aside>
      <div v-if="selected && draft && patientRecords" class="dw-content">
        <article class="dw-card dw-patient-card">
          <div class="dw-patient-line">
            <span class="dw-avatar large" :class="selected.color">{{
              selected.initials
            }}</span>
            <div>
              <h2>{{ selected.name }}</h2>
              <p>
                {{ selected.patientId }} · {{ selected.gender }} ·
                {{ selected.age }} yosh
                <template v-if="selected.dob"
                  >· {{ dateLabel(selected.dob) }}</template
                >
              </p>
              <p>{{ selected.phone }}</p>
            </div>
            <div class="dw-patient-actions">
              <button
                class="e-button dw-small"
                @click="
                  notice =
                    'Bemor kartasi shu bosqichda alohida ochilmaydi; haqiqiy Bemorlar sahifasi o‘zgartirilmagan.'
                "
              >
                <FileText :size="18" />Bemor kartasi</button
              ><span class="dw-badge blue">{{ statusLabel(selected) }}</span>
            </div>
          </div>
          <button
            class="dw-allergy"
            @click="
              notice = selected.allergy
                ? 'Allergiya: ' + selected.allergy
                : 'Allergiya: Ma’lumot kiritilmagan. Bu allergiya yo‘q degani emas.'
            "
          >
            <Clock3 :size="18" /><span
              ><b>Allergiya:</b>
              {{ selected.allergy || "Ma’lumot kiritilmagan" }}</span
            ><ChevronRight :size="18" />
          </button>
        </article>
        <div class="dw-tabs" role="tablist" aria-label="Bemor tafsilotlari">
          <button
            v-for="t in tabs"
            :key="t"
            role="tab"
            :aria-selected="tab === t"
            :class="{ selected: tab === t }"
            @click="tab = t"
          >
            {{ t }}
          </button>
        </div>
        <article v-if="tab === 'Bugungi qabul'" class="dw-card dw-current">
          <div class="dw-section-title">
            <h2>Qabul yozuvi</h2>
            <span class="dw-start"
              ><Clock3 :size="17" />Qabul boshlandi:
              {{ selected.startedAt || "—" }}
              <span class="dw-dirty"
                >●
                {{
                  dirty ? "Saqlanmagan o‘zgarishlar" : "Qoralama saqlangan"
                }}</span
              ></span
            >
          </div>
          <fieldset :disabled="selected.status === 'completed'">
            <div class="dw-grid-2">
              <label class="dw-field"
                >Shikoyatlar<textarea
                  v-model="draft.complaints"
                  aria-label="Shikoyatlar"
                  placeholder="Shikoyatlarni kiriting..."
                  rows="3"
                /></label
              ><label class="dw-field"
                >Anamnez<textarea
                  v-model="draft.anamnesis"
                  aria-label="Anamnez"
                  placeholder="Kasallik tarixini kiriting..."
                  rows="3"
                />
              </label>
            </div>
            <label class="dw-field"
              >Ko‘rik natijalari<textarea
                v-model="draft.examination"
                aria-label="Ko‘rik natijalari"
                placeholder="Ko‘rik natijalarini kiriting..."
                rows="2"
              />
            </label>
            <div class="dw-diagnosis">
              <label for="dw-diagnosis">Tashxis</label
              ><label class="dw-search"
                ><Search :size="19" /><input
                  id="dw-diagnosis"
                  v-model="draft.diagnosis"
                  aria-label="Tashxis nomi yoki kodi"
                  placeholder="Tashxis nomi yoki kodi..." /></label
              ><select v-model="draft.diagnosisKind" aria-label="Tashxis turi">
                <option>Dastlabki</option>
                <option>Yakuniy</option>
              </select>
            </div>
            <label class="dw-field"
              >Tavsiyalar<textarea
                v-model="draft.recommendations"
                aria-label="Tavsiyalar"
                placeholder="Tavsiyalarni kiriting..."
                rows="2"
              />
            </label>
            <div class="dw-current-actions">
              <button class="e-button dw-small" @click="referralOpen = true">
                <Plus :size="20" />Yo‘llanma</button
              ><button
                class="e-button dw-small"
                @click="notice = 'Retsept yozish oynasi hali tasdiqlanmagan.'"
              >
                <Plus :size="20" />Retsept</button
              ><span /><button class="e-button dw-small" @click="saveDraft">
                Qoralamani saqlash</button
              ><button
                class="e-button primary dw-small"
                @click="
                  notice =
                    'Qabulni yakunlash oynasi hali tasdiqlanmagan. Qabul holati o‘zgarmadi.'
                "
              >
                Qabulni yakunlash
              </button>
            </div>
          </fieldset>
          <p v-if="selected.status === 'completed'" class="dw-footnote">
            <Eye :size="17" />Yakunlangan qabul — faqat ko‘rish uchun.
          </p>
          <section v-if="currentReferrals.length" class="dw-referrals">
            <h3>Yo‘llanmalar</h3>
            <div class="dw-scroll">
              <table>
                <thead>
                  <tr>
                    <th>Xizmat</th>
                    <th>Turi</th>
                    <th>Holati</th>
                    <th>Amal</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="r in currentReferrals" :key="r.id">
                    <td>
                      {{ SERVICES.find((s) => s.id === r.service)?.name }}
                    </td>
                    <td>
                      {{ r.type }}
                      <span
                        v-if="r.location === 'external'"
                        class="dw-badge purple"
                        >Tashqi</span
                      >
                    </td>
                    <td>
                      {{
                        r.location === "external"
                          ? "Natija kutilmoqda"
                          : "Buyurilgan"
                      }}
                    </td>
                    <td>
                      <div class="dw-doc-actions">
                        <button
                          class="e-icon"
                          aria-label="Yo‘llanma demo PDF ko‘rish"
                          @click="referralFile(r, 'view')"
                        >
                          <Eye :size="17" /></button
                        ><button
                          class="e-icon"
                          aria-label="Yo‘llanma demo PDF yuklab olish"
                          @click="referralFile(r, 'download')"
                        >
                          <Download :size="17" /></button
                        ><button
                          class="e-icon"
                          aria-label="Yo‘llanmani chop etish"
                          @click="referralFile(r, 'print')"
                        >
                          <Printer :size="17" />
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </article>
        <HistoryPanel
          v-else-if="tab === 'Tashriflar tarixi'"
          :key="selected.patientId"
          :encounter="selected"
          :visits="patientRecords.history"
          @notice="notice = $event"
        /><RecordsPanel
          v-else
          :key="selected.patientId + tab"
          :kind="tab === 'Tekshiruv natijalari' ? 'results' : 'documents'"
          :encounter="selected"
          :results="patientRecords.results"
          :documents="patientRecords.documents"
          @notice="notice = $event"
        />
      </div>
      <article v-else class="dw-card dw-empty">
        Bemorni tanlang yoki boshqa sanani belgilang.
      </article>
    </div>
    <ReferralDialog
      v-if="referralOpen && selected"
      :encounter="selected"
      @close="referralOpen = false"
      @save="saveReferral"
    />
  </section>
</template>
<style src="../components/doctor/doctor.css"></style>
