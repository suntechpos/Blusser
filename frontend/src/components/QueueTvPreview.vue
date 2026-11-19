<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { Monitor, Clock3, Volume2, Maximize, ArrowRight } from 'lucide-vue-next'
type QueueRow = { ticket: string; room: string; call_key?: string; status?: 'called' | 'in_progress' }
const props = defineProps<{ rooms: string[]; theme: 'dark' | 'light'; language: 'uz' | 'ru'; sound: boolean; compact?: boolean; feed?: { clinic: string; timezone: string; local_date: string; called: QueueRow[]; next: QueueRow[] } | null; connectionError?: boolean; live?: boolean }>()
const ru = computed(() => props.language === 'ru')
const copy = computed(() => ru.value ? {
  title:'Электронная очередь', demo:'ДЕМО', calling:'СЕЙЧАС ВЫЗЫВАЕТСЯ', ticket:'НОМЕР ОЧЕРЕДИ', room:'КАБИНЕТ', enter:'Пожалуйста, пройдите в кабинет!', called:'На приёме', receiving:'СЕЙЧАС НА ПРИЁМЕ', receivingHint:'Приём продолжается', next:'Следующие номера', number:'Номер', cabinet:'Кабинет', empty:'Нет номеров', footer:'Ваше здоровье важно для нас!', sound:'Звуковые объявления', fullscreen:'Полный экран'
} : {
  title:'Elektron navbat', demo:'DEMO', calling:'HOZIR CHAQIRILMOQDA', ticket:'NAVBAT RAQAMI', room:'KABINET', enter:'Marhamat, qabulga kiring!', called:'Qabulda', receiving:'HOZIR QABULDA', receivingHint:'Qabul davom etmoqda', next:'Keyingi navbatlar', number:'Navbat', cabinet:'Kabinet', empty:'Navbat mavjud emas', footer:'Sog‘lig‘ingiz biz uchun muhim!', sound:'Ovozli e’lon', fullscreen:'To‘liq ekran'
})
const called = computed(() => props.feed?.called ?? [])
const next = computed(() => props.feed?.next ?? [])
const current = computed(() => called.value[0])
const page = computed(() => Math.floor(now.value.getTime() / 8000))
const visibleRows = (rows: QueueRow[]) => {
  const size = 3
  const start = (page.value % Math.max(1, Math.ceil(rows.length / size))) * size
  return rows.slice(start, start + size)
}
const now = ref(new Date())
let timer: ReturnType<typeof setInterval> | undefined
onMounted(()=>{timer=setInterval(()=>{now.value=new Date()},1000)})
onUnmounted(()=>{if(timer!==undefined)clearInterval(timer)})
const clock = computed(()=>new Intl.DateTimeFormat('en-GB',{hour:'2-digit',minute:'2-digit',...(props.feed?.timezone ? {timeZone:props.feed.timezone}: {})}).format(now.value))
const day = computed(()=>new Intl.DateTimeFormat(ru.value?'ru-RU':'uz-UZ',{day:'2-digit',month:'2-digit',year:'numeric',...(props.feed?.timezone ? {timeZone:props.feed.timezone}: {})}).format(now.value))
const root = ref<HTMLElement|null>(null)
async function fullscreen(){if(!root.value?.requestFullscreen)return;try{await root.value.requestFullscreen()}catch{/* browser may refuse fullscreen */}}
</script>

