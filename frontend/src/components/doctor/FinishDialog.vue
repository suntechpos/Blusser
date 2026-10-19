<script setup lang="ts">
import { computed, ref } from "vue";
import { CheckCircle2, Clock3, Info } from "lucide-vue-next";
import WorkspaceDialog from "./WorkspaceDialog.vue";
import PatientStrip from "./PatientStrip.vue";
import {
  type Encounter,
  type Draft,
  type Prescription,
  type ResultRow,
  type Referral,
  SERVICES,
} from "../../mocks/doctorWorkspace";
const props = defineProps<{
  encounter: Encounter;
  draft: Draft;
  prescriptions: Prescription[];
  results: ResultRow[];
  referrals: Referral[];
}>();
const emit = defineEmits<{
  close: [];
  finish: ["completed" | "awaiting_results"];
}>();
const fields = computed(() => [
  { label: "Shikoyatlar", value: props.draft.complaints },
  { label: "Ko‘rik natijalari", value: props.draft.examination },
  { label: "Tashxis", value: props.draft.diagnosis },
  { label: "Tavsiyalar", value: props.draft.recommendations },
]);
const pending = computed(() =>
  props.referrals
    .filter(
      (r) =>
        !props.results.some(
          (x) => x.referral === r.id && x.status === "Tayyor",
        ),
    )
    .map((r) => ({
      name: SERVICES.find((s) => s.id === r.service)?.name,
      status:
        props.results.find((x) => x.referral === r.id)?.status ||
        "Natija kutilmoqda",
    })),
);
const allPending = computed(() => [
  ...pending.value,
  ...props.results
    .filter(
      (r) =>
        r.encounter === props.encounter.id &&
        r.status !== "Tayyor" &&
        !props.referrals.some((f) => f.id === r.referral),
    )
    .map((r) => ({ name: r.name, status: r.status })),
]);
const busy = ref(false);
function finish(status: "completed" | "awaiting_results") {
  if (
    busy.value ||
    (status === "completed" && fields.value.some((f) => !f.value.trim()))
  )
    return;
  busy.value = true;
  emit("finish", status);
}
</script>
<template>
  <WorkspaceDialog
    class="dw-finish-modal"
    title="Qabulni yakunlash"
    subtitle="Yakunlashdan oldin qabul ma’lumotlarini tekshiring."
    @close="emit('close')"
    ><div class="dw-modal-body">
      <PatientStrip :encounter="encounter" context />
      <section class="dw-summary">
        <h3>Qabul xulosasi</h3>
        <div v-for="f in fields" :key="f.label">
          <CheckCircle2
            v-if="f.value.trim()"
            class="dw-success"
            :size="20"
          /><Clock3 v-else :size="20" /><span>{{ f.label }}</span
          ><b :class="f.value.trim() ? 'dw-success' : 'dw-error'">{{
            f.value.trim() ? "Kiritilgan" : "Kiritish kerak"
          }}</b>
        </div>
        <div>
          <CheckCircle2 :size="20" /><span>Retsept</span
          ><b
            >{{ prescriptions.length }} ta retsept ·
            {{ prescriptions.reduce((n, p) => n + p.medicines.length, 0) }} ta
            dori</b
          >
        </div>
      </section>
      <section v-if="allPending.length" class="dw-pending">
        <h3><Clock3 :size="20" />Natijasi kutilayotgan tekshiruvlar</h3>
        <div v-for="(r, i) in allPending" :key="i">
          <Clock3 :size="19" /><span>{{ r.name }}</span
          ><span class="dw-badge yellow">{{ r.status }}</span>
        </div>
      </section>
      <div v-if="allPending.length" class="dw-info">
        <Info :size="20" />Natijalarni kutish uchun qabulni ochiq holatda
        saqlashingiz mumkin.
      </div>
      <p class="dw-footnote">
        <Clock3 :size="17" />Qabul yakunlansa, yozuvlar ko‘rish rejimiga o‘tadi.
        Bu faqat lokal mock holat.
      </p>
    </div>
    <template #footer
      ><footer>
        <button class="e-button" @click="emit('close')">Ortga qaytish</button
        ><button
          v-if="allPending.length"
          class="e-button"
          :disabled="busy"
          @click="finish('awaiting_results')"
        >
          Natija kutishga qoldirish</button
        ><button
          class="e-button primary"
          :disabled="busy || fields.some((f) => !f.value.trim())"
          @click="finish('completed')"
        >
          Qabulni yakunlash
        </button>
      </footer></template
    ></WorkspaceDialog
  >
</template>
