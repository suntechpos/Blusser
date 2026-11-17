<script setup lang="ts">
import { computed, ref, watch, onBeforeUnmount } from "vue";
import { CalendarDays, List, Users, ChevronLeft, ChevronRight, Plus, RefreshCw, Clock3, Eye, UserRound, DoorOpen, Stethoscope, Utensils } from "lucide-vue-next";
import { can, session } from "../services/session";
import { clinicRequest, errorText } from "../services/api";
import { bookingCatalog, bookingAvailability, localDay, localTime, nextDay, dayRange, type BookingDoctor, type BookingAppointment, type BookingSlot, type BookingPreset } from "../services/receptionBooking";
import { canReception } from "../services/receptionAccess";
import type { Patient } from "../services/operations";
import BookingDialog from "../components/reception/BookingDialog.vue";
import "../components/reception/reception.css";
type BreakWindow={local_start:string;local_end:string};
type WorkWindow={id?:string;doctor_id:string;room_id:string;local_start:string;local_end:string;slot_minutes:number;breaks:BreakWindow[];weekday:number;effective_from:string;effective_to:string|null;active?:boolean};
type ScheduleException={doctor_id:string;local_date:string;kind:"closed"|"replace";replacement:WorkWindow|null};
type Room={id:string;number:string;active:boolean;availability:string};
type Ticket={id:string;patient_id:string;doctor_id:string;number:number;status:string;kind:string;local_date:string;visit_id:string;appointment_id:string|null};
type CalendarEvent={key:string;start:string;end:string;appointment?:BookingAppointment;slot?:BookingSlot;serviceId?:string};
const props=defineProps<{initialTab?:"calendar"|"queue"}>();
const emit=defineEmits<{openTablo:[]}>();
const tab=ref<"calendar"|"list"|"queue">(props.initialTab==="queue"&&can("queues.view")?"queue":can("appointments.view")?"calendar":"queue");
const branches=computed(()=>session.branches.filter(b=>b.active));
const branch=ref(session.branch||branches.value[0]?.id||"");
const zone=ref(branches.value.find(b=>b.id===branch.value)?.timezone||"");
const date=ref(zone.value?localDay(Date.now(),zone.value):"");
const doctors=ref<BookingDoctor[]>([]), appointments=ref<BookingAppointment[]>([]), tickets=ref<Ticket[]>([]);
const patients=ref<Record<string,Patient>>({}), free=ref<Record<string,BookingSlot[]>>({}), slotErrors=ref<Record<string,string>>({});
const windows=ref<Record<string,WorkWindow[]>>({}),rooms=ref<Room[]>([]),scheduleError=ref(""),roomFilter=ref(""),busyOnly=ref(false);
const freeServices=ref<Record<string,string>>({});
const doctorFilter=ref(""),serviceFilter=ref(""),query=ref(""),status=ref("");
const loading=ref(false),error=ref(""),catalogError=ref(""),booking=ref(false),preset=ref<BookingPreset>({}),selected=ref<BookingAppointment|null>(null);
const canCatalog=computed(()=>can("appointments.view")&&can("doctors.view")&&can("rooms.view")&&can("schedules.view")&&session.grants?.["services.view"]==="entire_clinic");
const labels:Record<string,string>={scheduled:"Yozilgan",checked_in:"Kutmoqda",in_progress:"Qabulda",completed:"Yakunlangan",cancelled:"Bekor qilingan",no_show:"Kelmagan",waiting:"Kutmoqda",called:"Chaqirildi",skipped:"O‘tkazib yuborilgan"};
const label=(s:string)=>labels[s]||s;
const name=(id:string)=>patients.value[id]?.name||"Bemor ma’lumoti mavjud emas";
const doctorName=(id:string)=>doctors.value.find(d=>d.id===id)?.name||"Shifokor ma’lumoti mavjud emas";
const serviceName=(a:BookingAppointment)=>doctors.value.find(d=>d.id===a.doctor_id)?.services.find(s=>s.id===a.service_id)?.name||"—";
const time=(s:string)=>zone.value?localTime(s,zone.value):"—";
const serviceOptions=computed(()=>[...new Map(doctors.value.flatMap(d=>d.services).map(s=>[s.id,s])).values()]);
const visibleDoctors=computed(()=>doctors.value.filter(d=>(!doctorFilter.value||d.id===doctorFilter.value)&&(!serviceFilter.value||d.services.some(s=>s.id===serviceFilter.value))&&(!roomFilter.value||(windows.value[d.id]||[]).some(w=>w.room_id===roomFilter.value)||appointments.value.some(a=>a.doctor_id===d.id&&a.room_id===roomFilter.value))));
const mins=(s:string)=>Number(s.slice(0,2))*60+Number(s.slice(3,5));
const roomName=(id:string)=>rooms.value.find(r=>r.id===id)?.number||"—";
const doctorWindows=(id:string)=>(windows.value[id]||[]).filter(w=>!roomFilter.value||w.room_id===roomFilter.value);
const wallClock=computed(()=>zone.value?new Intl.DateTimeFormat("en-GB",{timeZone:zone.value,hour:"2-digit",minute:"2-digit",hourCycle:"h23"}):null);
function localMinute(t:number){return wallClock.value?mins(wallClock.value.format(new Date(t))):0}
function slotKey(s:BookingSlot){return [s.doctor_id,s.room_id,s.starts_at,s.ends_at].join("|")}
function windowLabel(id:string){return doctorWindows(id).map(w=>w.local_start+"–"+w.local_end).join(", ")||"Ish jadvali yo‘q"}
const filtered=computed(()=>appointments.value.filter(a=>(!doctorFilter.value||a.doctor_id===doctorFilter.value)&&(!serviceFilter.value||a.service_id===serviceFilter.value)&&(!status.value||a.status===status.value)&&(!query.value.trim()||[name(a.patient_id),patients.value[a.patient_id]?.phone,patients.value[a.patient_id]?.patient_number].some(s=>s?.toLowerCase().includes(query.value.trim().toLowerCase())))));
const queueFiltered=computed(()=>tickets.value.filter(t=>(!doctorFilter.value||t.doctor_id===doctorFilter.value)&&(!query.value.trim()||[name(t.patient_id),String(t.number)].some(s=>s.toLowerCase().includes(query.value.trim().toLowerCase())))));
const occupied=computed(()=>appointments.value.filter(a=>!["cancelled","no_show"].includes(a.status)));
const slotCount=computed(()=>visibleDoctors.value.reduce((n,d)=>n+new Set((free.value[d.id]||[]).filter(s=>!roomFilter.value||s.room_id===roomFilter.value).map(s=>s.starts_at)).size,0));
const activeDoctors=computed(()=>visibleDoctors.value.filter(d=>doctorWindows(d.id).length>0).length);
let serial=0;
onBeforeUnmount(()=>{serial++});
async function all<T>(path:string,params:Record<string,unknown>,request:number){
 const result:T[]=[];
 for(let page=1;request===serial;page++){
  const r=(await clinicRequest<{data:T[];meta:{total:number}}>(path,{params:{...params,page,per_page:100}})).data;
  result.push(...r.data);if(result.length>=r.meta.total||!r.data.length)break;
 }
 return result;
}
async function load(){
 const request=++serial;loading.value=true;error.value="";catalogError.value="";doctors.value=[];appointments.value=[];tickets.value=[];patients.value={};free.value={};slotErrors.value={};windows.value={};rooms.value=[];scheduleError.value="";freeServices.value={};selected.value=null;
 if(!branch.value){loading.value=false;return}
 try{
  if(canCatalog.value){try{const cat=await bookingCatalog(branch.value);if(request!==serial)return;doctors.value=cat.doctors;zone.value=cat.timezone}catch(e){if(request===serial)catalogError.value=errorText(e)}}
  if(request!==serial)return;
  if(!zone.value)throw Error("Filial vaqt mintaqasi aniqlanmadi.");
  if(!date.value)date.value=localDay(Date.now(),zone.value);
  const params={branch_id:branch.value,...dayRange(date.value,zone.value)};
  if(tab.value==="queue"){
   if(!can("queues.view"))return;
   const result=await all<Ticket>("queue-tickets",params,request);if(request!==serial)return;tickets.value=result;
  }else{
   if(!can("appointments.view"))return;
   const result=await all<BookingAppointment>("appointments",params,request);if(request!==serial)return;appointments.value=result;
  }
  if(request!==serial)return;
  const ids=[...new Set([...appointments.value,...tickets.value].map(a=>a.patient_id))];
  if(can("patients.view"))for(let i=0;i<ids.length&&request===serial;i+=8){
   const entries=await Promise.all(ids.slice(i,i+8).map(async id=>{try{return [id,(await clinicRequest<Patient>("patients/"+id)).data] as const}catch{return null}}));
   if(request!==serial)return;for(const e of entries)if(e)patients.value[e[0]]=e[1];
  }
  if(tab.value==="calendar"&&canCatalog.value){
   try{
    const [rules,exceptions,roomRows]=await Promise.all([
     all<WorkWindow>("schedules",{branch_id:branch.value},request),
     all<ScheduleException>("schedule-exceptions",{branch_id:branch.value},request),
     all<Room>("rooms",{branch_id:branch.value},request)
    ]);
    if(request!==serial)return;
    rooms.value=roomRows;
    const weekday=new Date(date.value+"T12:00:00Z").getUTCDay()||7;
    windows.value=Object.fromEntries(doctors.value.map(d=>{
     const e=exceptions.find(e=>e.doctor_id===d.id&&e.local_date===date.value);
     return [d.id,e?(e.kind==="replace"&&e.replacement?[e.replacement]:[]):rules.filter(w=>w.active===true&&w.doctor_id===d.id&&w.weekday===weekday&&w.effective_from<=date.value&&(!w.effective_to||w.effective_to>=date.value))];
    }));
   }catch(e){if(request!==serial)return;scheduleError.value=errorText(e)}
   const eligible=visibleDoctors.value;
   for(let i=0;i<eligible.length&&request===serial;i+=4){
    const entries=await Promise.all(eligible.slice(i,i+4).map(async d=>{
     const choices=d.services.filter(s=>!serviceFilter.value||s.id===serviceFilter.value);
     const byStart=new Map<string,{slot:BookingSlot;service:string}>();
     let failure="";
     for(const service of choices){
      if(request!==serial)break;
      try{
       const slots=await bookingAvailability(d.id,branch.value,service.id,date.value,zone.value);
       for(const slot of slots){
        const key=slot.starts_at+"|"+slot.room_id;
        const prior=byStart.get(key);
        if(!prior||Date.parse(slot.ends_at)<Date.parse(prior.slot.ends_at))byStart.set(key,{slot,service:service.id});
       }
      }catch(e){failure=errorText(e)}
     }
     return {id:d.id,entries:[...byStart.values()].sort((a,b)=>Date.parse(a.slot.starts_at)-Date.parse(b.slot.starts_at)),error:failure};
    }));
    if(request!==serial)return;
    for(const e of entries){
     free.value[e.id]=e.entries.map(x=>x.slot);
     for(const x of e.entries)freeServices.value[slotKey(x.slot)]=x.service;
     if(e.error)slotErrors.value[e.id]=e.error;
    }
   }
  }
 }catch(e){if(request===serial)error.value=errorText(e)}
 finally{if(request===serial)loading.value=false}
}
watch(branch,()=>{zone.value=branches.value.find(b=>b.id===branch.value)?.timezone||"";date.value=zone.value?localDay(Date.now(),zone.value):"";doctorFilter.value="";serviceFilter.value="";roomFilter.value=""});
watch(()=>[session.epoch,branch.value,date.value,tab.value,serviceFilter.value,doctorFilter.value],()=>void load(),{immediate:true});
watch(()=>session.epoch,()=>{booking.value=false;selected.value=null;branch.value=session.branch||branches.value[0]?.id||"";if(!can("appointments.view"))tab.value="queue";else if(!can("queues.view")&&tab.value==="queue")tab.value="calendar"});
function setTab(t:"calendar"|"list"|"queue"){tab.value=t;status.value="";query.value="";serviceFilter.value="";doctorFilter.value=""}
function openBooking(slot?:BookingSlot){
 if(!canReception("book"))return;
 preset.value={branchId:branch.value,date:date.value,...(slot?{doctorId:slot.doctor_id,serviceId:freeServices.value[slotKey(slot)]||serviceFilter.value,slot}:{})};booking.value=true;
}
const bounds=computed(()=>{
 if(!date.value||!zone.value)return {start:0,end:0};
 try{const r=dayRange(date.value,zone.value);return {start:Date.parse(r.from),end:Date.parse(r.to)}}catch{return {start:0,end:0}}
});
const events=computed(()=>Object.fromEntries(visibleDoctors.value.map(d=>{
 const a=appointments.value.filter(a=>a.doctor_id===d.id&&(!roomFilter.value||a.room_id===roomFilter.value)&&!["cancelled","no_show"].includes(a.status)).map(a=>({key:a.id,start:a.starts_at,end:a.ends_at,appointment:a} as CalendarEvent));
 const starts=new Map<string,BookingSlot>();
 for(const slot of (free.value[d.id]||[]).filter(s=>!roomFilter.value||s.room_id===roomFilter.value))if(!starts.has(slot.starts_at))starts.set(slot.starts_at,slot);
 const f=(busyOnly.value?[]:[...starts.values()].filter((slot,i,all)=>i===0||Date.parse(slot.starts_at)-Date.parse(all[i-1]!.starts_at)>=60000)).map((s,i)=>({key:"slot"+i,start:s.starts_at,end:s.ends_at,slot:s,serviceId:serviceFilter.value} as CalendarEvent));
 return [d.id,[...a,...f]];
})));
const displayRange=computed(()=>{
 const entries=Object.values(events.value).flat(), b=bounds.value;
 const starts=entries.map(e=>Date.parse(e.start)).filter(Number.isFinite),ends=entries.map(e=>Date.parse(e.end)).filter(Number.isFinite);
 // Schedule boundaries are wall-clock minutes; use actual local instants from
 // the day timeline rather than assuming UTC or a fixed timezone offset.
 const work=visibleDoctors.value.flatMap(d=>doctorWindows(d.id));
 for(let t=b.start;t<b.end;t+=60000){
  const m=localMinute(t);
  if(work.some(w=>m===mins(w.local_start)))starts.push(t);
  if(work.some(w=>m===mins(w.local_end)))ends.push(t);
 }
 if(work.some(w=>w.local_end==="24:00"))ends.push(b.end);
 const start=starts.length?Math.max(b.start,b.start+Math.floor((Math.min(...starts)-b.start)/1800000)*1800000):b.start+9*3600000;
 const end=ends.length?Math.min(b.end,Math.max(start+1800000,b.start+Math.ceil((Math.max(...ends)-b.start)/1800000)*1800000)):Math.min(b.end,b.start+18*3600000);
 return {start,end};
});
const ticks=computed(()=>{const r=displayRange.value;const arr:number[]=[];for(let t=r.start;t<r.end;t+=1800000)arr.push(t);return arr});
const ROW_HEIGHT=46;
const height=computed(()=>ticks.value.length*ROW_HEIGHT);
function cellState(id:string,t:number){
 const m=localMinute(t), end=localMinute(t+1799999)+1;
 const work=doctorWindows(id);
 const w=work.find(w=>m>=mins(w.local_start)&&end<=mins(w.local_end));
 if(!w)return scheduleError.value?"unknown":"closed";
 if(w.breaks.some(b=>m<mins(b.local_end)&&end>mins(b.local_start)))return "break";
 return "work";
}
const roomSummary=computed(()=>rooms.value.filter(r=>r.active&&(!roomFilter.value||r.id===roomFilter.value)).map(r=>{
 const a=date.value===localDay(Date.now(),zone.value)?occupied.value.find(a=>a.room_id===r.id&&a.status==="in_progress"):undefined;
 const waiting=date.value===localDay(Date.now(),zone.value)?occupied.value.find(a=>a.room_id===r.id&&a.status==="checked_in"):undefined;
 return {room:r,appointment:a||waiting,state:r.availability!=="available"?"Yopiq":a?"Qabulda":waiting?"Kutmoqda":"Holat tasdiqlanmagan"};
}));
function position(e:CalendarEvent){const r=displayRange.value;return {top:Math.max(0,(Date.parse(e.start)-r.start)/1800000*ROW_HEIGHT)+"px",height:Math.max(2,(Math.min(Date.parse(e.end),r.end)-Math.max(Date.parse(e.start),r.start))/1800000*ROW_HEIGHT-4)+"px"}}
</script>
<template>
<section class="unified">
 <header class="heading"><div><h1>Qabullar va navbatlar</h1><p>Qabullarni rejalashtirish va navbatlarni kuzatish</p></div><button v-if="canReception('book')" class="primary" @click="openBooking()"><Plus :size="18"/> Qabulga yozish</button></header>
 <nav class="tabs"><button v-if="can('appointments.view')" :class="{active:tab==='calendar'}" @click="setTab('calendar')"><CalendarDays :size="19"/> Kalendar</button><button v-if="can('appointments.view')" :class="{active:tab==='list'}" @click="setTab('list')"><List :size="19"/> Qabullar</button><button v-if="can('queues.view')" :class="{active:tab==='queue'}" @click="setTab('queue')"><Users :size="19"/> Navbatlar</button></nav>
 <div v-if="tab!=='queue'" class="metrics"><div v-for="m in [{name:'Rejalashtirilgan qabullar',value:appointments.filter(a=>a.status==='scheduled').length,icon:CalendarDays},{name:'Bo‘sh vaqtlar',value:tab==='calendar'&&!Object.keys(slotErrors).length&&!catalogError&&!scheduleError?slotCount:'—',icon:Users},{name:'Band vaqtlar',value:occupied.length,icon:Clock3},{name:'Ishlayotgan shifokorlar',value:tab==='calendar'&&!scheduleError&&!catalogError?activeDoctors:'—',icon:Stethoscope}]" :key="m.name" class="panel metric"><span class="tile"><component :is="m.icon" :size="25"/></span><div><small>{{m.name}}</small><strong>{{loading||error?'—':m.value}}</strong><span class="metric-note">Tanlangan kun bo‘yicha</span></div></div></div>
 <div v-else class="metrics"><div v-for="m in [{name:'Kutmoqda',value:tickets.filter(t=>t.status==='waiting').length},{name:'Chaqirilgan',value:tickets.filter(t=>t.status==='called').length},{name:'Qabulda',value:tickets.filter(t=>t.status==='in_progress').length},{name:'Yakunlangan',value:tickets.filter(t=>t.status==='completed').length}]" :key="m.name" class="panel metric"><span class="tile"><Users :size="25"/></span><div><small>{{m.name}}</small><strong>{{loading||error?'—':m.value}}</strong></div></div></div>

 <div :class="tab==='calendar'?'calendar-layout':'list-layout'">
  <section class="panel content">
   <div class="toolbar">
    <h2>{{tab==='calendar'?'Qabullar kalendari':tab==='list'?'Qabullar ro‘yxati':'Navbatlar monitoringi'}}</h2>
    <div class="toolbar-actions">
     <div v-if="tab==='calendar'" class="view-switch"><button class="current" aria-pressed="true">Kunlik</button><button disabled title="Haftalik kalendar keyingi bosqichda">Haftalik</button><button @click="setTab('list')">Ro‘yxat</button></div>
     <button v-if="tab==='queue'&&can('display.view')" @click="emit('openTablo')">Tablo boshqaruvi</button>
     <button class="refresh" @click="load" aria-label="Yangilash"><RefreshCw :size="16"/></button>
    </div>
   </div>
   <div class="datebar">
    <button :disabled="!date" @click="date=nextDay(date,-1)" aria-label="Oldingi kun"><ChevronLeft :size="18"/></button>
    <div class="date-group"><input type="date" v-model="date" aria-label="Sana"/><button :disabled="!date" @click="date=nextDay(date)" aria-label="Keyingi kun"><ChevronRight :size="18"/></button></div>
    <button :disabled="!zone" @click="date=localDay(Date.now(),zone)">Bugun</button>
    <select v-model="branch" aria-label="Filial"><option v-for="b in branches" :key="b.id" :value="b.id">{{b.name}}</option></select>
   </div>
   <div class="filters" :class="{'calendar-filters':tab==='calendar'}">
    <select v-if="doctors.length" v-model="doctorFilter" aria-label="Shifokor"><option value="">Barcha shifokorlar</option><option v-for="d in doctors" :key="d.id" :value="d.id">{{d.name}}</option></select>
    <select v-if="tab!=='queue'&&serviceOptions.length" v-model="serviceFilter" aria-label="Xizmat"><option value="">Barcha xizmatlar</option><option v-for="s in serviceOptions" :key="s.id" :value="s.id">{{s.name}}</option></select>
    <select v-if="tab==='calendar'" v-model="roomFilter" aria-label="Kabinet"><option value="">Barcha kabinetlar</option><option v-for="r in rooms" :key="r.id" :value="r.id">{{r.number}}</option></select>
    <label v-if="tab==='calendar'" class="busy-switch">Faqat band vaqtlar <input type="checkbox" role="switch" v-model="busyOnly"/><span class="switch-track" aria-hidden="true"></span></label>
    <select v-if="tab==='list'" v-model="status" aria-label="Holat"><option value="">Barcha holatlar</option><option v-for="s in ['scheduled','checked_in','in_progress','completed','cancelled','no_show']" :key="s" :value="s">{{label(s)}}</option></select>
    <input v-if="tab!=='calendar'" v-model="query" placeholder="Bemor qidirish..."/>
   </div>
   <p v-if="loading" role="status" class="hint">Ma’lumotlar yuklanmoqda…</p>
   <p v-else-if="error" role="alert">{{error}} <button @click="load">Qayta urinish</button></p>
   <template v-else>
    <p v-if="catalogError" role="alert">Shifokorlar katalogi: {{catalogError}}</p>
    <template v-if="tab==='calendar'">
     <p v-if="scheduleError" role="alert">Ish jadvali: {{scheduleError}}</p>
     <p v-if="!visibleDoctors.length" class="hint">Kalendar uchun shifokorlar katalogi mavjud emas.</p>
     <template v-else>
      <div class="calendar-scroll"><div class="calendar" :style="{gridTemplateColumns:'64px repeat('+visibleDoctors.length+', minmax(230px,1fr))'}">
 <div class="corner">Vaqt</div><div v-for="(d,i) in visibleDoctors" :key="d.id" class="doctor-head" :data-tone="i%4"><div class="doctor-identity"><span class="doctor-avatar">{{d.name.split(" ").slice(0,2).map(n=>n[0]).join("")}}</span><div><strong>{{d.name}}</strong><small>{{d.specialty_name||'—'}} · {{[...new Set(doctorWindows(d.id).map(w=>roomName(w.room_id)))].join(", ")||"—"}}</small><small>{{windowLabel(d.id)}}</small></div></div><small v-if="slotErrors[d.id]" class="warn">Bo‘sh vaqtlar yuklanmadi</small></div>
 <div class="times" :style="{height:height+'px'}"><div v-for="t in ticks" :key="t">{{time(new Date(t).toISOString())}}</div></div>
 <div v-for="d in visibleDoctors" :key="d.id" class="day-column" :style="{height:height+'px'}"><div v-for="t in ticks" :key="t" class="work-cell" :class="cellState(d.id,t)"><span v-if="cellState(d.id,t)==='break'" class="break-label"><Utensils :size="13"/> Tanaffus</span><span v-else-if="cellState(d.id,t)==='closed'">—</span></div><button v-for="e in events[d.id]" :key="e.key" class="event" :class="e.appointment?e.appointment.status:'free'" :style="position(e)" :title="e.appointment?name(e.appointment.patient_id)+' · '+serviceName(e.appointment)+' · '+time(e.start)+'–'+time(e.end)+' · '+label(e.appointment.status):'Qabulga yozish: '+time(e.start)+'–'+time(e.end)" :aria-label="e.appointment?name(e.appointment.patient_id)+' · '+label(e.appointment.status):'Qabulga yozish: '+time(e.start)" :disabled="!!e.slot&&!canReception('book')" @click="e.appointment?selected=e.appointment:e.slot&&openBooking(e.slot)"><template v-if="e.appointment"><span class="event-icon"><UserRound :size="15"/></span><span class="event-body"><strong>{{name(e.appointment.patient_id)}}</strong><small>{{serviceName(e.appointment)}}</small></span><span class="status-pill" :class="e.appointment.status"><i></i>{{label(e.appointment.status)}}</span></template><template v-else><Plus :size="17"/></template></button></div>
 </div></div>
      <div class="legend"><span v-for="s in ['scheduled','checked_in','in_progress','completed']" :key="s" class="status-pill" :class="s"><i></i>{{label(s)}}</span><span class="legend-free"><Plus :size="13"/> Bo‘sh vaqt</span><span>Kulrang — tanaffus / ish vaqtidan tashqari</span></div>
     </template>
    </template>
    <template v-else> <div v-if="tab==='list'" class="table-scroll"><table><thead><tr><th>Vaqt</th><th>Bemor</th><th>Shifokor</th><th>Xizmat</th><th>Holati</th><th>Amallar</th></tr></thead><tbody><tr v-for="a in filtered" :key="a.id"><td>{{time(a.starts_at)}}–{{time(a.ends_at)}}</td><td>{{name(a.patient_id)}}</td><td>{{doctorName(a.doctor_id)}}</td><td>{{serviceName(a)}}</td><td>{{label(a.status)}}</td><td><button @click="selected=a"><Eye :size="15"/> Ko‘rish</button></td></tr><tr v-if="!filtered.length"><td colspan="6">Qabullar mavjud emas.</td></tr></tbody></table></div>
 <div v-else class="table-scroll"><table><thead><tr><th>Navbat №</th><th>Bemor</th><th>Shifokor</th><th>Turi</th><th>Holati</th></tr></thead><tbody><tr v-for="t in queueFiltered" :key="t.id"><td>{{t.number}}</td><td>{{name(t.patient_id)}}</td><td>{{doctorName(t.doctor_id)}}</td><td>{{t.kind==='walk_in'?'Jonli navbat':'Oldindan yozilgan'}}</td><td>{{label(t.status)}}</td></tr><tr v-if="!queueFiltered.length"><td colspan="5">Navbat mavjud emas.</td></tr></tbody></table></div>
