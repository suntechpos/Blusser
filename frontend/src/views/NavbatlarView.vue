<script setup lang="ts">
import { computed, ref } from 'vue'
import { Users, Play, Clock3, CalendarDays, ChartNoAxesColumnIncreasing, DoorOpen, Search, ChevronLeft, ChevronRight, Eye, MoreHorizontal, ArrowRightToLine } from 'lucide-vue-next'

type Status = 'Qabulda' | 'Chaqirildi' | 'Kutilmoqda' | 'Kechikdi' | 'Tugagan'
type QueueKind = 'Jonli navbat' | 'Oldindan yozilgan'
type Patient = { id:number; number:string; name:string; phone:string; kind:QueueKind; doctor:string; service:string; time:string; waiting:string; status:Status }
const patients = ref<Patient[]>([
{id:1,number:'A-001',name:'Aliyeva Mohira',phone:'+998 90 123 45 67',kind:'Oldindan yozilgan',doctor:'Karimov A.',service:'Kardiolog',time:'09:00',waiting:'—',status:'Qabulda'},
{id:2,number:'J-001',name:'Qodirov Aziz',phone:'+998 91 234 56 78',kind:'Jonli navbat',doctor:'Saidov B.',service:'UZI',time:'—',waiting:'5 daqiqa',status:'Chaqirildi'},
{id:3,number:'O-004',name:'Ismoilova Nilufar',phone:'+998 93 456 78 90',kind:'Oldindan yozilgan',doctor:'Rahmonova D.',service:'Terapevt',time:'09:35',waiting:'12 daqiqa',status:'Kutilmoqda'},
{id:4,number:'J-002',name:'Matchonov Siroj',phone:'+998 90 777 12 34',kind:'Jonli navbat',doctor:'Karimov A.',service:'Kardiolog',time:'—',waiting:'18 daqiqa',status:'Kutilmoqda'},
{id:5,number:'O-008',name:'Nematova Sevara',phone:'+998 91 222 33 44',kind:'Oldindan yozilgan',doctor:'Saidov B.',service:'UZI',time:'10:00',waiting:'2 daqiqa',status:'Chaqirildi'},
{id:6,number:'J-003',name:'Bo‘ronov Kamol',phone:'+998 93 111 22 33',kind:'Jonli navbat',doctor:'Toshpulatova M.',service:'Pediatr',time:'—',waiting:'25 daqiqa',status:'Kechikdi'},
{id:7,number:'O-010',name:'Saidova Aziza',phone:'+998 90 444 55 66',kind:'Oldindan yozilgan',doctor:'Karimov A.',service:'Kardiolog',time:'10:20',waiting:'—',status:'Kutilmoqda'},
{id:8,number:'J-004',name:'Raximov Behzod',phone:'+998 90 555 66 77',kind:'Jonli navbat',doctor:'Saidov B.',service:'UZI',time:'—',waiting:'32 daqiqa',status:'Kutilmoqda'},
{id:9,number:'O-012',name:'Davlatova Nilufar',phone:'+998 90 888 77 66',kind:'Oldindan yozilgan',doctor:'Rahmonova D.',service:'Terapevt',time:'10:40',waiting:'—',status:'Kutilmoqda'},
{id:10,number:'J-005',name:'Eshonqulov Komil',phone:'+998 91 666 77 88',kind:'Jonli navbat',doctor:'Toshpulatova M.',service:'Pediatr',time:'—',waiting:'40 daqiqa',status:'Kutilmoqda'},
])
const tab = ref('Barchasi'); const query=ref(''); const doctor=ref(''); const status=ref(''); const page=ref(1); const pageSize=10; const notice=ref('')
const doctors=['Karimov A.','Saidov B.','Rahmonova D.','Toshpulatova M.']
const filtered=computed(()=>patients.value.filter(p=>{
 const q=query.value.toLocaleLowerCase();
 return (!q || [p.name,p.number,p.phone].some(x=>x.toLocaleLowerCase().includes(q))) && (!doctor.value||p.doctor===doctor.value) && (!status.value||p.status===status.value) && (tab.value==='Barchasi'||(tab.value==='Jonli navbat'&&p.kind==='Jonli navbat')||(tab.value==='Oldindan yozilgan'&&p.kind==='Oldindan yozilgan')||tab.value===p.status)
}))
const pages=computed(()=>Math.max(1,Math.ceil(filtered.value.length/pageSize)))
const visible=computed(()=>filtered.value.slice((page.value-1)*pageSize,page.value*pageSize))
const tabs=['Barchasi','Jonli navbat','Oldindan yozilgan','Chaqirildi','Qabulda','Tugagan']
function changeTab(t:string){tab.value=t;page.value=1}
function action(p: Patient, next: Status) {
  p.status = next
  notice.value = `${p.number} — ${p.name}: ${next} (demo)`
}

