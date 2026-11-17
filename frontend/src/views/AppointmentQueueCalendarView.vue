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
  <section class="unified" :class="{ 'calendar-page': tab === 'calendar' }">
    <header class="heading">
      <div>
        <h1>Qabullar va navbatlar</h1>
        <p>Qabul rejasi, navbatlar va shifokorlarning bandlik holati</p>
      </div>
      <button v-if="canReception('book')" class="primary" @click="openBooking()">
        <Plus :size="18" /> Qabulga yozish
      </button>
    </header>

    <nav class="tabs" aria-label="Qabullar va navbatlar sahifalari">
      <button v-if="can('appointments.view')" :class="{ active: tab === 'calendar' }" :aria-current="tab === 'calendar' ? 'page' : undefined" @click="setTab('calendar')">
        <CalendarDays :size="23" /> Kalendar
      </button>
      <button v-if="can('appointments.view')" :class="{ active: tab === 'list' }" :aria-current="tab === 'list' ? 'page' : undefined" @click="setTab('list')">
        <List :size="23" /> Qabullar
      </button>
      <button v-if="can('queues.view')" :class="{ active: tab === 'queue' }" :aria-current="tab === 'queue' ? 'page' : undefined" @click="setTab('queue')">
        <Users :size="23" /> Navbatlar
      </button>
    </nav>

    <div v-if="tab !== 'queue'" class="metrics">
      <div v-for="m in [{name:'Rejalashtirilgan qabullar',value:appointments.filter(a=>a.status==='scheduled').length,icon:CalendarDays},{name:'Bo‘sh vaqtlar',value:tab==='calendar'&&!Object.keys(slotErrors).length&&!catalogError&&!scheduleError?slotCount:'—',icon:Users},{name:'Band vaqtlar',value:occupied.length,icon:Clock3},{name:'Ishlayotgan shifokorlar',value:tab==='calendar'&&!scheduleError&&!catalogError?activeDoctors:'—',icon:Stethoscope}]" :key="m.name" class="panel metric">
        <span class="tile"><component :is="m.icon" :size="32" :stroke-width="1.8" /></span>
        <div class="metric-body"><small>{{ m.name }}</small><strong>{{ loading || error ? '—' : m.value }}</strong><span class="metric-note">{{ zone && date === localDay(Date.now(), zone) ? 'Bugungi kun bo‘yicha' : 'Tanlangan kun bo‘yicha' }}</span></div>
      </div>
    </div>
    <div v-else class="metrics">
      <div v-for="m in [{name:'Kutmoqda',value:tickets.filter(t=>t.status==='waiting').length},{name:'Chaqirilgan',value:tickets.filter(t=>t.status==='called').length},{name:'Qabulda',value:tickets.filter(t=>t.status==='in_progress').length},{name:'Yakunlangan',value:tickets.filter(t=>t.status==='completed').length}]" :key="m.name" class="panel metric">
        <span class="tile"><Users :size="32" /></span><div class="metric-body"><small>{{ m.name }}</small><strong>{{ loading || error ? '—' : m.value }}</strong><span class="metric-note">Tanlangan kun bo‘yicha</span></div>
      </div>
    </div>

    <div :class="tab === 'calendar' ? 'calendar-layout' : 'list-layout'">
      <section class="panel content">
        <div class="toolbar">
          <h2>{{ tab === 'calendar' ? 'Qabullar kalendari' : tab === 'list' ? 'Qabullar ro‘yxati' : 'Navbatlar monitoringi' }}</h2>
          <div class="toolbar-actions">
            <div v-if="tab === 'calendar'" class="view-switch" aria-label="Kalendar ko‘rinishi">
              <button class="current" aria-pressed="true">Kunlik</button>
              <button disabled title="Haftalik kalendar hali ulanmagan">Haftalik</button>
              <button @click="setTab('list')">Ro‘yxat</button>
            </div>
            <button v-if="tab === 'queue' && can('display.view')" class="control" @click="emit('openTablo')">Tablo boshqaruvi</button>
            <button class="icon-button refresh" @click="load" aria-label="Yangilash" title="Yangilash"><RefreshCw :size="17" /></button>
          </div>
        </div>

        <div class="datebar">
          <button class="icon-button" :disabled="!date" @click="date = nextDay(date, -1)" aria-label="Oldingi kun"><ChevronLeft :size="21" /></button>
          <div class="date-group">
            <label class="date-display">
              <CalendarDays :size="19" />
              <span>{{ date ? Number(date.slice(8, 10)) + ' ' + ['yanvar','fevral','mart','aprel','may','iyun','iyul','avgust','sentabr','oktabr','noyabr','dekabr'][Number(date.slice(5, 7)) - 1] + ', ' + date.slice(0, 4) + ' (' + ['Yakshanba','Dushanba','Seshanba','Chorshanba','Payshanba','Juma','Shanba'][new Date(date + 'T12:00:00Z').getUTCDay()] + ')' : 'Sanani tanlang' }}</span>
              <input type="date" v-model="date" aria-label="Sana" />
            </label>
            <button class="icon-button" :disabled="!date" @click="date = nextDay(date)" aria-label="Keyingi kun"><ChevronRight :size="21" /></button>
          </div>
          <button class="control today-button" :disabled="!zone" @click="date = localDay(Date.now(), zone)">Bugun</button>
          <select class="branch-filter" v-model="branch" aria-label="Filial" title="Filial">
            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
          </select>
        </div>

        <div class="filters" :class="{ 'calendar-filters': tab === 'calendar' }">
          <select v-if="doctors.length" v-model="doctorFilter" aria-label="Shifokor"><option value="">Barcha shifokorlar</option><option v-for="d in doctors" :key="d.id" :value="d.id">{{ d.name }}</option></select>
          <select v-if="tab !== 'queue' && serviceOptions.length" v-model="serviceFilter" aria-label="Xizmat"><option value="">Barcha xizmatlar</option><option v-for="s in serviceOptions" :key="s.id" :value="s.id">{{ s.name }}</option></select>
          <select v-if="tab === 'calendar'" v-model="roomFilter" aria-label="Kabinet"><option value="">Barcha kabinetlar</option><option v-for="r in rooms" :key="r.id" :value="r.id">{{ /xona/i.test(r.number) ? r.number : r.number + '-xona' }}</option></select>
          <label v-if="tab === 'calendar'" class="busy-switch">Faqat band vaqtlar <input type="checkbox" role="switch" v-model="busyOnly" /><span class="switch-track" aria-hidden="true"></span></label>
          <select v-if="tab === 'list'" v-model="status" aria-label="Holat"><option value="">Barcha holatlar</option><option v-for="s in ['scheduled', 'checked_in', 'in_progress', 'completed', 'cancelled', 'no_show']" :key="s" :value="s">{{ label(s) }}</option></select>
          <input v-if="tab !== 'calendar'" v-model="query" placeholder="Bemor qidirish..." aria-label="Bemor qidirish" />
        </div>

        <p v-if="loading" role="status" class="hint calendar-message">Ma’lumotlar yuklanmoqda…</p>
        <p v-else-if="error" role="alert" class="calendar-message">{{ error }} <button class="control" @click="load">Qayta urinish</button></p>
        <template v-else>
          <p v-if="catalogError" role="alert">Shifokorlar katalogi: {{ catalogError }}</p>
          <template v-if="tab === 'calendar'">
            <p v-if="scheduleError" role="alert">Ish jadvali: {{ scheduleError }}</p>
            <p v-if="!visibleDoctors.length" class="hint calendar-message">Kalendar uchun shifokorlar katalogi mavjud emas.</p>
            <div v-else class="calendar-scroll" role="region" aria-label="Kunlik qabullar kalendari" tabindex="0">
              <div class="calendar" :style="{ gridTemplateColumns: '72px repeat(' + visibleDoctors.length + ', minmax(210px, 1fr))', minWidth: (72 + visibleDoctors.length * 210) + 'px' }">
                <div class="corner">Vaqt</div>
                <div v-for="(d, i) in visibleDoctors" :key="d.id" class="doctor-head" :data-tone="i % 4">
                  <div class="doctor-identity">
                    <span class="doctor-avatar" aria-hidden="true">{{ d.name.replace(/^Dr\.?\s+/i, "").trim().split(/\s+/).slice(0, 2).map(n => n[0]).join("") }}</span>
                    <div class="doctor-info">
                      <strong :title="d.name">{{ d.name }}</strong>
                      <small :title="[d.specialty_name, ...new Set(doctorWindows(d.id).map(w => roomName(w.room_id) === '—' ? '—' : /xona/i.test(roomName(w.room_id)) ? roomName(w.room_id) : roomName(w.room_id) + '-xona'))].filter(Boolean).join(' · ') || '—'">{{ [d.specialty_name, ...new Set(doctorWindows(d.id).map(w => roomName(w.room_id) === '—' ? '—' : /xona/i.test(roomName(w.room_id)) ? roomName(w.room_id) : roomName(w.room_id) + '-xona'))].filter(Boolean).join(' · ') || '—' }}</small>
                      <span class="doctor-hours" :title="windowLabel(d.id)">{{ windowLabel(d.id) }}</span>
                    </div>
                  </div>
                  <small v-if="slotErrors[d.id]" class="warn" :title="slotErrors[d.id]">Bo‘sh vaqtlar yuklanmadi</small>
                </div>

                <div class="times" :style="{ height: height + 'px' }"><div v-for="t in ticks" :key="t">{{ time(new Date(t).toISOString()) }}</div></div>
                <div v-for="d in visibleDoctors" :key="d.id" class="day-column" :style="{ height: height + 'px' }">
                  <div v-for="t in ticks" :key="t" class="work-cell" :class="cellState(d.id, t) === 'break' ? 'work' : cellState(d.id, t)"><span v-if="cellState(d.id, t) === 'closed'" aria-hidden="true">—</span></div>
                  <template v-for="(window, wi) in doctorWindows(d.id)" :key="wi">
                    <div v-for="(pause, bi) in window.breaks" :key="bi" class="calendar-break"
                      :style="{ top: ((mins(pause.local_start) - localMinute(displayRange.start)) / 30 * ROW_HEIGHT) + 'px', height: ((mins(pause.local_end) - mins(pause.local_start)) / 30 * ROW_HEIGHT) + 'px' }"
                      :title="'Tanaffus: ' + pause.local_start + '–' + pause.local_end">
                      <Utensils :size="15" /><span>Tanaffus ({{ pause.local_start }}–{{ pause.local_end }})</span>
                    </div>
                  </template>
                  <button v-for="e in events[d.id]" :key="e.key" class="event" :class="e.appointment ? e.appointment.status : 'free'" :style="position(e)"
                    :title="e.appointment ? name(e.appointment.patient_id) + ' · ' + serviceName(e.appointment) + ' · ' + time(e.start) + '–' + time(e.end) + ' · ' + label(e.appointment.status) : 'Qabulga yozish: ' + time(e.start) + '–' + time(e.end)"
                    :aria-label="e.appointment ? name(e.appointment.patient_id) + ' · ' + label(e.appointment.status) : 'Qabulga yozish: ' + time(e.start)"
                    :disabled="!!e.slot && !canReception('book')" @click="e.appointment ? selected = e.appointment : e.slot && openBooking(e.slot)">
                    <template v-if="e.appointment">
                      <span class="event-icon"><UserRound :size="18" /></span>
                      <span class="event-body"><strong>{{ name(e.appointment.patient_id) }}</strong><small>{{ serviceName(e.appointment) }}</small></span>
                      <span class="status-pill" :class="e.appointment.status"><svg v-if="e.appointment.status === 'completed'" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6" /></svg><Clock3 v-else-if="e.appointment.status === 'checked_in'" :size="12" /><i v-else></i>{{ label(e.appointment.status) }}</span>
                    </template>
                    <Plus v-else :size="18" />
                  </button>
                </div>
              </div>
            </div>
          </template>

          <div v-else-if="tab === 'list'" class="table-scroll">
            <table><thead><tr><th>Vaqt</th><th>Bemor</th><th>Shifokor</th><th>Xizmat</th><th>Holati</th><th>Amallar</th></tr></thead><tbody>
              <tr v-for="a in filtered" :key="a.id"><td>{{ time(a.starts_at) }}–{{ time(a.ends_at) }}</td><td>{{ name(a.patient_id) }}</td><td>{{ doctorName(a.doctor_id) }}</td><td>{{ serviceName(a) }}</td><td><span class="status-pill" :class="a.status">{{ label(a.status) }}</span></td><td><button class="control" @click="selected = a"><Eye :size="15" /> Ko‘rish</button></td></tr>
              <tr v-if="!filtered.length"><td colspan="6">Qabullar mavjud emas.</td></tr>
            </tbody></table>
          </div>
          <div v-else class="table-scroll">
            <table><thead><tr><th>Navbat №</th><th>Bemor</th><th>Shifokor</th><th>Turi</th><th>Holati</th></tr></thead><tbody>
              <tr v-for="t in queueFiltered" :key="t.id"><td>{{ t.number }}</td><td>{{ name(t.patient_id) }}</td><td>{{ doctorName(t.doctor_id) }}</td><td>{{ t.kind === 'walk_in' ? 'Jonli navbat' : 'Oldindan yozilgan' }}</td><td><span class="status-pill" :class="t.status">{{ label(t.status) }}</span></td></tr>
              <tr v-if="!queueFiltered.length"><td colspan="5">Navbat mavjud emas.</td></tr>
            </tbody></table>
          </div>
        </template>
      </section>

      <aside v-if="tab === 'calendar' && !loading && !error" class="calendar-side">
        <section class="side-card appointments-summary">
          <div class="side-heading"><h3>{{ zone && date === localDay(Date.now(), zone) ? 'Bugungi qabullar' : 'Tanlangan kundagi qabullar' }}</h3><button class="side-link" @click="setTab('list')">Barchasini ko‘rish <ChevronRight :size="14" /></button></div>
          <div class="side-list">
            <button v-for="a in occupied.slice(0, 8)" :key="a.id" class="side-row" @click="selected = a">
              <span class="side-time">{{ time(a.starts_at) }}</span>
              <span class="side-patient"><strong :title="name(a.patient_id)">{{ name(a.patient_id) }}</strong><small :title="doctorName(a.doctor_id) + ' · ' + serviceName(a)">{{ doctorName(a.doctor_id) }} · {{ serviceName(a) }}</small></span>
              <span class="status-pill" :class="a.status"><svg v-if="a.status === 'completed'" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6" /></svg><Clock3 v-else-if="a.status === 'checked_in'" :size="12" />{{ label(a.status) }}</span>
            </button>
            <p v-if="!occupied.length" class="hint">Qabullar mavjud emas.</p>
          </div>
        </section>

        <section class="side-card room-summary">
          <div class="side-heading"><h3>Kabinetlar holati</h3><span class="room-count" v-if="roomSummary.length">{{ roomSummary.length }} ta</span></div>
          <div class="room-list">
            <div v-for="r in roomSummary" :key="r.room.id" class="room-line">
              <span class="room-icon" :class="(r.state === 'Yopiq' ? 'closed' : r.appointment?.status || 'unknown')"><DoorOpen :size="24" :stroke-width="1.8" /></span>
              <div class="room-info"><strong :title="(/xona/i.test(r.room.number) ? r.room.number : r.room.number + '-xona')">{{ (/xona/i.test(r.room.number) ? r.room.number : r.room.number + '-xona') }}</strong><small v-if="doctors.filter(d => (windows[d.id] || []).some(w => w.room_id === r.room.id)).map(d => [d.specialty_name, d.name].filter(Boolean).join(' · ')).join(', ')" :title="doctors.filter(d => (windows[d.id] || []).some(w => w.room_id === r.room.id)).map(d => [d.specialty_name, d.name].filter(Boolean).join(' · ')).join(', ')">{{ doctors.filter(d => (windows[d.id] || []).some(w => w.room_id === r.room.id)).map(d => [d.specialty_name, d.name].filter(Boolean).join(' · ')).join(', ') }}</small></div>
              <span class="status-pill" :class="(r.state === 'Yopiq' ? 'closed' : r.appointment?.status || 'unknown')" :title="(r.state === 'Yopiq' ? 'closed' : r.appointment?.status || 'unknown') === 'unknown' ? 'API jonli bo‘sh holatni tasdiqlamaydi.' : undefined"><i></i>{{ r.state === 'Holat tasdiqlanmagan' ? 'Aniqlanmagan' : r.state }}</span>
            </div>
            <p v-if="!roomSummary.length" class="hint">Kabinet ma’lumotlari mavjud emas.</p>
          </div>
        </section>
      </aside>
    </div>

    <BookingDialog v-if="booking && canReception('book')" :preset="preset" @close="booking = false" @saved="booking = false; load()" />
    <Teleport to="body">
      <div v-if="selected && can('appointments.view')" class="calendar-overlay" @click.self="selected = null">
        <section class="panel detail" role="dialog" aria-modal="true" aria-label="Qabul tafsilotlari">
          <h2>Qabul tafsilotlari</h2><p><strong>{{ name(selected.patient_id) }}</strong></p><p>{{ doctorName(selected.doctor_id) }} · {{ serviceName(selected) }}</p><p>{{ date }} · {{ time(selected.starts_at) }}–{{ time(selected.ends_at) }}</p><p><span class="status-pill" :class="selected.status">{{ label(selected.status) }}</span></p><p v-if="selected.note">{{ selected.note }}</p><button class="control" @click="selected = null">Yopish</button>
        </section>
      </div>
    </Teleport>
  </section>
