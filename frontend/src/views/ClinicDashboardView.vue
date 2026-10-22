<script setup lang="ts">
import { session, selectClinic, can } from "../services/session";
import { errorText } from "../services/api";
import NavbatlarView from "./NavbatlarView.vue";
import TabloBoshqaruviView from "./TabloBoshqaruviView.vue";
import QabullarView from "./QabullarView.vue";
import BemorlarView from "./BemorlarView.vue";
import XodimlarView from "./XodimlarView.vue";
import DepartmentsRoomsView from "./DepartmentsRoomsView.vue";
import DoctorWorkspaceView from "./DoctorWorkspaceView.vue";
import RegistraturaView from "./RegistraturaView.vue";
import ServicesView from "./ServicesView.vue";
import DiagnosticsView from "./DiagnosticsView.vue";
import LaboratoryView from "./LaboratoryView.vue";
import CashierView from "./CashierView.vue";
import SettingsView from "./SettingsView.vue";
import { computed, ref, onMounted, onUnmounted, watch } from "vue";
import {
  Activity,
  Bell,
  Building2,
  CalendarDays,
  ChevronDown,
  ChevronRight,
  Clock3,
  CreditCard,
  FlaskConical,
  Home,
  LayoutGrid,
  Map,
  MapPin,
  Menu,
  Package,
  Search,
  Settings,
  Stethoscope,
  Users,
  Wallet,
  ClipboardList,
  ChartNoAxesColumnIncreasing,
  TriangleAlert,
  ArrowRight,
  BriefcaseMedical,
  LogOut,
  BedDouble,
} from "lucide-vue-next";

/** Shared authenticated shell. Only Patients is connected; other pages retain the demo banner. */
const emit = defineEmits<{ logout: []; links: [] }>();
const settingsView = ref<InstanceType<typeof SettingsView>>();
const diagnosticsView = ref<InstanceType<typeof DiagnosticsView>>();
const laboratoryView = ref<InstanceType<typeof LaboratoryView>>();
import {
  pages,
  allowedPage,
  firstAllowed,
  pageFromHash,
} from "../services/access";
const activeItem = ref(
  window.location.hash
    ? (pageFromHash(window.location.hash)?.label ?? "Ruxsatsiz sahifa")
    : (firstAllowed()?.label ?? ""),
);
function navigate(label: string) {
  if (settingsView.value?.guardLeave(() => navigate(label))) return;
  activeItem.value = label;
  const p = pages.find((p) => p.label === label);
  window.location.hash = p?.hash ?? "ruxsatsiz";
}
const pageAllowed = computed(() => allowedPage(activeItem.value));
function homeAllowed() {
  const p = firstAllowed();
  if (p) navigate(p.label);
}
const activeClinic = computed(() =>
  session.clinics.find((c) => c.id === session.user?.active_tenant_id),
);
async function changeClinic(e: Event) {
  try {
    await selectClinic((e.target as HTMLSelectElement).value);
  } catch (error) {
    session.error = errorText(error);
  }
}
const period = ref("Bugun, 15 oktabr 2026");
const branch = ref("Barcha filiallar");
const sidebarOpen = ref(true);
const profileOpen = ref(false);
const profileRef = ref<HTMLElement | null>(null);

function closeProfileOutside(event: MouseEvent) {
  if (profileRef.value && !profileRef.value.contains(event.target as Node)) {
    profileOpen.value = false;
  }
}
function syncHash() {
  const next = window.location.hash;
  const guard =
    settingsView.value ?? diagnosticsView.value ?? laboratoryView.value;
  if (
    guard?.guardLeave(() => {
      history.replaceState(
        null,
        "",
        location.pathname + location.search + next,
      );
      syncHash();
    })
  ) {
    history.replaceState(
      null,
      "",
      location.pathname +
        location.search +
        (pages.find((p) => p.label === activeItem.value)?.hash
          ? "#" + pages.find((p) => p.label === activeItem.value)!.hash
          : ""),
    );
    return;
  }
  activeItem.value = pageFromHash(next)?.label ?? "Ruxsatsiz sahifa";
}
watch(
  () => session.grants,
  () => {
    if (!pageAllowed.value) profileOpen.value = false;
  },
);
onMounted(() => {
  document.addEventListener("click", closeProfileOutside);
  window.addEventListener("hashchange", syncHash);
});
onUnmounted(() => {
  document.removeEventListener("click", closeProfileOutside);
  window.removeEventListener("hashchange", syncHash);
});

const allGroups = [
  { label: "", items: [{ label: "Bosh sahifa", icon: Home }] },
  {
    label: "BEMORLAR VA QABULLAR",
    items: [
      { label: "Registratura", icon: BriefcaseMedical },
      { label: "Bemorlar", icon: Users },
      { label: "Qabullar", icon: CalendarDays },
      { label: "Navbatlar", icon: ClipboardList },
    ],
  },
  {
    label: "KLINIK JARAYONLAR",
    items: [
      { label: "Shifokor kabineti", icon: Stethoscope },
      { label: "Laboratoriya", icon: FlaskConical },
      { label: "UZI va Diagnostika", icon: Activity },
      { label: "Xizmatlar", icon: LayoutGrid },
    ],
  },
  {
    label: "MOLIYA VA OMBOR",
    items: [
      { label: "Kassa", icon: Wallet },
      { label: "Moliya", icon: ChartNoAxesColumnIncreasing },
      { label: "Ombor", icon: Package },
    ],
  },
  {
    label: "XODIMLAR VA BOSHQARUV",
    items: [
      { label: "Xodimlar", icon: Users },
      { label: "Filiallar", icon: Building2 },
      { label: "Bo‘limlar va Xonalar", icon: Map },
      { label: "Hisobotlar", icon: ChartNoAxesColumnIncreasing },
    ],
  },
  { label: "SOZLAMALAR", items: [{ label: "Sozlamalar", icon: Settings }] },
];
const groups = computed(() =>
  allGroups
    .map((g) => ({ ...g, items: g.items.filter((i) => allowedPage(i.label)) }))
    .filter((g) => g.items.length),
);