function demo(actionName: string) {
  notice.value = `${actionName}: hozircha demo, backend ulanmagan.`
}
function initials(name:string){return name.split(' ').map(x=>x[0]).join('').slice(0,2).toUpperCase()}
</script>

<template>
  <section class="queue-page">
    <div class="queue-heading">
      <div><h1>Navbatlar</h1><p>Oldindan yozilgan bemorlar va jonli navbatni birgalikda boshqarish.</p></div>
      <div class="queue-top-actions">
        <button class="queue-primary" @click="demo('Jonli navbat qo‘shish')"><Users :size="17"/> Jonli navbat qo‘shish</button>
        <button class="queue-green" @click="demo('Oldindan yozish')"><CalendarDays :size="17"/> Oldindan yozish</button>
        <button class="queue-tablo-btn" @click="$emit('open-tablo')"> 🖥️ Tablo boshqaruvi
        </button>
      </div>
    </div>
    <div v-if="notice" class="queue-notice">{{notice}} <button @click="notice=''">×</button></div>
    <div class="queue-stats">
      <article class="queue-stat amber"><div class="queue-stat-icon"><Users :size="23"/></div><div><span>Kutayotganlar</span><strong>18</strong><small>Bemor navbatda</small></div></article>
      <article class="queue-stat mint"><div class="queue-stat-icon"><Play :size="23"/></div><div><span>Chaqirilgan</span><strong>7</strong><small>Bugun chaqirilgan</small></div></article>
      <article class="queue-stat rose"><div class="queue-stat-icon"><Clock3 :size="23"/></div><div><span>Qabulda</span><strong>5</strong><small>Hozir qabulda</small></div></article>
      <article class="queue-stat sky"><div class="queue-stat-icon"><CalendarDays :size="23"/></div><div><span>Oldindan yozilgan</span><strong>28</strong><small>Bugungi qabulga</small></div></article>
      <article class="queue-stat lilac"><div class="queue-stat-icon"><ChartNoAxesColumnIncreasing :size="23"/></div><div><span>O‘rtacha kutish</span><strong>18 <i>daqiqa</i></strong><small>Joriy navbat</small></div></article>
      <article class="queue-stat aqua"><div class="queue-stat-icon"><DoorOpen :size="23"/></div><div><span>Xizmat yo‘nalishlari / xonalar</span><strong>4 / 6</strong><small>Faol kabinetlar</small></div></article>
    </div>
    <div class="queue-columns">
      <div class="queue-main-panel">
        <nav class="queue-tabs"><button v-for="t in tabs" :key="t" :class="{active:tab===t}" @click="changeTab(t)">{{t}} <span>{{t==='Barchasi'?'53':t==='Jonli navbat'?'18':t==='Oldindan yozilgan'?'28':t==='Chaqirildi'?'7':t==='Qabulda'?'5':'15'}}</span></button></nav>
        <div class="queue-filters">
          <select v-model="doctor" @change="page=1"><option value="">Barcha shifokorlar</option><option v-for="d in doctors" :key="d">{{d}}</option></select>
          <select @change="demo('Filial filtri')"><option>Barcha filiallar</option><option>Asosiy filial</option></select>
          <select v-model="status" @change="page=1"><option value="">Barcha holatlar</option><option v-for="s in ['Kutilmoqda','Chaqirildi','Qabulda','Tugagan','Kechikdi']" :key="s">{{s}}</option></select>
          <label><Search :size="16"/><input v-model="query" @input="page=1" placeholder="Bemor qidirish..."/></label>
          <select><option>Saralash: Navbat vaqti</option></select>
        </div>
        <div class="queue-table-scroll"><table><thead><tr><th>#</th><th>Navbat №</th><th>Bemor</th><th>Turi</th><th>Shifokor / Xizmat</th><th>Belgilangan vaqt</th><th>Kutish vaqti</th><th>Holat</th><th>Amallar</th></tr></thead>
        <tbody><tr v-for="(p,i) in visible" :key="p.id"><td>{{(page-1)*pageSize+i+1}}</td><td class="queue-number">{{p.number}}</td><td><div class="queue-patient"><span class="queue-avatar">{{initials(p.name)}}</span><div><b>{{p.name}}</b><small>{{p.phone}}</small></div></div></td><td><span class="queue-kind" :class="p.kind==='Jonli navbat'?'live':'booked'">{{p.kind}}</span></td><td><b>{{p.doctor}}</b><small>{{p.service}}</small></td><td><b>{{p.time}}</b></td><td>{{p.waiting}}</td><td><span class="queue-status" :class="p.status">{{p.status}}</span></td><td><div class="queue-actions"><button v-if="p.status==='Kutilmoqda'||p.status==='Kechikdi'" @click="action(p,'Chaqirildi')"><Play :size="13"/> Chaqirish</button><button v-else-if="p.status==='Chaqirildi'" @click="action(p,'Qabulda')"><ArrowRightToLine :size="13"/> Qabulga o'tkazish</button><button v-else @click="demo(p.name+' tafsiloti')"><Eye :size="13"/> Ko‘rish</button><button class="queue-more" @click="demo('Qo‘shimcha amallar')"><MoreHorizontal :size="18"/></button></div></td></tr><tr v-if="!visible.length"><td colspan="9" class="queue-empty">Bemorlar topilmadi.</td></tr></tbody></table></div>
        <div class="queue-pagination"><span>Ko‘rsatish: <b>10</b> &nbsp; {{filtered.length? (page-1)*pageSize+1:0}}–{{Math.min(page*pageSize,filtered.length)}} / {{filtered.length}} natija</span><div><button :disabled="page===1" @click="page--"><ChevronLeft :size="16"/></button><button v-for="n in pages" :key="n" :class="{active:page===n}" @click="page=n">{{n}}</button><button :disabled="page===pages" @click="page++"><ChevronRight :size="16"/></button></div></div>
      </div>
      <aside class="queue-side">
        <div class="queue-panel"><div class="queue-panel-title"><h2>Hozir chaqirilmoqda</h2><button @click="demo('Barchasini ko‘rish')">Barchasini ko‘rish ›</button></div><div class="queue-call" v-for="s in [{n:'J-001',name:'Qodirov Aziz',doctor:'UZI · Saidov B.',room:'102-xona',color:'green'},{n:'O-008',name:'Nematova Sevara',doctor:'UZI · Saidov B.',room:'102-xona',color:'orange'},{n:'A-003',name:'Xolmirzayev Jalol',doctor:'Kardiolog · Karimov A.',room:'101-xona',color:'red'}]" :key="s.n" :class="s.color"><strong>{{s.n}}</strong><div><b>{{s.name}}</b><small>{{s.doctor}}</small></div><span>{{s.room}}</span></div></div>
        <div class="queue-panel"><div class="queue-panel-title"><h2>Bugungi xonalar / kabinetlar</h2><button @click="demo('Kabinetlar')">Barchasi ›</button></div><div class="queue-room" v-for="r in [{n:'101-xona',kind:'Kardiolog',name:'Dr. Karimov A.',state:'Qabulda'},{n:'102-xona',kind:'UZI',name:'Dr. Saidov B.',state:'Chaqirilmoqda'},{n:'103-xona',kind:'Terapevt',name:'Dr. Rahmonova D.',state:'Bo‘sh'},{n:'201-xona',kind:'Pediatr',name:'Dr. Toshpulatova M.',state:'Qabulda'},{n:'202-xona',kind:'Nevropatolog',name:'Dr. Ismoilov S.',state:'Bo‘sh'},{n:'203-xona',kind:'LOR',name:'Dr. Alimova N.',state:'Band'}]" :key="r.n"><div><b>{{r.n}}</b><small>{{r.kind}}</small></div><span>{{r.name}}</span><em :class="r.state">{{r.state}}</em></div></div>
        <div class="queue-panel"><div class="queue-panel-title"><h2>Elektron tablo <small>(kutish zali uchun)</small></h2></div><div class="queue-tv"><span>HOZIR CHAQIRILMOQDA</span><div><strong>J-001</strong><strong>102</strong></div><small>Navbat &nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Kabinet</small></div><p class="queue-footnote">Tablolarni sozlash uchun yuqoridagi “Tablo boshqaruvi” bo‘limiga o‘ting.</p></div>
      </aside>
    </div>
  </section>