<template>
  <div ref="root" class="queue-tv" :class="[theme, { compact }]">
    <header class="tv-header">
      <div class="tv-brand"><span class="tv-brand-icon"><Monitor /></span><div><strong>{{ feed?.clinic || (ru ? 'Клиника' : 'Klinika') }}</strong><small>{{ copy.title }}</small></div></div>
      <div class="tv-header-right"><span class="tv-demo" :class="{ disconnected: connectionError }">{{ connectionError ? (ru ? "НЕТ СВЯЗИ" : "ALOQA YO‘Q") : live && feed ? (ru ? "В ЭФИРЕ" : "JONLI") : (ru ? "ОЖИДАНИЕ" : "KUTILMOQDA") }}</span><div class="tv-clock"><strong>{{ clock }}</strong><small>{{ day }}</small></div></div>
    </header>
    <main class="tv-main">
      <section class="tv-call">
        <div class="tv-kicker"><i></i>{{ current?.status === 'in_progress' ? copy.receiving : copy.calling }}</div>
        <div v-if="current" class="tv-current">
          <div><strong>{{ current.ticket }}</strong><span>{{ copy.ticket }}</span></div>
          <ArrowRight class="tv-arrow" />
          <div><strong>{{ current.room }}</strong><span>{{ copy.room }}</span></div>
        </div>
        <div v-else class="tv-empty">{{ connectionError ? (ru ? "Связь с сервером потеряна" : "Server bilan aloqa uzildi") : !feed ? (ru ? "Загрузка…" : "Yuklanmoqda…") : copy.empty }}</div>
        <p v-if="current" class="tv-invite">{{ current.status === 'in_progress' ? copy.receivingHint : copy.enter }}</p>
      </section>
      <div class="tv-lists">
        <section v-for="group in [{title:copy.called, rows:called, kind:'called'}, {title:copy.next, rows:next, kind:'next'}]" :key="group.kind" class="tv-list" :class="group.kind">
          <h3><Volume2 v-if="group.kind==='called'" /><Clock3 v-else />{{ group.title }}</h3>
          <div class="tv-list-label"><span>{{ copy.number }}</span><span>{{ copy.cabinet }}</span></div>
          <div v-for="row in visibleRows(group.rows)" :key="row.ticket + row.room" class="tv-row"><strong>{{ row.ticket }}</strong><span>{{ row.room }}</span></div>
          <p v-if="!group.rows.length" class="tv-empty">{{ copy.empty }}</p>
        </section>
      </div>
    </main>
    <footer class="tv-footer"><span>{{ copy.footer }}</span><span>Powered by <b>Blusser</b></span><button v-if="!compact" type="button" :aria-label="copy.fullscreen" :title="copy.fullscreen" @click="fullscreen"><Maximize /></button></footer>
  </div>
</template>

