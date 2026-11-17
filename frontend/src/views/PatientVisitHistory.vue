<script setup lang="ts">
import { computed, ref, watch, onBeforeUnmount } from "vue";
import { Search, CalendarDays, Eye, ChevronLeft, ChevronRight, Users, CheckCircle2, Clock3, Download } from "lucide-vue-next";
import { clinicRequest, errorText, downloadBlob } from "../services/api";
import { can, session } from "../services/session";
import { bookingCatalog, type BookingDoctor } from "../services/receptionBooking";
import { getVisitDiagnosis } from "../services/visitDiagnosis";
import PatientVisitDetails from "./PatientVisitDetails.vue";
type RecordRow = {id:string; patient_id:string; doctor_id:string; branch_id:string; service_id?:string; appointment_id?:string|null; arrived_at?:string; starts_at?:string; status:string};
type Row = RecordRow & {kind:"visit"|"appointment"};
const props=defineProps<{patientId:string; revision:number}>();
const rows=ref<Row[]>([]), loading=ref(false), failure=ref(""), selected=ref<Row|null>(null);
const catalog=ref<Record<string,BookingDoctor[]>>({}), diagnoses=ref<Record<string,string>>({});
const query=ref(""), from=ref(""), to=ref(""), doctorFilter=ref(""), serviceFilter=ref(""), statusFilter=ref("all"), page=ref(1), size=ref(10);
let seq=0;
onBeforeUnmount(()=>{seq++});
const labels:Record<string,string>={scheduled:"Rejalashtirilgan",checked_in:"Kutmoqda",arrived:"Kelgan",queued:"Kutmoqda",called:"Chaqirilgan",in_progress:"Qabulda",completed:"Yakunlangan",skipped:"O‘tkazib yuborilgan",cancelled:"Bekor qilingan",no_show:"Kelmadi"};
const groups=[{id:"all",name:"Barchasi"},{id:"completed",name:"Yakunlangan"},{id:"active",name:"Jarayonda"},{id:"scheduled",name:"Rejalashtirilgan"},{id:"cancelled",name:"Bekor qilingan"}];
function group(r:Row){return r.status==="completed"?"completed":r.status==="scheduled"?"scheduled":["cancelled","no_show","skipped"].includes(r.status)?"cancelled":"active"}
const counts=computed(()=>Object.fromEntries(groups.map(g=>[g.id,rows.value.filter(r=>g.id==="all"||group(r)===g.id).length])));
function doctor(r:Row){return catalog.value[r.branch_id]?.find(d=>d.id===r.doctor_id)}
function service(r:Row){return doctor(r)?.services.find(s=>s.id===r.service_id)?.name||"—"}
function date(r:Row){return r.arrived_at||r.starts_at||""}
function localDate(r:Row){const tz=session.branches.find(b=>b.id===r.branch_id)?.timezone;return date(r)&&tz?new Intl.DateTimeFormat("en-CA",{timeZone:tz,year:"numeric",month:"2-digit",day:"2-digit"}).format(new Date(date(r))):""}
function displayDate(r:Row){const tz=session.branches.find(b=>b.id===r.branch_id)?.timezone;return date(r)&&tz?new Intl.DateTimeFormat("en-GB",{timeZone:tz,day:"2-digit",month:"2-digit",year:"numeric",hour:"2-digit",minute:"2-digit",hourCycle:"h23"}).format(new Date(date(r))).replaceAll("/","."):"—"}
const doctorOptions=computed(()=>[...new Map(rows.value.map(r=>[r.doctor_id,doctor(r)?.name||r.doctor_id])).entries()]);
const serviceOptions=computed(()=>[...new Map(rows.value.filter(r=>r.service_id).map(r=>[r.service_id!,service(r)])).entries()]);
const filtered=computed(()=>rows.value.filter(r=>{
 const q=query.value.trim().toLowerCase(), d=localDate(r);
 return (!q||[r.id,doctor(r)?.name,service(r),diagnoses.value[r.id]].some(v=>v?.toLowerCase().includes(q)))&&(!from.value||d>=from.value)&&(!to.value||d<=to.value)&&(!doctorFilter.value||r.doctor_id===doctorFilter.value)&&(!serviceFilter.value||r.service_id===serviceFilter.value)&&(statusFilter.value==="all"||group(r)===statusFilter.value)
}));
const pages=computed(()=>Math.max(1,Math.ceil(filtered.value.length/size.value)));
const visible=computed(()=>filtered.value.slice((page.value-1)*size.value,page.value*size.value));
watch([query,from,to,doctorFilter,serviceFilter,statusFilter,size],()=>{page.value=1});
async function all(kind:string,request:number){
 const path="patients/"+encodeURIComponent(props.patientId)+"/"+kind;
 const out:RecordRow[]=[];let p=1;
 while(request===seq){
  const res=(await clinicRequest<{data:RecordRow[];meta:{total:number}}>(path,{params:{page:p,per_page:100}})).data;
  out.push(...res.data);if(out.length>=res.meta.total||!res.data.length)break;p++;
 }
 return out;
}
async function load(){
 const request=++seq;loading.value=true;failure.value="";rows.value=[];catalog.value={};diagnoses.value={};selected.value=null;page.value=1;
 try{
  const [visits,appointments]=await Promise.all([can("visits.view")?all("visits",request):[],can("appointments.view")?all("appointments",request):[]]);
  if(request!==seq)return;
  const linked=new Set(visits.map(v=>v.appointment_id).filter(Boolean));
  rows.value=[...visits.map(v=>({...v,kind:"visit" as const})),...appointments.filter(a=>!linked.has(a.id)).map(a=>({...a,kind:"appointment" as const}))].sort((a,b)=>Date.parse(date(b))-Date.parse(date(a)));
  if(can("appointments.view")&&can("doctors.view")&&can("rooms.view")&&can("schedules.view")&&session.grants?.["services.view"]==="entire_clinic"){
   const entries=await Promise.all([...new Set(rows.value.map(r=>r.branch_id))].map(async id=>{try{return [id,(await bookingCatalog(id)).doctors] as const}catch{return [id,[] as BookingDoctor[]] as const}}));
   if(request!==seq)return;catalog.value=Object.fromEntries(entries);
  }
  // Clinical access remains governed by the existing visit diagnosis endpoint.
  if(can("workspace.view")){const v=rows.value.filter(r=>r.kind==="visit");for(let i=0;i<v.length&&request===seq;i+=8){const entries=await Promise.all(v.slice(i,i+8).map(async r=>{try{const d=await getVisitDiagnosis(r.id);return [r.id,d?[d.icd_code,d.diagnosis_text].filter(Boolean).join(" — "):""] as const}catch{return [r.id,""] as const}}));if(request===seq)Object.assign(diagnoses.value,Object.fromEntries(entries));}}
 }catch(e){if(request===seq){failure.value=errorText(e);rows.value=[]}}
 finally{if(request===seq)loading.value=false}
}
watch(()=>[props.patientId,props.revision,session.epoch],()=>void load(),{immediate:true});
function exportCsv(){
 if(!can("appointments.export"))return;
 const cell=(v:unknown)=>{let s=String(v??"");if(/^[\s\u0000-\u001f]*[=+@-]/.test(s))s="'"+s;return '"'+s.replaceAll('"','""')+'"'};
 const lines=[["ID","Sana","Xizmat","Shifokor","Holat"],...filtered.value.map(r=>[r.id,displayDate(r),service(r),doctor(r)?.name||"—",labels[r.status]||r.status])];
 downloadBlob(new Blob(["\uFEFF"+lines.map(r=>r.map(cell).join(",")).join("\r\n")],{type:"text/csv;charset=utf-8"}),"tashriflar.csv");
}
</script>
<template>
<section class="history">
 <div class="history-metrics"><div v-for="(g,i) in groups.slice(0,4)" :key="g.id" class="history-panel metric"><span class="tile"><component :is="[Users,CheckCircle2,Clock3,CalendarDays][i]" :size="24"/></span><div><small>{{g.id==='all'?'Jami tashrif va qabullar':g.name}}</small><strong>{{loading||failure?'—':counts[g.id]}}</strong></div></div></div>
 <div class="history-panel content">
 <h2>Tashriflar tarixi</h2><p class="muted">Bemorning klinikadagi barcha tashriflari va qabullari.</p>
 <div class="filters"><label class="search"><Search :size="17"/><input v-model="query" placeholder="Shifokor, xizmat yoki tashxis bo‘yicha qidirish..."/></label><label>Sana dan <input type="date" v-model="from"/></label><label>gacha <input type="date" v-model="to"/></label><select v-model="doctorFilter" aria-label="Shifokor"><option value="">Shifokor</option><option v-for="[id,name] in doctorOptions" :key="id" :value="id">{{name}}</option></select><select v-model="serviceFilter" aria-label="Xizmat turi"><option value="">Xizmat turi</option><option v-for="[id,name] in serviceOptions" :key="id" :value="id">{{name}}</option></select><button v-if="can('appointments.export')" :disabled="loading||!!failure" @click="exportCsv"><Download :size="16"/> Eksport</button></div>
 <div class="chips"><button v-for="g in groups" :key="g.id" :class="{active:statusFilter===g.id}" @click="statusFilter=g.id">{{g.name}} <span>{{loading||failure?'—':counts[g.id]}}</span></button></div>
 <p v-if="loading" role="status">Tashriflar yuklanmoqda…</p><p v-else-if="failure" role="alert">{{failure}} <button @click="load">Qayta urinish</button></p>
 <template v-else><div class="table-wrap"><table><thead><tr><th>#</th><th>Tashrif ID</th><th>Sana va vaqt</th><th>Xizmat</th><th>Shifokor</th><th>Tashxis (qisqacha)</th><th>Holati</th><th>Amallar</th></tr></thead><tbody><tr v-for="(r,i) in visible" :key="r.kind+r.id"><td>{{(page-1)*size+i+1}}</td><td :title="r.id">{{r.id.slice(0,8)}}</td><td>{{displayDate(r)}}</td><td>{{service(r)}}</td><td><strong>{{doctor(r)?.name||'—'}}</strong><small>{{doctor(r)?.specialty_name}}</small></td><td class="diagnosis">{{diagnoses[r.id]||'—'}}</td><td><span class="status" :class="group(r)">{{labels[r.status]||r.status}}</span></td><td><button @click="selected=r"><Eye :size="15"/> Ko‘rish</button></td></tr><tr v-if="!visible.length"><td colspan="8" class="empty">Tashriflar topilmadi.</td></tr></tbody></table></div><footer><span>Jami: {{filtered.length}} ta</span><div><button :disabled="page<=1" @click="page--"><ChevronLeft :size="16"/></button><span>{{page}} / {{pages}}</span><button :disabled="page>=pages" @click="page++"><ChevronRight :size="16"/></button><select v-model.number="size"><option :value="10">10 / sahifa</option><option :value="20">20 / sahifa</option><option :value="50">50 / sahifa</option></select></div></footer></template>
 </div>
 <PatientVisitDetails v-if="selected" :key="selected.kind+selected.id" :record-id="selected.id" :kind="selected.kind" :status="labels[selected.status]||selected.status" :date="displayDate(selected)" :doctor="doctor(selected)?.name||'—'" :service="service(selected)" @close="selected=null"/>