</template>

<style scoped>
.queue-page{font-family:Inter,Arial,sans-serif;color:#102044;min-width:0;width:100%;box-sizing:border-box}.queue-page *{box-sizing:border-box}.queue-page button,.queue-page input,.queue-page select{font-family:inherit}.queue-page button{cursor:pointer}.queue-heading{display:flex;justify-content:space-between;align-items:center;gap:14px;margin:0 0 18px;flex-wrap:wrap}.queue-heading h1{font-size:30px;line-height:1.2;margin:0 0 6px;font-weight:800}.queue-heading p{font-size:13px;margin:0;color:#617ba7}.queue-top-actions{display:flex;gap:12px}.queue-top-actions button{height:42px;display:flex;align-items:center;gap:8px;border:0;border-radius:8px;padding:0 16px;color:white;font-size:12px;font-weight:700}.queue-primary{background:#1971fc}.queue-green{background:#00ad90}.queue-notice{background:#eef6ff;border:1px solid #ccdefe;color:#2768b4;padding:10px 14px;border-radius:7px;margin:0 0 12px;display:flex;justify-content:space-between}.queue-notice button{background:transparent;border:0;color:inherit}.queue-stats{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:11px;margin:0 0 15px}.queue-stat{display:flex;align-items:flex-start;gap:10px;min-width:0;min-height:105px;border-radius:11px;padding:15px 10px}.queue-stat.amber{background:#fff8eb}.queue-stat.mint{background:#eaf9f3}.queue-stat.rose{background:#fff0f4}.queue-stat.sky{background:#ebf6ff}.queue-stat.lilac{background:#f5efff}.queue-stat.aqua{background:#e9fafb}.queue-stat-icon{width:39px;height:39px;flex:none;display:grid;place-items:center;border-radius:10px;background:#ffffff8c;color:#2677ff}.amber .queue-stat-icon{color:#f39308}.mint .queue-stat-icon{color:#05a981}.rose .queue-stat-icon{color:#ee355e}.lilac .queue-stat-icon{color:#8254e9}.aqua .queue-stat-icon{color:#06b6c3}.queue-stat>div:last-child{min-width:0}.queue-stat span,.queue-stat small{display:block;font-size:10px;color:#60799c}.queue-stat strong{display:block;font-size:25px;line-height:1.3;margin:5px 0;font-weight:800;white-space:nowrap}.queue-stat strong i{font-size:14px;font-style:normal}.queue-columns{display:grid;grid-template-columns:minmax(0,2.1fr) minmax(300px,1fr);gap:12px;align-items:start}.queue-main-panel,.queue-panel{background:white;border:1px solid #e4ebf6;border-radius:10px;min-width:0}.queue-main-panel{padding:0 10px 12px}.queue-tabs{display:flex;justify-content:space-between;gap:5px;border-bottom:1px solid #e8eef8;overflow-x:auto}.queue-tabs button{padding:12px 5px;background:transparent;border:0;border-bottom:2px solid transparent;white-space:nowrap;font-size:10px;color:#405b84}.queue-tabs button.active{color:#0d6af7;border-bottom-color:#176bf8;font-weight:700}.queue-tabs span{background:#edf3fb;border-radius:5px;padding:3px 5px;margin-left:3px}.queue-filters{display:flex;gap:7px;padding:12px 0}.queue-filters>select,.queue-filters>label{min-width:0;flex:1;height:34px;border:1px solid #e1eafa;border-radius:7px;background:white;color:#375376;padding:0 6px;font-size:10px}.queue-filters>label{display:flex;align-items:center;gap:4px;padding:0 8px}.queue-filters label input{width:100%;min-width:0;border:0;outline:none;font:inherit}.queue-filters label svg{flex:none}.queue-table-scroll{overflow-x:auto;width:100%}.queue-page table{border-collapse:collapse;width:100%;font-size:10px;text-align:left}.queue-page th{background:#f3f7fd;color:#5d7599;padding:11px 6px;white-space:nowrap}.queue-page td{padding:9px 6px;border-bottom:1px solid #edf2f8;white-space:nowrap;color:#213a5f}.queue-page td b{font-weight:700;display:block;font-size:10px}.queue-page td small{display:block;font-size:9px;color:#8498ba;margin-top:4px}.queue-number{font-weight:800}.queue-patient{display:flex;align-items:center;gap:7px}.queue-avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#deecfa,#fbe9dc);color:#42658d;display:grid;place-items:center;font-size:10px;flex:none}.queue-kind,.queue-status{padding:7px 8px;border-radius:6px;display:inline-block;font-size:9px}.queue-kind.live{background:#e7f1ff;color:#176aff}.queue-kind.booked{background:#e0f8ef;color:#05a879}.queue-status.Qabulda{background:#e8f2ff;color:#176aff}.queue-status.Chaqirildi{background:#f0e9ff;color:#8150ef}.queue-status.Kutilmoqda{background:#fff1e0;color:#e08311}.queue-status.Kechikdi{background:#ffe9ee;color:#ee3654}.queue-status.Tugagan{background:#e0f8ed;color:#0a9a6b}.queue-actions{display:flex;gap:5px}.queue-actions button{display:inline-flex;align-items:center;gap:4px;border:0;border-radius:6px;color:#146bf9;background:#edf5ff;font-size:9px;padding:7px}.queue-actions button.queue-more{background:white;border:1px solid #e1eaf9}.queue-empty{text-align:center;padding:30px!important}.queue-pagination{padding:12px 5px 0;display:flex;justify-content:space-between;align-items:center;gap:10px;color:#6a83ac;font-size:10px}.queue-pagination>div{display:flex;gap:5px}.queue-pagination button{border:1px solid #e0eaf7;background:white;border-radius:6px;min-width:27px;height:27px;color:#466087;display:grid;place-items:center}.queue-pagination button.active{background:#176cfb;color:white}.queue-pagination button:disabled{opacity:.4}.queue-side{display:flex;flex-direction:column;gap:12px;min-width:0}.queue-panel{padding:11px}.queue-panel-title{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:9px}.queue-panel-title h2{margin:0;font-size:13px}.queue-panel-title h2 small{font-size:10px;color:#7991af;font-weight:400}.queue-panel-title button{border:0;background:transparent;color:#1971f9;font-size:9px}.queue-call{display:flex;align-items:center;gap:7px;padding:10px 6px;background:#f3fbf9;border-left:3px solid #0db88b;margin-bottom:6px;min-width:0}.queue-call.orange{border-left-color:#f69b15;background:#fffaf3}.queue-call.red{border-left-color:#eb5670;background:#fff7f8}.queue-call>strong{font-size:15px;width:48px;flex:none}.queue-call>div{flex:1;min-width:0}.queue-call b,.queue-call small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:10px}.queue-call small{font-size:9px;color:#7491b3;margin-top:3px}.queue-call>span{background:#e3efff;color:#1569e8;border-radius:6px;padding:6px;font-size:9px;white-space:nowrap}.queue-room{display:flex;align-items:center;gap:7px;padding:8px 4px;border-bottom:1px solid #edf2f8;font-size:9px}.queue-room:last-child{border-bottom:0}.queue-room>div{width:75px;flex:none}.queue-room b,.queue-room small{display:block}.queue-room small{color:#8196b4;margin-top:3px}.queue-room>span{flex:1;min-width:0;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.queue-room>em{font-style:normal;color:#60718e;background:#f1f4f9;padding:6px;border-radius:6px;white-space:nowrap}.queue-room>em.Qabulda{background:#e0f8ee;color:#00a77f}.queue-room>em.Chaqirilmoqda{background:#e5f0ff;color:#176bfd}.queue-room>em.Band{background:#ffe7ec;color:#ed3654}.queue-tv{border-radius:8px;padding:16px;background:linear-gradient(135deg,#112b4c,#19395e);color:white;text-align:center}.queue-tv>span{font-size:9px;letter-spacing:1px;color:#a5c4e8}.queue-tv>div{display:flex;justify-content:space-around;gap:15px;margin:10px 0 3px}.queue-tv strong{font-size:29px}.queue-tv small{color:#a4b9d9}.queue-footnote{font-size:9px;color:#738aab;line-height:1.5;margin-bottom:0}@media(max-width:1470px){.queue-stats{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:1200px){.queue-columns{grid-template-columns:minmax(0,1fr)}.queue-side{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.queue-stat strong{font-size:22px}}@media(max-width:750px){.queue-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.queue-side{display:flex}.queue-filters{flex-wrap:wrap}.queue-filters>select,.queue-filters>label{min-width:120px}.queue-heading h1{font-size:24px}}

.queue-top-actions .queue-tablo-btn {
  background: #ffffff;
  color: #176bfa;
  border: 1px solid #cbdcff;
  border-radius: 8px;
  font-weight: 700;
}

.queue-top-actions .queue-tablo-btn:hover {
  background: #eff6ff;
  border-color: #176bfa;
}

</style>