</template>

<style scoped>
.unified {
  --line: #e3ebf8;
  --blue: #0866ff;
  --ink: #101b44;
  --muted: #617da7;
  --shell-space: 120px;
  display: grid;
  grid-template-rows: auto auto auto minmax(0, 1fr);
  gap: 16px;
  min-width: 0;
  min-height: 0;
  height: calc(100dvh - var(--shell-space));
  color: var(--ink);
  font: 13px "Employees Inter", Inter, Arial, sans-serif;
}
.unified.calendar-page { height: auto; grid-template-rows: auto auto auto auto; }
.unified :is(button, input, select), .detail button { font: inherit; box-sizing: border-box; }
.unified button, .detail button { cursor: pointer; }
.unified button:disabled { cursor: not-allowed; opacity: .48; }
.unified :is(button, select, input):focus-visible, .calendar-scroll:focus-visible, .detail button:focus-visible { outline: 2px solid var(--blue, #0866ff); outline-offset: 2px; }
.heading, .toolbar, .toolbar-actions, .datebar, .tabs { display: flex; align-items: center; }
.heading, .toolbar { justify-content: space-between; gap: 12px; }
.heading h1 { font-size: 27px; line-height: 1.2; letter-spacing: -.65px; margin: 0; font-weight: 750; }
.heading p { color: var(--muted); margin: 4px 0 0; font-size: 14px; line-height: 1.35; }
.primary, .control, .icon-button, .branch-filter, .filters :is(select, input) { border: 1px solid var(--line); border-radius: 9px; background: #fff; color: #1d365d; min-width: 0; height: 38px; }
.primary, .control, .icon-button { display: inline-flex; align-items: center; justify-content: center; gap: 7px; }
.primary { padding: 0 14px; background: var(--blue); border-color: var(--blue); color: #fff; font-weight: 600; flex-shrink: 0; }
.control { padding: 0 12px; }
.icon-button { width: 38px; flex-shrink: 0; padding: 0; }
.panel, .tabs, .side-card { border: 1px solid var(--line); border-radius: 18px; background: #fff; box-shadow: 0 3px 5px #18397005, 0 5px 14px #18397005; min-width: 0; }
.tabs { width: 614px; max-width: 100%; padding: 0; height: 48px; overflow: hidden; }
.tabs button { display: inline-flex; justify-content: center; align-items: center; gap: 13px; height: 100%; flex: 1; padding: 0 20px; border: 0; border-bottom: 3px solid transparent; background: transparent; font-weight: 650; color: var(--ink); font-size: 15px; }
.tabs button.active { color: var(--blue); border-bottom-color: var(--blue); background: #fcfdff; }
.metrics { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
.metric { display: flex; align-items: center; gap: 16px; padding: 12px 16px; min-height: 96px; box-sizing: border-box; }
.tile { display: grid; place-items: center; width: 56px; height: 56px; border-radius: 16px; background: #e3efff; color: var(--blue); flex-shrink: 0; }
.metric:nth-child(2) .tile { background: #e0f8ec; color: #00a752; }
.metric:nth-child(3) .tile { background: #fff1df; color: #e67e00; }
.metric:nth-child(4) .tile { background: #f0e8ff; color: #7529dd; }
.metric-body { min-width: 0; }
.metric small, .metric strong, .metric-note { display: block; }
.metric small { font-weight: 650; font-size: 12px; line-height: 1.3; }
.metric strong { font-size: 30px; line-height: 1.1; margin: 4px 0; letter-spacing: -.7px; }
.metric-note { font-size: 12px; line-height: 1.3; color: var(--muted); }
.calendar-layout { display: grid; grid-template-columns: minmax(0, 69fr) minmax(0, 31fr); align-items: start; gap: 16px; min-width: 0; min-height: 0; }
.list-layout { min-width: 0; min-height: 0; }
.content { display: flex; flex-direction: column; box-sizing: border-box; padding: 16px; min-height: 0; height: 100%; overflow: hidden; }
.calendar-page .content { height: auto; overflow: visible; }
.toolbar { margin-bottom: 12px; flex-shrink: 0; }
.toolbar h2 { font-size: 23px; line-height: 1.2; letter-spacing: -.6px; margin: 0; font-weight: 750; }
.toolbar-actions { gap: 7px; flex-shrink: 0; }
.view-switch { display: flex; padding: 2px; background: #f5f7fd; border: 1px solid #e6edf9; border-radius: 11px; box-shadow: 0 2px 4px #21488309; }
.view-switch button { border: 0; background: transparent; color: #4b6899; border-radius: 9px; height: 34px; min-width: 86px; padding: 0 16px; font-size: 13px; font-weight: 550; }
.view-switch .current { color: #fff; background: var(--blue); box-shadow: 0 3px 7px #0866ff26; }
.refresh { color: var(--blue); width: 34px; height: 34px; }
.datebar { flex-wrap: wrap; gap: 7px; margin-bottom: 12px; flex-shrink: 0; }
.date-group { display: flex; align-items: center; border: 1px solid var(--line); border-radius: 9px; overflow: hidden; height: 38px; box-sizing: border-box; box-shadow: 0 2px 3px #18397006; }
.date-group .icon-button { border: 0; border-left: 1px solid var(--line); border-radius: 0; height: 36px; }
.date-display { position: relative; display: inline-flex; align-items: center; gap: 10px; padding: 0 12px; height: 36px; cursor: pointer; font-weight: 650; color: var(--ink); white-space: nowrap; }
.date-display svg { color: #244c8d; flex-shrink: 0; }
.date-display input { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; border: 0; padding: 0; }
.date-display input::-webkit-calendar-picker-indicator { position: absolute; inset: 0; width: 100%; height: 100%; margin: 0; padding: 0; cursor: pointer; }
.unified .branch-filter { margin-left: auto; padding: 0 24px 0 10px; max-width: 170px; font-size: 12px; }
.unified .today-button { font-size: 12px; color: #4b6899; }
.filters { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 14px; flex-shrink: 0; }
.filters :is(select, input) { padding: 0 30px 0 12px; flex: 1; min-width: 130px; height: 36px; font-size: 12px; box-shadow: 0 2px 3px #18397006; }
.calendar-filters { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)) auto; gap: 12px; }
.calendar-filters select { width: 100%; min-width: 0; }
.busy-switch { display: inline-flex; align-items: center; gap: 10px; white-space: nowrap; font-size: 12px; font-weight: 550; position: relative; cursor: pointer; }
.busy-switch input { position: absolute; width: 40px; height: 24px; right: 0; opacity: 0; cursor: pointer; z-index: 1; }
.switch-track { flex-shrink: 0; display: inline-block; width: 40px; height: 24px; background: #d7dfed; border-radius: 99px; position: relative; transition: background .15s; }
.switch-track::after { content: ""; width: 20px; height: 20px; border-radius: 50%; background: white; position: absolute; top: 2px; left: 2px; box-shadow: 0 1px 4px #233c6326; transition: transform .15s; }
.busy-switch input:checked + .switch-track { background: var(--blue); }
.busy-switch input:checked + .switch-track::after { transform: translateX(16px); }
.busy-switch input:focus-visible + .switch-track { outline: 2px solid var(--blue); outline-offset: 2px; }
.hint { color: var(--muted); font-size: 12px; margin: 8px 0; line-height: 1.5; }
.calendar-message { padding: 22px 6px; }
.calendar-scroll { flex: none; height: clamp(430px, 52vh, 620px); min-height: 430px; min-width: 0; overflow: auto; overscroll-behavior: contain; border: 1px solid #e5ecf8; border-radius: 12px; scrollbar-width: thin; scrollbar-color: #cbd9ed #f5f8fd; }
.calendar { display: grid; width: 100%; isolation: isolate; }
.corner { position: sticky; top: 0; left: 0; z-index: 5; background: #f3f7ff; display: flex; align-items: center; padding-left: 17px; color: #48648d; font-size: 12px; border-right: 1px solid #e7eef9; border-bottom: 1px solid #e7eef9; }
.doctor-head { position: sticky; top: 0; z-index: 4; background: #f3f7ff; border-right: 1px solid #e7eef9; border-bottom: 1px solid #e7eef9; min-width: 0; padding: 6px 9px; box-sizing: border-box; }
.doctor-identity { display: flex; align-items: center; gap: 10px; min-height: 40px; }
.doctor-avatar { display: grid; place-items: center; width: 34px; height: 34px; flex-shrink: 0; border-radius: 50%; background: #d9f0ff; color: #125780; font-size: 13px; font-weight: 700; }
.doctor-head[data-tone="1"] .doctor-avatar { background: #d9f8e9; color: #007e49; }
.doctor-head[data-tone="2"] .doctor-avatar { background: #e9e1ff; color: #6430ce; }
.doctor-head[data-tone="3"] .doctor-avatar { background: #ffe9dc; color: #a75a27; }
.doctor-info { flex: 1; min-width: 0; }
.doctor-info strong, .doctor-info small, .doctor-hours { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.doctor-info strong { font-size: 12px; line-height: 16px; font-weight: 650; }
.doctor-info small { font-size: 11px; line-height: 15px; color: #4b6899; margin-top: 1px; }
.doctor-hours { font-size: 10px; line-height: 13px; color: #7890b3; }
.warn { display: block; color: #b45309; font-size: 10px; margin-top: 4px; }
.times { position: sticky; left: 0; z-index: 3; background: #fff; border-right: 1px solid #e7eef9; color: #314563; font-size: 13px; }
.times > div, .work-cell { height: 46px; box-sizing: border-box; border-bottom: 1px solid #edf1f8; }
.times > div { display: flex; align-items: center; justify-content: center; font-variant-numeric: tabular-nums; }
.day-column { position: relative; min-width: 0; border-right: 1px solid #e7eef9; }
.work-cell { background: #fff; }
.work-cell.closed, .work-cell.unknown { background: #f6f8fc; color: #b0bfd4; text-align: center; line-height: 46px; }
.event { position: absolute; left: 3px; right: 3px; box-sizing: border-box; display: flex; align-items: center; gap: 6px; margin: 0; padding: 5px 7px; border: 0; border-radius: 8px; background: #e3efff; color: #0866ff; z-index: 2; overflow: hidden; text-align: left; }
.event-icon { display: grid; place-items: center; flex-shrink: 0; width: 23px; height: 25px; border-radius: 5px; background: #ffffff80; }
.event-body { flex: 1; min-width: 0; overflow: hidden; }
.event-body strong, .event-body small { display: block; white-space: nowrap; text-overflow: ellipsis; overflow: hidden; }
.event-body strong { color: var(--ink); font-size: 12px; font-weight: 650; line-height: 16px; }
.event-body small { color: #4e6a98; font-size: 11px; line-height: 14px; }
.event.free { justify-content: center; padding: 0; max-height: 42px; border: 1px dashed #b9cdf7; background: #fff; color: #5779ac; z-index: 1; }
.event.free:hover:not(:disabled) { background: #eef5ff; color: var(--blue); border-color: var(--blue); }
.event.free:disabled { opacity: .5; }
.calendar-break { position: absolute; left: 3px; right: 3px; box-sizing: border-box; display: flex; align-items: center; justify-content: center; gap: 7px; border-radius: 8px; background: #e9eef6; color: #647c9e; font-size: 11px; font-weight: 550; z-index: 1; pointer-events: none; text-align: center; padding: 4px; }
.status-pill { display: inline-flex; align-items: center; gap: 4px; padding: 4px 7px; border-radius: 7px; white-space: nowrap; font-size: 11px; line-height: 15px; font-weight: 600; flex-shrink: 0; background: #e3efff; color: #0866ff; }
.status-pill i { width: 6px; height: 6px; flex-shrink: 0; border-radius: 50%; background: currentColor; }
.event.checked_in, .status-pill.checked_in, .status-pill.waiting, .status-pill.called { background: #fff0dc; color: #d97706; }
.event.in_progress, .status-pill.in_progress { background: #eee5ff; color: #7028e4; }
.event.completed, .status-pill.completed { background: #dff7ea; color: #009b50; }
.event.cancelled, .event.no_show, .status-pill.cancelled, .status-pill.no_show { background: #fde8ec; color: #dc3545; }
.event .status-pill { padding: 3px 5px; font-size: 10px; line-height: 14px; }
.status-pill.unknown, .status-pill.closed { background: #eef2f8; color: #71849f; }
.calendar-side { display: flex; flex-direction: column; min-width: 0; gap: 16px; }
.side-card { box-sizing: border-box; padding: 16px; min-height: 0; display: flex; flex-direction: column; }
.appointments-summary { flex: none; }
.side-heading { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; flex-shrink: 0; }
.side-heading h3 { font-size: 18px; font-weight: 750; line-height: 24px; letter-spacing: -.4px; margin: 0; }
.unified .side-link { display: inline-flex; align-items: center; gap: 0; padding: 0; border: 0; background: none; color: var(--blue); font-size: 11px; flex-shrink: 0; white-space: nowrap; }
.side-list, .room-list { min-height: 0; overflow: auto; scrollbar-width: thin; scrollbar-color: #cbd9ed transparent; }
.side-row { display: flex; align-items: center; width: 100%; min-height: 42px; text-align: left; gap: 9px; padding: 5px 0; border: 0; border-bottom: 1px solid #edf1f8; background: #fff; color: var(--ink); }
.side-row:last-child, .room-line:last-child { border-bottom: 0; }
.side-time { flex-shrink: 0; width: 41px; font-size: 12px; font-weight: 650; font-variant-numeric: tabular-nums; }
.side-patient, .room-info { flex: 1; min-width: 0; }
.side-patient strong, .side-patient small, .room-info strong, .room-info small { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.side-patient strong, .room-info strong { font-size: 12px; font-weight: 650; line-height: 16px; }
.side-patient small, .room-info small { color: var(--muted); font-size: 10px; line-height: 15px; }
.side-list { max-height: 368px; }
.room-list { max-height: 324px; }
.room-summary { flex: none; }
.room-count { color: #7c93b5; font-size: 11px; }
.room-line { display: flex; align-items: center; gap: 11px; min-height: 50px; padding: 5px 0; box-sizing: border-box; border-bottom: 1px solid #edf1f8; }
.room-icon { width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0; display: grid; place-items: center; background: #edf2fa; color: #6480ad; }
.room-icon.in_progress { background: #eee5ff; color: #7028e4; }
.room-icon.checked_in { background: #fff0dc; color: #d97706; }
.table-scroll { overflow: auto; flex: 1; min-height: 0; border-radius: 10px; }
.table-scroll table { width: 100%; min-width: 680px; border-collapse: collapse; text-align: left; font-size: 13px; }
.table-scroll th, .table-scroll td { padding: 12px; border-bottom: 1px solid var(--line); }
.table-scroll th { position: sticky; top: 0; background: #f1f6fd; color: #526e96; }
.calendar-overlay { position: fixed; inset: 0; display: grid; place-items: center; z-index: 1200; background: #10204580; padding: 20px; }
.detail { width: min(480px, 100%); padding: 24px; box-sizing: border-box; color: #102045; font: 14px "Employees Inter", Inter, Arial, sans-serif; }
.detail p { overflow-wrap: anywhere; }
.detail h2 { margin-top: 0; }
@media (min-width: 1600px) { .unified { --shell-space: 132px; } .tile { width: 60px; height: 60px; } .metric small { font-size: 13px; } .metric-note { font-size: 13px; } .doctor-info strong, .event-body strong { font-size: 12px; } .event-body small { font-size: 11px; } .event .status-pill { font-size: 10px; } .side-patient strong, .room-info strong { font-size: 13px; } .side-patient small, .room-info small { font-size: 11px; } }
@media (max-width: 1150px) { .unified { height: auto; } .calendar-layout { grid-template-columns: minmax(0, 1fr); } .calendar-scroll { flex: none; height: 440px; } .calendar-side { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); height: auto; } .metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 720px) { .unified { gap: 12px; } .heading { align-items: flex-start; flex-wrap: wrap; } .heading h1 { font-size: 24px; } .heading p { max-width: 300px; } .tabs { width: 100%; } .tabs button { padding: 0 8px; font-size: 13px; gap: 8px; } .metric { gap: 12px; padding: 13px; } .tile { width: 44px; height: 44px; border-radius: 12px; } .tile svg { width: 25px; } .metric strong { font-size: 28px; } .metric small { font-size: 11px; } .metric-note { font-size: 10px; } .content { padding: 12px; } .toolbar { flex-wrap: wrap; } .toolbar h2 { font-size: 20px; } .toolbar-actions { width: 100%; justify-content: space-between; } .calendar-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); } .busy-switch { justify-self: start; grid-column: 1 / -1; } .calendar-side { grid-template-columns: minmax(0, 1fr); } .branch-filter { max-width: 140px; } .date-display { padding: 0 9px; font-size: 12px; gap: 6px; } .date-group .icon-button { width: 29px; } .today-button { padding: 0 8px; } }
@media (prefers-reduced-motion: reduce) { .switch-track, .switch-track::after { transition: none; } }
</style>