</template>
   </template>
  </section>
  <template v-if="tab==='calendar'&&!loading&&!error"><aside class="calendar-side"><section class="side-card"><div class="side-heading"><h3>{{zone&&date===localDay(Date.now(),zone)?'Bugungi qabullar':'Tanlangan kundagi qabullar'}}</h3><button class="side-link" @click="setTab('list')">Barchasini ko‘rish <ChevronRight :size="13"/></button></div><button v-for="a in occupied.slice(0,8)" :key="a.id" class="side-row" @click="selected=a"><span>{{time(a.starts_at)}}</span><div><strong>{{name(a.patient_id)}}</strong><small>{{doctorName(a.doctor_id)}} · {{serviceName(a)}}</small></div><span class="status-pill" :class="a.status"><i></i>{{label(a.status)}}</span></button><p v-if="!occupied.length" class="hint">Qabullar mavjud emas.</p></section><section class="side-card room-summary"><h3>Kabinetlar holati</h3><p class="hint">Qabul yozuvlari asosida; jonli bandlik alohida tekshiriladi.</p><div v-for="r in roomSummary" :key="r.room.id" class="room-line"><span class="room-icon" :class="r.appointment?.status||'unknown'"><DoorOpen :size="19"/></span><div class="room-info"><strong>{{r.room.number}}-xona</strong><small v-if="r.appointment">{{doctorName(r.appointment.doctor_id)}}</small></div><span class="status-pill" :class="r.appointment?.status||'unknown'"><i></i>{{r.state}}</span></div><p v-if="!roomSummary.length" class="hint">Kabinet ma’lumotlari mavjud emas.</p></section></aside></template>
 </div>
  <BookingDialog v-if="booking&&canReception('book')" :preset="preset" @close="booking=false" @saved="booking=false;load()"/>
 <Teleport to="body"><div v-if="selected&&can('appointments.view')" class="calendar-overlay" @click.self="selected=null"><section class="panel detail" role="dialog" aria-modal="true" aria-label="Qabul tafsilotlari"><h2>Qabul tafsilotlari</h2><p><strong>{{name(selected.patient_id)}}</strong></p><p>{{doctorName(selected.doctor_id)}} · {{serviceName(selected)}}</p><p>{{date}} · {{time(selected.starts_at)}}–{{time(selected.ends_at)}}</p><p>{{label(selected.status)}}</p><p v-if="selected.note">{{selected.note}}</p><button @click="selected=null">Yopish</button></section></div></Teleport>
