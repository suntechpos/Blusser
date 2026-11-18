<script setup lang="ts">
import { computed, nextTick, ref, watch, onMounted, onBeforeUnmount } from 'vue'
import QueueTvPreview from '../components/QueueTvPreview.vue'
import { clinicRequest, errorText } from '../services/api'
import { session } from '../services/session'
import { Monitor, Plus, Search, Eye, Settings2, Trash2, Wifi, WifiOff, Volume2, VolumeX, X, Save, Check, ExternalLink, LayoutDashboard, ShieldCheck, Ellipsis, ArrowLeft, Maximize, RefreshCw } from 'lucide-vue-next'

type Screen = { id:string; branch_id:string; name:string; zone:string; floor:string; room_ids:string[]; sound:boolean; theme:'dark'|'light'; language:'uz'|'ru'; version:number }
type Room = { id:string; number:string; active:boolean }
type Row = { ticket:string; room:string; call_key?:string }
type Feed = { clinic:string; timezone:string; local_date:string; called:Row[]; next:Row[] }
const branches=computed(()=>session.branches.filter(b=>b.active))
const branch=ref(session.branch || branches.value[0]?.id || '')
const screens=ref<Screen[]>([]), roomOptions=ref<Room[]>([])
const query=ref(''), notice=ref(''), error=ref(''), loading=ref(false), saving=ref(false)
const openModal=ref(false), editingId=ref<string|null>(null), editingVersion=ref(0), createId=ref('')
const previewScreen=ref<Screen|null>(null), selectedId=ref(''), menuId=ref<string|null>(null)
const selectedScreen=computed(()=>screens.value.find(s=>s.id===selectedId.value)??null)
const feed=ref<Feed|null>(null), connectionError=ref(false)
const roomNumber=(id:string)=>roomOptions.value.find(r=>r.id===id)?.number??'—'
const displayRooms=(s:Screen)=>s.room_ids.map(roomNumber)
const form=ref({name:'',zone:'Asosiy zal',floor:'1-qavat',room_ids:[] as string[],sound:true,theme:'dark' as 'dark'|'light',language:'uz' as 'uz'|'ru'})
const shown=computed(()=>screens.value.filter(s=>[s.name,s.zone,s.floor,...displayRooms(s)].join(' ').toLowerCase().includes(query.value.trim().toLowerCase())))
const scoped=(code:string)=>['assigned_branches','entire_clinic'].includes(session.grants?.[code]??'')
const canView=computed(()=>scoped('display.view'))
const canPreview=computed(()=>canView.value&&scoped('display.preview'))
const canCreate=computed(()=>canView.value&&scoped('display.create'))
const canUpdate=computed(()=>canView.value&&scoped('display.update'))
const canDelete=computed(()=>canView.value&&scoped('display.delete'))
let indexSerial=0, feedSerial=0, timer: ReturnType<typeof setInterval>|undefined, disposed=false, polling=false
async function load(){
 const request=++indexSerial, bid=branch.value
 if(!bid||!canView.value){screens.value=[];roomOptions.value=[];selectedId.value='';return}
 loading.value=true;error.value=''
 try{
  const {data}=await clinicRequest<{data:Screen[];rooms:Room[]}>('displays',{params:{branch_id:bid}})
  if(disposed||request!==indexSerial)return
  screens.value=data.data;roomOptions.value=data.rooms
  if(!screens.value.some(s=>s.id===selectedId.value))selectedId.value=screens.value[0]?.id??''
 }catch(e){if(!disposed&&request===indexSerial){screens.value=[];roomOptions.value=[];selectedId.value='';error.value=errorText(e)}}
 finally{if(!disposed&&request===indexSerial)loading.value=false}
}
async function refreshFeed(){
 const s=selectedScreen.value
 if(disposed||polling||!s||!canPreview.value)return
 const request=feedSerial
 polling=true
 try{
  const {data}=await clinicRequest<Feed>('displays/'+s.id+'/feed',{params:{branch_id:s.branch_id}})
  if(!disposed&&request===feedSerial&&selectedId.value===s.id){feed.value=data;connectionError.value=false}
 }catch(e){if(!disposed&&request===feedSerial){feed.value=null;connectionError.value=true}}
 finally{polling=false;if(!disposed&&request!==feedSerial)void refreshFeed()}
}
watch([selectedId,canPreview],()=>{feedSerial++;feed.value=null;connectionError.value=false;void refreshFeed()})
watch(branch,()=>{indexSerial++;feedSerial++;screens.value=[];roomOptions.value=[];selectedId.value='';previewScreen.value=null;openModal.value=false;menuId.value=null;feed.value=null;connectionError.value=false;void load()})
onMounted(()=>{void load();timer=setInterval(()=>void refreshFeed(),2000)})
onBeforeUnmount(()=>{disposed=true;indexSerial++;feedSerial++;if(timer)clearInterval(timer)})
function addScreen(){if(!canCreate.value)return;editingId.value=null;editingVersion.value=0;createId.value=crypto.randomUUID();form.value={name:'',zone:'Asosiy zal',floor:'1-qavat',room_ids:[],sound:true,theme:'dark',language:'uz'};notice.value='';openModal.value=true}
function editScreen(s:Screen){if(!canUpdate.value)return;editingId.value=s.id;editingVersion.value=s.version;form.value={name:s.name,zone:s.zone,floor:s.floor,room_ids:[...s.room_ids],sound:s.sound,theme:s.theme,language:s.language};menuId.value=null;notice.value='';openModal.value=true}
function toggleRoom(id:string){form.value.room_ids=form.value.room_ids.includes(id)?form.value.room_ids.filter(r=>r!==id):[...form.value.room_ids,id]}
async function saveScreen(){
 if(saving.value||!(editingId.value?canUpdate.value:canCreate.value))return
 if(!form.value.name.trim()||!form.value.zone.trim()||!form.value.floor.trim()||!form.value.room_ids.length){notice.value='Tablo nomi, joylashuvi va kamida bitta kabinetni kiriting.';return}
 const bid=branch.value,id=editingId.value??createId.value
 saving.value=true
 try{await clinicRequest('displays/'+id,{method:'PUT',body:{branch_id:bid,expected_version:editingVersion.value,...form.value}});if(disposed||branch.value!==bid)return;openModal.value=false;notice.value='Tablo sozlamalari saqlandi.';await load();if(branch.value===bid){selectedId.value=id;feedSerial++;feed.value=null;void refreshFeed()}}
 catch(e){if(!disposed&&branch.value===bid)notice.value=errorText(e)}
 finally{saving.value=false}
}
async function deleteScreen(s:Screen){if(!canDelete.value||!confirm('“'+s.name+'” tabloni o‘chirasizmi?'))return;try{await clinicRequest('displays/'+s.id,{method:'DELETE',body:{branch_id:s.branch_id,expected_version:s.version}});if(disposed||branch.value!==s.branch_id)return;menuId.value=null;notice.value='Tablo o‘chirildi.';await load()}catch(e){if(!disposed)notice.value=errorText(e)}}
function openPreview(s:Screen){if(!canPreview.value)return;selectedId.value=s.id;previewScreen.value=s;menuId.value=null;void refreshFeed()}
const tvElement=ref<HTMLElement|null>(null)
async function fullScreenPreview(){if(!selectedScreen.value||!canPreview.value)return;openPreview(selectedScreen.value);await nextTick();const element=tvElement.value?.querySelector<HTMLElement>('.queue-tv');if(element?.requestFullscreen)await element.requestFullscreen().catch(()=>{})}
</script>

