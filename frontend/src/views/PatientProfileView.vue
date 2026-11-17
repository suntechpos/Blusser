<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { ArrowLeft, UserRound, Users, CalendarDays, Stethoscope, Wallet, Phone, Pencil, HeartPulse, Clock3, Pill, ClipboardList, FlaskConical, Folder } from "lucide-vue-next";
import { clinicRequest, errorText } from "../services/api";
import { can, session } from "../services/session";
import { bookingCatalog, type BookingDoctor } from "../services/receptionBooking";
import BookingDialog from "../components/reception/BookingDialog.vue";
import { canReception } from "../services/receptionAccess";
const bookingOpen = ref(false);
import type { Patient } from "../services/operations";

type Visit = { id:string; patient_id:string; doctor_id:string; branch_id:string; service_id?:string; arrived_at?:string; status:string };
const props = defineProps<{ patientId:string; revision:number }>();
const emit = defineEmits<{ back: []; edit: [patient:Patient] }>();
const patient = ref<Patient|null>(null);
const visits = ref<Visit[]>([]);
const loading = ref(false), failure = ref(""), visitsError = ref(""), visitsLoading = ref(false);
const total = ref<number|null>(null);
const catalogs = ref<Record<string,BookingDoctor[]>>({});
const activeTab = ref("Umumiy");
const tabs = [{name:"Umumiy",icon:UserRound},{name:"Tashriflar",icon:Clock3},{name:"Tashxislar",icon:Stethoscope},{name:"Retseptlar",icon:Pill},{name:"Yo‘llanmalar",icon:ClipboardList},{name:"Natijalar",icon:FlaskConical},{name:"Moliya",icon:Wallet},{name:"Hujjatlar",icon:Folder}];
let seq = 0;
const labels:Record<string,string> = {arrived:"Kelgan",queued:"Kutmoqda",called:"Chaqirilgan",in_progress:"Qabulda",completed:"Yakunlangan",skipped:"O‘tkazib yuborilgan",cancelled:"Bekor qilingan"};
const dateOnly=(s?:string|null)=>s ? s.slice(0,10).split("-").reverse().join(".") : "—";
function visitDate(v:Visit) {
 if(!v.arrived_at) return "—";
 const tz=session.branches.find(b=>b.id===v.branch_id)?.timezone;
 return tz ? new Intl.DateTimeFormat("en-GB",{timeZone:tz,day:"2-digit",month:"2-digit",year:"numeric"}).format(new Date(v.arrived_at)).replaceAll("/",".") : "—";
}
const age=computed(()=>{
 if(!patient.value?.date_of_birth) return "—";
 const [y,m,d]=patient.value.date_of_birth.split("-").map(Number);
 if(y===undefined||m===undefined||d===undefined) return "—";
 const n=new Date();return n.getFullYear()-y-(n.getMonth()+1<m||(n.getMonth()+1===m&&n.getDate()<d)?1:0);
});
const gender=computed(()=>patient.value?.gender==="male"?"Erkak":patient.value?.gender==="female"?"Ayol":"—");
const branches=computed(()=>patient.value?.branch_ids.map(id=>session.branches.find(b=>b.id===id)?.name).filter(Boolean).join(", ")||"—");
const initials=computed(()=>patient.value?.name.trim().split(/\s+/).slice(0,2).map(x=>x[0]).join("").toUpperCase());
function doctor(v:Visit){return catalogs.value[v.branch_id]?.find(d=>d.id===v.doctor_id)}
function service(v:Visit){return doctor(v)?.services.find(s=>s.id===v.service_id)?.name||"—"}
async function load(){
 const request=++seq;
 patient.value=null;visits.value=[];total.value=null;catalogs.value={};failure.value="";visitsError.value="";loading.value=true;visitsLoading.value=false;
 try{
  const r=await clinicRequest<Patient>("patients/"+encodeURIComponent(props.patientId));
  if(request!==seq)return;
  patient.value=r.data;
 }catch(e){if(request===seq)failure.value=errorText(e)}
 finally{if(request===seq)loading.value=false}
 if(request!==seq||!patient.value||!can("visits.view"))return;
 visitsLoading.value=true;
 try{
  const path="patients/"+encodeURIComponent(props.patientId)+"/visits";
  const first=(await clinicRequest<{data:Visit[];meta:{total:number}}>(path,{params:{page:1,per_page:10}})).data;
  if(request!==seq)return;
  total.value=first.meta.total;
  const lastPage=Math.max(1,Math.ceil(first.meta.total/10));
  const last=lastPage===1?first:(await clinicRequest<{data:Visit[];meta:{total:number}}>(path,{params:{page:lastPage,per_page:10}})).data;
  if(request!==seq)return;
  const rows=[...last.data];
  if(rows.length<3&&lastPage>1){
   const previous=(await clinicRequest<{data:Visit[];meta:{total:number}}>(path,{params:{page:lastPage-1,per_page:10}})).data;
   rows.unshift(...previous.data);
  }
  if(request!==seq)return;
  visits.value=rows.reverse().slice(0,3);
  if(can("appointments.view")&&can("doctors.view")&&can("rooms.view")&&can("schedules.view")&&session.grants?.["services.view"]==="entire_clinic"){
   const entries=await Promise.all([...new Set(visits.value.map(v=>v.branch_id))].map(async id=>{
    try{return [id,(await bookingCatalog(id)).doctors] as const}catch{return [id,[] as BookingDoctor[]] as const}
   }));
   if(request===seq)catalogs.value=Object.fromEntries(entries);
  }
 }catch(e){if(request===seq){visitsError.value=errorText(e);total.value=null;visits.value=[]}}
 finally{if(request===seq)visitsLoading.value=false}
}
watch(()=>[props.patientId,props.revision,session.epoch],()=>void load(),{immediate:true});
</script>
<template>
<section class="profile">
 <nav class="crumb"><button @click="emit('back')"><ArrowLeft :size="18"/> Bemorlar</button><span>/ Bemor kartasi</span></nav>
 <p v-if="loading" role="status">Bemor ma’lumotlari yuklanmoqda…</p>
 <div v-else-if="failure" role="alert">{{failure}} <button @click="load">Qayta urinish</button></div>
 <template v-else-if="patient">
  <header class="panel identity"><div class="avatar">{{initials}}</div><div class="identity-info"><h1>{{patient.name}}</h1><p>{{patient.patient_number}} · {{age}} yosh · {{gender}}</p><p><Phone :size="15"/> {{patient.phone||"Telefon kiritilmagan"}}</p></div><span class="state" :class="{archived:patient.archived_at}">{{patient.archived_at?"Arxiv":"Faol"}}</span><div class="actions"><button v-if="can('patients.update')&&!patient.archived_at" @click="emit('edit',patient)"><Pencil :size="17"/> Tahrirlash</button><button v-if="canReception('book')&&!patient.archived_at" class="primary" @click="bookingOpen=true"><CalendarDays :size="17"/> Qabulga yozish</button></div></header>
  <div class="metrics">
   <div class="panel metric" v-if="can('visits.view')"><span class="tile"><Users :size="24"/></span><div><small>Jami tashriflar</small><strong>{{total??"—"}}</strong></div></div>
   <div class="panel metric" v-if="can('visits.view')"><span class="tile"><CalendarDays :size="24"/></span><div><small>Oxirgi tashrif</small><strong>{{visits[0]?visitDate(visits[0]):"—"}}</strong></div></div>
   <div class="panel metric" v-if="can('visits.view')"><span class="tile"><Stethoscope :size="24"/></span><div><small>Faol tashxislar</small><strong>—</strong></div></div>
   <div class="panel metric" v-if="can('finance.view')"><span class="tile"><Wallet :size="24"/></span><div><small>Qarzdorlik</small><strong>—</strong></div></div>
  </div>
  <nav class="panel tabs"><button v-for="t in tabs.filter(t=>t.name==='Umumiy'||(t.name==='Moliya'?can('finance.view'):can('visits.view')))" :key="t.name" :class="{active:t.name===activeTab}" :disabled="t.name!=='Umumiy'" :title="t.name!=='Umumiy'?'Keyingi bosqichda ulanadi':''"><component :is="t.icon" :size="19"/> {{t.name}}</button></nav>
  <div class="info-grid">
   <section class="panel info"><h2><UserRound :size="20"/> Shaxsiy ma’lumotlar</h2><dl><dt>F.I.Sh.</dt><dd>{{patient.name}}</dd><dt>ID</dt><dd>{{patient.patient_number}}</dd><dt>Tug‘ilgan sana</dt><dd>{{dateOnly(patient.date_of_birth)}} ({{age}} yosh)</dd><dt>Jinsi</dt><dd>{{gender}}</dd><dt>Telefon raqami</dt><dd>{{patient.phone||"—"}}</dd><dt>Filial</dt><dd>{{branches}}</dd></dl></section>
   <section v-if="can('visits.view')" class="panel info"><h2><HeartPulse :size="20"/> Tibbiy ma’lumotlar</h2><dl><dt>Qon guruhi</dt><dd>—</dd><dt>Allergiyalar</dt><dd>—</dd><dt>Surunkali kasalliklar</dt><dd>—</dd><dt>Doimiy qabul qiladigan dorilar</dt><dd>—</dd></dl><small class="muted">Ushbu ma’lumotlar hozircha ulanmagan.</small></section>
  </div>
  <section v-if="can('visits.view')" class="panel recent"><h2><CalendarDays :size="20"/> Oxirgi tashriflar</h2><p class="muted">Bemorning so‘nggi qabullari va natijalari.</p>
   <p v-if="visitsLoading" role="status">Tashriflar yuklanmoqda…</p><p v-else-if="visitsError" role="alert">{{visitsError}} <button @click="load">Qayta urinish</button></p>
   <div v-else class="table-wrap"><table><thead><tr><th>#</th><th>Sana</th><th>Xizmat</th><th>Shifokor</th><th>Holati</th></tr></thead><tbody><tr v-for="(v,i) in visits" :key="v.id"><td>{{i+1}}</td><td>{{visitDate(v)}}</td><td>{{service(v)}}</td><td>{{doctor(v)?.name||"—"}}</td><td><span class="visit-state" :class="v.status">{{labels[v.status]||v.status}}</span></td></tr><tr v-if="!visits.length"><td colspan="5" class="muted">Tashriflar mavjud emas.</td></tr></tbody></table></div>
  </section>
  <BookingDialog v-if="bookingOpen" :fixed-patient="patient" @close="bookingOpen=false" @saved="bookingOpen=false"/>
 </template>
