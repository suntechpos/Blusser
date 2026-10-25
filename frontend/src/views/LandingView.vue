<script setup lang="ts">
import { onMounted, ref } from "vue";
import {
  ArrowRight, BadgeCheck, Building2, CalendarDays, Check, ChevronDown, Cloud,
  CreditCard, FlaskConical, Headphones, Mail, Menu, Monitor, Network, Phone,
  Play, Plus, Send, ShieldCheck, UserRound, UsersRound, X, Zap, Globe,
  ChartNoAxesColumnIncreasing as Chart,
} from "lucide-vue-next";

const emit = defineEmits<{ login: [] }>();
const demoHref = "mailto:info@blusser.uz?subject=Blusser%20demo%20so%E2%80%98rovi";
const menu = ref(false);
const openFaq = ref<number[]>([0]);
const toggleFaq = (i: number) =>
  (openFaq.value = openFaq.value.includes(i) ? openFaq.value.filter((x) => x !== i) : [...openFaq.value, i]);
function go(id: string) {
  menu.value = false;
  document.getElementById(id)?.scrollIntoView({ behavior: "smooth", block: "start" });
}
onMounted(() => {
  document.title = "Blusser — Klinikangizni aqlli boshqaring";
  let m = document.querySelector('meta[name="description"]');
  if (!m) { m = document.createElement("meta"); m.setAttribute("name", "description"); document.head.appendChild(m); }
  m.setAttribute("content", "Blusser — xususiy klinikalar uchun yagona boshqaruv platformasi: registratura, bemorlar, shifokorlar, laboratoriya va kassa.");
});

