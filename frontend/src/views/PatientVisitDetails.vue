<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from "vue";
import { X, Printer } from "lucide-vue-next";
import { can } from "../services/session";
import { errorText } from "../services/api";
import { getVisitClinicalNote, type VisitClinicalNote } from "../services/visitClinicalNotes";
import { getVisitDiagnosis, type VisitDiagnosis } from "../services/visitDiagnosis";
import { getVisitRecommendation, type VisitRecommendation } from "../services/visitRecommendations";
import { listPrescriptions, type VisitPrescription } from "../services/visitPrescriptions";
import { visitReferrals, type VisitReferral } from "../services/visitReferrals";
const props=defineProps<{recordId:string;kind:"visit"|"appointment";status:string;date:string;doctor:string;service:string}>();
const emit=defineEmits<{close:[]}>();
const busy=ref(false), errors=ref<Record<string,string>>({});
const notes=ref<VisitClinicalNote|null>(null), diagnosis=ref<VisitDiagnosis|null>(null), recommendation=ref<VisitRecommendation|null>(null), prescriptions=ref<VisitPrescription[]>([]), referrals=ref<VisitReferral[]>([]);
let alive=true;
async function load(){
 if(props.kind!=="visit"||!can("workspace.view")||!can("visits.view"))return;
 busy.value=true;errors.value={};
 async function read<T>(key:string,call:()=>Promise<T>,assign:(v:T)=>void){try{const value=await call();if(alive)assign(value)}catch(e){if(alive)errors.value[key]=errorText(e)}}
 await Promise.all([
 read("notes",()=>getVisitClinicalNote(props.recordId),v=>notes.value=v),
 read("diagnosis",()=>getVisitDiagnosis(props.recordId),v=>diagnosis.value=v),
 read("recommendation",()=>getVisitRecommendation(props.recordId),v=>recommendation.value=v),
 read("prescriptions",()=>listPrescriptions(props.recordId),v=>prescriptions.value=v),
 read("referrals",()=>visitReferrals(props.recordId),v=>referrals.value=v)
 ]);
 if(alive)busy.value=false;
}
function key(e:KeyboardEvent){if(e.key==="Escape")emit("close")}
onMounted(()=>{document.addEventListener("keydown",key);void load()});
onBeforeUnmount(()=>{alive=false;document.removeEventListener("keydown",key)});
function print(){document.querySelectorAll<HTMLDetailsElement>(".visit-detail details").forEach(d=>d.open=true);window.print()}
</script>
<template>
<Teleport to="body"><div class="visit-detail-overlay" @click.self="emit('close')"><article class="visit-detail" role="dialog" aria-modal="true" aria-label="Tashrif tafsilotlari">
 <header><div><h2>Tashrif tafsilotlari</h2><p>{{recordId}}</p></div><button aria-label="Yopish" @click="emit('close')"><X :size="21"/></button></header>
 <div class="visit-detail-body">
 <div class="summary"><div><small>Sana va vaqt</small><strong>{{date}}</strong></div><div><small>Shifokor</small><strong>{{doctor}}</strong></div><div><small>Xizmat</small><strong>{{service}}</strong></div><div><small>Holati</small><strong>{{status}}</strong></div></div>
 <p v-if="kind==='appointment'">Bu bron ma’lumotlari. Tibbiy yozuvlar haqiqiy tashrifga biriktiriladi.</p>
 <p v-else-if="!can('workspace.view')||!can('visits.view')">Tibbiy yozuvlarni ko‘rish uchun ruxsat yo‘q.</p>
 <p v-else-if="busy" role="status">Qabul yozuvlari yuklanmoqda…</p>
 <template v-else>
 <details open><summary>Shikoyatlar</summary><p>{{errors.notes||notes?.complaints||'Ma’lumot kiritilmagan'}}</p></details>
 <details><summary>Anamnez</summary><p>{{errors.notes||notes?.anamnesis||'Ma’lumot kiritilmagan'}}</p></details>
 <details><summary>Ko‘rik natijalari</summary><p>{{errors.notes||notes?.examination||'Ma’lumot kiritilmagan'}}</p></details>
 <details open><summary>Tashxis</summary><p v-if="errors.diagnosis">{{errors.diagnosis}}</p><p v-else-if="diagnosis">{{diagnosis.icd_code||''}} {{diagnosis.diagnosis_text}} · {{diagnosis.diagnosis_type==='final'?'Yakuniy':'Dastlabki'}}</p><p v-else>Tashxis kiritilmagan</p></details>
 <details><summary>Tavsiyalar</summary><p>{{errors.recommendation||recommendation?.recommendation_text||'Ma’lumot kiritilmagan'}}</p></details>
 <details><summary>Retseptlar ({{prescriptions.length}})</summary><p v-if="errors.prescriptions">{{errors.prescriptions}}</p><p v-else-if="!prescriptions.length">Retsept mavjud emas.</p><div v-for="r in prescriptions" :key="r.id" class="record"><div v-for="(m,i) in r.medicines" :key="i"><strong>{{m.name}}</strong><p>{{m.form}} · {{m.strength}} · {{m.dose}} {{m.unit}} · {{m.route}} · {{m.frequency}} · {{m.duration}} · {{m.instruction}}</p></div><p>{{r.note}}</p></div></details>
 <details><summary>Yo‘llanmalar ({{referrals.length}})</summary><p v-if="errors.referrals">{{errors.referrals}}</p><p v-else-if="!referrals.length">Yo‘llanma mavjud emas.</p><div v-for="r in referrals" :key="r.id" class="record"><strong>{{r.service_name}}</strong><p>{{r.target_branch_name||r.external_clinic}} · {{r.status}}</p><p>{{r.note}}</p></div></details>
 </template>
 </div>
 <footer><button v-if="!busy&&kind==='visit'&&can('workspace.view')&&can('visits.view')&&!Object.keys(errors).length" @click="print"><Printer :size="18"/> Chop etish / PDF</button><button class="primary" @click="emit('close')">Yopish</button></footer>
 </article></div></Teleport>