<template>
  <section class="tb-page">
    <div class="tb-head">
      <div><h1>Tablo boshqaruvi</h1><p>Kutish zali ekranlari va elektron navbatlarni boshqaring</p></div>
      <div class="tb-head-buttons">
        <button class="tb-btn tb-soft" @click="$emit('open-navbatlar')"><ArrowLeft :size="16"/> Navbatlar</button>
        <button class="tb-btn tb-soft" :disabled="!selectedScreen || !canPreview" @click="fullScreenPreview"><ExternalLink :size="17"/> TV ko‘rinishini ochish</button>
        <button v-if="canCreate" class="tb-btn tb-primary" @click="addScreen"><Plus :size="19"/> Yangi tablo</button>
      </div>
    </div>
    <div class="tb-live-toolbar"><label>Filial <select v-model="branch" aria-label="Filial"><option v-for="b in branches" :key="b.id" :value="b.id">{{b.name}}</option></select></label><button class="tb-btn tb-soft" @click="load" :disabled="loading"><RefreshCw :size="16"/> Yangilash</button></div>
    <p v-if="!canView" role="alert" class="tb-error">Tablo uchun filial yoki klinika doirasidagi ruxsat kerak.</p>
    <p v-if="error" role="alert" class="tb-error">{{error}}</p>
    <div v-if="notice" class="tb-notice" role="status"><ShieldCheck :size="17"/> {{ notice }} <button @click="notice = ''" aria-label="Yopish"><X :size="15"/></button></div>
    <div class="tb-metrics">
      <article v-for="m in [{label:'Jami tablolar',value:screens.length,icon:Monitor,tone:'blue',hint:'Klinikaga biriktirilgan'}, {label:'Onlayn tablolar',value:'—',icon:Wifi,tone:'green',hint:'Qurilma ulanishi hali mavjud emas'}, {label:'Oflayn tablolar',value:'—',icon:WifiOff,tone:'amber',hint:'Qurilma monitoringi hali ulanmagan'}, {label:'Biriktirilgan kabinetlar',value:new Set(screens.flatMap(s=>s.room_ids)).size,icon:LayoutDashboard,tone:'violet',hint:'Navbat ko‘rsatiladigan xonalar'}]" :key="m.label" class="tb-metric" :class="m.tone">
        <span class="tb-metric-icon"><component :is="m.icon" :size="27"/></span><div><small>{{m.label}}</small><strong>{{m.value}}</strong><span>{{m.hint}}</span></div>
      </article>
    </div>
    <div class="tb-layout">
      <div class="tb-panel tb-main-panel">
        <div class="tb-panel-heading"><div><h2>Tablolar ro‘yxati</h2><p>Televizorlar, kabinetlar va ulanish holati</p></div><span class="tb-counter">{{ shown.length }} ta ekran</span></div>
        <div class="tb-toolbar"><label class="tb-search"><Search :size="18"/><input v-model="query" placeholder="Tablo nomi, joylashuvi yoki kabinet..." aria-label="Tablo qidirish"/></label></div>
        <div class="tb-table-wrap"><table><thead><tr><th>Tablo / ekran</th><th>Joylashuv</th><th>Kabinetlar</th><th>Holati</th><th>Ovoz</th><th>Oxirgi faollik</th><th>Amallar</th></tr></thead><tbody>
          <tr v-for="s in shown" :key="s.id" :class="{ 'tb-selected-row': selectedScreen?.id===s.id }">
            <td><button class="tb-name tb-select-screen" @click="selectedId=s.id" :aria-pressed="selectedScreen?.id===s.id"><span class="tb-device-icon"><Monitor :size="21"/></span><span><strong>{{s.name}}</strong><small>TV-{{s.id.slice(0,8).toUpperCase()}}</small></span></button></td>
            <td><strong class="tb-normal">{{s.zone}}</strong><small>{{s.floor}}</small></td>
            <td><div class="tb-room-tags"><span v-for="r in displayRooms(s)" :key="r">{{r}}</span></div></td>
            <td><span class="tb-status pending"><i></i>Ulanmagan</span></td>
            <td><span class="tb-sound" :class="{muted:!s.sound}"><Volume2 v-if="s.sound" :size="16"/><VolumeX v-else :size="16"/> {{s.sound?'Yoqilgan':'O‘chiq'}}</span></td>
            <td class="tb-last-seen">—</td>
            <td><div class="tb-actions"><button v-if="canPreview" title="Ko‘rish" aria-label="Ko‘rish" @click="openPreview(s)"><Eye :size="17"/></button><button v-if="canUpdate" title="Sozlash" aria-label="Sozlash" @click="editScreen(s)"><Settings2 :size="17"/></button><button v-if="canDelete" title="Boshqa amallar" aria-label="Boshqa amallar" :aria-expanded="menuId===s.id" @click="menuId=menuId===s.id?null:s.id"><Ellipsis :size="18"/></button></div>
              <div v-if="menuId===s.id" class="tb-menu"><button disabled title="Qurilma ulanishi keyingi bosqichda">Ulanish kodini olish</button><button disabled title="Qurilma ulanishi keyingi bosqichda"><ExternalLink :size="15"/> TV manzilini nusxalash</button><button disabled title="API ulangandan keyin"><Volume2 :size="15"/> Ovozni sinash</button><button disabled title="API ulangandan keyin"><WifiOff :size="15"/> Qurilmani uzish</button><button v-if="canDelete" class="danger" @click="menuId=null;deleteScreen(s)"><Trash2 :size="15"/> O‘chirish</button></div>
            </td>
          </tr>
          <tr v-if="!shown.length"><td colspan="7" class="tb-empty">{{ loading ? "Yuklanmoqda…" : "Tablo topilmadi. Yangi tablo yarating." }}</td></tr>
        </tbody></table></div>
        <div class="tb-panel-footer"><span>Jami: {{ shown.length }} ta tablo</span><button v-if="canCreate" @click="addScreen"><Plus :size="16"/> Yangi ekran qo‘shish</button></div>
      </div>
      <aside class="tb-side">
        <div class="tb-panel tb-preview-panel">
          <div class="tb-aside-title"><div><h2>TV ko‘rinishi</h2><small>Haqiqiy navbatlar · 2 soniyada yangilanadi</small></div><button :disabled="!selectedScreen || !canPreview" aria-label="To‘liq ekranda ochish" @click="fullScreenPreview"><Maximize :size="17"/></button></div>
          <template v-if="selectedScreen">
            <QueueTvPreview v-if="canPreview" :feed="feed" :connection-error="connectionError" live :rooms="displayRooms(selectedScreen)" :theme="selectedScreen.theme" :language="selectedScreen.language" :sound="selectedScreen.sound" compact/>
            <div class="tb-preview-info"><div class="tb-preview-name"><strong>{{selectedScreen.name}}</strong><span class="tb-status pending"><i></i>Ulanmagan</span></div><dl><div><dt>Joylashuvi</dt><dd>{{selectedScreen.zone}} · {{selectedScreen.floor}}</dd></div><div><dt>Kabinetlar</dt><dd class="tb-room-tags"><span v-for="r in displayRooms(selectedScreen)" :key="r">{{r}}</span></dd></div><div><dt>Ovoz</dt><dd>{{selectedScreen.sound?'Yoqilgan':'O‘chiq'}}</dd></div><div><dt>Oxirgi faollik</dt><dd>—</dd></div></dl></div>
            <div v-if="canPreview" class="tb-preview-actions"><button class="tb-wide-outline" @click="openPreview(selectedScreen)"><Eye :size="16"/> Ko‘rish</button><button class="tb-wide-outline" @click="fullScreenPreview"><Maximize :size="16"/> To‘liq ekran</button></div>
          </template>
          <p v-else class="tb-empty">Tablo mavjud emas.</p>
        </div>
        <div class="tb-panel tb-help"><h2><ShieldCheck :size="19"/> Televizorni ulash</h2><div class="tb-help-steps"><div><span>1</span>TV brauzerida tablo sahifasini oching</div><div><span>2</span>Bir martalik ulanish kodidan foydalaning</div><div><span>3</span>Kabinetlarni biriktiring</div></div><p>Haqiqiy navbatlar admin sessiyasida ishlaydi. Alohida televizorni ulash va ulanish kodi keyingi bosqichda.</p></div>
      </aside>
    </div>

    <div v-if="openModal" class="tb-overlay" @click.self="openModal = false"><div class="tb-dialog" role="dialog" aria-modal="true" aria-label="Tablo sozlamalari"><header><div><h2>{{ editingId === null ? 'Yangi tablo qo‘shish' : 'Tabloni sozlash' }}</h2><p>Ekran joylashuvi va qaysi kabinetlarni ko‘rsatishini belgilang</p></div><button @click="openModal = false" aria-label="Yopish"><X :size="20" /></button></header><div class="tb-form"><p v-if="notice" role="alert" class="tb-error">{{notice}}</p><div class="tb-form-grid"><label>Tablo nomi <input v-model="form.name" placeholder="Masalan, Asosiy kutish zali" maxlength="70" /></label><label>Kutish zali <input v-model="form.zone" maxlength="70"/></label><label>Qavat <input v-model="form.floor" maxlength="70"/></label><label>Ekran dizayni <select v-model="form.theme"><option value="dark">To‘q (Dark)</option><option value="light">Yorug‘ (Light)</option></select></label><label>Til <select v-model="form.language"><option value="uz">O‘zbekcha</option><option value="ru">Русский</option></select></label><label class="tb-toggle-row">Ovozli e’lon <input v-model="form.sound" type="checkbox" /></label></div><div class="tb-room-field"><strong>Kabinetlarni tanlang</strong><p>Ushbu tablo ko‘rsatadigan xonalar</p><div class="tb-room-picker"><button v-for="r in roomOptions.filter(r=>r.active)" :key="r.id" :class="{ selected: form.room_ids.includes(r.id) }" @click="toggleRoom(r.id)">{{ r.number }} <Check v-if="form.room_ids.includes(r.id)" :size="13" /></button></div></div></div><footer><button class="tb-btn tb-soft" @click="openModal = false">Bekor qilish</button><button class="tb-btn tb-primary" :disabled="saving" @click="saveScreen"><Save :size="16" /> Saqlash</button></footer></div></div>

    <div v-if="previewScreen" class="tb-overlay" @click.self="previewScreen = null"><div class="tb-dialog tb-preview-dialog" role="dialog" aria-modal="true" aria-label="Tablo ko‘rinishi"><header><div><h2>{{ previewScreen.name }}</h2><p>{{ previewScreen.floor }} · {{ displayRooms(previewScreen).join(', ') }}-kabinetlar</p></div><button @click="previewScreen = null" aria-label="Yopish"><X :size="20" /></button></header><div ref="tvElement" class="tb-tv-frame"><QueueTvPreview :feed="feed" :connection-error="connectionError" live :rooms="displayRooms(previewScreen)" :theme="previewScreen.theme" :language="previewScreen.language" :sound="previewScreen.sound" /></div><p class="tb-preview-caveat">Haqiqiy navbatlar. Alohida televizor ulanishi va ovozli e’lon hali faollashtirilmagan.</p></div></div>
  </section>
