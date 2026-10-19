<script setup lang="ts">
import { computed, ref } from 'vue'
import { CalendarDays, Clock3, CheckCircle2, Stethoscope, Search, Plus, Download, Eye, Pencil, MoreHorizontal, ChevronLeft, ChevronRight, X, UserRound, Phone, ClipboardList } from 'lucide-vue-next'

type Status = 'Rejalashtirilgan' | 'Kutilmoqda' | 'Qabulda' | 'Yakunlangan' | 'Bekor qilingan' | 'Kelmadi'
type Appointment = { id:number; patient:string; patientId:string; phone:string; doctor:string; specialty:string; service:string; date:string; time:string; status:Status }
const seed: Appointment[] = [
  {id:1, patient:'Aliyeva Mohira',patientId:'P-00124',phone:'+998 90 123 45 67',doctor:'Karimov A.',specialty:'Kardiolog',service:'Kardiolog konsultatsiya',date:'2026-10-15',time:'09:00',status:'Qabulda'},
  {id:2, patient:'Qodirov Aziz',patientId:'P-00126',phone:'+998 91 234 56 78',doctor:'Saidov B.',specialty:'UZI',service:'UZI',date:'2026-10-15',time:'09:20',status:'Kutilmoqda'},
  {id:3, patient:'Ismoilova Nilufar',patientId:'P-00127',phone:'+998 93 456 78 90',doctor:'Rahmonova D.',specialty:'Terapevt',service:'Terapevt konsultatsiya',date:'2026-10-15',time:'10:00',status:'Rejalashtirilgan'},
  {id:4, patient:'Matchonov Siroj',patientId:'P-00128',phone:'+998 90 987 65 43',doctor:'Karimov A.',specialty:'Kardiolog',service:'EKG',date:'2026-10-15',time:'10:30',status:'Yakunlangan'},
  {id:5, patient:'Toshboyev Akmal',patientId:'P-00129',phone:'+998 91 111 22 33',doctor:'Rasulova M.',specialty:'Laboratoriya',service:'Laboratoriya tahlillari',date:'2026-10-15',time:'11:00',status:'Kutilmoqda'},
  {id:6, patient:'Yo‘ldosheva Xulkar',patientId:'P-00130',phone:'+998 93 222 33 44',doctor:'Nuriddinov O.',specialty:'Ortoped',service:'Ortoped konsultatsiya',date:'2026-10-15',time:'11:30',status:'Rejalashtirilgan'},
  {id:7, patient:'Karimov Rustam',patientId:'P-00131',phone:'+998 90 333 44 55',doctor:'Saidov B.',specialty:'UZI',service:'UZI',date:'2026-10-16',time:'09:40',status:'Rejalashtirilgan'},
  {id:8, patient:'Saidova Barno',patientId:'P-00132',phone:'+998 91 555 66 77',doctor:'Rahmonova D.',specialty:'Terapevt',service:'Terapevt konsultatsiya',date:'2026-10-16',time:'10:20',status:'Bekor qilingan'},
]
const appointments = ref<Appointment[]>(seed.map(x=>({...x})))
const selectedDate = ref('')
const selectedDoctor = ref('')
const selectedStatus = ref('')
const search = ref('')
const tab = ref('Barchasi')
const page = ref(1)
const perPage = 6
const modal = ref<'new'|'view'|'edit'|null>(null)
const chosen = ref<Appointment|null>(null)
const notice = ref('')
const menuId = ref<number|null>(null)
const form = ref({patient:'',patientId:'',phone:'',doctor:'Karimov A.',service:'Kardiolog konsultatsiya',date:'2026-10-16',time:'09:00',status:'Rejalashtirilgan' as Status})
const doctors = ['Karimov A.','Saidov B.','Rahmonova D.','Rasulova M.','Nuriddinov O.']
const states: Status[] = ['Rejalashtirilgan','Kutilmoqda','Qabulda','Yakunlangan','Bekor qilingan','Kelmadi']
const tabs = ['Barchasi','Bugungi','Rejalashtirilgan','Qabulda','Yakunlangan']
const countStatus = (s:Status) => appointments.value.filter(a=>a.status===s).length
const totalForTab = (t:string) => t==='Barchasi' ? appointments.value.length : t==='Bugungi' ? appointments.value.filter(a=>a.date==='2026-10-15').length : appointments.value.filter(a=>a.status===t).length
const filtered = computed(()=>appointments.value.filter(a=>{
  const q=search.value.toLocaleLowerCase('uz').trim()
  return (!q || [a.patient,a.patientId,a.phone,a.doctor,a.service].some(v=>v.toLocaleLowerCase('uz').includes(q)))
    && (!selectedDate.value || a.date===selectedDate.value)
    && (!selectedDoctor.value || a.doctor===selectedDoctor.value)
    && (!selectedStatus.value || a.status===selectedStatus.value)
    && (tab.value==='Barchasi' || (tab.value==='Bugungi' ? a.date==='2026-10-15' : a.status===tab.value))
}))
const pages = computed(()=>Math.max(1,Math.ceil(filtered.value.length/perPage)))
const visible = computed(()=>filtered.value.slice((Math.min(page.value,pages.value)-1)*perPage,Math.min(page.value,pages.value)*perPage))
function updateFilters(){page.value=1}
function initials(n:string){return n.trim().split(/\s+/).slice(0,2).map(x=>x[0]??'').join('').toUpperCase()}
function displayDate(date:string){const [y,m,d]=date.split('-'); return `${d}-${m}-${y}`}
function openNew(){menuId.value=null;chosen.value=null;form.value={patient:'',patientId:'',phone:'',doctor:doctors[0],service:'Kardiolog konsultatsiya',date:'2026-10-16',time:'09:00',status:'Rejalashtirilgan'};modal.value='new'}
function openPatient(a:Appointment,mode:'view'|'edit'){menuId.value=null;chosen.value=a;form.value={patient:a.patient,patientId:a.patientId,phone:a.phone,doctor:a.doctor,service:a.service,date:a.date,time:a.time,status:a.status};modal.value=mode}
function save(){
  if(!form.value.patient.trim() || !form.value.date || !form.value.time){notice.value='Bemor ismi, sana va vaqtni to‘ldiring.';return}
  const conflict=appointments.value.some(a=>a.id!==chosen.value?.id && a.doctor===form.value.doctor && a.date===form.value.date && a.time===form.value.time && !['Bekor qilingan','Kelmadi'].includes(a.status))
  if(conflict){notice.value='Bu shifokorning tanlangan vaqti band. Boshqa vaqtni tanlang.';return}
  if(modal.value==='new'){
    const id=Math.max(0,...appointments.value.map(a=>a.id))+1
    appointments.value.unshift({id,...form.value,specialty:form.value.service.split(' ')[0]})
    notice.value='Qabul demo ro‘yxatiga qo‘shildi.'
  } else if(chosen.value) {
    Object.assign(chosen.value,form.value)
    notice.value='Qabul demo ma’lumotlari yangilandi.'
  }
  modal.value=null;page.value=1;tab.value='Barchasi';selectedDate.value='';selectedDoctor.value='';selectedStatus.value='';search.value=''
}
function setStatus(a:Appointment,status:Status){a.status=status;menuId.value=null;notice.value=`${a.patient}: holat «${status}» deb belgilandi (demo).`}
function exportCsv(){
  const lines=[['ID','Bemor','Telefon','Shifokor','Xizmat','Sana','Vaqt','Holat'],...filtered.value.map(a=>[a.patientId,a.patient,a.phone,a.doctor,a.service,a.date,a.time,a.status])]
  const csv='\uFEFF'+lines.map(line=>line.map(v=>'"'+String(v).replace(/"/g,'""')+'"').join(',')).join('\r\n')
  const url=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8;'}))
  const link=document.createElement('a');link.href=url;link.download='blusser-qabullar-demo.csv';link.click();URL.revokeObjectURL(url)
}
</script>

<template>
  <section class="appointments-page legacy-ui">
    <div class="ap-head">
      <div><h1>Qabullar</h1><p>Barcha qabullarni boshqarish va rejalashtirish</p></div>
      <div class="ap-actions"><button class="ap-primary" @click="openNew"><Plus :size="18"/> Qabulga yozish</button><button class="ap-export" @click="exportCsv"><Download :size="18"/> Eksport</button></div>
    </div>
    <div v-if="notice" class="ap-notice" role="status">{{ notice }} <button aria-label="Yopish" @click="notice=''">×</button></div>
    <div class="ap-filterbox">
      <label class="ap-field"><span>Sana</span><input type="date" v-model="selectedDate" @change="updateFilters"/></label>
      <label class="ap-field"><span>Shifokor</span><select v-model="selectedDoctor" @change="updateFilters"><option value="">Barchasi</option><option v-for="d in doctors" :key="d">{{d}}</option></select></label>
      <label class="ap-field"><span>Holat</span><select v-model="selectedStatus" @change="updateFilters"><option value="">Barchasi</option><option v-for="s in states" :key="s">{{s}}</option></select></label>
      <label class="ap-field"><span>Filial</span><select disabled><option>Shifo Med Klinikasi</option></select></label>
      <label class="ap-search"><Search :size="18"/><input v-model="search" @input="updateFilters" placeholder="Bemor, telefon yoki ID bo‘yicha qidiring..." /></label>
    </div>
    <div class="ap-stats">
      <div class="ap-stat blue"><div class="ap-stat-icon"><CalendarDays :size="27"/></div><div><span>Jami qabullar</span><strong>{{appointments.length}}</strong><small>Demo ro‘yxatidagi barcha qabullar</small></div></div>
      <div class="ap-stat amber"><div class="ap-stat-icon"><Clock3 :size="27"/></div><div><span>Kutilmoqda</span><strong>{{countStatus('Kutilmoqda')}}</strong><small>Bemorlar navbatda</small></div></div>
      <div class="ap-stat pink"><div class="ap-stat-icon"><Stethoscope :size="27"/></div><div><span>Qabulda</span><strong>{{countStatus('Qabulda')}}</strong><small>Hozir qabulda</small></div></div>
      <div class="ap-stat green"><div class="ap-stat-icon"><CheckCircle2 :size="27"/></div><div><span>Yakunlangan</span><strong>{{countStatus('Yakunlangan')}}</strong><small>Yakunlangan qabullar</small></div></div>
    </div>
    <div class="ap-panel">
      <nav class="ap-tabs" aria-label="Qabullar holati"><button v-for="t in tabs" :key="t" :class="{selected:tab===t}" @click="tab=t;page=1">{{t}} <span>{{totalForTab(t)}}</span></button></nav>
      <div class="ap-table-wrap"><table><thead><tr><th>#</th><th>Bemor</th><th>Telefon</th><th>Shifokor</th><th>Xizmat</th><th>Sana</th><th>Vaqt</th><th>Holat</th><th>Amallar</th></tr></thead>
      <tbody><tr v-for="(a,i) in visible" :key="a.id"><td>{{(Math.min(page,pages)-1)*perPage+i+1}}</td><td><div class="ap-person"><div class="ap-avatar">{{initials(a.patient)}}</div><div><strong>{{a.patient}}</strong><small>{{a.patientId}}</small></div></div></td><td class="ap-muted">{{a.phone}}</td><td><strong>{{a.doctor}}</strong><small>{{a.specialty}}</small></td><td class="ap-muted">{{a.service}}</td><td class="ap-muted">{{displayDate(a.date)}}</td><td><strong>{{a.time}}</strong></td><td><span class="ap-status" :class="{'inprogress':a.status==='Qabulda','waiting':a.status==='Kutilmoqda','planned':a.status==='Rejalashtirilgan','finished':a.status==='Yakunlangan','cancelled':['Bekor qilingan','Kelmadi'].includes(a.status)}">● {{a.status}}</span></td><td><div class="ap-row-actions"><button @click="openPatient(a,'view')" title="Ko‘rish"><Eye :size="15"/> Ko‘rish</button><button @click="openPatient(a,'edit')" title="Tahrirlash"><Pencil :size="15"/> Tahrirlash</button><div class="ap-more-wrap"><button @click="menuId=menuId===a.id?null:a.id" aria-label="Qo‘shimcha amallar"><MoreHorizontal :size="17"/></button><div v-if="menuId===a.id" class="ap-menu"><button @click="setStatus(a,'Kutilmoqda')">Kutilmoqda</button><button @click="setStatus(a,'Qabulda')">Qabulda</button><button @click="setStatus(a,'Yakunlangan')">Yakunlandi</button><button @click="setStatus(a,'Bekor qilingan')">Bekor qilish</button></div></div></div></td></tr>
      <tr v-if="!visible.length"><td colspan="9" class="ap-empty">Tanlangan shartlarga mos qabullar topilmadi.</td></tr></tbody></table></div>
      <div class="ap-footer"><span>{{filtered.length? (Math.min(page,pages)-1)*perPage+1:0}}–{{Math.min(Math.min(page,pages)*perPage,filtered.length)}} dan {{filtered.length}} ta qabul ko‘rsatilmoqda</span><div class="ap-pagination"><button :disabled="page<=1" @click="page--"><ChevronLeft :size="17"/></button><button v-for="p in pages" :key="p" :class="{active:page===p}" @click="page=p">{{p}}</button><button :disabled="page>=pages" @click="page++"><ChevronRight :size="17"/></button></div></div>
    </div>
    <div v-if="modal" class="ap-overlay" @click.self="modal=null"><div class="ap-modal" role="dialog" aria-modal="true"><div class="ap-modal-head"><div><h2>{{modal==='new'?'Qabulga yozish':modal==='edit'?'Qabulni tahrirlash':'Qabul ma’lumotlari'}}</h2><p>Blusser · Demo ma’lumotlar</p></div><button @click="modal=null" aria-label="Yopish"><X :size="20"/></button></div><form v-if="modal!=='view'" @submit.prevent="save" class="ap-form"><label>Bemor F.I.Sh. *<div><UserRound :size="16"/><input v-model="form.patient" placeholder="Bemor ismi" required/></div></label><label>Bemor ID<div><ClipboardList :size="16"/><input v-model="form.patientId" placeholder="P-00124"/></div></label><label>Telefon<div><Phone :size="16"/><input v-model="form.phone" placeholder="+998..."/></div></label><label>Shifokor<select v-model="form.doctor"><option v-for="d in doctors" :key="d">{{d}}</option></select></label><label>Xizmat<input v-model="form.service" placeholder="Xizmat nomi"/></label><label>Sana *<input v-model="form.date" type="date" required/></label><label>Vaqt *<input v-model="form.time" type="time" required/></label><label>Holat<select v-model="form.status"><option v-for="s in states" :key="s">{{s}}</option></select></label><div class="ap-modal-buttons"><button type="button" @click="modal=null">Bekor qilish</button><button type="submit">{{modal==='new'?'Qabul yaratish':'Saqlash'}}</button></div></form><div v-else-if="chosen" class="ap-details"><div><span>Bemor</span><strong>{{chosen.patient}} ({{chosen.patientId}})</strong></div><div><span>Telefon</span><strong>{{chosen.phone}}</strong></div><div><span>Shifokor</span><strong>{{chosen.doctor}}</strong></div><div><span>Xizmat</span><strong>{{chosen.service}}</strong></div><div><span>Sana va vaqt</span><strong>{{displayDate(chosen.date)}} · {{chosen.time}}</strong></div><div><span>Holat</span><strong>{{chosen.status}}</strong></div><button @click="openPatient(chosen!,'edit')">Tahrirlash</button></div></div></div>
  </section>
</template>

<style scoped>
.appointments-page{font-family:Inter,Arial,sans-serif;color:#102045;min-width:0;width:100%;max-width:100%;box-sizing:border-box}.appointments-page *{box-sizing:border-box}.appointments-page button,.appointments-page input,.appointments-page select{font:inherit}.appointments-page button{cursor:pointer}.ap-head{display:flex;justify-content:space-between;align-items:center;gap:15px;margin:3px 0 19px}.ap-head h1{font-size:30px;font-weight:800;margin:0 0 4px;letter-spacing:-.5px}.ap-head p{font-size:14px;margin:0;color:#7189af}.ap-actions{display:flex;gap:12px}.ap-actions button{height:45px;display:inline-flex;align-items:center;gap:9px;padding:0 18px;border-radius:8px;font-size:12px;font-weight:700;white-space:nowrap}.ap-primary{background:#206dff;border:1px solid #206dff;color:white}.ap-export{background:white;border:1px solid #dfe8f6;color:#1d3e72}.ap-notice{padding:10px 14px;border-radius:8px;background:#eff6ff;border:1px solid #d4e5ff;color:#246ac4;margin-bottom:12px;font-size:12px;display:flex;justify-content:space-between}.ap-notice button{border:0;background:none;font-size:20px;color:inherit}.ap-filterbox{background:white;border:1px solid #e5ecf7;border-radius:11px;padding:18px 14px;display:grid;grid-template-columns:1fr 1fr 1fr 1fr 1.6fr;gap:12px;margin-bottom:15px;min-width:0}.ap-field{height:44px;position:relative;display:block;min-width:0}.ap-field>span{position:absolute;z-index:1;background:white;font-size:10px;color:#7892ba;top:-7px;left:11px;padding:0 5px}.ap-field input,.ap-field select{border:1px solid #dfebfa;border-radius:8px;background:white;width:100%;height:44px;color:#26436e;padding:0 12px;font-size:12px;outline-color:#4284f5;min-width:0}.ap-field select:disabled{color:#526b92;background:#fbfdff}.ap-search{display:flex;align-items:center;gap:10px;background:#f8faff;border:1px solid #dfe8f6;border-radius:8px;padding:0 12px;height:44px;color:#8099bf;min-width:0}.ap-search input{border:0;outline:0;background:transparent;width:100%;min-width:0;font-size:11px;color:#1c315a}.ap-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px;margin-bottom:15px}.ap-stat{height:116px;display:flex;gap:13px;padding:19px 15px;border-radius:12px;min-width:0}.ap-stat.blue{background:#ecf6ff}.ap-stat.amber{background:#fff7e9}.ap-stat.pink{background:#fff0f3}.ap-stat.green{background:#eafaf5}.ap-stat-icon{width:53px;height:53px;display:grid;place-items:center;border-radius:13px;flex:none;background:#dceeff;color:#1673ff}.amber .ap-stat-icon{background:#ffedcf;color:#f39700}.pink .ap-stat-icon{background:#ffdae4;color:#f03255}.green .ap-stat-icon{background:#d5f6e8;color:#02ad83}.ap-stat div:last-child{display:flex;flex-direction:column;min-width:0}.ap-stat span{font-size:12px;color:#526b95;white-space:nowrap}.ap-stat strong{font-size:27px;font-weight:800;margin-top:4px;line-height:1.25}.ap-stat small{font-size:11px;color:#7892b7;margin-top:7px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ap-panel{border:1px solid #e2eaf6;border-radius:11px;background:white;padding:0 12px 12px;min-width:0}.ap-tabs{display:flex;gap:16px;border-bottom:1px solid #e5edf8;overflow-x:auto;min-width:0}.ap-tabs button{border:0;border-bottom:2px solid transparent;background:transparent;padding:16px 5px 15px;font-size:12px;color:#40618d;white-space:nowrap}.ap-tabs button.selected{color:#126afe;border-bottom-color:#126afe;font-weight:700}.ap-tabs span{border-radius:6px;background:#eef5ff;padding:4px 7px;margin-left:5px;font-size:11px}.ap-table-wrap{overflow-x:auto;width:100%}.ap-panel table{border-collapse:collapse;width:100%;text-align:left;font-size:11px}.ap-panel th{background:#f1f6fc;color:#556e94;font-weight:650;padding:13px 9px;white-space:nowrap}.ap-panel td{padding:10px 9px;border-bottom:1px solid #e8eff7;white-space:nowrap;vertical-align:middle}.ap-panel td strong{display:block;font-size:11px;font-weight:700;color:#152950}.ap-panel td small{display:block;font-size:10px;margin-top:5px;color:#7791b7}.ap-person{display:flex;gap:9px;align-items:center}.ap-avatar{width:32px;height:32px;border-radius:50%;display:grid;place-items:center;background:linear-gradient(135deg,#e0efff,#fbe8d8);color:#426184;font-size:10px;flex:none}.ap-muted{color:#6682ad}.ap-status{display:inline-flex;align-items:center;gap:6px;background:#edf4ff;color:#476895;padding:8px 10px;border-radius:7px;font-size:10px}.ap-status.inprogress{background:#e8f2ff;color:#096af7}.ap-status.waiting{background:#fff0df;color:#e68700}.ap-status.finished{background:#e2f8ee;color:#00a77b}.ap-status.cancelled{background:#ffeef0;color:#d84052}.ap-status.planned{background:#ecf2fa;color:#4e709e}.ap-row-actions{display:flex;gap:6px;align-items:center}.ap-row-actions>button,.ap-more-wrap>button{display:inline-flex;align-items:center;gap:5px;background:#f0f6ff;border:1px solid #e3edfb;border-radius:6px;color:#0e65e9;padding:8px;font-size:10px;white-space:nowrap}.ap-row-actions>button:nth-child(2){background:#fff}.ap-more-wrap{position:relative}.ap-menu{position:absolute;top:100%;right:0;z-index:10;width:145px;box-shadow:0 5px 22px #1b335022;border-radius:8px;background:white;border:1px solid #e3ebf8;padding:5px}.ap-menu button{display:block;padding:8px;border:0;background:none;width:100%;text-align:left;color:#24416d;font-size:11px;border-radius:4px}.ap-menu button:hover{background:#eef5ff}.ap-empty{text-align:center;color:#7187ad;padding:30px!important}.ap-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 3px 2px;color:#7189b0;font-size:11px}.ap-pagination{display:flex;gap:5px}.ap-pagination button{border:1px solid #e1eaf7;border-radius:6px;min-width:29px;height:31px;background:white;color:#315180;display:grid;place-items:center;font-size:11px}.ap-pagination button.active{background:#1769f8;color:white;border-color:#1769f8}.ap-pagination button:disabled{opacity:.4;cursor:default}.ap-overlay{position:fixed;inset:0;z-index:2000;background:#10204470;display:grid;place-items:center;padding:20px}.ap-modal{width:min(620px,100%);background:white;border-radius:14px;box-shadow:0 12px 60px #10204440;padding:22px;max-height:90vh;overflow:auto}.ap-modal-head{display:flex;align-items:flex-start;justify-content:space-between;border-bottom:1px solid #e7eef8;padding-bottom:17px;margin-bottom:18px}.ap-modal-head h2{font-size:20px;margin:0 0 5px}.ap-modal-head p{margin:0;color:#8197b6;font-size:12px}.ap-modal-head>button{border:0;background:#f1f6fc;color:#516a90;padding:7px;border-radius:8px}.ap-form{display:grid;grid-template-columns:1fr 1fr;gap:15px}.ap-form>label{font-size:12px;font-weight:600;color:#445c83;min-width:0}.ap-form input,.ap-form select,.ap-form label>div{width:100%;border:1px solid #dce7f5;border-radius:8px;background:white;color:#19335b;min-width:0;height:40px;margin-top:7px;font-size:12px;padding:0 10px}.ap-form label>div{display:flex;align-items:center;gap:8px}.ap-form label>div input{border:0;margin:0;padding:0;height:36px;outline:0}.ap-modal-buttons{grid-column:1/-1;display:flex;justify-content:flex-end;gap:9px;margin-top:10px}.ap-modal-buttons button,.ap-details>button{border:1px solid #e1eaf7;border-radius:8px;background:white;padding:11px 16px;color:#284a7a;font-size:12px}.ap-modal-buttons button:last-child,.ap-details>button{background:#1769fa;color:white;border-color:#1769fa}.ap-details{display:grid;gap:12px}.ap-details>div{display:flex;justify-content:space-between;gap:10px;border-bottom:1px solid #e9eff8;padding-bottom:11px;font-size:13px}.ap-details span{color:#8093b2}.ap-details strong{color:#18345e}.ap-details>button{justify-self:end;margin-top:5px}@media(max-width:1450px){.ap-filterbox{grid-template-columns:repeat(4,minmax(0,1fr))}.ap-search{grid-column:1/-1}.ap-stat{padding:14px 10px;gap:8px}.ap-stat-icon{width:44px;height:44px}.ap-stat strong{font-size:24px}}@media(max-width:1000px){.ap-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.ap-filterbox{grid-template-columns:repeat(2,minmax(0,1fr))}.ap-search{grid-column:1/-1}}@media(max-width:640px){.ap-head{align-items:flex-start;flex-direction:column}.ap-stats{gap:8px}.ap-stat{height:105px}.ap-stat-icon{width:38px;height:38px}.ap-filterbox{padding:12px;gap:11px}.ap-footer{flex-wrap:wrap}.ap-form{grid-template-columns:1fr}}
</style>

<style scoped src="../styles/legacy-pages.css"></style>