</template>
<style scoped>
.visit-detail-overlay{position:fixed;inset:0;background:#14274a80;display:grid;place-items:center;z-index:1200;padding:18px}.visit-detail{width:min(960px,100%);max-height:94vh;background:white;border-radius:13px;display:flex;flex-direction:column;color:#102045;box-shadow:0 18px 65px #0b18343d}.visit-detail header,.visit-detail footer{padding:18px 24px;display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid #dce7f8}.visit-detail h2{margin:0;font-size:23px}.visit-detail p{white-space:pre-wrap;overflow-wrap:anywhere;margin:8px 0;color:#486589}.visit-detail header p{font-size:12px}.visit-detail-body{overflow:auto;padding:20px 24px;display:grid;gap:12px}.summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;background:#eef5ff;border-radius:9px;padding:16px}.summary small,.summary strong{display:block}.summary small{color:#6480a5;margin-bottom:5px}.visit-detail details{border:1px solid #dce7f8;border-radius:9px;padding:14px}.visit-detail summary{cursor:pointer;font-weight:600}.record{border-top:1px solid #dce7f8;padding:12px 0}.visit-detail footer{justify-content:flex-end;border-top:1px solid #dce7f8;border-bottom:0}.visit-detail button{font:inherit;display:inline-flex;align-items:center;gap:8px;border:1px solid #cdddf5;background:white;color:#176dff;border-radius:8px;padding:10px 15px;cursor:pointer}.visit-detail button.primary{background:#176dff;color:white}@media print{body:has(.visit-detail-overlay) #app{display:none!important}.visit-detail-overlay{position:static;background:white;display:block;padding:0}.visit-detail{width:100%;max-height:none;box-shadow:none}.visit-detail-body{overflow:visible}.visit-detail footer,.visit-detail header button{display:none}}
</style>