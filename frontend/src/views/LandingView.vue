<script setup lang="ts">
import { onMounted, onUnmounted, ref } from "vue";
import blusserLogo from "../assets/blusser-logo-transparent.png";
import {
  ArrowRight, Building2, CalendarDays, Check, ChevronDown, Cloud,
  CreditCard, FlaskConical, Headphones, Mail, Menu, Monitor, Network, Phone,
  Play, Plus, Send, ShieldCheck, UserRound, UsersRound, X, Zap, Globe,
  ChartNoAxesColumnIncreasing as Chart,
} from "lucide-vue-next";

const emit = defineEmits<{ login: [] }>();
const demoHref = "mailto:info@blusser.uz?subject=Blusser%20demo%20so%E2%80%98rovi";
const menu = ref(false);
const landing = ref<HTMLElement>();
const openFaq = ref<number[]>([0]);
const toggleFaq = (i: number) =>
  (openFaq.value = openFaq.value.includes(i) ? openFaq.value.filter((x) => x !== i) : [...openFaq.value, i]);
function go(id: string) {
  menu.value = false;
  document.getElementById(id)?.scrollIntoView({ behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth", block: "start" });
}
onMounted(() => {
  document.title = "Blusser — Klinikangizni aqlli boshqaring";
  let m = document.querySelector('meta[name="description"]');
  if (!m) { m = document.createElement("meta"); m.setAttribute("name", "description"); document.head.appendChild(m); }
  m.setAttribute("content", "Blusser — xususiy klinikalar uchun yagona boshqaruv platformasi: registratura, bemorlar, shifokorlar, laboratoriya va kassa.");
});

// Content stays visible if motion is unsupported or disabled. Animate both
// on entry and on exit, so scrolling backwards naturally reverses the reveal.
let disposeMotion = () => {};
onMounted(() => {
  const root = landing.value;
  if (!root) return;
  const reduced = window.matchMedia("(prefers-reduced-motion: reduce)");
  const animations = new Map<HTMLElement, Animation>();
  let active = new WeakSet<HTMLElement>();
  let observer: IntersectionObserver | undefined;

  const configure = () => {
    observer?.disconnect();
    animations.forEach((animation) => animation.cancel());
    animations.clear();
    active = new WeakSet<HTMLElement>();

    if (reduced.matches || typeof window.IntersectionObserver !== "function")
      return;

    observer = new IntersectionObserver((entries) => {
      for (const entry of entries) {
        const element = entry.target as HTMLElement;
        const visible = entry.isIntersecting;
        if (visible === active.has(element)) continue;

        if (visible) {
          active.add(element);
          const previous = animations.get(element);
          if (previous) {
            previous.reverse();
            continue;
          }
          if (typeof element.animate !== "function") continue;

          const direction = element.dataset.reveal;
          const from = direction === "left" ? "translate3d(-24px,0,0)"
            : direction === "right" ? "translate3d(24px,0,0)"
            : "translate3d(0,20px,0)";
          const animation = element.animate([
            { opacity: 0, transform: from },
            { opacity: 1, transform: "translate3d(0,0,0)" },
          ], {
            duration: 650,
            delay: Number(element.dataset.revealDelay ?? 0),
            easing: "cubic-bezier(.2,.65,.3,1)",
            fill: "both",
          });
          animations.set(element, animation);
        } else {
          active.delete(element);
          // Keep the finished animation (fill: both) to reverse towards its
          // original entry position. Re-entering plays it forwards again.
          animations.get(element)?.reverse();
        }
      }
    }, { threshold: 0.08 });
    root.querySelectorAll<HTMLElement>("[data-reveal]").forEach((element) =>
      observer!.observe(element),
    );
  };

  configure();
  reduced.addEventListener("change", configure);
  disposeMotion = () => {
    observer?.disconnect();
    animations.forEach((animation) => animation.cancel());
    animations.clear();
    reduced.removeEventListener("change", configure);
  };
});
onUnmounted(() => disposeMotion());

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
  <div ref="landing" class="lp">
    <header class="hd">
      <div class="wrap hd-in">
        <a class="logo" href="#top" aria-label="Blusser" @click.prevent="go('top')"><img class="brand-image" :src="blusserLogo" alt="Blusser — Medical Software" width="2146" height="733" fetchpriority="high" /></a>
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
        <div class="hero-t" data-reveal>
          <span class="tag">KLINIKALAR UCHUN YAGONA PLATFORMA</span>
          <h1>Klinikangizni<br /><span class="grad">aqlli boshqaring</span></h1>
          <p>Registratura, bemorlar, shifokorlar, xodimlar, filiallar va moliyaviy jarayonlarni yagona tizimda boshqaring. Blusser — zamonaviy klinikalar uchun ishonchli raqamli yechim.</p>
          <div class="row">
            <a class="btn pri" :href="demoHref">Demo so‘rash <ArrowRight :size="16" /></a>
            <button class="btn out" disabled title="Video hozircha mavjud emas"><Play :size="16" /> Tizim haqida video</button>
          </div>
        </div>
        <div class="dash" data-reveal data-reveal-delay="100" aria-label="Blusser dashboard ko‘rinishi"><span class="dash-orbit" aria-hidden="true" />
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

      <section class="wrap" aria-label="Blusser afzalliklari"><div class="feature-window" data-reveal><div class="q5" aria-label="Imkoniyatlar, uzluksiz harakatlanuvchi lenta">
        <div v-for="copy in 2" :key="copy" class="marquee-group" :aria-hidden="copy === 2 ? 'true' : undefined">
          <div v-for="q in quick" :key="q[1] as string" class="qc"><span class="ic"><component :is="q[0]" :size="20" /></span><div><b>{{ q[1] }}</b><small>{{ q[2] }}</small></div></div>
        </div>
      </div></div></section>
      <section id="features" class="wrap" aria-label="Blusser modullari"><div class="feature-window" data-reveal><div class="q6" aria-label="Modullar, uzluksiz harakatlanuvchi lenta">
        <div v-for="copy in 2" :key="copy" class="marquee-group" :aria-hidden="copy === 2 ? 'true' : undefined">
          <div v-for="m in modules" :key="m[1] as string" class="qc"><span class="ic"><component :is="m[0]" :size="20" /></span><div><b>{{ m[1] }}</b><small>{{ m[2] }}</small></div></div>
        </div>
      </div></div></section>

      <section class="wrap cmp">
        <div class="cm bad" data-reveal="left"><span class="pill r">Hozirgi holat</span><div class="ph paper" aria-hidden="true"><img src="https://images.unsplash.com/photo-1497633762265-9d179a990aa6?auto=format&amp;fit=crop&amp;w=560&amp;q=78" loading="lazy" alt="" /></div><ul><li v-for="b in bad" :key="b"><X :size="14" class="mk r" />{{ b }}</li></ul></div>
        <span class="mid"><ArrowRight :size="18" /></span>
        <div class="cm good" data-reveal="right"><span class="pill g">Blusser bilan</span><div class="ph doc" aria-hidden="true"><img src="https://images.unsplash.com/photo-1559839734-2b71ea197ec2?auto=format&amp;fit=crop&amp;w=560&amp;q=78" loading="lazy" alt="" /></div><ul><li v-for="g in good" :key="g"><Check :size="14" class="mk g" />{{ g }}</li></ul></div>
      </section>

      <section class="wrap sec"><h2 data-reveal>Blusser bilan nimalarni boshqarasiz?</h2><p class="sub" data-reveal data-reveal-delay="80">Klinikangizning barcha jarayonlari uchun yagona, qulay va samarali yechim.</p>
        <div class="g3">
          <article v-for="(c, i) in cards" :key="c.title" class="card" :data-reveal="i % 2 === 0 ? 'right' : 'left'" :data-reveal-delay="i * 100">
            <div class="card-h"><span class="ic big"><component :is="c.icon" :size="26" /></span><div><h3>{{ c.title }}</h3><p>{{ c.text }}</p></div></div>
            <div class="pv"><small>{{ c.head }}</small>
              <div v-if="c.title === 'Hisobotlar'" class="mini wide"><i v-for="b in bars" :key="b[0]" :style="{ height: b[1] + '%' }"><em>{{ b[0] }}</em></i></div>
              <p v-for="r in c.rows" :key="r[1]"><span class="av" /><span>{{ r[0] }}</span><span class="nm">{{ r[1] }}</span><i :class="{ w: r[2] === 'Navbatda' || r[2] === 'Jarayonda' }">{{ r[2] }}</i></p>
            </div>
          </article>
        </div>
      </section>

      <section id="why" class="wrap sec why" data-reveal><h2>Nima uchun klinikalar Blusserni tanlaydi?</h2><p class="sub">Sizning muvaffaqiyatingiz — bizning ustuvor vazifamiz.</p>
        <div class="w-row"><div class="w4"><div v-for="w in why" :key="w[1] as string" class="qc col"><span class="ic"><component :is="w[0]" :size="20" /></span><div><b>{{ w[1] }}</b><small>{{ w[2] }}</small></div></div></div><div class="ph doc2" aria-hidden="true"><img src="https://images.unsplash.com/photo-1559839734-2b71ea197ec2?auto=format&amp;fit=crop&amp;w=560&amp;q=78" loading="lazy" alt="" /><span class="doc2-shield"><ShieldCheck :size="28" /></span></div></div>
      </section>

      <section id="pricing" class="wrap sec"><h2 data-reveal>Tariflar</h2><p class="sub" data-reveal data-reveal-delay="80">Klinikangiz ehtiyojiga mos tarifni tanlang.</p>
        <div class="g3 plans"><article v-for="(p, i) in plans" :key="p.name" class="plan" data-reveal :data-reveal-delay="i * 80" :class="{ hot: p.hot }"><span v-if="p.hot" class="pop">Eng ommabop</span><h3>{{ p.name }}</h3><p class="sub s2">{{ p.sub }}</p>
          <ul><li v-for="i in p.items" :key="i"><Check :size="14" class="mk g" />{{ i }}</li></ul><b class="price">{{ p.price }}</b>
          <a class="btn sm" :class="p.hot ? 'pri' : 'out'" :href="demoHref">{{ p.cta }} <ArrowRight :size="14" /></a></article></div>
      </section>

      <section id="faq" class="wrap sec faq" data-reveal><h2 class="l">Ko‘p so‘raladigan savollar</h2><p class="sub l">Sizni qiziqtirgan savollarga javoblar.</p>
        <div class="fq"><div v-for="(f, i) in faqs" :key="f[0]" class="fi"><button :aria-expanded="openFaq.includes(i)" @click="toggleFaq(i)"><component :is="openFaq.includes(i) ? Zap : Plus" :size="14" class="mk b" />{{ f[0] }}<ChevronDown :size="16" class="chev" :class="{ up: openFaq.includes(i) }" /></button><p v-show="openFaq.includes(i)">{{ f[1] }}</p></div></div>
      </section>

      <section class="wrap"><div class="cta" data-reveal><div><h2>Klinikangizni raqamli boshqaruvga olib chiqing</h2><p>Demo so‘rang va Blusser klinikangizga qanday mos tushishini ko‘ring.</p></div><div class="row"><a class="btn wh" :href="demoHref">Demo so‘rash <ArrowRight :size="16" /></a><button class="btn ol" @click="go('contact')">Kontakt</button></div></div></section>
    </main>

    <footer id="contact" class="ft"><div class="wrap" data-reveal>
      <div class="ft-g"><div><a class="logo footer-brand" href="#top" @click.prevent="go('top')"><img class="brand-image" :src="blusserLogo" alt="Blusser — Medical Software" width="2146" height="733" loading="lazy" /></a><p>Zamonaviy klinikalar uchun yagona raqamli boshqaruv platformasi.</p><div class="soc"><Send :size="16" /><span>IG</span><span>YT</span><span>in</span></div></div>
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

/* Visual parity pass for the approved full-page Blusser landing reference.
   Scope: public landing only. These styles do not change authenticated screens. */
.lp{--b:#1566ff;--t:#15c9bc;--ink:#0c2152;--mu:#58749c;--ln:#e0eaf9;background:linear-gradient(180deg,#f0f8ff 0,#fff 720px);color:var(--ink);font-size:14px}
.lp .wrap{max-width:1510px;padding-left:28px;padding-right:28px}
.lp .hd{background:rgba(255,255,255,.96);border-bottom:1px solid #edf2fc;box-shadow:0 2px 16px #1e65b008}
.lp .hd-in{height:68px;max-width:1610px;gap:20px}
.lp .hd .logo{font-size:26px;letter-spacing:-.9px;gap:9px}
.lp .nav{gap:34px;margin-left:52px}
.lp .nav button{font-size:13px;font-weight:600;color:#172f59}
.lp .nav button:hover,.lp .ft button:hover{color:var(--b)}
.lp .lang{font-size:13px;gap:7px}
.lp .btn.sm{height:37px;padding:0 24px;font-size:12px;border-radius:7px}
.lp .btn{font-size:14px;font-weight:700;min-height:45px}
.lp .hero{max-width:1510px;grid-template-columns:.91fr 1.09fr;gap:48px;padding-top:66px;padding-bottom:38px;min-height:510px}
.lp .hero-t{z-index:1}
.lp .tag{font-size:11px;border-radius:7px;padding:8px 13px;color:#0961fd;background:#dfedff;font-weight:800}
.lp .hero h1{font-size:clamp(50px,5vw,76px);line-height:1.06;letter-spacing:-.052em;max-width:685px;margin:22px 0 22px}
.lp .hero .grad{background-image:linear-gradient(90deg,#2167ff,#1cbeb9)}
.lp .hero p{font-size:17px;line-height:1.62;color:#304e80;max-width:610px;margin-bottom:29px}
.lp .hero .row{gap:14px}
.lp .hero .btn{height:53px}
.lp .dash{position:relative;isolation:isolate;overflow:visible;border:1px solid #d9e8fa;background:#fff;box-shadow:0 24px 65px #2779d131,0 3px 10px #1977bf13;border-radius:19px;font-size:10px;margin:23px 6px 22px 0}
.lp .dash:before{content:"";position:absolute;inset:-70px -25px -55px -70px;background:radial-gradient(ellipse at 32% 20%,#9aefff 0,#c4f6fb 34%,transparent 55%),radial-gradient(ellipse at 78% 73%,#7fd4ff 0,#bfeafc 32%,transparent 62%);border-radius:45%;filter:blur(4px);z-index:-2;pointer-events:none}
.lp .dash-orbit{position:absolute;width:195px;height:195px;background:linear-gradient(135deg,#69bffe,#c4f3fd);border-radius:50%;right:-26px;top:27px;z-index:-1;opacity:.8}
.lp .d-top{min-height:43px;padding:10px 14px;background:#fff;border-radius:19px 19px 0 0}
.lp .d-top .logo{font-size:15px;color:#0b2553}
.lp .d-s{font-size:9px;background:#f3f7fd;border-radius:20px}
.lp .d-body{background:#fff;border-radius:0 0 19px 19px;overflow:hidden}
.lp .d-side{width:118px;gap:9px;padding:12px 9px;font-size:10px;background:#fff;border-right:1px solid #edf2fc}
.lp .d-side li{padding:4px 7px}
.lp .d-side .on{padding:7px 8px;background:#186dff;border-radius:6px;font-weight:700}
.lp .d-main{padding:15px;gap:11px;background:#f6faff}
.lp .d-stats{gap:8px}
.lp .d-stats div,.lp .d-card{border-radius:9px;border-color:#e4edf9;padding:10px}
.lp .d-stats small,.lp .d-card>small{font-size:9px}
.lp .d-stats b{font-size:15px;margin-top:4px;display:block}
.lp .d-grid{grid-template-columns:1.25fr .9fr;gap:9px}
.lp .d-card p{align-items:center;padding:4px 0;border-top:1px solid #ecf1fa;gap:8px;font-size:9px}
.lp .d-card p i{font-size:9px;white-space:nowrap}
.lp .mini{height:80px;gap:7px}
.lp .mini i{background:linear-gradient(180deg,#1cced5,#2774fe);border-radius:4px 4px 0 0}
.lp .ring{min-height:71px}
.lp .donut{width:50px;height:50px}
.lp .q5,.lp .q6{gap:14px;margin:10px auto 0}
.lp .q5{grid-template-columns:repeat(5,minmax(0,1fr))}
.lp .q6{grid-template-columns:repeat(6,minmax(0,1fr));margin-top:16px}
.lp .qc{gap:12px;min-height:76px;padding:12px 13px;background:#fff;border-radius:13px;box-shadow:0 9px 25px #1d65d009}
.lp .qc b{font-size:13px}
.lp .qc small{font-size:11px;line-height:1.38}
.lp .ic{width:43px;height:43px;border-radius:11px;background:linear-gradient(145deg,#e3f5ff,#e6edff);color:#0874fa}
.lp .ic.big{width:52px;height:52px}
.lp .cmp{margin-top:27px;grid-template-columns:1fr 28px 1fr;gap:0}
.lp .cm{min-height:235px;grid-template-columns:42% 1fr;gap:20px;padding:58px 17px 20px;border-radius:16px}
.lp .cm.bad{background:linear-gradient(135deg,#fff1f2,#fff4f4)}
.lp .cm.good{background:linear-gradient(135deg,#e7faf6,#e8fbfa)}
.lp .ph{aspect-ratio:1.24;max-height:188px;overflow:hidden;border-radius:12px;display:block;background:#d5e7f8}
.lp .ph img{display:block;width:100%;height:100%;object-fit:cover;object-position:center 31%}
.lp .ph.paper{background:#d9dce1}
.lp .ph.paper img{filter:grayscale(.9) contrast(.94)}
.lp .ph.doc img{object-position:center 22%}
.lp .cm ul{font-size:13px;line-height:1.42;gap:15px}
.lp .pill{font-size:13px;top:16px;left:16px}
.lp .mid{width:44px;height:44px;margin:0 -8px;box-shadow:0 9px 24px #1e65b51a}
.lp .sec{margin-top:46px}
.lp h2{font-size:29px;line-height:1.22;letter-spacing:-.035em}
.lp .sub{font-size:14px;margin:8px 0 22px;color:#5876a2}
.lp .g3{gap:18px}
.lp .card{padding:18px;border-radius:14px;box-shadow:0 7px 24px #1d6bff0c}
.lp .card h3{font-size:16px}
.lp .card-h p{font-size:12px}
.lp .pv{min-height:135px;margin-top:14px;border-radius:10px;font-size:11px}
.lp .pv p{padding:7px 0}
.lp .pv p i{font-size:10px}
.lp .w-row{grid-template-columns:1fr 300px;gap:25px}
.lp .w4{grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
.lp .qc.col{min-height:119px}
.lp .doc2{position:relative;aspect-ratio:1.15;border-radius:44% 56% 45% 55%;overflow:visible;background:linear-gradient(145deg,#e8f7ff,#c5f7fa)}
.lp .doc2 img{height:100%;border-radius:44% 56% 45% 55%;object-position:center 30%}
.lp .doc2-shield{position:absolute;top:7px;left:-13px;width:55px;height:55px;display:grid;place-items:center;border-radius:14px;color:#0875ff;background:#fff;box-shadow:0 9px 25px #0969dd20}
.lp .plans{max-width:1180px;margin-left:auto;margin-right:auto}
.lp .plan{padding:24px;gap:8px}
.lp .plan h3{font-size:19px}
.lp .plan ul{font-size:13px}
.lp .faq .fq{column-gap:18px}
.lp .fi button{font-size:13px}
.lp .cta{padding:32px 38px;background:linear-gradient(100deg,#1670ff,#15c8c9);border-radius:16px;box-shadow:0 9px 30px #1583d11f}
.lp .cta h2{font-size:25px}
.lp .ft{margin-top:30px;background:linear-gradient(115deg,#071933,#0b2140);padding:40px 0 18px}
.lp .ft-g{gap:32px}
.lp .ft .logo{font-size:25px}
@media(max-width:1250px){
.lp .wrap{padding-left:24px;padding-right:24px}
.lp .hero{gap:35px}
.lp .hero h1{font-size:clamp(45px,5vw,69px)}
.lp .nav{margin-left:15px;gap:21px}
.lp .w-row{grid-template-columns:1fr 240px}
.lp .w4{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:1000px){
.lp .hero{grid-template-columns:1fr 1fr;gap:22px;min-height:0}
.lp .hero h1{font-size:clamp(36px,5vw,54px)}
.lp .hero p{font-size:15px}
.lp .dash{font-size:9px}
.lp .q5{grid-template-columns:repeat(3,minmax(0,1fr))}
.lp .q6{grid-template-columns:repeat(3,minmax(0,1fr))}
.lp .cm{grid-template-columns:1fr;gap:15px}
.lp .cm .ph{aspect-ratio:2.1;max-height:155px}
}
@media(max-width:900px){
.lp .hd-in{height:62px}
.lp .hero{grid-template-columns:1fr;gap:25px;padding-top:46px}
.lp .hero h1{font-size:clamp(40px,7vw,62px)}
.lp .dash{max-width:690px;margin:20px auto 30px}
.lp .dash:before{inset:-25px -18px}
.lp .g3{grid-template-columns:repeat(2,1fr)}
.lp .cmp{grid-template-columns:1fr}
.lp .cm{grid-template-columns:32% 1fr}
.lp .cm .ph{aspect-ratio:1.2;max-height:none}
.lp .w-row{grid-template-columns:1fr}
.lp .doc2{display:none}
}
@media(max-width:640px){
.lp .wrap{padding-left:17px;padding-right:17px}
.lp .hero{padding-top:33px;padding-bottom:16px}
.lp .hero h1{font-size:clamp(39px,10vw,52px)}
.lp .hero p{font-size:15px}
.lp .dash{margin:23px 0 21px}
.lp .dash:before{inset:-18px}
.lp .d-main{padding:8px}
.lp .d-side{display:none}
.lp .d-top{padding:9px}
.lp .d-stats{grid-template-columns:repeat(2,1fr)}
.lp .d-grid{grid-template-columns:1fr 1fr}
.lp .q5,.lp .q6{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.lp .qc{padding:10px;min-height:67px}
.lp .qc b{font-size:12px}
.lp .qc small{font-size:10px}
.lp .cm{grid-template-columns:1fr;padding:58px 16px 19px}
.lp .cm .ph{max-height:210px;aspect-ratio:2.1}
.lp .g3{grid-template-columns:1fr}
.lp .w4{grid-template-columns:repeat(2,minmax(0,1fr))}
.lp .plans{max-width:none}
.lp .ft-g{grid-template-columns:1fr 1fr;gap:20px}
.lp .hero .btn{width:auto;padding:0 19px;font-size:12px}
.lp .cta .btn{width:auto}
}
@media(prefers-reduced-motion:reduce){
.lp *, .lp *::before, .lp *::after{scroll-behavior:auto!important;transition:none!important}
}

/* Original branding, seamless opposite-direction marquee and staggered cards. */
.lp .hd .logo{flex-shrink:0}
.lp .brand-image{display:block;width:164px;height:auto;max-width:100%;object-fit:contain}
.lp .ft .footer-brand{display:inline-flex;background:#fff;border-radius:10px;padding:8px 12px;margin-bottom:0}
.lp .ft .brand-image{width:180px}
.lp .feature-window{overflow:hidden;padding:10px 0 12px}
.lp #features .feature-window{padding-top:4px}
.lp .feature-window .q5,.lp .feature-window .q6{
  display:flex;gap:0;align-items:stretch;width:max-content;margin:0;
  will-change:transform;animation:blusser-ribbon-right 105s linear infinite;
}
.lp .feature-window .q6{animation-name:blusser-ribbon-left;animation-duration:115s}
.lp .feature-window .marquee-group{display:flex;align-items:stretch;flex:none;gap:14px;padding-right:14px}
.lp .feature-window .qc{min-height:83px;flex:none}
.lp .feature-window .q5 .qc{width:clamp(265px,19vw,330px)}
.lp .feature-window .q6 .qc{width:clamp(240px,16.5vw,300px)}
/* Each track has TWO identical groups. Their padding includes the seam gap,
   so exactly half the track is one complete repeat: no reset jump or empty space. */
@keyframes blusser-ribbon-right{
  from{transform:translate3d(-50%,0,0)}
  to{transform:translate3d(0,0,0)}
}
@keyframes blusser-ribbon-left{
  from{transform:translate3d(0,0,0)}
  to{transform:translate3d(-50%,0,0)}
}
.lp [data-reveal]{scroll-margin-top:84px}
.lp [data-reveal]:focus-within{opacity:1!important;transform:none!important}
@media(max-width:640px){
.lp .brand-image{width:148px}
.lp .feature-window .q5 .qc{width:245px}
.lp .feature-window .q6 .qc{width:245px}
}
@media(prefers-reduced-motion:reduce){
  .lp .feature-window .q5,.lp .feature-window .q6{width:100%;animation:none!important;transform:none!important;will-change:auto}
  .lp .feature-window .marquee-group:nth-child(2){display:none}
  .lp .feature-window .marquee-group{display:grid;gap:14px;padding-right:0;width:100%;grid-template-columns:repeat(3,minmax(0,1fr))}
  .lp .feature-window .qc{width:auto!important;min-width:0}
}
@media(max-width:640px) and (prefers-reduced-motion:reduce){
  .lp .feature-window .marquee-group{grid-template-columns:1fr}
}

</style>