</section>
</template>
<style scoped>
.profile{color:#102045;display:grid;gap:16px;min-width:0}.profile *{box-sizing:border-box}.profile button{font:inherit;cursor:pointer;border:1px solid #cdddf5;background:white;color:#176dff;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 14px}.profile button.primary{background:#176dff;color:white;border-color:#176dff}.crumb{display:flex;align-items:center;gap:8px;color:#557196;font-size:14px}.crumb button{border:0;background:none;padding:4px;color:#193b69}.panel{border:1px solid #dce7f8;background:white;border-radius:12px;min-width:0}.identity{padding:18px;display:flex;gap:16px;align-items:center;flex-wrap:wrap}.avatar{width:68px;height:68px;display:grid;place-items:center;border-radius:50%;background:#eee7ff;color:#7029ea;font-size:27px;font-weight:700;flex:none}.identity-info{flex:1;min-width:200px}.identity h1{font-size:25px;margin:0 0 4px;overflow-wrap:anywhere}.identity p{margin:5px 0;color:#526f98;display:flex;align-items:center;gap:7px}.state,.visit-state{padding:6px 10px;border-radius:7px;background:#edf4ff;color:#176dff;font-size:12px;white-space:nowrap}.state,.visit-state.completed{background:#e2f7eb;color:#12834f}.state.archived,.visit-state.cancelled{background:#f3f4f6;color:#667085}.actions{display:flex;gap:8px}.metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px}.metrics:empty{display:none}.metric{display:flex;align-items:center;gap:14px;padding:17px}.tile{background:#eaf2ff;color:#176dff;padding:13px;border-radius:10px;display:flex}.metric small{display:block;color:#526f98;margin-bottom:6px}.metric strong{font-size:23px}.tabs{display:flex;overflow:auto;gap:6px;padding:0 9px}.tabs button{flex:1;white-space:nowrap;border:0;border-bottom:3px solid transparent;border-radius:0;background:none;color:#39557e;padding:15px 12px}.tabs button.active{border-bottom-color:#176dff;color:#176dff}.tabs button:disabled{opacity:.58;cursor:not-allowed}.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,360px),1fr));gap:14px}.info,.recent{padding:18px}.profile h2{font-size:17px;display:flex;align-items:center;gap:9px;margin:0 0 12px}.profile h2 svg{color:#176dff}.info dl{display:grid;grid-template-columns:minmax(110px,1fr) minmax(0,2fr);margin:0;font-size:13px}.info dt,.info dd{padding:9px 0;border-bottom:1px solid #e9eff8;margin:0;overflow-wrap:anywhere}.info dt,.muted{color:#6480a5}.info .muted{display:block;margin-top:12px}.table-wrap{overflow:auto}.recent table{width:100%;border-collapse:collapse;min-width:540px;text-align:left;font-size:13px}.recent th{background:#f0f5fc;color:#4d688e}.recent th,.recent td{padding:13px 10px;border-bottom:1px solid #e9eff8}.recent p{font-size:13px}.recent .muted{margin:0 0 15px}@media(max-width:600px){.identity h1{font-size:20px}.identity-info{min-width:160px}.metric strong{font-size:19px}}
</style>
