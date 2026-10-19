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
} from "lucide-vue-next";
import DateField from "../components/employees/DateField.vue";
import PrescriptionDialog from "../components/doctor/PrescriptionDialog.vue";
import FinishDialog from "../components/doctor/FinishDialog.vue";
import ReferralDetailsDialog from "../components/doctor/ReferralDetailsDialog.vue";
import ReferralDialog from "../components/doctor/ReferralDialog.vue";
import HistoryPanel from "../components/doctor/HistoryPanel.vue";
import RecordsPanel from "../components/doctor/RecordsPanel.vue";

import "../components/employees/employees.css";
import { demoFile } from "../components/doctor/mockFiles";
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
  type Prescription,
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
const prescriptions = reactive<Record<string, Prescription[]>>({});
const prescriptionOpen = ref(false),
  finishOpen = ref(false);
const editingPrescription = ref<Prescription>();
const referralDetail = ref<Referral | null>(null),
  referralCreated = ref(false);
const currentPrescriptions = computed(() =>
  selected.value ? prescriptions[doctorKey(selected.value)] || [] : [],
);
function savePrescription(p: Prescription) {
  if (!selected.value || selected.value.status === "completed") return;
  const list = prescriptions[doctorKey(selected.value)]!;
  const index = list.findIndex((x) => x.id === p.id);
  if (index < 0) list.push(p);
  else list[index] = p;
  prescriptionOpen.value = false;
  notice.value = "Retsept faqat frontend xotirasida saqlandi.";
}
function finish(status: "completed" | "awaiting_results") {
  if (!selected.value) return;
  saveDraft();
  selected.value.status = status;
  finishOpen.value = false;
  notice.value =
    status === "completed"
      ? "Lokal qabul yakunlandi. Yozuvlar faqat ko‘rish uchun."
      : "Qabul natija kutishga qoldirildi. Uni Kutilmoqda ro‘yxatidan davom ettirish mumkin.";
  if (status === "completed") queueTab.value = "completed";
}
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
  prescriptions[key] ??= [];
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
void Promise.all(
  records["P-00124"]!.documents.map(async (d) => {
    d.blob = await demoFile(d.name, d.format);
  }),
);
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
  prescriptionOpen.value = false;
  finishOpen.value = false;
  referralDetail.value = null;
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
  r.id = `Y-${String(125 + Object.values(referrals).flat().length).padStart(5, "0")}`;
  r.createdTime = new Intl.DateTimeFormat("uz", {
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date());
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
      encounter: selected.value.id,
    });
  }
  referralOpen.value = false;
  if (r.location === "external") {
    referralCreated.value = true;
    referralDetail.value = r;
  }
  notice.value =
    "Demo yo‘llanma lokal ro‘yxatga qo‘shildi. Haqiqiy buyurtma yuborilmadi.";
}
function uploadDocument(d: DocumentRow, external: boolean) {
  if (!patientRecords.value) return;
  patientRecords.value.documents.unshift(d);
  if (external) {
    const r = Object.values(referrals)
      .flat()
      .find((r) => r.id === d.referral);
    patientRecords.value.results.unshift({
      id: crypto.randomUUID(),
      name: d.name,
      date: d.date,
      type: (r?.type || "Diagnostika") as ResultRow["type"],
      doctor: DOCTOR.name,
      status: "Tayyor",
      external: true,
      referral: d.referral || "",
      documentId: d.id,
      encounter: d.encounter,
    });
  }
  notice.value =
    "Fayl faqat frontend xotirasiga qo‘shildi. Serverga yuklanmadi.";
}
const statusLabel = (e: Encounter) =>
  ({
    in_progress: "Qabulda",
    waiting: "Kutmoqda",
    walk_in: "Jonli navbat",
    called: "Chaqirildi",
    completed: "Yakunlangan",
    awaiting_results: "Natija kutilmoqda",
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
          </fieldset>
          <section v-if="currentReferrals.length" class="dw-referrals">
            <h3>
              Yo‘llanmalar
              <span class="dw-badge blue"
                >{{ currentReferrals.length }} ta</span
              >
            </h3>
            <div class="dw-scroll">
              <table>
                <thead>
                  <tr>
                    <th>Yo‘llanma</th>
                    <th>Xizmat</th>
                    <th>Qayerda</th>
                    <th>Holati</th>
                    <th>Amal</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="r in currentReferrals" :key="r.id">
                    <td>{{ r.id }}</td>
                    <td>
                      {{ SERVICES.find((s) => s.id === r.service)?.name }}
                    </td>
                    <td>
                      {{
                        r.location === "external"
                          ? "Boshqa klinikada"
                          : "Bizning klinikada"
                      }}<small>{{
                        r.location === "external"
                          ? r.clinic || "Klinika tanlanmagan"
                          : r.branch
                      }}</small>
                    </td>
                    <td>
                      <span
                        class="dw-badge"
                        :class="r.location === 'external' ? 'yellow' : 'blue'"
                        >{{
                          patientRecords.results.some(
                            (x) => x.referral === r.id && x.status === "Tayyor",
                          )
                            ? "Tayyor"
                            : r.location === "external"
                              ? "Natija kutilmoqda"
                              : "Buyurilgan"
                        }}</span
                      >
                    </td>
                    <td>
                      <button
                        class="dw-link"
                        @click="
                          referralCreated = false;
                          referralDetail = r;
                        "
                      >
                        <Eye :size="18" />Ko‘rish
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
          <section
            v-if="currentPrescriptions.length"
            class="dw-referrals dw-prescriptions"
          >
            <h3>Retseptlar</h3>
            <table>
              <thead>
                <tr>
                  <th>Retsept</th>
                  <th>Sana</th>
                  <th>Dorilar soni</th>
                  <th>Amal</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="p in currentPrescriptions" :key="p.id">
                  <td>{{ p.id }}</td>
                  <td>{{ dateLabel(p.date) }}</td>
                  <td>{{ p.medicines.length }}</td>
                  <td>
                    <button
                      v-if="selected.status !== 'completed'"
                      class="dw-link"
                      @click="
                        editingPrescription = p;
                        prescriptionOpen = true;
                      "
                    >
                      Tahrirlash</button
                    ><span v-else>{{
                      p.medicines
                        .map(
                          (m) =>
                            m.name +
                            " · " +
                            m.dose +
                            " " +
                            m.unit +
                            " · " +
                            m.frequency +
                            " · " +
                            m.duration,
                        )
                        .join("; ")
                    }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </section>
          <fieldset :disabled="selected.status === 'completed'">
            <div class="dw-current-actions">
              <button class="e-button dw-small" @click="referralOpen = true">
                <Plus :size="20" />Yo‘llanma</button
              ><button
                class="e-button dw-small"
                @click="
                  editingPrescription = undefined;
                  prescriptionOpen = true;
                "
              >
                <Plus :size="20" />Retsept</button
              ><span /><button class="e-button dw-small" @click="saveDraft">
                Qoralamani saqlash</button
              ><button
                class="e-button primary dw-small"
                @click="finishOpen = true"
              >
                Qabulni yakunlash
              </button>
            </div>
          </fieldset>
          <p v-if="selected.status === 'completed'" class="dw-footnote">
            <Eye :size="17" />Yakunlangan qabul — faqat ko‘rish uchun.
          </p>
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
          :encounters="queue.filter((e) => e.patientId === selected!.patientId)"
          :referrals="
            Object.values(referrals)
              .flat()
              .filter((r) =>
                r.encounterKey.startsWith(selected!.patientId + ':'),
              )
          "
          @uploaded="uploadDocument"
          @notice="notice = $event"
        />
      </div>
      <article v-else class="dw-card dw-empty">
        Bemorni tanlang yoki boshqa sanani belgilang.
      </article>
    </div>
    <PrescriptionDialog
      v-if="prescriptionOpen && selected"
      :encounter="selected"
      :prescription="editingPrescription"
      @close="prescriptionOpen = false"
      @save="savePrescription"
    />
    <FinishDialog
      v-if="finishOpen && selected && draft && patientRecords"
      :encounter="selected"
      :draft="draft"
      :prescriptions="currentPrescriptions"
      :results="patientRecords.results"
      :referrals="currentReferrals"
      @close="finishOpen = false"
      @finish="finish"
    />
    <ReferralDetailsDialog
      v-if="referralDetail && selected"
      :encounter="selected"
      :referral="referralDetail"
      :created="referralCreated"
      :status="
        patientRecords?.results.some(
          (x) => x.referral === referralDetail!.id && x.status === 'Tayyor',
        )
          ? 'Tayyor'
          : undefined
      "
      @close="
        referralDetail = null;
        referralCreated = false;
      "
    />
    <ReferralDialog
      v-if="referralOpen && selected"
      :encounter="selected"
      @close="referralOpen = false"
      @save="saveReferral"
    />
  </section>
</template>
<style src="../components/doctor/doctor.css"></style>