const nav = [["Imkoniyatlar", "features"], ["Afzalliklar", "why"], ["Tariflar", "pricing"], ["FAQ", "faq"], ["Kontakt", "contact"]];
const quick = [
  [Cloud, "Bulutli tizim", "Istalgan joydan kirish"], [Building2, "Ko‘p filial", "Bitta tizimda boshqarish"],
  [ShieldCheck, "Rol va ruxsatlar", "Xavfsiz ishlash"], [Chart, "Real vaqt hisobotlari", "Aniq tahlil va nazorat"],
  [Headphones, "Qo‘llab-quvvatlash", "Doimiy texnik yordam"],
];
const modules = [
  [CalendarDays, "Qabul jadvali", "Onlayn va oflayn qabul boshqaruvi"], [UsersRound, "Bemorlar bazasi", "To‘liq tarix va tibbiy ma’lumotlar"],
  [UserRound, "Shifokorlar ishi", "Qulay va tezkor interfeys"], [FlaskConical, "Laboratoriya", "Tahlil jarayonlari va natijalar"],
  [CreditCard, "Kassa va moliya", "To‘lovlar, hisobotlar, daromad nazorati"], [Network, "Filiallar boshqaruvi", "Bir nechta filialni yagona tizimda"],
];
const bad = ["Qog‘ozdagi navbatlar", "Bemor ma’lumotlari tarqoq", "Noaniq moliyaviy hisoblar", "Shifokor bandligini nazorat qilish qiyin"];
const good = ["Barcha jarayonlar yagona platformada", "Qabul va navbat boshqaruvi", "Aniq va shaffof moliyaviy hisobotlar", "Filiallar bir tizimda"];
type Card = { icon: unknown; title: string; text: string; head: string; rows: string[][] };
const cards: Card[] = [
  { icon: CalendarDays, title: "Registratura", text: "Onlayn va oflayn qabul, navbatlar va bemor oqimini boshqarish.", head: "Bugungi qabul",
    rows: [["09:00", "Aliyeva Malika", "Qabulda"], ["09:30", "Karimov Aziz", "Navbatda"], ["10:00", "Saidova Dilbora", "Qabulda"]] },
  { icon: UsersRound, title: "Bemorlar", text: "Bemorlar bazasi, tibbiy tarix va qayta murojaatlar.", head: "Bemorlar",
    rows: [["", "Aliyeva Malika", "28 yosh"], ["", "Karimov Aziz", "45 yosh"], ["", "Saidova Dilbora", "32 yosh"]] },
  { icon: UserRound, title: "Shifokorlar", text: "Shifokorlar jadvali, bandlik va xizmatlar nazorati.", head: "Shifokorlar",
    rows: [["", "Dr. Karimov", "Terapevt"], ["", "Dr. Sattorova", "Kardiolog"], ["", "Dr. Mirzayev", "Nevrolog"]] },
  { icon: FlaskConical, title: "Laboratoriya", text: "Tahlil buyurtmalari va natijalarni elektron shaklda boshqarish.", head: "Tahlil natijalari",
    rows: [["", "Qon tahlili", "Tayyor"], ["", "Siydik tahlili", "Tayyor"], ["", "Biokimyo", "Jarayonda"]] },
  { icon: Chart, title: "Hisobotlar", text: "Moliyaviy, statistik va tibbiy hisobotlar.", head: "Tushum statistikasi", rows: [] },
  { icon: CreditCard, title: "Kassa va to‘lovlar", text: "To‘lovlar, xizmatlar, chegirmalar va moliyaviy nazorat.", head: "To‘lov usullari",
    rows: [["", "Naqd to‘lov", "1 240 000"], ["", "Karta orqali", "2 480 000"], ["", "Onlayn to‘lov", "600 000"]] },
];
const bars = [["Yan", 28], ["Fev", 38], ["Mar", 50], ["Apr", 60], ["May", 78], ["Iyn", 100]];
const why = [
  [Zap, "Tez joriy etish", "Qisqa muddatda ishga tushirish va oson moslashuv."], [Monitor, "Tushunarli interfeys", "Barcha xodimlar uchun qulay va intuitiv."],
  [Network, "Ko‘p filial nazorati", "Bitta tizimda barcha filiallarni boshqarish."], [ShieldCheck, "Xavfsiz ma’lumotlar", "Zamonaviy himoya va muntazam zaxiralash."],
];
const plans = [
  { name: "Start", sub: "Kichik klinikalar uchun", items: ["Asosiy funksiyalar", "5 tagacha shifokor", "Bemorlar bazasi", "Standart hisobotlar"], price: "Individual taklif", cta: "Demo so‘rash", hot: false },
  { name: "Standard", sub: "Rivojlanayotgan klinikalar uchun", items: ["Barcha asosiy funksiyalar", "Cheksiz shifokorlar", "Laboratoriya moduli", "Kengaytirilgan hisobotlar", "Ko‘p filial imkoniyati"], price: "Individual taklif", cta: "Demo so‘rash", hot: true },
  { name: "Enterprise", sub: "Tarmoq va yirik klinikalar uchun", items: ["Barcha modullar", "Cheksiz filiallar", "Maxsus integratsiyalar", "Shaxsiy menejer", "Moslashtirilgan funksiyalar"], price: "Moslashtirilgan tarif", cta: "Bog‘lanish", hot: false },
];
const faqs = [
  ["Blusser qaysi klinikalar uchun mos?", "Blusser xususiy klinikalar, tibbiyot markazlari, diagnostika laboratoriyalari, stomatologiyalar va ko‘p tarmoqli tibbiyot muassasalari uchun keladi."],
  ["Bir nechta filialni boshqarsa bo‘ladimi?", "Ha, Blusser bir nechta filialni bitta tizimda boshqarish imkonini beradi. Har bir filial uchun alohida sozlamalar va ruxsatlar mavjud."],
  ["Tizimni joriy etish qancha vaqt oladi?", "Joriy etish muddati klinikangiz hajmi va sozlamalariga bog‘liq. Aniq muddatni demo vaqtida kelishamiz."],
  ["Demo olish mumkinmi?", "Ha, “Demo so‘rash” tugmasi orqali murojaat qoldiring va biz siz bilan bog‘lanamiz."],
  ["Ma’lumotlar xavfsizligi qanday ta’minlanadi?", "Ma’lumotlar rollar bo‘yicha ruxsatlar va zamonaviy himoya choralari bilan muhofaza qilinadi."],
];
const foot = [
  ["Platforma", [["Imkoniyatlar", "features"], ["Tariflar", "pricing"], ["Demo so‘rash", "contact"]]],
  ["Kompaniya", [["Biz haqimizda", "contact"], ["Hamkorlik", "contact"], ["Kontakt", "contact"]]],
  ["Yordam", [["FAQ", "faq"], ["Maxfiylik siyosati", "contact"], ["Foydalanish shartlari", "contact"]]],
];
</script>

