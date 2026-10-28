<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { Building2, ExternalLink, FlaskConical, Monitor, UserRound, Info } from "lucide-vue-next";
import WorkspaceDialog from "./WorkspaceDialog.vue";
import { errorText } from "../../services/api";
import { referralOptions, createVisitReferral, type ReferralPayload, type ReferralService, type ReferralBranch } from "../../services/visitReferrals";
import type { Encounter } from "../../mocks/doctorWorkspace";

const props = defineProps<{ encounter: Encounter; visitId: string; doctorName: string }>();
const emit = defineEmits<{ close: []; saved: [] }>();
const loading = ref(true);
const saving = ref(false);
const error = ref("");
const services = ref<ReferralService[]>([]);
const branches = ref<ReferralBranch[]>([]);
const draft = ref<ReferralPayload>({
  location: "internal", referral_type: "diagnostic", service_id: "",
  target_branch_id: null, external_clinic: "", note: "",
});
const dirty = computed(() => !!draft.value.service_id || !!draft.value.note || !!draft.value.external_clinic);
const availableServices = computed(() => services.value.filter(s =>
  s.type === draft.value.referral_type &&
  (draft.value.location === "external" || !draft.value.target_branch_id ||
    !s.branch_ids.length || s.branch_ids.includes(draft.value.target_branch_id)),
));
watch(() => [draft.value.location, draft.value.referral_type, draft.value.target_branch_id], () => {
  if (!availableServices.value.some(s => s.id === draft.value.service_id)) draft.value.service_id = "";
});
onMounted(async () => {
  try {
    const options = await referralOptions(props.visitId);
    services.value = options.services;
    branches.value = options.branches;
  } catch (cause) { error.value = errorText(cause); }
  finally { loading.value = false; }
});
async function save() {
  if (saving.value || !draft.value.service_id || (draft.value.location === "internal" && !draft.value.target_branch_id)) return;
  saving.value = true;
  error.value = "";
  try {
    await createVisitReferral(props.visitId, {
      ...draft.value, note: draft.value.note.trim(), external_clinic: draft.value.external_clinic.trim(),
      target_branch_id: draft.value.location === "external" ? null : draft.value.target_branch_id,
    });
    emit("saved");
  } catch (cause) { error.value = errorText(cause); }
  finally { saving.value = false; }
}
</script>
<template>
  <WorkspaceDialog :dirty="dirty" class="dw-referral-modal"
    title="Yo‘llanma yaratish" subtitle="Bemorni tekshiruv yoki konsultatsiyaga yuborish"
    @close="emit('close')">
    <div class="dw-modal-body">
      <div class="dw-referral-patient">
        <div class="dw-patient-line">
          <span class="dw-avatar" :class="encounter.color">{{ encounter.initials }}</span>
          <div><h3>{{ encounter.name }}</h3><p>{{ encounter.patientId }} · {{ encounter.date }}</p></div>
        </div>
        <div class="dw-referral-context"><div>Yo‘llanma bergan shifokor<strong>{{ doctorName }}</strong></div>
          <div>Qabul<strong>{{ encounter.date }}</strong></div></div>
      </div>
      <p v-if="loading" role="status">Haqiqiy xizmatlar yuklanmoqda...</p>
      <p v-if="error" class="dw-error" role="alert">{{ error }}</p>
      <form v-if="!loading" novalidate @submit.prevent="save">
        <fieldset>
          <legend>Qayerda o‘tkaziladi? <em>*</em></legend>
          <div class="dw-options">
            <label :class="{selected:draft.location==='internal'}"><Building2 :size="24"/>Bizning klinikada
              <input v-model="draft.location" type="radio" name="location" value="internal"/></label>
            <label :class="{selected:draft.location==='external'}"><ExternalLink :size="24"/>Boshqa klinikada
              <input v-model="draft.location" type="radio" name="location" value="external"/></label>
          </div>
        </fieldset>
        <fieldset>
          <legend>Yo‘llanma turi <em>*</em></legend>
          <div class="dw-options types">
            <label v-for="item in [{id:'laboratory',label:'Laboratoriya',icon:FlaskConical},{id:'diagnostic',label:'Diagnostika/UZI',icon:Monitor},{id:'consultation',label:'Mutaxassis',icon:UserRound}]"
              :key="item.id" :class="{selected:draft.referral_type===item.id}">
              <component :is="item.icon" :size="24"/>{{ item.label }}
              <input v-model="draft.referral_type" type="radio" name="referral-type" :value="item.id"/>
            </label>
          </div>
        </fieldset>
        <label v-if="draft.location==='internal'" class="dw-field">Filial <em>*</em>
          <select v-model="draft.target_branch_id" aria-label="Filial">
            <option :value="null">Filialni tanlang</option>
            <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
          </select>
        </label>
        <label class="dw-field">Tekshiruv / xizmat <em>*</em>
          <select v-model="draft.service_id" aria-label="Tekshiruv / xizmat">
            <option value="">Xizmatni tanlang</option>
            <option v-for="service in availableServices" :key="service.id" :value="service.id">{{ service.name }}</option>
          </select>
          <small v-if="!availableServices.length">Bu tur uchun faol xizmat topilmadi.</small>
        </label>
        <label v-if="draft.location==='external'" class="dw-field">Klinika nomi (ixtiyoriy)
          <input v-model="draft.external_clinic" maxlength="255" aria-label="Klinika nomi" placeholder="Klinika nomini kiriting"/>
        </label>
        <label class="dw-field">Yo‘llanma sababi / izoh
          <textarea v-model="draft.note" maxlength="4000" rows="3" aria-label="Yo‘llanma sababi / izoh"
            placeholder="Tekshiruv maqsadi va izoh..." />
        </label>
        <div class="dw-info"><Info :size="20"/>
          <span>Yo‘llanma ushbu tashrifga serverda saqlanadi. Bu avtomatik bron yoki laboratoriya natijasi yaratmaydi.</span>
        </div>
        <footer>
          <button class="e-button" type="button" @click="emit('close')">Bekor qilish</button>
          <button class="e-button primary" type="submit"
            :disabled="saving || !draft.service_id || (draft.location==='internal' && !draft.target_branch_id)">
            {{ saving ? "Saqlanmoqda..." : "Yo‘llanma yaratish" }}
          </button>
        </footer>
      </form>
    </div>
  </WorkspaceDialog>
</template>