</template>

<style scoped>
.tb-page{font-family:Inter,Arial,sans-serif;color:#14254a;width:100%;min-width:0}.tb-page *{box-sizing:border-box}.tb-page button,.tb-page input,.tb-page select{font:inherit}.tb-page button{cursor:pointer}.tb-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin:3px 0 24px}.tb-eyebrow{display:flex;align-items:center;gap:7px;font-size:10px;font-weight:800;color:#5880bd;letter-spacing:1px;margin-bottom:9px}.tb-head h1{font-size:30px;letter-spacing:-.7px;margin:0 0 7px;font-weight:800}.tb-head p{font-size:13px;color:#7890b1;margin:0}.tb-head-buttons{display:flex;gap:10px}.tb-btn{height:42px;display:flex;align-items:center;justify-content:center;gap:9px;padding:0 15px;border-radius:8px;border:1px solid #dce8fa;font-size:12px;font-weight:700;white-space:nowrap}.tb-primary{background:#2175fa;color:white;border-color:#2175fa}.tb-soft{background:white;color:#31577e}.tb-notice{display:flex;align-items:center;gap:9px;border:1px solid #bddfd4;color:#08775c;background:#effbf6;border-radius:8px;padding:10px 13px;font-size:12px;margin-bottom:16px}.tb-notice button{margin-left:auto;border:0;background:transparent;color:inherit}.tb-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:20px}.tb-metric{border:1px solid #e0e9f7;border-radius:16px;display:flex;align-items:center;gap:15px;padding:16px;min-width:0;min-height:100px;box-shadow:0 3px 9px #18345608}.tb-metric.blue{background:#fff}.tb-metric.green{background:#fff}.tb-metric.amber{background:#fff}.tb-metric.violet{background:#fff}.tb-metric-icon{width:49px;height:49px;border-radius:12px;display:grid;place-items:center;flex:none;background:#dbeaff;color:#2175fa}.green .tb-metric-icon{background:#d5f5e7;color:#00ae85}.amber .tb-metric-icon{background:#ffead0;color:#eb920c}.violet .tb-metric-icon{background:#e6ddff;color:#7957db}.tb-metric div{display:flex;flex-direction:column;min-width:0}.tb-metric small{font-size:11px;color:#547099}.tb-metric strong{font-size:30px;margin:4px 0 3px;line-height:1.1}.tb-metric div>span{font-size:10px;color:#7e96b5}.tb-layout{display:grid;grid-template-columns:minmax(0,2.35fr) minmax(290px,1fr);gap:16px;align-items:start}.tb-panel{background:white;border:1px solid #e4ecf8;border-radius:12px;min-width:0;overflow:hidden}.tb-panel-heading{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:19px 20px 14px}.tb-panel h2{font-size:16px;margin:0;font-weight:800}.tb-panel-heading p{font-size:11px;color:#8295b1;margin:6px 0 0}.tb-counter{color:#4075b8;background:#edf5ff;padding:7px 9px;border-radius:7px;font-size:11px;white-space:nowrap}.tb-toolbar{display:flex;gap:10px;padding:0 20px 16px}.tb-search{display:flex;align-items:center;gap:9px;flex:1;border:1px solid #e2eaf7;border-radius:8px;height:39px;padding:0 11px;color:#8399b6;min-width:0}.tb-search input{border:0;background:transparent;outline:0;width:100%;min-width:0;font-size:11px}.tb-toolbar select{border:1px solid #e2eaf7;background:white;border-radius:8px;color:#425e85;padding:0 10px;font-size:11px;min-width:124px}.tb-table-wrap{width:100%;overflow-x:auto}.tb-table-wrap table{border-collapse:collapse;width:100%;min-width:820px;text-align:left}.tb-table-wrap th{background:#f5f8fd;color:#6981a4;font-size:10px;padding:13px 12px;white-space:nowrap}.tb-table-wrap td{border-bottom:1px solid #edf2fa;padding:14px 12px;font-size:11px;color:#2b436c;white-space:nowrap}.tb-table-wrap tr:last-child td{border-bottom:0}.tb-table-wrap td small{display:block;font-size:10px;color:#93a4bd;margin-top:5px}.tb-name{display:flex;align-items:center;gap:10px}.tb-name strong{font-size:11px}.tb-device-icon{display:grid;place-items:center;width:40px;height:40px;border-radius:10px;background:#edf5ff;color:#2277fa;flex:none}.tb-normal{font-weight:600}.tb-room-tags{display:flex;gap:4px;flex-wrap:wrap;max-width:155px}.tb-room-tags span{font-size:10px;border-radius:5px;background:#f0f5fc;color:#3b6597;padding:5px 7px}.tb-status{display:inline-flex;align-items:center;gap:5px;border-radius:7px;padding:7px 9px;font-size:10px}.tb-status i{width:6px;height:6px;border-radius:50%;background:currentColor}.tb-status.online{background:#e6f9ef;color:#00a578}.tb-status.offline{background:#fff0ec;color:#dc6b3e}.tb-sound{display:flex;align-items:center;gap:5px;color:#1ca582;font-size:10px}.tb-sound.muted{color:#92a2b5}.tb-actions{display:flex;gap:5px}.tb-actions button{width:31px;height:31px;display:grid;place-items:center;border:1px solid #e4eefa;border-radius:7px;background:#f5f9ff;color:#2476ee}.tb-actions button.danger{background:white;color:#c56979}.tb-empty{text-align:center;padding:40px!important;color:#8a9cb8!important}.tb-panel-footer{padding:13px 17px;border-top:1px solid #edf1f8;display:flex;justify-content:space-between;gap:12px;align-items:center;font-size:10px;color:#8295b3}.tb-panel-footer button{border:0;background:transparent;color:#2175fa;display:flex;align-items:center;gap:5px;font-size:11px;white-space:nowrap}.tb-side{display:flex;flex-direction:column;gap:14px;min-width:0}.tb-preview-panel,.tb-help{padding:17px}.tb-aside-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:13px}.tb-aside-title small{display:block;margin-top:5px;color:#8b9cb6;font-size:10px}.tb-aside-title button{background:#edf5ff;border:0;border-radius:7px;color:#2276fa;padding:7px}.tb-wide-outline{margin-top:12px;width:100%;height:37px;display:flex;align-items:center;justify-content:center;gap:8px;border:1px solid #dce8fa;background:white;border-radius:7px;color:#2376fb;font-size:11px;font-weight:700}.tb-help h2{display:flex;align-items:center;gap:7px;font-size:14px}.tb-help h2 svg{color:#2479f8}.tb-help p{font-size:11px;color:#7086a9;line-height:1.7;margin:10px 0 0}.tb-help-steps{margin-top:12px;display:flex;flex-direction:column;gap:9px}.tb-help-steps div{display:flex;align-items:center;gap:9px;color:#425b82;font-size:11px}.tb-help-steps span{width:21px;height:21px;border-radius:50%;background:#ecf5ff;color:#236ffd;display:grid;place-items:center;font-weight:700}.tb-overlay{position:fixed;z-index:9999;inset:0;background:#12234488;display:grid;place-items:center;padding:20px}.tb-dialog{background:white;border-radius:15px;width:min(650px,100%);max-height:90vh;overflow-y:auto;box-shadow:0 20px 70px #14244755}.tb-dialog header{display:flex;align-items:start;justify-content:space-between;padding:22px 23px;border-bottom:1px solid #edf1f8}.tb-dialog header h2{margin:0;font-size:21px}.tb-dialog header p{color:#8193ae;font-size:12px;margin:6px 0 0}.tb-dialog header button{border:0;background:#f3f6fb;border-radius:7px;padding:7px;color:#647c9b}.tb-form{padding:20px 23px}.tb-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:17px}.tb-form-grid label{display:flex;flex-direction:column;gap:7px;color:#385578;font-size:12px;font-weight:600}.tb-form-grid input:not([type=checkbox]),.tb-form-grid select{border:1px solid #dfe8f7;border-radius:8px;padding:11px 12px;background:white;font-size:12px;outline:0;color:#233e65;min-width:0}.tb-form-grid .tb-toggle-row{display:flex;flex-direction:row;align-items:center;justify-content:space-between;border:1px solid #e7eef8;border-radius:8px;padding:0 13px;min-height:42px;align-self:end}.tb-toggle-row input{accent-color:#2476fa;width:17px;height:17px}.tb-room-field{margin-top:24px;padding-top:19px;border-top:1px solid #edf1f8}.tb-room-field strong{font-size:13px}.tb-room-field p{font-size:11px;color:#8396af;margin:5px 0 11px}.tb-room-picker{display:flex;flex-wrap:wrap;gap:8px}.tb-room-picker button{display:flex;align-items:center;gap:5px;border-radius:7px;background:#f7faff;border:1px solid #dde8f8;padding:8px 12px;font-size:12px;color:#426285}.tb-room-picker button.selected{background:#e8f2ff;color:#176cf4;border-color:#a8caff;font-weight:700}.tb-dialog footer{padding:16px 23px;border-top:1px solid #edf1f8;display:flex;gap:10px;justify-content:flex-end}.tb-preview-dialog{width:min(1040px,100%,calc((100dvh - 230px)*1.65))}.tb-tv-frame{margin:19px 23px 8px}.tb-preview-bottom{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:15px 23px;font-size:12px}.tb-preview-caveat{font-size:11px;color:#7e90aa;padding:0 23px 20px;margin:0}@media(max-width:1180px){.tb-metric{padding:13px 10px;gap:9px}.tb-metric-icon{width:40px;height:40px}.tb-metric strong{font-size:25px}.tb-layout{grid-template-columns:minmax(0,1fr)}.tb-side{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:900px){.tb-head{flex-wrap:wrap}.tb-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.tb-side{grid-template-columns:1fr}.tb-preview-panel{max-width:480px}}@media(max-width:600px){.tb-head-buttons{flex-wrap:wrap}.tb-metrics{gap:8px}.tb-metric{min-height:98px}.tb-metric-icon{width:34px;height:34px}.tb-metric-icon svg{width:19px}.tb-metric strong{font-size:23px}.tb-metric div>span{display:none}.tb-panel-heading,.tb-toolbar{padding-left:12px;padding-right:12px}.tb-toolbar{flex-wrap:wrap}.tb-form-grid{grid-template-columns:1fr}.tb-dialog header,.tb-form,.tb-dialog footer{padding-left:15px;padding-right:15px}.tb-preview-bottom{flex-wrap:wrap}.tb-tv-frame{margin:12px 15px}}

.tb-head h1{font-size:30px}.tb-head p{font-size:13px}.tb-metric small{font-size:12px;font-weight:600;color:#284469}.tb-metric strong{font-size:29px}.tb-metric div>span{font-size:11px}.tb-panel{border-radius:15px;box-shadow:0 3px 10px #18345608}.tb-panel h2{font-size:18px}.tb-table-wrap td{padding:14px 10px;font-size:12px}.tb-table-wrap th{padding:12px 10px;font-size:11px}.tb-name strong{font-size:12px}.tb-select-screen{border:0;background:transparent;color:inherit;text-align:left;padding:0;white-space:nowrap}.tb-select-screen:focus-visible{outline:2px solid #2175fa;outline-offset:3px}.tb-selected-row{background:#f3f8ff}.tb-last-seen{color:#627da4!important}.tb-actions button{background:#fff}.tb-menu{display:flex;flex-direction:column;gap:3px;background:white;border:1px solid #dce8fa;border-radius:9px;padding:6px;margin-top:7px;min-width:160px}.tb-menu button{display:flex;align-items:center;gap:7px;background:none;border:0;color:#31577e;text-align:left;padding:7px;font-size:11px}.tb-menu button:disabled{opacity:.45;cursor:not-allowed}.tb-menu button.danger{color:#c84959}.tb-preview-info{padding-top:15px}.tb-preview-name{display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:13px}.tb-preview-info dl{margin:9px 0 0}.tb-preview-info dl>div{display:flex;justify-content:space-between;align-items:center;gap:10px;border-bottom:1px solid #edf2fa;padding:9px 0;font-size:11px}.tb-preview-info dt{color:#7890b1}.tb-preview-info dd{margin:0;text-align:right;color:#284469;font-weight:600}.tb-preview-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px}.tb-preview-dialog>header{position:sticky;top:0;background:white;z-index:1}.tb-preview-dialog{max-height:calc(100dvh - 40px)}.tb-btn:disabled,.tb-aside-title button:disabled{opacity:.45;cursor:not-allowed}
.tb-live-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:-10px 0 16px}.tb-live-toolbar label{display:flex;align-items:center;gap:10px;font-size:12px;color:#547099}.tb-live-toolbar select{padding:10px;border:1px solid #dce8fa;border-radius:8px;background:white;color:#31577e}.tb-error{background:#fff0ec;border:1px solid #f1c7bf;border-radius:8px;padding:10px;color:#a13838;font-size:12px}.tb-status.pending{background:#eef3fa;color:#647a99}.tb-preview-caveat{padding-top:12px}
</style>