<template>
  <div class="lp">
    <header class="hd">
      <div class="wrap hd-in">
        <a class="logo" href="#top" aria-label="Blusser" @click.prevent="go('top')"><svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><rect x="2" y="2" width="14" height="9" rx="3" fill="#1d6bff"/><rect x="6" y="9" width="16" height="6" rx="3" fill="#19c3c9"/><rect x="2" y="13" width="14" height="9" rx="3" fill="#1d6bff"/></svg>Blusser</a>
        <nav class="nav" aria-label="Asosiy">
          <button v-for="n in nav" :key="n[1]" @click="go(n[1])">{{ n[0] }}<ChevronDown v-if="n[0] === 'Imkoniyatlar'" :size="12" /></button>
        </nav>
        <span class="lang"><Globe :size="14" /> UZ <ChevronDown :size="12" /></span>
        <button class="btn out sm" @click="emit('login')">Tizimga kirish</button>
        <a class="btn pri sm dm" :href="demoHref">Demo so‘rash <ArrowRight :size="14" /></a>
        <button class="burger" :aria-expanded="menu" aria-label="Menyu" @click="menu = !menu"><component :is="menu ? X : Menu" :size="22" /></button>
      </div>
      <div v-if="menu" class="mob">
        <button v-for="n in nav" :key="n[1]" @click="go(n[1])">{{ n[0] }}</button>
        <button class="btn out" @click="emit('login')">Tizimga kirish</button>
        <a class="btn pri" :href="demoHref">Demo so‘rash</a>
      </div>
    </header>

    <main id="top">
      <section class="hero wrap">
        <div class="hero-t">
          <span class="tag">KLINIKALAR UCHUN YAGONA PLATFORMA</span>
          <h1>Klinikangizni<br /><span class="grad">aqlli boshqaring</span></h1>
          <p>Registratura, bemorlar, shifokorlar, xodimlar, filiallar va moliyaviy jarayonlarni yagona tizimda boshqaring. Blusser — zamonaviy klinikalar uchun ishonchli raqamli yechim.</p>
          <div class="row">
            <a class="btn pri" :href="demoHref">Demo so‘rash <ArrowRight :size="16" /></a>
            <button class="btn out" disabled title="Video hozircha mavjud emas"><Play :size="16" /> Tizim haqida video</button>
          </div>
        </div>
        <div class="dash" aria-label="Blusser dashboard ko‘rinishi">
          <div class="d-top"><b class="logo sm2">Blusser</b><span class="d-s">Bemor qidirish…</span><span class="d-u">Dr. Bekzod</span></div>
          <div class="d-body">
            <ul class="d-side"><li class="on">Asosiy sahifa</li><li>Bemorlar</li><li>Qabul jadvali</li><li>Shifokorlar</li><li>Laboratoriya</li><li>Kassa va moliya</li><li>Hisobotlar</li><li>Filiallar</li></ul>
            <div class="d-main">
              <div class="d-stats"><div><small>Bugungi qabul</small><b>24</b></div><div><small>Onlayn shifokorlar</small><b>8 / 12</b></div><div><small>Bugungi tushum</small><b>4 320 000 so‘m</b></div><div><small>Yangi bemorlar</small><b>6</b></div></div>
              <div class="d-grid">
                <div class="d-card"><small>Bugungi jadval</small><p v-for="r in cards[0].rows" :key="r[0]"><span>{{ r[0] }}</span><span>{{ r[1] }}</span><i>{{ r[2] }}</i></p></div>
                <div class="d-card"><small>Tushum dinamikasi</small><div class="mini"><i v-for="b in bars" :key="b[0]" :style="{ height: b[1] + '%' }" /></div></div>
              </div>
              <div class="d-card ring"><div class="donut">72%</div><span>Yangi bemorlar 72%<br />Qayta murojaat 28%</span></div>
            </div>
          </div>
        </div>
      </section>

      <section class="wrap"><div class="q5"><div v-for="q in quick" :key="q[1] as string" class="qc"><span class="ic"><component :is="q[0]" :size="20" /></span><div><b>{{ q[1] }}</b><small>{{ q[2] }}</small></div></div></div></section>
      <section id="features" class="wrap"><div class="q6"><div v-for="m in modules" :key="m[1] as string" class="qc"><span class="ic"><component :is="m[0]" :size="20" /></span><div><b>{{ m[1] }}</b><small>{{ m[2] }}</small></div></div></div></section>

      <section class="wrap cmp">
        <div class="cm bad"><span class="pill r">Hozirgi holat</span><div class="ph paper" aria-hidden="true" /><ul><li v-for="b in bad" :key="b"><X :size="14" class="mk r" />{{ b }}</li></ul></div>
        <span class="mid"><ArrowRight :size="18" /></span>
        <div class="cm good"><span class="pill g">Blusser bilan</span><div class="ph doc" aria-hidden="true"><BadgeCheck :size="36" /></div><ul><li v-for="g in good" :key="g"><Check :size="14" class="mk g" />{{ g }}</li></ul></div>
      </section>

      <section class="wrap sec"><h2>Blusser bilan nimalarni boshqarasiz?</h2><p class="sub">Klinikangizning barcha jarayonlari uchun yagona, qulay va samarali yechim.</p>
        <div class="g3">
          <article v-for="c in cards" :key="c.title" class="card">
            <div class="card-h"><span class="ic big"><component :is="c.icon" :size="26" /></span><div><h3>{{ c.title }}</h3><p>{{ c.text }}</p></div></div>
            <div class="pv"><small>{{ c.head }}</small>
              <div v-if="c.title === 'Hisobotlar'" class="mini wide"><i v-for="b in bars" :key="b[0]" :style="{ height: b[1] + '%' }"><em>{{ b[0] }}</em></i></div>
              <p v-for="r in c.rows" :key="r[1]"><span class="av" /><span>{{ r[0] }}</span><span class="nm">{{ r[1] }}</span><i :class="{ w: r[2] === 'Navbatda' || r[2] === 'Jarayonda' }">{{ r[2] }}</i></p>
            </div>
          </article>
        </div>
      </section>

      <section id="why" class="wrap sec why"><h2>Nima uchun klinikalar Blusserni tanlaydi?</h2><p class="sub">Sizning muvaffaqiyatingiz — bizning ustuvor vazifamiz.</p>
        <div class="w-row"><div class="w4"><div v-for="w in why" :key="w[1] as string" class="qc col"><span class="ic"><component :is="w[0]" :size="20" /></span><div><b>{{ w[1] }}</b><small>{{ w[2] }}</small></div></div></div><div class="ph doc2" aria-hidden="true"><ShieldCheck :size="40" /></div></div>
      </section>

      <section id="pricing" class="wrap sec"><h2>Tariflar</h2><p class="sub">Klinikangiz ehtiyojiga mos tarifni tanlang.</p>
        <div class="g3 plans"><article v-for="p in plans" :key="p.name" class="plan" :class="{ hot: p.hot }"><span v-if="p.hot" class="pop">Eng ommabop</span><h3>{{ p.name }}</h3><p class="sub s2">{{ p.sub }}</p>
          <ul><li v-for="i in p.items" :key="i"><Check :size="14" class="mk g" />{{ i }}</li></ul><b class="price">{{ p.price }}</b>
          <a class="btn sm" :class="p.hot ? 'pri' : 'out'" :href="demoHref">{{ p.cta }} <ArrowRight :size="14" /></a></article></div>
      </section>

      <section id="faq" class="wrap sec faq"><h2 class="l">Ko‘p so‘raladigan savollar</h2><p class="sub l">Sizni qiziqtirgan savollarga javoblar.</p>
        <div class="fq"><div v-for="(f, i) in faqs" :key="f[0]" class="fi"><button :aria-expanded="openFaq.includes(i)" @click="toggleFaq(i)"><component :is="openFaq.includes(i) ? Zap : Plus" :size="14" class="mk b" />{{ f[0] }}<ChevronDown :size="16" class="chev" :class="{ up: openFaq.includes(i) }" /></button><p v-show="openFaq.includes(i)">{{ f[1] }}</p></div></div>
      </section>

      <section class="wrap"><div class="cta"><div><h2>Klinikangizni raqamli boshqaruvga olib chiqing</h2><p>Demo so‘rang va Blusser klinikangizga qanday mos tushishini ko‘ring.</p></div><div class="row"><a class="btn wh" :href="demoHref">Demo so‘rash <ArrowRight :size="16" /></a><button class="btn ol" @click="go('contact')">Kontakt</button></div></div></section>
    </main>

    <footer id="contact" class="ft"><div class="wrap">
      <div class="ft-g"><div><b class="logo w">Blusser</b><p>Zamonaviy klinikalar uchun yagona raqamli boshqaruv platformasi.</p><div class="soc"><Send :size="16" /><span>IG</span><span>YT</span><span>in</span></div></div>
        <div v-for="c in foot" :key="c[0] as string"><h4>{{ c[0] }}</h4><button v-for="l in c[1] as string[][]" :key="l[0]" @click="go(l[1])">{{ l[0] }}</button></div>
        <div><h4>Aloqa</h4><a href="mailto:info@blusser.uz"><Mail :size="14" /> info@blusser.uz</a><a href="tel:+998900000000"><Phone :size="14" /> +998 90 000 00 00</a><a href="https://t.me/" rel="noopener"><Send :size="14" /> Telegram</a></div></div>
      <div class="ft-b"><span>© 2026 Blusser. Barcha huquqlar himoyalangan.</span><span>Sog‘lom jamiyat uchun raqamli yechimlar.</span></div></div></footer>
  </div>