const metrics = [
  {
    label: "Bugungi bemorlar",
    value: "42",
    change: "12%",
    note: "Kecha: 37",
    icon: Users,
    cls: "sky",
  },
  {
    label: "Rejalashtirilgan qabullar",
    value: "28",
    change: "8%",
    note: "Kecha: 26",
    icon: CalendarDays,
    cls: "mint",
  },
  {
    label: "Tugagan qabullar",
    value: "20",
    change: "25%",
    note: "Kecha: 16",
    icon: Stethoscope,
    cls: "amber",
  },
  {
    label: "Kutilayotgan navbat",
    value: "6",
    change: "",
    note: "O‘rtacha kutish: 18 daqiqa",
    icon: Clock3,
    cls: "rose",
  },
  {
    label: "Bugungi tushum",
    value: "4 250 000",
    change: "32%",
    note: "Kecha: 3 220 000 so‘m",
    icon: CreditCard,
    cls: "violet",
  },
];
const visits = [
  {
    time: "09:00",
    name: "Aliyeva Mohira",
    id: "P-00124",
    doctor: "Karimov A.",
    specialty: "Kardiolog",
    service: "Konsultatsiya",
    status: "Bajarilgan",
    kind: "success",
    avatar: "AM",
  },
  {
    time: "09:30",
    name: "Toshboyev Akmal",
    id: "P-00125",
    doctor: "Rahmonova D.",
    specialty: "Terapevt",
    service: "Konsultatsiya",
    status: "Bajarilgan",
    kind: "success",
    avatar: "TA",
  },
  {
    time: "10:00",
    name: "Qodirov Aziz",
    id: "P-00126",
    doctor: "Saidov B.",
    specialty: "Nevrolog",
    service: "Konsultatsiya",
    status: "Qabulda",
    kind: "info",
    avatar: "QA",
  },
  {
    time: "10:30",
    name: "Ismoilova Nilufar",
    id: "P-00127",
    doctor: "Karimov A.",
    specialty: "Kardiolog",
    service: "EKG",
    status: "Kutilmoqda",
    kind: "warning",
    avatar: "IN",
  },
  {
    time: "11:00",
    name: "Matchonov Siroj",
    id: "P-00128",
    doctor: "Rahmonova D.",
    specialty: "Terapevt",
    service: "Konsultatsiya",
    status: "Rejalashtirilgan",
    kind: "neutral",
    avatar: "MS",
  },
];
const queue = [
  {
    patient: "Aliyeva Mohira",
    note: "Konsultatsiya • 5 daqiqa",
    doctor: "Karimov A.",
    specialty: "Kardiolog",
    status: "Qabulda",
    kind: "success",
    avatar: "AM",
    dr: "KA",
  },
  {
    patient: "Qodirov Aziz",
    note: "UZI • 18 daqiqa",
    doctor: "Saidova N.",
    specialty: "UZI",
    status: "Kutilmoqda",
    kind: "warning",
    avatar: "QA",
    dr: "SN",
  },
  {
    patient: "Ismoilova Nilufar",
    note: "Laboratoriya • 30 daqiqa",
    doctor: "Rasulova M.",
    specialty: "Laboratoriya",
    status: "Kutilmoqda",
    kind: "warning",
    avatar: "IN",
    dr: "RM",
  },
  {
    patient: "Toshboyev Akmal",
    note: "Konsultatsiya • 45 daqiqa",
    doctor: "Rahmonova D.",
    specialty: "Terapevt",
    status: "Kutilmoqda",
    kind: "neutral",
    avatar: "TA",
    dr: "RD",
  },
];
const chartX = [0, 105, 210, 315, 420, 525, 630];
const seriesA = [12, 23, 18, 34, 25, 40, 47];
const seriesB = [6, 15, 10, 20, 15, 29, 34];
const point = (v: number, i: number) => `${chartX[i]},${140 - v * 2.55}`;
const lineA = computed(() => seriesA.map(point).join(" "));
const lineB = computed(() => seriesB.map(point).join(" "));
const areaB = computed(() => `0,140 ${lineB.value} 630,140`);
</script>

