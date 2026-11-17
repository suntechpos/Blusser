<script setup lang="ts">
import { computed, ref, watch, onBeforeUnmount } from "vue";
import { CalendarDays, List, Users, ChevronLeft, ChevronRight, Plus, RefreshCw, Clock3, Eye } from "lucide-vue-next";
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
const labels:Record<string,string>={scheduled:"Yozilgan",checked_in:"Kelgan",in_progress:"Qabulda",completed:"Yakunlangan",cancelled:"Bekor qilingan",no_show:"Kelmagan",waiting:"Kutmoqda",called:"Chaqirildi",skipped:"O‘tkazib yuborilgan"};
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
function localMinute(t:number){return mins(time(new Date(t).toISOString()))}
function slotKey(s:BookingSlot){return [s.doctor_id,s.room_id,s.starts_at,s.ends_at].join("|")}
function windowLabel(id:string){return doctorWindows(id).map(w=>w.local_start+"–"+w.local_end).join(", ")||"Ish jadvali yo‘q"}
const filtered=computed(()=>appointments.value.filter(a=>(!doctorFilter.value||a.doctor_id===doctorFilter.value)&&(!serviceFilter.value||a.service_id===serviceFilter.value)&&(!status.value||a.status===status.value)&&(!query.value.trim()||[name(a.patient_id),patients.value[a.patient_id]?.phone,patients.value[a.patient_id]?.patient_number].some(s=>s?.toLowerCase().includes(query.value.trim().toLowerCase())))));
const queueFiltered=computed(()=>tickets.value.filter(t=>(!doctorFilter.value||t.doctor_id===doctorFilter.value)&&(!query.value.trim()||[name(t.patient_id),String(t.number)].some(s=>s.toLowerCase().includes(query.value.trim().toLowerCase())))));
const occupied=computed(()=>appointments.value.filter(a=>!["cancelled","no_show"].includes(a.status)));
const slotCount=computed(()=>visibleDoctors.value.reduce((n,d)=>n+(free.value[d.id]||[]).filter(s=>!roomFilter.value||s.room_id===roomFilter.value).length,0));
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
        const key=slot.starts_at;
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
 const f=(busyOnly.value?[]:(free.value[d.id]||[]).filter(s=>!roomFilter.value||s.room_id===roomFilter.value)).map((s,i)=>({key:"slot"+i,start:s.starts_at,end:s.ends_at,slot:s,serviceId:serviceFilter.value} as CalendarEvent));
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
const height=computed(()=>ticks.value.length*64);
function cellState(id:string,t:number){
 const m=localMinute(t), end=localMinute(t+1799999)+1;
 const work=doctorWindows(id);
 const w=work.find(w=>m>=mins(w.local_start)&&end<=mins(w.local_end));
 if(!w)return "closed";
 if(w.breaks.some(b=>m<mins(b.local_end)&&end>mins(b.local_start)))return "break";
 return "work";
}
const roomSummary=computed(()=>rooms.value.filter(r=>r.active&&(!roomFilter.value||r.id===roomFilter.value)).map(r=>{
 const a=occupied.value.find(a=>a.room_id===r.id&&a.status==="in_progress");
 const waiting=occupied.value.find(a=>a.room_id===r.id&&a.status==="checked_in");
 return {room:r,appointment:a||waiting,state:r.availability!=="available"?"Yopiq":a?"Qabulda":waiting?"Kutmoqda":"Holat tasdiqlanmagan"};
}));
function position(e:CalendarEvent){const r=displayRange.value;return {top:Math.max(0,(Date.parse(e.start)-r.start)/1800000*64)+"px",height:Math.max(14,(Math.min(Date.parse(e.end),r.end)-Math.max(Date.parse(e.start),r.start))/1800000*64-3)+"px"}}
</script>
<template>
<section class="unified">
 <header class="heading"><div><h1>Qabullar va navbatlar</h1><p>Qabullarni rejalashtirish va navbatlarni kuzatish</p></div><button v-if="canReception('book')" class="primary" @click="openBooking()"><Plus :size="18"/> Qabulga yozish</button></header>
 <nav class="tabs"><button v-if="can('appointments.view')" :class="{active:tab==='calendar'}" @click="setTab('calendar')"><CalendarDays :size="19"/> Kalendar</button><button v-if="can('appointments.view')" :class="{active:tab==='list'}" @click="setTab('list')"><List :size="19"/> Qabullar</button><button v-if="can('queues.view')" :class="{active:tab==='queue'}" @click="setTab('queue')"><Users :size="19"/> Navbatlar</button></nav>
 <div v-if="tab!=='queue'" class="metrics"><div v-for="m in [{name:'Rejalashtirilgan',value:appointments.filter(a=>a.status==='scheduled').length,icon:CalendarDays},{name:'Bo‘sh vaqtlar',value:tab==='calendar'&&!Object.keys(slotErrors).length&&!catalogError&&!scheduleError?slotCount:'—',icon:Plus},{name:'Band qabullar',value:occupied.length,icon:Clock3},{name:'Ish jadvalidagi shifokorlar',value:tab==='calendar'&&!scheduleError&&!catalogError?activeDoctors:'—',icon:Users}]" :key="m.name" class="panel metric"><span class="tile"><component :is="m.icon" :size="25"/></span><div><small>{{m.name}}</small><strong>{{loading||error?'—':m.value}}</strong></div></div></div>
 <div v-else class="metrics"><div v-for="m in [{name:'Kutmoqda',value:tickets.filter(t=>t.status==='waiting').length},{name:'Chaqirilgan',value:tickets.filter(t=>t.status==='called').length},{name:'Qabulda',value:tickets.filter(t=>t.status==='in_progress').length},{name:'Yakunlangan',value:tickets.filter(t=>t.status==='completed').length}]" :key="m.name" class="panel metric"><span class="tile"><Users :size="25"/></span><div><small>{{m.name}}</small><strong>{{loading||error?'—':m.value}}</strong></div></div></div>
 <section class="panel content">
 <div class="toolbar"><h2>{{tab==='calendar'?'Qabullar kalendari':tab==='list'?'Qabullar ro‘yxati':'Navbatlar monitoringi'}}</h2><button v-if="tab==='queue'&&can('display.view')" @click="emit('openTablo')">Tablo boshqaruvi</button><button @click="load" aria-label="Yangilash"><RefreshCw :size="17"/></button></div>
 <div class="filters"><button :disabled="!date" @click="date=nextDay(date,-1)" aria-label="Oldingi kun"><ChevronLeft :size="18"/></button><input type="date" v-model="date" aria-label="Sana"/><button :disabled="!date" @click="date=nextDay(date)" aria-label="Keyingi kun"><ChevronRight :size="18"/></button><button :disabled="!zone" @click="date=localDay(Date.now(),zone)">Bugun</button><select v-model="branch" aria-label="Filial"><option v-for="b in branches" :key="b.id" :value="b.id">{{b.name}}</option></select><select v-if="doctors.length" v-model="doctorFilter" aria-label="Shifokor"><option value="">Barcha shifokorlar</option><option v-for="d in doctors" :key="d.id" :value="d.id">{{d.name}}</option></select><select v-if="tab!=='queue'&&serviceOptions.length" v-model="serviceFilter" aria-label="Xizmat"><option value="">Barcha xizmatlar</option><option v-for="s in serviceOptions" :key="s.id" :value="s.id">{{s.name}}</option></select><select v-if="tab==='list'" v-model="status" aria-label="Holat"><option value="">Barcha holatlar</option><option v-for="s in ['scheduled','checked_in','in_progress','completed','cancelled','no_show']" :key="s" :value="s">{{label(s)}}</option></select><input v-if="tab!=='calendar'" v-model="query" placeholder="Bemor qidirish..."/></div>
 <p v-if="loading" role="status">Ma’lumotlar yuklanmoqda…</p><p v-else-if="error" role="alert">{{error}} <button @click="load">Qayta urinish</button></p>
 <template v-else>
 <p v-if="catalogError" role="alert">Shifokorlar katalogi: {{catalogError}}</p>
 <template v-if="tab==='calendar'">
 <p class="hint">Bo‘sh vaqtlar xizmat davomiyligiga bog‘liq. + bosilganda server tasdiqlagan xizmat va vaqt tanlanadi.</p>
 <p v-if="scheduleError" role="alert">Ish jadvali: {{scheduleError}}</p><div class="calendar-extra"><select v-model="roomFilter" aria-label="Kabinet"><option value="">Barcha kabinetlar</option><option v-for="r in rooms" :key="r.id" :value="r.id">{{r.number}}</option></select><label><input type="checkbox" v-model="busyOnly"/> Faqat band vaqtlar</label></div><p v-if="!visibleDoctors.length" class="hint">Kalendar uchun shifokorlar katalogi mavjud emas. Qabullar tabida ruxsat berilgan bronlarni ko‘rishingiz mumkin.</p>
 <div v-else class="calendar-layout"><div class="calendar-scroll"><div class="calendar" :style="{gridTemplateColumns:'76px repeat('+visibleDoctors.length+', minmax(220px,1fr))'}">
 <div class="corner">Vaqt</div><div v-for="d in visibleDoctors" :key="d.id" class="doctor-head"><div class="doctor-identity"><span class="doctor-avatar">{{d.name.split(" ").slice(0,2).map(n=>n[0]).join("")}}</span><div><strong>{{d.name}}</strong><small>{{d.specialty_name||'—'}} · {{[...new Set(doctorWindows(d.id).map(w=>roomName(w.room_id)))].join(", ")||"—"}}</small><small>{{windowLabel(d.id)}}</small></div></div><small v-if="slotErrors[d.id]" class="warn">Bo‘sh vaqtlar yuklanmadi</small></div>
 <div class="times" :style="{height:height+'px'}"><div v-for="t in ticks" :key="t">{{time(new Date(t).toISOString())}}</div></div>
 <div v-for="d in visibleDoctors" :key="d.id" class="day-column" :style="{height:height+'px'}"><div v-for="t in ticks" :key="t" class="work-cell" :class="cellState(d.id,t)"><span v-if="cellState(d.id,t)==='break'">Tanaffus</span><span v-else-if="cellState(d.id,t)==='closed'">—</span></div><button v-for="e in events[d.id]" :key="e.key" class="event" :class="e.appointment?e.appointment.status:'free'" :style="position(e)" :disabled="!!e.slot&&!canReception('book')" @click="e.appointment?selected=e.appointment:e.slot&&openBooking(e.slot)"><template v-if="e.appointment"><strong>{{name(e.appointment.patient_id)}}</strong><small>{{time(e.start)}}–{{time(e.end)}} · {{label(e.appointment.status)}} · {{serviceName(e.appointment)}}</small></template><template v-else><Plus :size="15"/> {{time(e.start)}}–{{time(e.end)}}</template></button></div>
 </div></div><aside class="calendar-side"><h3>Tanlangan kundagi qabullar</h3><button v-for="a in occupied.slice(0,8)" :key="a.id" class="side-row" @click="selected=a"><span>{{time(a.starts_at)}}</span><div><strong>{{name(a.patient_id)}}</strong><small>{{doctorName(a.doctor_id)}} · {{serviceName(a)}}</small><small>{{label(a.status)}}</small></div></button><p v-if="!occupied.length" class="hint">Qabullar mavjud emas.</p><button v-if="occupied.length>8" @click="setTab('list')">Barchasini ko‘rish</button><div class="room-summary"><h3>Kabinetlar holati</h3><div v-for="r in roomSummary" :key="r.room.id" class="room-line"><div><strong>{{r.room.number}}</strong><small v-if="r.appointment">{{doctorName(r.appointment.doctor_id)}}</small></div><span>{{r.state}}</span></div><p v-if="!roomSummary.length" class="hint">Kabinet ma’lumotlari mavjud emas.</p></div></aside></div>
 <div class="legend"><span>🔵 Yozilgan</span><span>🟢 Yakunlangan</span><span>🟣 Qabulda</span><span>＋ Tasdiqlangan bo‘sh vaqt</span><span>Kulrang — ish vaqtidan tashqari yoki tanaffus</span></div>
 </template>
 <div v-else-if="tab==='list'" class="table-scroll"><table><thead><tr><th>Vaqt</th><th>Bemor</th><th>Shifokor</th><th>Xizmat</th><th>Holati</th><th>Amallar</th></tr></thead><tbody><tr v-for="a in filtered" :key="a.id"><td>{{time(a.starts_at)}}–{{time(a.ends_at)}}</td><td>{{name(a.patient_id)}}</td><td>{{doctorName(a.doctor_id)}}</td><td>{{serviceName(a)}}</td><td>{{label(a.status)}}</td><td><button @click="selected=a"><Eye :size="15"/> Ko‘rish</button></td></tr><tr v-if="!filtered.length"><td colspan="6">Qabullar mavjud emas.</td></tr></tbody></table></div>
 <div v-else class="table-scroll"><table><thead><tr><th>Navbat №</th><th>Bemor</th><th>Shifokor</th><th>Turi</th><th>Holati</th></tr></thead><tbody><tr v-for="t in queueFiltered" :key="t.id"><td>{{t.number}}</td><td>{{name(t.patient_id)}}</td><td>{{doctorName(t.doctor_id)}}</td><td>{{t.kind==='walk_in'?'Jonli navbat':'Oldindan yozilgan'}}</td><td>{{label(t.status)}}</td></tr><tr v-if="!queueFiltered.length"><td colspan="5">Navbat mavjud emas.</td></tr></tbody></table></div>
 </template>
 </section>
 <BookingDialog v-if="booking&&canReception('book')" :preset="preset" @close="booking=false" @saved="booking=false;load()"/>
 <Teleport to="body"><div v-if="selected&&can('appointments.view')" class="calendar-overlay" @click.self="selected=null"><section class="panel detail" role="dialog" aria-modal="true" aria-label="Qabul tafsilotlari"><h2>Qabul tafsilotlari</h2><p><strong>{{name(selected.patient_id)}}</strong></p><p>{{doctorName(selected.doctor_id)}} · {{serviceName(selected)}}</p><p>{{date}} · {{time(selected.starts_at)}}–{{time(selected.ends_at)}}</p><p>{{label(selected.status)}}</p><p v-if="selected.note">{{selected.note}}</p><button @click="selected=null">Yopish</button></section></div></Teleport>