<style scoped>
.queue-tv{--bg:#0c2240;--panel:#142f50;--hero:#173b61;--text:#f6faff;--muted:#adc4df;--line:#ffffff20;--accent:#69edbf;container-type:inline-size;display:flex;flex-direction:column;gap:0;background:var(--bg);color:var(--text);border-radius:12px;overflow:hidden;text-align:left;width:100%;font-family:Inter,Arial,sans-serif;box-sizing:border-box}
.queue-tv *{box-sizing:border-box}.queue-tv.light{--bg:#edf4ff;--panel:#fff;--hero:#dfeeff;--text:#132e51;--muted:#496583;--line:#b9cce5;--accent:#007a55}
.tv-header{display:flex;justify-content:space-between;align-items:center;padding:2.3cqw 3cqw;border-bottom:1px solid var(--line);gap:2cqw}.tv-brand,.tv-header-right{display:flex;align-items:center;gap:1.4cqw}.tv-brand-icon{display:grid;place-items:center;width:4cqw;height:4cqw;border-radius:.9cqw;background:var(--hero);color:var(--accent)}.tv-brand-icon svg{width:2.4cqw;height:2.4cqw}.tv-brand strong,.tv-brand small,.tv-clock strong,.tv-clock small{display:block}.tv-brand strong{font-size:2.1cqw;letter-spacing:.02em}.tv-brand small,.tv-clock small{font-size:1.15cqw;color:var(--muted);margin-top:.25cqw}.tv-clock{text-align:right}.tv-clock strong{font-size:2.6cqw;font-variant-numeric:tabular-nums}.tv-demo{font-size:1cqw;letter-spacing:.12em;color:var(--muted);border:1px solid var(--line);border-radius:.5cqw;padding:.4cqw .65cqw}
.tv-main{display:grid;grid-template-rows:auto auto;gap:1.5cqw;padding:1.6cqw 3cqw;flex:1;min-height:0}.tv-call{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1.1cqw;background:var(--hero);border:1px solid var(--line);border-radius:1.3cqw;padding:1.7cqw 2cqw}.tv-kicker{display:flex;align-items:center;gap:.7cqw;color:var(--muted);font-size:1.4cqw;letter-spacing:.16em;font-weight:700}.tv-kicker i{width:.65cqw;height:.65cqw;border-radius:50%;background:var(--accent);box-shadow:0 0 1cqw var(--accent)}.tv-current{width:85%;display:grid;grid-template-columns:1fr auto 1fr;align-items:center;text-align:center;gap:2cqw}.tv-current>div{display:flex;flex-direction:column;align-items:center;gap:.4cqw}.tv-current strong{font-size:8cqw;line-height:1;font-weight:800;letter-spacing:-.04em;font-variant-numeric:tabular-nums}.tv-current span{font-size:1.25cqw;color:var(--muted);letter-spacing:.15em;font-weight:600}.tv-arrow{width:3.8cqw;height:3.8cqw;color:var(--muted)}.tv-invite{font-size:1.9cqw;color:var(--accent);font-weight:700;margin:0;letter-spacing:0;opacity:1}
.tv-lists{display:grid;grid-template-columns:1fr 1fr;gap:1.5cqw;min-height:0}.tv-list{background:var(--panel);border:1px solid var(--line);border-radius:1.2cqw;padding:1.2cqw 1.7cqw;min-width:0}.tv-list h3{display:flex;align-items:center;gap:.8cqw;margin:0 0 .8cqw;font-size:1.65cqw;font-weight:700}.tv-list h3 svg{width:1.6cqw;height:1.6cqw;color:var(--accent)}.tv-list-label{display:flex;justify-content:space-between;color:var(--muted);font-size:1.05cqw;padding:0 .4cqw .5cqw}.tv-row{display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--line);padding:.65cqw .4cqw;gap:1cqw}.tv-row strong{font-size:2.4cqw;font-variant-numeric:tabular-nums}.tv-row span{font-size:2cqw;color:var(--muted);font-weight:700}.called .tv-row span{color:var(--accent)}.tv-empty{font-size:1.5cqw;color:var(--muted);margin:1cqw 0;text-align:center}.tv-footer{display:flex;align-items:center;justify-content:space-between;gap:1cqw;border-top:1px solid var(--line);padding:1.1cqw 3cqw;color:var(--muted);font-size:1.1cqw}.tv-footer b{color:var(--text)}.tv-footer button{display:grid;place-items:center;border:0;color:var(--muted);background:transparent;padding:0;cursor:pointer}.tv-footer svg{width:1.6cqw;height:1.6cqw}
.queue-tv:fullscreen{border-radius:0;width:100%;height:100%;max-height:none;justify-content:space-between}.queue-tv:fullscreen .tv-main{grid-template-rows:minmax(0,1.1fr) minmax(0,1fr);overflow:auto}.queue-tv:fullscreen .tv-call{min-height:0}.queue-tv:fullscreen .tv-current strong{font-size:min(8cqw,17vh)}.queue-tv:fullscreen .tv-list{display:flex;flex-direction:column;justify-content:center}.queue-tv:fullscreen .tv-row{padding:min(.65cqw,1vh) .4cqw}.queue-tv:fullscreen .tv-row strong{font-size:min(2.4cqw,4.2vh)}.queue-tv:fullscreen .tv-row span{font-size:min(2cqw,3.5vh)}
.tv-demo.disconnected{color:#ffb4a8;border-color:#ffb4a8}.light .tv-demo.disconnected{color:#a52a24;border-color:#a52a24}
</style>