<template>
  <div class="clinic-shell" :class="{ 'side-collapsed': !sidebarOpen }">
    <aside class="clinic-sidebar">
      <div class="clinic-logo">
        <span class="clinic-mark"><i></i><b></b></span>
        <div><strong>Blusser</strong><small>MEDICAL SOFTWARE</small></div>
      </div>
      <nav class="clinic-menu" aria-label="Klinika bo‘limlari">
        <div v-for="(group, g) in groups" :key="g" class="clinic-nav-group">
          <div v-if="group.label" class="clinic-group-label">
            {{ group.label }}
          </div>
          <button
            v-for="item in group.items"
            :key="item.label"
            type="button"
            class="clinic-nav-item"
            :class="{ selected: activeItem === item.label }"
            @click="navigate(item.label)"
          >
            <component :is="item.icon" :size="20" :stroke-width="2" /><span>{{
              item.label
            }}</span>
          </button>
        </div>
      </nav>
    </aside>
    <div class="clinic-workspace">
      <header class="clinic-topbar">
        <button
          class="clinic-icon-btn"
          aria-label="Menyuni ochish/yopish"
          @click="sidebarOpen = !sidebarOpen"
        >
          <Menu :size="23" />
        </button>
        <label class="clinic-selector"
          ><Building2 :size="20" /><select
            style="
              background: transparent;
              border: 0;
              font: inherit;
              color: inherit;
              max-width: 220px;
            "
            aria-label="Faol klinika"
            :value="session.user?.active_tenant_id"
            :disabled="session.switching"
            @change="changeClinic($event)"
          >
            <option v-for="c in session.clinics" :key="c.id" :value="c.id">
              {{ c.name }}
            </option>
          </select></label
        >
        <div class="clinic-search">
          <Search :size="19" /><span
            >Bemor qidirish (F.I.Sh. yoki telefon)...</span
          ><kbd>Ctrl + K</kbd>
        </div>
        <button
          v-if="can('audit.view')"
          class="clinic-icon-btn clinic-notify"
          aria-label="Bildirishnomalar"
          title="Bildirishnomalar API’ga ulanmagan"
        >
          <Bell :size="21" /><span v-if="activeItem !== 'Bemorlar'">3</span>
        </button>
        <div class="clinic-profile-wrap" ref="profileRef">
          <button
            type="button"
            class="clinic-user clinic-profile-trigger"
            :aria-expanded="profileOpen"
            aria-label="Profil menyusi"
            @click="profileOpen = !profileOpen"
          >
            <span class="clinic-avatar">{{
              session.user?.login.slice(0, 2).toUpperCase()
            }}</span>
            <span class="clinic-profile-name">
              <strong>{{ session.user?.login }}</strong>
              <small>{{ activeClinic?.role }}</small>
            </span>
            <ChevronDown :size="16" />
          </button>
          <div v-if="profileOpen" class="clinic-profile-menu">
            <div class="clinic-profile-title">
              <strong>{{ session.user?.login }}</strong>
              <small>{{ activeClinic?.role }} · {{ activeClinic?.name }}</small>
            </div>
            <div class="clinic-profile-row">
              <span>Lavozim</span><strong>{{ activeClinic?.role }}</strong>
            </div>
            <div class="clinic-profile-row">
              <span>Klinika</span><strong>{{ activeClinic?.name }}</strong>
            </div>
            <button
              type="button"
              class="clinic-profile-exit"
              style="color: #245da8"
              @click="
                profileOpen = false;
                emit('links');
              "
            >
              Klinikaga bog‘lash so‘rovlari
            </button>
            <button
              type="button"
              class="clinic-profile-exit"
              @click="
                profileOpen = false;
                emit('logout');
              "
            >
              <LogOut :size="17" /> Tizimdan chiqish
            </button>
          </div>
        </div>
      </header>
      <main class="clinic-main" :key="session.epoch">
        <section v-if="!pageAllowed" class="clinic-placeholder" role="alert">
          <h1>403 — Ruxsat yo‘q</h1>
          <p>Bu sahifani ko‘rish huquqi berilmagan.</p>
          <button v-if="firstAllowed()" @click="homeAllowed">
            Ruxsat etilgan sahifaga qaytish
          </button>
          <p v-else>
            Hech qanday sahifa ruxsati berilmagan. Administratorga murojaat
            qiling.
          </p>
        </section>
        <template v-else>
          <div
            v-if="
              activeItem !== 'Sozlamalar' &&
              activeItem !== 'Kassa' &&
              activeItem !== 'Laboratoriya' &&
              activeItem !== 'UZI va Diagnostika' &&
              activeItem !== 'Xizmatlar' &&
              activeItem !== 'Registratura' &&
              activeItem !== 'Bemorlar' &&
              activeItem !== 'Xodimlar' &&
              activeItem !== 'Bo‘limlar va Xonalar' &&
              activeItem !== 'Shifokor kabineti'
            "
            class="clinic-demo-note"
          >
            <span>DEMO VERSTKA</span> Figma dizayni asosida namunaviy
            ma’lumotlar. Haqiqiy klinika va bemorlar bazasiga ulanmagan.
          </div>
          <template v-if="activeItem === 'Bosh sahifa'">
            <div class="clinic-page-head">
              <div>
                <h1>Xush kelibsiz, Gulnoza!</h1>
                <p>Bugungi klinika faoliyati haqida umumiy ma’lumot</p>
              </div>
              <div class="clinic-filters">
                <button>
                  <CalendarDays :size="18" />{{ period
                  }}<ChevronDown :size="16" /></button
                ><button>
                  <MapPin :size="19" />{{ branch }}<ChevronDown :size="16" />
                </button>
              </div>
            </div>
            <section v-if="can('finance.view')" class="clinic-stats">
              <div
                v-for="(m, i) in metrics"
                :key="m.label"
                class="clinic-stat"
                :class="m.cls"
              >
                <span class="clinic-stat-icon"
                  ><component :is="m.icon" :size="28"
                /></span>
                <div class="clinic-stat-detail">
                  <span class="clinic-stat-label">{{ m.label }}</span>
                  <div class="clinic-stat-value">
                    {{ m.value }}<small v-if="i === 4"> so‘m</small>
                    <em v-if="m.change">↑ {{ m.change }}</em
                    ><small v-if="i === 3"> bemor</small>
                  </div>
                  <span class="clinic-stat-note">{{ m.note }}</span>
                </div>
              </div>
            </section>
            <section v-if="can('finance.view')" class="clinic-analytics">
              <article class="clinic-panel clinic-activity">
                <div class="clinic-panel-head">
                  <h2>Klinika faolligi</h2>
                  <button>So‘nggi 7 kun <ChevronDown :size="15" /></button>
                </div>
                <div class="clinic-line-chart">
                  <div class="clinic-ylabels">
                    <span>50</span><span>40</span><span>30</span><span>20</span
                    ><span>10</span><span>0</span>
                  </div>
                  <div class="clinic-plot">
                    <svg
                      viewBox="0 0 630 140"
                      preserveAspectRatio="none"
                      aria-label="7 kunlik klinika faolligi grafigi"
                      role="img"
                    >
                      <g
                        stroke="#e6edf7"
                        stroke-dasharray="3 3"
                        stroke-width="1"
                      >
                        <path
                          v-for="y in [0, 28, 56, 84, 112, 140]"
                          :key="`h${y}`"
                          :d="`M0 ${y}H630`"
                        />
                        <path
                          v-for="x in chartX"
                          :key="`v${x}`"
                          :d="`M${x} 0V140`"
                        />
                      </g>
                      <polygon
                        :points="areaB"
                        fill="#11b99a"
                        fill-opacity=".14"
                      />
                      <polyline
                        :points="lineB"
                        fill="none"
                        stroke="#05b99c"
                        stroke-width="2.5"
                        vector-effect="non-scaling-stroke"
                      />
                      <polyline
                        :points="lineA"
                        fill="none"
                        stroke="#2376fb"
                        stroke-width="2.5"
                        vector-effect="non-scaling-stroke"
                      />
                      <circle
                        v-for="(v, i) in seriesA"
                        :key="`a${i}`"
                        :cx="chartX[i]"
                        :cy="140 - v * 2.55"
                        r="3.5"
                        fill="#2376fb"
                      />
                      <circle
                        v-for="(v, i) in seriesB"
                        :key="`b${i}`"
                        :cx="chartX[i]"
                        :cy="140 - v * 2.55"
                        r="3.5"
                        fill="#05b99c"
                      />
                    </svg>
                    <div class="clinic-xlabels">
                      <span
                        v-for="day in [
                          '9 okt',
                          '10 okt',
                          '11 okt',
                          '12 okt',
                          '13 okt',
                          '14 okt',
                          '15 okt',
                        ]"
                        :key="day"
                        >{{ day }}</span
                      >
                    </div>
                  </div>
                </div>
                <div class="clinic-legend">
                  <span><i class="blue-dot"></i>Bemorlar soni</span
                  ><span><i class="green-dot"></i>Tushum (mln so‘m)</span>
                </div>
              </article>
              <article class="clinic-panel clinic-services">
                <div class="clinic-panel-head">
                  <h2>Xizmatlar bo‘yicha</h2>
                  <button>Bugun <ChevronDown :size="15" /></button>
                </div>
                <div class="clinic-donut-row">
                  <div class="clinic-donut">
                    <div><strong>42</strong><small>bemor</small></div>
                  </div>
                  <div class="clinic-donut-legend">
                    <div
                      v-for="s in [
                        { l: 'Konsultatsiya', p: 45, c: '#2477ff' },
                        { l: 'Laboratoriya', p: 20, c: '#04b99b' },
                        { l: 'UZI va Diagnostika', p: 18, c: '#ffbb45' },
                        { l: 'Muolajalar', p: 10, c: '#b78bfa' },
                        { l: 'Boshqa', p: 7, c: '#a1b5d0' },
                      ]"
                      :key="s.l"
                    >
                      <i :style="{ background: s.c }"></i><span>{{ s.l }}</span
                      ><b>{{ s.p }}%</b>
                    </div>
                  </div>
                </div>
              </article>
            </section>
            <section class="clinic-bottom">
              <article
                v-if="can('appointments.view')"
                class="clinic-panel clinic-appointments"
              >
                <div class="clinic-panel-head">
                  <h2>Bugungi qabullar</h2>
                  <button class="clinic-link" @click="activeItem = 'Qabullar'">
                    Barchasini ko‘rish <ArrowRight :size="16" />
                  </button>
                </div>
                <div class="clinic-table-wrap">
                  <table>
                    <thead>
                      <tr>
                        <th>Vaqt</th>
                        <th>Bemor</th>
                        <th>Shifokor</th>
                        <th>Xizmat</th>
                        <th>Holat</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="v in visits" :key="v.id">
                        <td>{{ v.time }}</td>
                        <td>
                          <div class="clinic-patient">
                            <span class="clinic-small-avatar">{{
                              v.avatar
                            }}</span>
                            <div>
                              <b>{{ v.name }}</b
                              ><small>{{ v.id }}</small>
                            </div>
                          </div>
                        </td>
                        <td>
                          <b>{{ v.doctor }}</b
                          ><small>{{ v.specialty }}</small>
                        </td>
                        <td>{{ v.service }}</td>
                        <td>
                          <span class="clinic-status" :class="v.kind">{{
                            v.status
                          }}</span>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </article>
              <article
                v-if="can('queues.view')"
                class="clinic-panel clinic-queue"
              >
                <div class="clinic-panel-head">
                  <h2>Navbatlar</h2>
                  <button class="clinic-link" @click="activeItem = 'Navbatlar'">
                    Barchasini ko‘rish <ArrowRight :size="16" />
                  </button>
                </div>
                <div
                  v-for="(q, i) in queue"
                  :key="q.patient"
                  class="clinic-queue-item"
                >
                  <span class="clinic-queue-number">{{ i + 1 }}</span
                  ><span class="clinic-small-avatar">{{ q.avatar }}</span>
                  <div class="clinic-queue-patient">
                    <b>{{ q.patient }}</b
                    ><small>{{ q.note }}</small>
                  </div>
                  <span class="clinic-small-avatar clinic-doctor-avatar">{{
                    q.dr
                  }}</span>
                  <div class="clinic-queue-doctor">
                    <b>{{ q.doctor }}</b
                    ><small>{{ q.specialty }}</small>
                  </div>
                  <span class="clinic-status" :class="q.kind">{{
                    q.status
                  }}</span>
                </div>
                <div v-if="can('cashier.history')" class="clinic-unpaid">
                  <TriangleAlert :size="24" />
                  <div>
                    <strong>To‘lanmagan hisoblar</strong
                    ><small
                      >Bugungi tashriflarda 3 ta to‘lanmagan xizmat
                      mavjud.</small
                    >
                  </div>
                  <button @click="activeItem = 'Kassa'">
                    Kassaga o‘tish <ArrowRight :size="15" />
                  </button>
                </div>
              </article>
            </section>
          </template>
          <RegistraturaView
            v-access-surface="'reception'"
            v-else-if="activeItem === 'Registratura'"
          />
          <SettingsView
            ref="settingsView"
            v-else-if="activeItem === 'Sozlamalar'"
          />
          <CashierView
            v-access-surface="'cashier'"
            v-else-if="activeItem === 'Kassa'"
          />
          <LaboratoryView
            v-access-surface="'laboratory'"
            ref="laboratoryView"
            v-else-if="activeItem === 'Laboratoriya'"
          />
          <DiagnosticsView
            v-access-surface="'diagnostics'"
            ref="diagnosticsView"
            v-else-if="activeItem === 'UZI va Diagnostika'"
          />
          <ServicesView
            v-access-surface="'services'"
            v-else-if="activeItem === 'Xizmatlar'"
          />
          <BemorlarView v-else-if="activeItem === 'Bemorlar'" />
          <XodimlarView v-else-if="activeItem === 'Xodimlar'" />
          <DepartmentsRoomsView
            v-access-surface="'rooms'"
            v-else-if="activeItem === 'Bo‘limlar va Xonalar'"
          />
          <DoctorWorkspaceView
            v-access-surface="'workspace'"
            v-else-if="activeItem === 'Shifokor kabineti'"
          />
          <QabullarView
            v-access-surface="'appointments'"
            v-else-if="activeItem === 'Qabullar'"
          />
          <NavbatlarView
            v-access-surface="'queues'"
            v-else-if="activeItem === 'Navbatlar'"
            @open-tablo="activeItem = 'Tablo boshqaruvi'"
          />
          <TabloBoshqaruviView
            v-access-surface="'display'"
            v-else-if="activeItem === 'Tablo boshqaruvi'"
            @open-navbatlar="activeItem = 'Navbatlar'"
          />
          <div v-else class="clinic-placeholder">
            <div class="clinic-placeholder-icon"><BedDouble :size="38" /></div>
            <h1>{{ activeItem }}</h1>
            <p>
              Bu bo‘limning Figma maketini keyingi bosqichda verstka qilamiz.
            </p>
            <button @click="activeItem = 'Bosh sahifa'">
              <ChevronRight :size="17" /> Bosh sahifaga qaytish
            </button>
          </div>
        </template>
      </main>
    </div>
  </div>