</section>
</template>
<style scoped>
.unified{display:grid;gap:16px;min-width:0;color:#102045}.heading,.toolbar,.filters,.tabs,.legend{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.heading,.toolbar{justify-content:space-between}.heading h1{font-size:30px;margin:0}.heading p,.hint,.legend{color:#647fa6;font-size:13px}.heading p{margin:5px 0}.panel,.tabs{background:white;border:1px solid #dce7f8;border-radius:12px;min-width:0}.unified button,.unified select,.unified input,.detail button{font:inherit;border:1px solid #d1def4;border-radius:8px;background:white;color:#176dff;padding:9px 12px;min-width:0}.unified button,.detail button{display:inline-flex;align-items:center;justify-content:center;gap:6px;cursor:pointer}.unified button:disabled{opacity:.5;cursor:not-allowed}.unified .primary{background:#176dff;color:white;border-color:#176dff}.tabs{padding:0 12px;gap:16px}.tabs button{border:0;border-bottom:3px solid transparent;border-radius:0;color:#39557e;padding:13px 20px}.tabs button.active{border-bottom-color:#176dff;color:#176dff}.metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}.metric{display:flex;gap:13px;align-items:center;padding:15px}.tile{background:#eaf2ff;color:#176dff;padding:12px;border-radius:10px;display:flex}.metric small,.metric strong{display:block}.metric small{color:#526f98;margin-bottom:5px}.metric strong{font-size:25px}.content{padding:16px}.toolbar h2{font-size:21px;margin:0}.filters{margin:15px 0}.calendar-scroll,.table-scroll{overflow:auto;max-width:100%}.calendar-scroll{max-height:65vh}.calendar{display:grid;min-width:max-content}.corner,.doctor-head{position:sticky;top:0;z-index:3;background:#eef4fc;padding:13px;border-bottom:1px solid #dce7f8;border-right:1px solid #dce7f8}.corner{left:0;z-index:5}.doctor-head small,.event small{display:block;font-size:11px;margin-top:3px}.doctor-head small{color:#6480a5}.doctor-head .warn{color:#a65b00}.times{position:sticky;left:0;z-index:2;background:white}.times div{height:64px;border-bottom:1px solid #e4ebf7;padding:8px;font-size:12px}.day-column{position:relative;border-right:1px solid #dce7f8;background:repeating-linear-gradient(to bottom,#f6f8fc 0,#f6f8fc 63px,#dce7f8 63px,#dce7f8 64px)}.unified .event{position:absolute;left:4px;right:4px;display:block;text-align:left;overflow:hidden;padding:4px 7px;font-size:12px;background:#e3efff;border:1px solid #cfe1ff;color:#164c97;z-index:1}.unified .event.free{background:white;border:1px dashed #b5c9e5;color:#527cac;display:flex;align-items:center;z-index:0}.unified .event.completed{background:#e1f6eb;color:#137b4d}.unified .event.in_progress{background:#eee5ff;color:#6a34c9}.unified .event.checked_in{background:#fff1d8;color:#a56800}.unified .event.cancelled,.unified .event.no_show{background:#fcebec;color:#a83136;opacity:.7}.legend{margin-top:12px}.table-scroll table{width:100%;min-width:680px;border-collapse:collapse;text-align:left;font-size:13px}.table-scroll th,.table-scroll td{padding:12px;border-bottom:1px solid #e5edf8}.table-scroll th{background:#eef4fc;color:#526e96}.calendar-overlay{position:fixed;inset:0;background:#10204580;display:grid;place-items:center;z-index:1200;padding:20px}.detail{width:min(480px,100%);padding:24px;color:#102045}.detail p{overflow-wrap:anywhere}.detail h2{margin-top:0}
.calendar-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(220px,280px);gap:14px;align-items:start}.calendar-side{border:1px solid #dce7f8;border-radius:10px;padding:12px;min-width:0}.calendar-side h3{font-size:15px;margin:0 0 10px}.unified .side-row{display:flex;width:100%;justify-content:flex-start;align-items:flex-start;text-align:left;border:0;border-bottom:1px solid #e5edf8;border-radius:0;color:#102045;padding:10px 0;gap:10px}.side-row>span{font-size:12px;min-width:40px}.side-row strong,.side-row small{display:block;overflow-wrap:anywhere}.side-row strong{font-size:12px}.side-row small{color:#6480a5;font-size:11px;margin-top:4px}@media(max-width:1250px){.calendar-layout{grid-template-columns:minmax(0,1fr)}}
.calendar-extra{display:flex;align-items:center;gap:12px;margin:8px 0 14px;color:#526f98;font-size:13px}.calendar-extra label{display:flex;gap:7px;align-items:center}.doctor-identity{display:flex;align-items:center;gap:9px}.doctor-avatar{background:#e0efff;color:#176dff;border-radius:50%;width:34px;height:34px;display:grid;place-items:center;flex:none;font-size:12px;font-weight:700}.work-cell{height:64px;border-bottom:1px solid #e1e9f6;display:flex;align-items:center;justify-content:center;font-size:11px;color:#8093ac;background:#f9fbff;box-sizing:border-box}.work-cell.closed,.work-cell.break{background:#e9eef5}.work-cell.break{color:#526f98}.unified .event.free{z-index:1;justify-content:center;max-height:59px}.unified .event:not(.free){z-index:2}.metric:nth-child(2) .tile{background:#e0f7ec;color:#099665}.metric:nth-child(3) .tile{background:#fff0db;color:#da8000}.metric:nth-child(4) .tile{background:#f0e8ff;color:#7639e8}.room-summary{border-top:1px solid #e0e9f6;margin-top:14px;padding-top:14px}.room-line{display:flex;justify-content:space-between;align-items:center;gap:6px;padding:10px 0;border-bottom:1px solid #e5edf8;font-size:12px}.room-line small{display:block;color:#6480a5;margin-top:4px}.room-line span{color:#526f98;font-size:11px}
</style>