</section>

</template>

<style scoped>
.unified{display:grid;gap:16px;min-width:0;color:#102045;font-size:13px;--line:#e3ebf7;--blue:#0866f8}
.heading,.toolbar,.toolbar-actions,.datebar,.filters,.tabs,.legend{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.heading,.toolbar{justify-content:space-between}.heading h1{font-size:27px;line-height:1.2;margin:0}.heading p,.hint{color:#647fa6;font-size:12px;margin:5px 0}.panel,.tabs,.side-card{background:#fff;border:1px solid var(--line,#e3ebf7);border-radius:16px;min-width:0;box-shadow:0 3px 10px #193e760b}
.unified button,.unified select,.unified input,.detail button{font:inherit;border:1px solid #dbe5f6;border-radius:8px;background:#fff;color:#19385e;padding:8px 10px;min-width:0;box-sizing:border-box}
.unified button,.detail button{display:inline-flex;align-items:center;justify-content:center;gap:5px;cursor:pointer}.unified button:disabled{opacity:.48;cursor:not-allowed}.unified .primary{background:var(--blue);color:white;border-color:var(--blue)}
.tabs{width:fit-content;max-width:100%;gap:0;padding:0 5px}.tabs button{border:0;border-bottom:2px solid transparent;border-radius:0;color:#163359;padding:12px 27px;font-weight:600}.tabs button.active{border-bottom-color:var(--blue);color:var(--blue)}
.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.metric{display:flex;gap:14px;align-items:center;padding:13px 15px;min-height:88px;box-sizing:border-box}.tile{background:#e3efff;color:#0866f8;width:52px;height:52px;border-radius:13px;display:grid;place-items:center;flex:none}.metric:nth-child(2) .tile{background:#ddf7eb;color:#009c63}.metric:nth-child(3) .tile{background:#fff0dd;color:#df7e00}.metric:nth-child(4) .tile{background:#efe6ff;color:#7635d9}.metric small,.metric strong{display:block}.metric small{color:#19385e;font-weight:600;font-size:12px;margin-bottom:4px}.metric strong{font-size:26px;line-height:1.1}.metric-note{display:block;font-size:10px;color:#647fa6;margin-top:4px}
.calendar-layout{display:grid;grid-template-columns:minmax(0,2.3fr) minmax(260px,1fr);gap:14px;align-items:start;min-width:0}.list-layout{min-width:0}.content{padding:13px 12px}.toolbar{margin-bottom:12px}.toolbar h2{font-size:21px;line-height:1.2;margin:0}.toolbar-actions{gap:5px}.view-switch{display:flex;gap:2px;padding:2px;border:1px solid #e3ebf7;background:#f3f6fc;border-radius:9px}.unified .view-switch button{border:0;background:transparent;padding:7px 12px;color:#58749a;font-size:12px;font-weight:600}.unified .view-switch .current{background:var(--blue);color:#fff}.unified .refresh{padding:7px;color:var(--blue)}
.datebar{margin:0 0 10px;gap:6px}.datebar button,.datebar input,.datebar select{height:34px;font-size:12px}.date-group{display:flex;align-items:center;border:1px solid #dbe5f6;border-radius:8px;overflow:hidden}.date-group input,.date-group button{border:0;border-radius:0;height:32px}.date-group button{border-left:1px solid #e3ebf7}.datebar input{font-weight:600}.filters{margin:0 0 12px}.filters select,.filters input{height:34px;font-size:12px}.calendar-filters{display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) auto;gap:8px;align-items:center}.calendar-filters select{width:100%;padding:6px 8px}.busy-switch{display:flex;align-items:center;gap:6px;position:relative;cursor:pointer;font-size:11px;white-space:nowrap;font-weight:600}.busy-switch input{position:absolute;right:0;width:34px;height:22px;opacity:0;cursor:pointer}.switch-track{width:34px;height:20px;background:#d8e1ee;border-radius:12px;position:relative;pointer-events:none}.switch-track:after{content:"";position:absolute;left:3px;top:3px;width:14px;height:14px;border-radius:50%;background:white;box-shadow:0 1px 3px #10204522;transition:transform .15s}.busy-switch input:checked+.switch-track{background:var(--blue)}.busy-switch input:checked+.switch-track:after{transform:translateX(14px)}.busy-switch input:focus-visible+.switch-track{outline:2px solid #176dff;outline-offset:2px}
.calendar-scroll,.table-scroll{overflow:auto;max-width:100%;min-width:0}.calendar-scroll{max-height:65vh;border-radius:10px;border:1px solid #edf1f8;scrollbar-width:thin;scrollbar-color:#c0cfe4 #f5f8fc}.calendar{display:grid;width:100%;min-width:max-content;isolation:isolate}.corner,.doctor-head{position:sticky;top:0;z-index:5;background:#f1f5fc;padding:9px 8px;border-bottom:1px solid #e6edf8;border-right:1px solid #e6edf8;box-sizing:border-box}.corner{left:0;z-index:7;display:grid;place-items:center;color:#58749a;font-size:11px}.doctor-identity{display:flex;align-items:center;gap:8px}.doctor-identity>div{min-width:0}.doctor-head strong,.doctor-head small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.doctor-head strong{font-size:11px}.doctor-head small{font-size:10px;color:#6680a3;margin-top:2px;line-height:1.2}.doctor-head .warn{color:#a65b00}.doctor-avatar{display:grid;place-items:center;width:32px;height:32px;border-radius:50%;background:#dceeff;color:#176dff;font-size:11px;font-weight:700;flex:none}.doctor-head[data-tone="1"] .doctor-avatar{background:#d9f4e9;color:#07876b}.doctor-head[data-tone="2"] .doctor-avatar{background:#ece3ff;color:#7041cb}.doctor-head[data-tone="3"] .doctor-avatar{background:#ffeedc;color:#ad6a13}.times{position:sticky;left:0;z-index:4;background:#f9fbfe}.times div,.work-cell{height:46px;box-sizing:border-box;border-bottom:1px solid #edf1f7;display:flex;align-items:center;justify-content:center}.times div{font-size:12px;font-weight:500}.day-column{position:relative;border-right:1px solid #edf1f7;background:#fff;min-width:0}.work-cell{font-size:11px;color:#9aabc0}.work-cell.closed{background:#edf1f6}.work-cell.break{background:#e9eff8;color:#58749a}.break-label{display:flex;gap:5px;align-items:center}
.unified .event{position:absolute;left:3px;right:3px;display:flex;align-items:center;justify-content:flex-start;gap:5px;padding:4px 5px;overflow:hidden;box-sizing:border-box;border:0;border-radius:7px;background:#e1edff;color:#1261d0;z-index:2;text-align:left}.event-icon{display:grid;place-items:center;width:23px;height:23px;border-radius:5px;background:#ffffffa6;flex:none}.event-body{flex:1;min-width:0;overflow:hidden}.event-body strong,.event-body small{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.2}.event-body strong{font-size:11px;color:#102045}.event-body small{font-size:10px;margin-top:2px}.unified .event.free{justify-content:center;border:1px dashed #c5d8f4;background:white;color:#6588b7;z-index:1;max-height:42px}.unified .event.free:hover{background:#eef5ff;border-color:#0866f8;color:#0866f8}.unified .event.completed{background:#dcf6e7;color:#008c48}.unified .event.in_progress{background:#eee4ff;color:#7033d7}.unified .event.checked_in{background:#fff0dc;color:#be7100}.unified .event:focus-visible{outline:2px solid #0866f8;outline-offset:-2px}
.status-pill{display:inline-flex;align-items:center;gap:3px;border-radius:6px;padding:4px 5px;font-size:10px;font-weight:600;white-space:nowrap;flex:none;background:#e3efff;color:#0866f8}.status-pill i{width:5px;height:5px;border-radius:50%;background:currentColor;flex:none}.status-pill.completed{background:#d9f5e5;color:#008c48}.status-pill.checked_in,.status-pill.waiting{background:#ffefd8;color:#b96d00}.status-pill.in_progress{background:#ece3ff;color:#7132d4}.status-pill.cancelled,.status-pill.no_show{background:#fbe5e9;color:#b83d4c}.status-pill.unknown{background:#eef2f7;color:#62738d}.event .status-pill{font-size:9px;padding:3px;background:#ffffff70;color:inherit}.legend{font-size:10px;color:#647fa6;margin-top:9px;gap:5px}.legend-free{display:flex;align-items:center;gap:4px}
.calendar-side{display:grid;gap:14px;min-width:0}.side-card{padding:13px 14px}.side-heading{display:flex;align-items:center;justify-content:space-between;gap:5px;margin-bottom:5px}.side-card h3{font-size:16px;margin:0 0 7px}.side-heading h3{margin:0}.unified .side-link{border:0;color:var(--blue);font-size:10px;padding:2px 0;white-space:nowrap}.unified .side-row{width:100%;display:flex;justify-content:flex-start;text-align:left;border:0;border-bottom:1px solid #edf1f7;border-radius:0;padding:9px 0;gap:8px}.side-row:last-child{border-bottom:0}.side-row>span:first-child{min-width:37px;font-size:12px;font-weight:600}.side-row>div{flex:1;min-width:0}.side-row strong,.side-row small,.room-info strong,.room-info small{display:block;overflow-wrap:anywhere}.side-row strong,.room-info strong{font-size:12px}.side-row small,.room-info small{font-size:10px;color:#6480a5;margin-top:3px}.room-summary .hint{font-size:10px;margin:0 0 6px}.room-line{display:flex;align-items:center;gap:9px;padding:10px 0;border-bottom:1px solid #edf1f7}.room-line:last-child{border-bottom:0}.room-icon{width:36px;height:36px;display:grid;place-items:center;flex:none;border-radius:9px;background:#edf2fa;color:#647fa6}.room-icon.in_progress{background:#eee5ff;color:#7433d5}.room-icon.checked_in{background:#fff0dc;color:#ca7800}.room-info{flex:1;min-width:0}.room-line .status-pill{font-size:9px}
.table-scroll table{width:100%;min-width:680px;border-collapse:collapse;text-align:left;font-size:13px}.table-scroll th,.table-scroll td{padding:12px;border-bottom:1px solid #e5edf8}.table-scroll th{background:#eef4fc;color:#526e96}.calendar-overlay{position:fixed;inset:0;background:#10204580;display:grid;place-items:center;z-index:1200;padding:20px}.detail{width:min(480px,100%);padding:24px;color:#102045}.detail p{overflow-wrap:anywhere}.detail h2{margin-top:0}
@media(max-width:1250px){.calendar-layout{grid-template-columns:minmax(0,1fr)}.calendar-side{grid-template-columns:repeat(2,minmax(0,1fr))}.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:720px){.calendar-side,.metrics{grid-template-columns:1fr}.calendar-filters{grid-template-columns:repeat(2,minmax(0,1fr))}.tabs button{padding:10px 12px}}
</style>