</template>

<style scoped>
.clinic-shell {
  --ink: #101f42;
  --muted: #7890b3;
  --blue: #2376fb;
  --line: #e6edf8;
  display: flex;
  width: 100%;
  min-height: 100vh;
  background: #f7faff;
  color: var(--ink);
  font-family: Inter, Arial, sans-serif;
  font-size: 14px;
}
.clinic-shell * {
  box-sizing: border-box;
}
.clinic-shell button {
  font-family: inherit;
  cursor: pointer;
}
.clinic-sidebar {
  width: 270px;
  flex: none;
  background: #fff;
  border-right: 1px solid #eaf0f9;
  min-height: 100vh;
}
.clinic-logo {
  height: 78px;
  display: flex;
  align-items: center;
  gap: 13px;
  padding: 14px 30px;
}
.clinic-mark {
  width: 37px;
  height: 49px;
  display: block;
  position: relative;
  flex: none;
}
.clinic-mark i,
.clinic-mark b {
  position: absolute;
  display: block;
  left: 0;
  width: 37px;
  height: 27px;
  border-radius: 5px 16px 6px 13px;
  transform: skewY(10deg);
}
.clinic-mark i {
  top: 0;
  background: linear-gradient(130deg, #1863fb, #24baff);
}
.clinic-mark b {
  bottom: 1px;
  background: linear-gradient(130deg, #00bfd8, #0cca96);
  border-top: 4px solid white;
}
.clinic-logo strong {
  display: block;
  font-size: 29px;
  font-weight: 800;
  letter-spacing: -1.2px;
  line-height: 1;
}
.clinic-logo small {
  display: block;
  font-size: 9px;
  color: #7c92b3;
  letter-spacing: 1.2px;
  font-weight: 800;
  margin-top: 5px;
}
.clinic-menu {
  padding: 13px 13px 30px;
}
.clinic-nav-group {
  margin-bottom: 17px;
}
.clinic-group-label {
  font-size: 11px;
  color: #84a0ca;
  letter-spacing: 1px;
  font-weight: 800;
  padding: 0 14px;
  margin: 16px 0 8px;
}
.clinic-nav-item {
  width: 100%;
  border: 0;
  background: transparent;
  color: #253d64;
  height: 41px;
  padding: 0 14px;
  display: flex;
  gap: 17px;
  align-items: center;
  text-align: left;
  border-radius: 8px;
  font-size: 14px;
  margin: 1px 0;
}
.clinic-nav-item svg {
  color: #355680;
  flex: none;
}
.clinic-nav-item:hover {
  background: #f1f6ff;
}
.clinic-nav-item.selected {
  color: white;
  background: #2376fb;
}
.clinic-nav-item.selected svg {
  color: white;
}
.clinic-workspace {
  flex: 1;
  min-width: 0;
}
.clinic-topbar {
  height: 78px;
  background: white;
  border-bottom: 1px solid var(--line);
  display: flex;
  align-items: center;
  gap: 21px;
  padding: 0 28px;
}
.clinic-icon-btn {
  background: transparent;
  border: 0;
  color: #375478;
  display: grid;
  place-items: center;
  position: relative;
  padding: 6px;
  flex: none;
}
.clinic-selector {
  background: #f7faff;
  border: 0;
  border-radius: 10px;
  padding: 12px 14px;
  color: #172b4e;
  display: flex;
  align-items: center;
  gap: 12px;
  white-space: nowrap;
  font-weight: 600;
}
.clinic-selector svg:last-child {
  margin-left: 12px;
}
.clinic-search {
  height: 43px;
  max-width: 490px;
  margin: auto;
  min-width: 170px;
  flex: 1;
  display: flex;
  align-items: center;
  gap: 13px;
  border: 1px solid var(--line);
  background: #f8faff;
  border-radius: 10px;
  color: #8197b6;
  padding: 0 14px;
  white-space: nowrap;
  overflow: hidden;
}
.clinic-search span {
  overflow: hidden;
  text-overflow: ellipsis;
}
.clinic-search kbd {
  margin-left: auto;
  flex: none;
  font-size: 11px;
  padding: 4px 7px;
  border: 1px solid #e7eef8;
  border-radius: 6px;
  color: #8399b7;
}
.clinic-notify span {
  position: absolute;
  right: 1px;
  top: -2px;
  background: #ff2840;
  color: white;
  border-radius: 100%;
  font-size: 10px;
  min-width: 16px;
  height: 16px;
  display: grid;
  place-items: center;
}
.clinic-user {
  display: flex;
  gap: 10px;
  align-items: center;
  white-space: nowrap;
}
.clinic-user strong,
.clinic-user small {
  display: block;
}
.clinic-user strong {
  font-size: 13px;
}
.clinic-user small {
  font-size: 12px;
  color: #8ba0bd;
  margin-top: 3px;
}
.clinic-user svg {
  margin-left: 15px;
  color: #426289;
}
.clinic-avatar,
.clinic-small-avatar {
  display: grid;
  place-items: center;
  border-radius: 50%;
  color: #285277;
  background: linear-gradient(135deg, #dfebff, #b0e4e8);
  font-weight: 700;
  flex: none;
}
.clinic-avatar {
  height: 40px;
  width: 40px;
}
.clinic-main {
  padding: 22px 25px 30px;
  max-width: 1750px;
  margin: 0 auto;
}
.clinic-demo-note {
  padding: 8px 12px;
  border: 1px solid #cfe1ff;
  border-radius: 8px;
  color: #53739e;
  background: #f0f6ff;
  margin-bottom: 17px;
  font-size: 12px;
}
.clinic-demo-note span {
  font-weight: 800;
  color: #2066cb;
  margin-right: 10px;
}
.clinic-page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 14px;
  margin: 6px 8px 24px;
}
.clinic-page-head h1 {
  font-size: 29px;
  line-height: 1.2;
  letter-spacing: -0.7px;
  margin: 0 0 4px;
  font-weight: 800;
}
.clinic-page-head p {
  margin: 0;
  color: #8499b9;
  font-size: 16px;
}
.clinic-filters {
  display: flex;
  gap: 12px;
}
.clinic-filters button,
.clinic-panel-head button {
  height: 42px;
  display: flex;
  align-items: center;
  gap: 11px;
  border: 1px solid #dce7f8;
  background: white;
  border-radius: 9px;
  padding: 0 12px;
  color: #30486e;
  font-size: 13px;
  white-space: nowrap;
}
.clinic-stats {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 18px;
}
.clinic-stat {
  height: 131px;
  border-radius: 12px;
  padding: 20px 14px;
  display: flex;
  align-items: flex-start;
  gap: 12px;
  min-width: 0;
}
.clinic-stat.sky {
  background: #edf6ff;
}
.clinic-stat.mint {
  background: #eafbf5;
}
.clinic-stat.amber {
  background: #fff7e7;
}
.clinic-stat.rose {
  background: #fff0f3;
}
.clinic-stat.violet {
  background: #f6f1ff;
}
.clinic-stat-icon {
  width: 50px;
  height: 50px;
  flex: none;
  border-radius: 14px;
  display: grid;
  place-items: center;
}
.sky .clinic-stat-icon {
  color: #2376fb;
  background: #dbeeff;
}
.mint .clinic-stat-icon {
  color: #04b996;
  background: #d3f7e9;
}
.amber .clinic-stat-icon {
  color: #ed9816;
  background: #ffedbf;
}
.rose .clinic-stat-icon {
  color: #ed3747;
  background: #ffd6dc;
}
.violet .clinic-stat-icon {
  color: #8047ea;
  background: #e8ddff;
}
.clinic-stat-detail {
  min-width: 0;
}
.clinic-stat-label {
  display: block;
  color: #536689;
  white-space: nowrap;
  font-size: 12px;
  margin: 4px 0 9px;
}
.rose .clinic-stat-label {
  color: #b42b47;
}
.clinic-stat-value {
  font-size: 26px;
  font-weight: 800;
  white-space: nowrap;
  letter-spacing: -0.5px;
  line-height: 1.1;
}
.clinic-stat-value small {
  font-weight: 500;
  font-size: 13px;
  color: #496185;
  letter-spacing: 0;
}
.clinic-stat-value em {
  font-size: 13px;
  color: #0ab98e;
  font-style: normal;
  letter-spacing: 0;
  font-weight: 700;
  margin-left: 3px;
}
.clinic-stat-note {
  display: block;
  color: #778cad;
  font-size: 12px;
  white-space: nowrap;
  margin-top: 10px;
}
.clinic-analytics {
  display: grid;
  grid-template-columns: 1.6fr 1fr;
  gap: 18px;
  margin-bottom: 18px;
}
.clinic-panel {
  background: white;
  border-radius: 12px;
  border: 1px solid #e7eef7;
  min-width: 0;
  overflow: hidden;
  box-shadow: 0 5px 20px #d6e5f721;
}
.clinic-activity,
.clinic-services {
  padding: 16px 20px;
  height: 275px;
}
.clinic-panel-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
}
.clinic-panel-head h2 {
  font-size: 18px;
  font-weight: 800;
  letter-spacing: -0.3px;
  margin: 0;
}
.clinic-panel-head button {
  height: 33px;
  background: #fff;
  font-size: 12px;
}
.clinic-line-chart {
  height: 154px;
  display: flex;
  gap: 7px;
}
.clinic-ylabels {
  height: 135px;
  width: 24px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  align-items: end;
  color: #8ca2c2;
  font-size: 11px;
}
.clinic-plot {
  min-width: 0;
  flex: 1;
}
.clinic-plot svg {
  width: 100%;
  height: 135px;
  display: block;
  overflow: visible;
}
.clinic-xlabels {
  display: flex;
  justify-content: space-between;
  color: #89a0c1;
  font-size: 11px;
  margin-top: 5px;
}
.clinic-legend {
  display: flex;
  gap: 23px;
  justify-content: center;
  margin-top: 11px;
  color: #728aae;
  font-size: 12px;
}
.clinic-legend span {
  display: flex;
  align-items: center;
  gap: 7px;
}
.clinic-legend i {
  width: 10px;
  height: 10px;
  border-radius: 50%;
}
.blue-dot {
  background: #2376fb;
}
.green-dot {
  background: #05b99c;
}
.clinic-donut-row {
  display: flex;
  align-items: center;
  gap: 28px;
  justify-content: space-around;
  height: 189px;
}
.clinic-donut {
  width: 174px;
  height: 174px;
  flex: none;
  display: grid;
  place-items: center;
  border-radius: 50%;
  background: conic-gradient(
    #2477ff 0 45%,
    #04b99b 45% 65%,
    #ffbb45 65% 83%,
    #b78bfa 83% 93%,
    #a1b5d0 93% 100%
  );
  position: relative;
}
.clinic-donut::after {
  content: "";
  position: absolute;
  inset: 43px;
  background: white;
  border-radius: 50%;
}
.clinic-donut > div {
  z-index: 1;
  text-align: center;
}
.clinic-donut strong {
  display: block;
  font-size: 23px;
}
.clinic-donut small {
  display: block;
  color: #7187a5;
  font-size: 12px;
}
.clinic-donut-legend {
  flex: 1;
  min-width: 0;
}
.clinic-donut-legend > div {
  display: flex;
  align-items: center;
  gap: 9px;
  margin-bottom: 16px;
  color: #51698c;
  font-size: 12px;
  white-space: nowrap;
}
.clinic-donut-legend i {
  width: 12px;
  height: 12px;
  border-radius: 3px;
  flex: none;
}
.clinic-donut-legend span {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
}
.clinic-donut-legend b {
  color: #172848;
}
.clinic-bottom {
  display: grid;
  grid-template-columns: 1.23fr 1fr;
  gap: 18px;
}
.clinic-appointments,
.clinic-queue {
  padding: 14px 13px 16px;
  min-height: 341px;
}
.clinic-appointments .clinic-panel-head,
.clinic-queue .clinic-panel-head {
  padding: 0 5px;
}
.clinic-panel-head button.clinic-link {
  background: #f1f7ff;
  border: 0;
  color: #2376fb;
  font-weight: 600;
}
.clinic-table-wrap {
  overflow-x: auto;
}
.clinic-table-wrap table {
  border-collapse: collapse;
  width: 100%;
  font-size: 12px;
  text-align: left;
}
.clinic-table-wrap th {
  background: #f5f9ff;
  color: #536d96;
  font-weight: 700;
  padding: 11px 9px;
  white-space: nowrap;
}
.clinic-table-wrap td {
  border-bottom: 1px solid #ebf1f8;
  padding: 9px;
  white-space: nowrap;
  color: #2e4265;
}
.clinic-table-wrap tr:last-child td {
  border-bottom: 0;
}
.clinic-table-wrap b,
.clinic-queue-item b {
  font-size: 12px;
  font-weight: 700;
  display: block;
}
.clinic-table-wrap small,
.clinic-queue-item small {
  display: block;
  font-size: 11px;
  color: #7893b9;
  margin-top: 2px;
}
.clinic-patient {
  display: flex;
  align-items: center;
  gap: 8px;
}
.clinic-small-avatar {
  width: 30px;
  height: 30px;
  font-size: 9px;
}
.clinic-status {
  display: inline-block;
  padding: 7px 9px;
  border-radius: 9px;
  font-size: 11px;
  white-space: nowrap;
}
.clinic-status.success {
  background: #dff9ee;
  color: #079f75;
}
.clinic-status.info {
  background: #e4f0ff;
  color: #2376fb;
}
.clinic-status.warning {
  background: #fff0d1;
  color: #d47912;
}
.clinic-status.neutral {
  background: #edf1f7;
  color: #7185a2;
}
.clinic-queue-item {
  display: flex;
  align-items: center;
  gap: 8px;
  border-bottom: 1px solid #edf2f8;
  padding: 9px 3px;
  min-width: 0;
}
.clinic-queue-number {
  flex: none;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: #f0f6ff;
  color: #5a759b;
  display: grid;
  place-items: center;
}
.clinic-queue-patient,
.clinic-queue-doctor {
  min-width: 0;
  flex: 1;
}
.clinic-queue-item b,
.clinic-queue-item small {
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.clinic-doctor-avatar {
  background: linear-gradient(135deg, #fee8df, #a6d9f2);
}
.clinic-unpaid {
  display: flex;
  align-items: center;
  gap: 14px;
  background: #fff1f4;
  border-radius: 10px;
  padding: 12px;
  margin-top: 14px;
  color: #ed3d4b;
}
.clinic-unpaid > svg {
  flex: none;
}
.clinic-unpaid > div {
  flex: 1;
}
.clinic-unpaid strong {
  display: block;
  font-size: 14px;
}
.clinic-unpaid small {
  display: block;
  color: #876c79;
  margin-top: 5px;
  font-size: 11px;
}
.clinic-unpaid button {
  background: #fffafa;
  border: 1px solid #ffd6dd;
  color: #dc3145;
  border-radius: 7px;
  padding: 8px 9px;
  display: flex;
  gap: 7px;
  align-items: center;
  white-space: nowrap;
  font-size: 11px;
}
.clinic-placeholder {
  padding: 75px 30px;
  text-align: center;
  border: 1px solid #e6edf8;
  background: white;
  border-radius: 15px;
}
.clinic-placeholder-icon {
  display: inline-grid;
  place-items: center;
  border-radius: 15px;
  padding: 22px;
  background: #eaf3ff;
  color: #2376fb;
}
.clinic-placeholder h1 {
  font-size: 26px;
}
.clinic-placeholder p {
  color: #7188a9;
}
.clinic-placeholder button {
  border: 0;
  border-radius: 8px;
  background: #2376fb;
  color: white;
  padding: 12px 18px;
  display: inline-flex;
  align-items: center;
  gap: 7px;
}
.side-collapsed .clinic-sidebar {
  width: 0;
  overflow: hidden;
  border: 0;
}
@media (max-width: 1400px) {
  .clinic-stat {
    padding: 16px 9px;
    gap: 8px;
  }
  .clinic-stat-icon {
    width: 42px;
    height: 42px;
  }
  .clinic-stat-value {
    font-size: 22px;
  }
  .clinic-stat-label,
  .clinic-stat-note {
    font-size: 11px;
  }
  .clinic-donut {
    width: 145px;
    height: 145px;
  }
  .clinic-donut::after {
    inset: 37px;
  }
  .clinic-donut-row {
    gap: 15px;
  }
  .clinic-topbar {
    gap: 11px;
  }
  .clinic-user svg {
    margin-left: 2px;
  }
}
@media (max-width: 1130px) {
  .clinic-sidebar {
    width: 230px;
  }
  .clinic-main {
    padding: 20px 14px;
  }
  .clinic-stats {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
  .clinic-bottom,
  .clinic-analytics {
    grid-template-columns: 1fr;
  }
  .clinic-services {
    height: 245px;
  }
  .clinic-topbar {
    padding: 0 12px;
  }
  .clinic-user div {
    display: none;
  }
}
@media (max-width: 690px) {
  .clinic-sidebar {
    display: none;
  }
  .clinic-topbar {
    height: 64px;
  }
  .clinic-selector {
    font-size: 11px;
    gap: 5px;
    padding: 8px;
  }
  .clinic-search,
  .clinic-user {
    display: none;
  }
  .clinic-main {
    padding: 12px;
  }
  .clinic-page-head {
    display: block;
  }
  .clinic-filters {
    margin-top: 16px;
    flex-wrap: wrap;
  }
  .clinic-stats {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .clinic-stat {
    height: 120px;
  }
  .clinic-queue-item {
    gap: 5px;
  }
  .clinic-queue-doctor,
  .clinic-doctor-avatar {
    display: none;
  }
}

/* Dashboard typography and responsive layout */
.clinic-shell {
  font-family: Inter, Arial, sans-serif;
  font-size: 13px;
}

.clinic-sidebar {
  width: 250px;
}

.clinic-topbar {
  height: 76px;
}

.clinic-nav-item {
  height: 39px;
  font-size: 13px;
  gap: 14px;
}

.clinic-group-label {
  font-size: 10px;
  letter-spacing: 0.8px;
}

.clinic-page-head h1 {
  font-size: 27px;
}

.clinic-page-head p {
  font-size: 14px;
}

.clinic-stat {
  padding: 17px 12px;
  gap: 10px;
}

.clinic-stat-value {
  font-size: clamp(19px, 1.5vw, 25px);
}

.clinic-stat-value small {
  font-size: 11px;
}

.clinic-stat-value em {
  font-size: 11px;
}

.clinic-main {
  max-width: 1700px;
  padding: 20px 22px 30px;
}

@media (min-width: 1600px) {
  .clinic-sidebar {
    width: 268px;
  }

  .clinic-main {
    padding-inline: 28px;
  }

  .clinic-stat {
    padding: 20px 15px;
  }
}

/* Profile dropdown: isolated from the rest of Dashboard */
.clinic-profile-wrap {
  position: relative;
  flex: none;
}
.clinic-profile-trigger {
  border: 0;
  background: transparent;
  padding: 0;
  color: inherit;
  cursor: pointer;
  text-align: left;
}
.clinic-profile-name strong,
.clinic-profile-name small {
  display: block;
}
.clinic-profile-menu {
  position: absolute;
  top: calc(100% + 17px);
  right: 0;
  width: 290px;
  padding: 14px;
  z-index: 100;
  background: #fff;
  border: 1px solid #e6edf8;
  border-radius: 12px;
  box-shadow: 0 14px 38px #142e5226;
}
.clinic-profile-title {
  padding: 5px 4px 13px;
  border-bottom: 1px solid #edf1f7;
}
.clinic-profile-title strong,
.clinic-profile-title small {
  display: block;
}
.clinic-profile-title strong {
  font-size: 14px;
}
.clinic-profile-title small {
  margin-top: 5px;
  color: #7890b3;
  font-size: 12px;
}
.clinic-profile-row {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 4px;
  font-size: 12px;
}
.clinic-profile-row span {
  color: #7890b3;
}
.clinic-profile-row strong {
  text-align: right;
}
.clinic-profile-exit {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  margin-top: 8px;
  padding: 12px;
  border: 0;
  border-radius: 8px;
  background: #fff0f2;
  color: #d72d43;
  font-weight: 600;
  cursor: pointer;
}
.clinic-profile-exit:hover {
  background: #ffe1e7;
}

/* Shared sidebar typography only; page content and navigation remain unchanged. */
.clinic-nav-item {
  font-size: 14px;
  font-weight: 500;
  min-height: 40px;
  height: 40px;
  gap: 13px;
  padding-inline: 14px;
}
.clinic-group-label {
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.25px;
  margin-top: 17px;
  margin-bottom: 7px;
}
.clinic-nav-group {
  margin-bottom: 14px;
}
@media (min-width: 761px) {
  .clinic-sidebar {
    width: 230px;
  }
  .clinic-menu {
    padding-inline: 13px;
  }
}
</style>