</template>

<style scoped>
.lp{--b:#1d6bff;--t:#19c3c9;--ink:#0b1f4d;--mu:#5b6b8c;--ln:#e3ebf7;background:linear-gradient(#f2f8ff,#fff 600px);color:var(--ink);overflow-x:hidden}
.lp *{box-sizing:border-box}.wrap{max-width:1240px;margin:0 auto;padding:0 20px}
button{cursor:pointer;background:none;border:0;color:inherit;font:inherit}
.hd{position:sticky;top:0;z-index:20;background:#fffffff2;backdrop-filter:blur(8px);border-bottom:1px solid var(--ln)}
.hd-in{display:flex;align-items:center;gap:18px;height:52px;max-width:1400px}
.logo{display:flex;align-items:center;gap:6px;font-weight:800;font-size:20px;color:var(--ink);text-decoration:none}.logo.w{color:#fff;font-size:24px}
.nav{display:flex;gap:26px;margin-left:34px;flex:1}.nav button{font-size:11px;font-weight:500;display:flex;gap:3px;align-items:center}
.lang{font-size:11px;display:flex;gap:4px;align-items:center;margin-right:8px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:44px;padding:0 24px;border-radius:8px;font-weight:600;font-size:14px;text-decoration:none;border:1.5px solid var(--b);transition:.15s}
.btn.sm{height:30px;padding:0 20px;font-size:11px;border-radius:5px}.btn.pri{background:var(--b);color:#fff}.btn.out{background:#fff;color:var(--b)}
.btn.wh{background:#fff;color:var(--b);border-color:#fff}.btn.ol{border-color:#fff;color:#fff}.btn:disabled{opacity:.6;cursor:not-allowed}.btn:hover:not(:disabled){filter:brightness(.95)}
.burger,.mob{display:none}
.hero{display:grid;grid-template-columns:1fr 1.05fr;gap:24px;align-items:center;padding-top:40px;padding-bottom:30px;max-width:1240px}
.tag{display:inline-block;font-size:9px;font-weight:700;color:var(--b);background:#e3efff;padding:6px 12px;border-radius:5px;letter-spacing:.04em}
h1{font-size:clamp(38px,5.6vw,64px);line-height:1.04;margin:20px 0 18px;font-weight:800;letter-spacing:-.02em}
.grad{background:linear-gradient(90deg,var(--b),var(--t));-webkit-background-clip:text;background-clip:text;color:transparent}
.hero p{color:#2b3b61;font-size:15px;line-height:1.55;max-width:470px;margin:0 0 26px}.row{display:flex;gap:14px;flex-wrap:wrap}
.dash{background:#fff;border:1px solid var(--ln);border-radius:14px;box-shadow:0 20px 60px #1d6bff2e;overflow:hidden;font-size:9px}
.d-top{display:flex;align-items:center;gap:12px;padding:8px 12px;border-bottom:1px solid var(--ln)}.sm2{font-size:13px}.d-s{flex:1;background:#f3f6fb;border-radius:12px;padding:4px 10px;color:var(--mu)}
.d-body{display:flex}.d-side{list-style:none;margin:0;padding:10px 8px;width:96px;display:grid;gap:7px;align-content:start;color:var(--mu)}.d-side .on{background:var(--b);color:#fff;border-radius:4px;padding:4px 6px}
.d-main{flex:1;padding:10px;display:grid;gap:8px;background:#f8fbff}.d-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:6px}.d-stats div,.d-card{background:#fff;border:1px solid var(--ln);border-radius:6px;padding:7px}
.d-stats small,.d-card>small{display:block;color:var(--mu);font-size:8px}.d-stats b{font-size:12px}.d-grid{display:grid;grid-template-columns:1.3fr 1fr;gap:8px}
.d-card p{display:flex;gap:6px;margin:5px 0 0}.d-card p i{margin-left:auto;font-style:normal;color:#0a9d6b;background:#e5f9f0;padding:1px 5px;border-radius:8px}
.mini{display:flex;align-items:flex-end;gap:5px;height:56px;margin-top:6px}.mini i{flex:1;background:linear-gradient(#3b8bff,#1d6bff);border-radius:2px 2px 0 0;position:relative}.mini.wide{height:80px;margin-bottom:14px}
.mini em{position:absolute;bottom:-14px;left:0;right:0;text-align:center;font-style:normal;font-size:8px;color:var(--mu)}
.ring{display:flex;gap:10px;align-items:center}.donut{width:44px;height:44px;border-radius:50%;background:conic-gradient(var(--t) 72%,#d9e6f7 0);display:grid;place-items:center;font-weight:700;position:relative}
.donut::after{content:"";position:absolute;inset:6px;background:#fff;border-radius:50%;z-index:0}.donut{isolation:isolate}
.q5,.q6{display:grid;gap:12px;margin:14px auto}.q5{grid-template-columns:repeat(5,1fr)}.q6{grid-template-columns:repeat(6,1fr)}
.qc{display:flex;gap:10px;align-items:center;background:#fff;border:1px solid var(--ln);border-radius:10px;padding:14px 12px;box-shadow:0 4px 14px #1d6bff0f}
.qc b{display:block;font-size:12px}.qc small{display:block;color:var(--mu);font-size:10px;margin-top:3px;line-height:1.3}
.ic{flex:none;width:38px;height:38px;border-radius:9px;background:#e6f1ff;color:var(--b);display:grid;place-items:center}.ic.big{width:52px;height:52px;border-radius:12px}
.cmp{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:0;margin-top:30px}.mid{width:38px;height:38px;border-radius:50%;background:#fff;color:var(--b);display:grid;place-items:center;margin:0 -19px;z-index:2;box-shadow:0 4px 14px #0001}
.cm{position:relative;display:grid;grid-template-columns:42% 1fr;gap:16px;align-items:center;padding:46px 22px 20px;border-radius:14px}.cm.bad{background:#fff1f1}.cm.good{background:#e8faf8}
.pill{position:absolute;top:14px;left:14px;font-weight:700;font-size:13px;padding:6px 14px;border-radius:7px;background:#fff}.pill.r{color:#e53935}.pill.g{color:#0b9e8f}
.ph{aspect-ratio:1.4;border-radius:10px;display:grid;place-items:center;color:#fff}.paper{background:repeating-linear-gradient(0deg,#cfd3da 0 6px,#eceef2 6px 12px)}.doc{background:linear-gradient(135deg,#bfe9f3,#7fc6dc)}
.cm ul{list-style:none;margin:0;padding:0;display:grid;gap:12px;font-size:12px;font-weight:500}.cm li{display:flex;gap:8px;align-items:center}
.mk{flex:none;border-radius:50%;padding:3px;width:20px;height:20px;color:#fff}.mk.r{background:#e53935}.mk.g{background:#12b886}.mk.b{background:none;color:var(--b);padding:0}
.sec{margin-top:46px;text-align:center}h2{font-size:24px;margin:0;font-weight:800}.sub{color:var(--mu);font-size:13px;margin:6px 0 18px}
.g3{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;text-align:left}.card{background:#fff;border:1px solid var(--ln);border-radius:12px;padding:16px;box-shadow:0 6px 20px #1d6bff0f}
.card-h{display:flex;gap:14px}.card h3{margin:0;font-size:15px}.card-h p{margin:4px 0 0;font-size:11px;color:var(--mu);line-height:1.4}
.pv{margin-top:14px;background:#f7faff;border:1px solid var(--ln);border-radius:8px;padding:10px;font-size:10px;min-height:118px}.pv small{font-weight:700;display:block;margin-bottom:6px}
.pv p{display:flex;align-items:center;gap:8px;margin:0;padding:6px 0;border-top:1px solid var(--ln)}.av{width:16px;height:16px;border-radius:50%;background:#cfd9ea}.nm{flex:1}.pv p i{font-style:normal;color:#0a9d6b;background:#e5f9f0;padding:2px 8px;border-radius:8px}.pv p i.w{color:#e08a00;background:#fff3de}
.w-row{display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:center}.w4{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.qc.col{flex-direction:column;align-items:flex-start}.doc2{aspect-ratio:1;border-radius:50%;background:linear-gradient(135deg,#bfe9f3,#7fc6dc)}
.plans{align-items:stretch}.plan{position:relative;background:#fff;border:1px solid var(--ln);border-radius:12px;padding:20px;text-align:center;display:flex;flex-direction:column;gap:6px}.plan.hot{border:1.5px solid var(--b);background:#f4f9ff}
.plan h3{margin:0;font-size:17px}.s2{margin:0 0 8px}.plan ul{list-style:none;padding:0;margin:6px 0 14px;display:grid;gap:9px;font-size:12px;text-align:left;flex:1}.plan li{display:flex;gap:8px;align-items:center}
.price{color:var(--b);font-size:13px;margin-bottom:8px}.pop{position:absolute;top:-10px;left:50%;transform:translateX(-50%);background:var(--b);color:#fff;font-size:9px;font-weight:700;padding:3px 12px;border-radius:5px}
.faq .l{text-align:left}.fq{columns:2;column-gap:14px;text-align:left}.fi{break-inside:avoid;background:#fff;border:1px solid var(--ln);border-radius:8px;margin-bottom:10px}
.fi button{width:100%;display:flex;gap:10px;align-items:center;padding:14px;font-weight:600;font-size:13px;text-align:left}.chev{margin-left:auto;transition:.2s}.chev.up{transform:rotate(180deg)}.fi p{margin:0;padding:0 14px 14px 38px;font-size:12px;color:var(--mu);line-height:1.5}
.cta{margin-top:40px;background:linear-gradient(90deg,#1d6bff,#19c3c9);border-radius:16px;padding:30px 40px;display:flex;justify-content:space-between;align-items:center;gap:20px;color:#fff;flex-wrap:wrap}.cta h2{font-size:24px}.cta p{margin:6px 0 0;font-size:14px;opacity:.95}
.ft{margin-top:40px;background:#0b1530;color:#cdd6ec;padding:40px 0 18px;font-size:12px}.ft-g{display:grid;grid-template-columns:1.6fr 1fr 1fr 1fr 1.2fr;gap:24px}.ft h4{color:#fff;margin:0 0 12px;font-size:12px}
.ft button,.ft a{display:flex;gap:8px;align-items:center;margin-bottom:9px;color:#cdd6ec;text-decoration:none;font-size:12px}.ft p{margin:10px 0 14px;line-height:1.5}.soc{display:flex;gap:12px;font-size:11px}.soc span{opacity:.85}
.ft-b{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:24px;padding-top:16px;border-top:1px solid #ffffff1f;font-size:10px;color:#8e9bbd}
@media(max-width:1100px){.nav{gap:14px;margin-left:12px}.q6{grid-template-columns:repeat(3,1fr)}.q5{grid-template-columns:repeat(3,1fr)}.w4{grid-template-columns:repeat(2,1fr)}.w-row{grid-template-columns:1fr}.doc2{display:none}}
@media(max-width:900px){.nav,.lang,.hd .btn,.hd .dm{display:none}.hd-in{justify-content:space-between}.burger{display:block}.mob{display:grid;gap:4px;padding:10px 20px 16px;background:#fff;border-bottom:1px solid var(--ln)}.mob button{text-align:left;padding:10px 0;font-weight:600}.mob .btn{display:inline-flex;margin-top:6px}
.hero{grid-template-columns:1fr}.g3{grid-template-columns:1fr 1fr}.cmp{grid-template-columns:1fr}.mid{margin:-6px auto;transform:rotate(90deg)}.fq{columns:1}.ft-g{grid-template-columns:1fr 1fr}}
@media(max-width:640px){.q5,.q6,.g3,.w4{grid-template-columns:1fr}.cm{grid-template-columns:1fr}.cta{padding:24px 20px}.d-side{display:none}.d-stats{grid-template-columns:1fr 1fr}.btn{width:100%}h2{font-size:21px}.cta h2{font-size:20px}}
</style>