</section>
</template>
<style scoped>
.history{display:grid;gap:15px;color:#102045;min-width:0}.history-panel{background:white;border:1px solid #dce7f8;border-radius:12px;min-width:0}.history-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px}.metric{padding:15px;display:flex;gap:13px;align-items:center}.tile{background:#eaf2ff;color:#176dff;padding:12px;border-radius:9px;display:flex}.metric small,.metric strong{display:block}.metric small,.muted{color:#58739a}.metric strong{font-size:24px;margin-top:5px}.content{padding:16px}.content h2{margin:0;font-size:21px}.muted{margin:5px 0 18px;font-size:13px}.filters,.chips{display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-bottom:15px}.filters label{display:flex;align-items:center;gap:5px;font-size:12px;color:#526e96}.search{flex:1;min-width:240px;background:#f3f7fd;border:1px solid #dce7f8;border-radius:7px;padding:0 10px}.history input,.history select,.history button{font:inherit;border:1px solid #d1def4;border-radius:7px;background:white;color:#19375f;padding:9px;min-width:0}.search input{width:100%;background:none;border:0;outline:0}.history button{cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:5px;color:#176dff}.history button:disabled{opacity:.5;cursor:not-allowed}.chips button{border-radius:25px;color:#233e65;padding:8px 13px}.chips button.active{background:#176dff;color:white;border-color:#176dff}.chips span{background:#eaf1fc;color:#304c75;border-radius:8px;padding:2px 6px}.table-wrap{overflow:auto}table{width:100%;min-width:900px;border-collapse:collapse;text-align:left;font-size:13px}th{background:#eef4fc;color:#49658b;font-weight:600}td,th{padding:12px 9px;border-bottom:1px solid #e8eef8}td small{display:block;color:#6480a5;margin-top:4px}.diagnosis{max-width:230px;overflow-wrap:anywhere}.status{display:inline-block;white-space:nowrap;border-radius:7px;padding:6px 9px;background:#eaf2ff;color:#176dff;font-size:12px}.status.completed{background:#e3f7ea;color:#12834f}.status.scheduled{background:#fff1d8;color:#a56800}.status.cancelled{background:#fcebec;color:#a83136}.empty{text-align:center;color:#6480a5;padding:30px}footer,footer div{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}footer{padding-top:15px;color:#526e96;font-size:13px}
</style>