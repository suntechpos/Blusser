<script setup lang="ts">
import { computed, ref } from "vue";
import {
  Users,
  CalendarDays,
  Clock3,
  CreditCard,
  ArrowRight,
} from "lucide-vue-next";

const props = defineProps<{ username: string }>();
const emit = defineEmits<{ navigate: [string] }>();
// A single synthetic source; never reads or filters the live Patients API.
const services = [
  "Konsultatsiya",
  "Laboratoriya",
  "UZI va Diagnostika",
  "Muolajalar",
  "Boshqa",
];
const colors = ["#2477ff", "#04b99b", "#ffbb45", "#b78bfa", "#8ca4c4"];
const source = Array.from({ length: 7 }, (_, day) =>
  Array.from({ length: 8 + day * 2 }, (_, i) => ({
    id: `demo-${day}-${i}`,
    date: `2026-10-${String(9 + day).padStart(2, "0")}`,
    branch: i % 2 ? "Yunusobod" : "Asosiy filial",
    service: services[i % 5]!,
    name: `Namunaviy bemor ${day + 1}-${i + 1}`,
    time: `${String(9 + Math.floor(i / 3)).padStart(2, "0")}:${String((i % 3) * 20).padStart(2, "0")}`,
    fee: 50000 + (i % 5) * 25000,
    status:
      i % 4 === 0 ? "Yakunlangan" : i % 4 === 1 ? "Qabulda" : "Kutilmoqda",
  })),
).flat();
const from = ref("2026-10-09"),
  to = ref("2026-10-15"),
  branch = ref(""),
  selectedDate = ref(""),
  selectedService = ref("");
const showPatients = ref(true),
  showRevenue = ref(true),
  tooltip = ref("");
const filtered = computed(() =>
  source.filter(
    (r) =>
      (!from.value || r.date >= from.value) &&
      (!to.value || r.date <= to.value) &&
      (!branch.value || r.branch === branch.value),
  ),
);
const days = computed(() =>
  [...new Set(filtered.value.map((r) => r.date))].sort().map((date) => ({
    date,
    count: filtered.value.filter((r) => r.date === date).length,
    revenue: filtered.value
      .filter((r) => r.date === date)
      .reduce((n, r) => n + r.fee, 0),
  })),
);
const breakdown = computed(() =>
  services
    .map((name, i) => ({
      name,
      color: colors[i],
      count: filtered.value.filter((r) => r.service === name).length,
    }))
    .filter((s) => s.count),
);
const detail = computed(() =>
  filtered.value.filter(
    (r) =>
      (!selectedDate.value || r.date === selectedDate.value) &&
      (!selectedService.value || r.service === selectedService.value),
  ),
);
const format = (n: number) => new Intl.NumberFormat("uz-UZ").format(n);
const metrics = computed(() => [
  {
    label: "Bemorlar",
    value: filtered.value.length,
    unit: "bemor",
    icon: Users,
  },
  {
    label: "Yakunlangan qabullar",
    value: filtered.value.filter((r) => r.status === "Yakunlangan").length,
    unit: "qabul",
    icon: CalendarDays,
  },
  {
    label: "Kutilayotganlar",
    value: filtered.value.filter((r) => r.status === "Kutilmoqda").length,
    unit: "bemor",
    icon: Clock3,
  },
  {
    label: "Namuna tushum",
    value: filtered.value.reduce((n, r) => n + r.fee, 0),
    unit: "so‘m",
    icon: CreditCard,
  },
]);
function points(key: "count" | "revenue") {
  const max = Math.max(1, ...days.value.map((d) => d[key]));
  return days.value
    .map((d, i) => `${x(i)},${160 - (d[key] / max) * 130}`)
    .join(" ");
}
const x = (i: number) => 40 + i * (540 / Math.max(1, days.value.length - 1));
function y(value: number, key: "count" | "revenue") {
  return 160 - (value / Math.max(1, ...days.value.map((d) => d[key]))) * 130;
}
function offset(index: number) {
  return (
    (-breakdown.value.slice(0, index).reduce((n, s) => n + s.count, 0) /
      Math.max(1, filtered.value.length)) *
    100
  );
}
function reset() {
  selectedDate.value = "";
  selectedService.value = "";
}
</script>
<template>
  <section class="legacy-ui overview">
    <header class="overview-head">
      <div>
        <h1>Xush kelibsiz, {{ props.username }}!</h1>
        <p>Klinika faoliyati — lokal namunaviy ma’lumotlar</p>
      </div>
      <div class="overview-filters">
        <label
          >Boshlanish sanasi<input
            v-model="from"
            type="date"
            @change="reset" /></label
        ><label
          >Tugash sanasi<input
            v-model="to"
            type="date"
            @change="reset" /></label
        ><label
          >Filial<select
            v-model="branch"
            aria-label="Dashboard filiali"
            @change="reset"
          >
            <option value="">Barcha filiallar</option>
            <option>Asosiy filial</option>
            <option>Yunusobod</option>
          </select></label
        >
      </div>
    </header>
    <p v-if="from > to" role="alert">Sana oralig‘ini tekshiring.</p>
    <div class="overview-metrics">
      <article v-for="m in metrics" :key="m.label">
        <component :is="m.icon" :size="26" />
        <div>
          <span>{{ m.label }}</span
          ><strong
            >{{ format(m.value) }} <small>{{ m.unit }}</small></strong
          >
        </div>
      </article>
    </div>
    <div class="overview-charts">
      <article class="overview-card">
        <h2>Klinika faolligi</h2>
        <div class="overview-legend">
          <button
            :aria-pressed="showPatients"
            @click="showPatients = !showPatients"
          >
            Bemorlar soni</button
          ><button
            :aria-pressed="showRevenue"
            @click="showRevenue = !showRevenue"
          >
            Tushum (so‘m)
          </button>
        </div>
        <p v-if="!days.length" class="overview-empty">
          Tanlangan davrda ma’lumot mavjud emas.
        </p>
        <template
          v-for="chart in [
            {
              key: 'count' as const,
              label: 'Bemorlar soni',
              unit: 'bemor',
              visible: showPatients,
              color: '#2477ff',
            },
            {
              key: 'revenue' as const,
              label: 'Tushum',
              unit: 'so‘m',
              visible: showRevenue,
              color: '#04a88c',
            },
          ]"
          :key="chart.key"
          ><div v-if="chart.visible && days.length" class="overview-chart">
            <p>
              {{ chart.label }} · {{ chart.unit }} · 0–{{
                format(Math.max(...days.map((d) => d[chart.key])))
              }}
            </p>
            <svg viewBox="0 0 620 190" role="group" :aria-label="chart.label">
              <path
                v-for="h in [30, 95, 160]"
                :key="h"
                :d="`M40 ${h}H580`"
                stroke="#e2eafa"
                stroke-dasharray="4 4"
              />
              <polyline
                :points="points(chart.key)"
                fill="none"
                :stroke="chart.color"
                stroke-width="3"
              />
              <g
                v-for="(d, i) in days"
                :key="d.date"
                role="button"
                tabindex="0"
                :aria-label="`${d.date}: ${chart.label} ${format(d[chart.key])} ${chart.unit}`"
                @mouseenter="
                  tooltip = `${d.date} · ${chart.label}: ${format(d[chart.key])} ${chart.unit}`
                "
                @focus="
                  tooltip = `${d.date} · ${chart.label}: ${format(d[chart.key])} ${chart.unit}`
                "
                @click="selectedDate = d.date"
                @keydown.enter="selectedDate = d.date"
                @keydown.space.prevent="selectedDate = d.date"
              >
                <circle
                  :cx="x(i)"
                  :cy="y(d[chart.key], chart.key)"
                  r="12"
                  fill="transparent"
                />
                <circle
                  :cx="x(i)"
                  :cy="y(d[chart.key], chart.key)"
                  r="5"
                  :fill="chart.color"
                />
                <text :x="x(i)" y="185" text-anchor="middle">
                  {{ d.date.slice(8) }} okt
                </text>
                <title>
                  {{ d.date }} · {{ chart.label }}: {{ format(d[chart.key]) }}
                  {{ chart.unit }}
                </title>
              </g>
            </svg>
          </div></template
        >
        <p role="status" class="overview-tooltip">
          {{
            tooltip ||
            "Nuqta ustiga kursor yoki fokus olib boring; tafsilot uchun tanlang."
          }}
        </p>
      </article>
      <article class="overview-card">
        <h2>Xizmatlar bo‘yicha</h2>
        <div v-if="filtered.length" class="overview-donut">
          <svg
            viewBox="0 0 200 200"
            role="group"
            aria-label="Xizmatlar taqsimoti"
          >
            <circle
              v-for="(s, i) in breakdown"
              :key="s.name"
              cx="100"
              cy="100"
              r="72"
              fill="none"
              :stroke="s.color"
              stroke-width="25"
              pathLength="100"
              :stroke-dasharray="`${(s.count / filtered.length) * 100} ${100 - (s.count / filtered.length) * 100}`"
              :stroke-dashoffset="offset(i)"
              transform="rotate(-90 100 100)"
              role="button"
              tabindex="0"
              :aria-label="`${s.name}: ${s.count} bemor`"
              @click="selectedService = s.name"
              @keydown.enter="selectedService = s.name"
              @keydown.space.prevent="selectedService = s.name"
            >
              <title>{{ s.name }}: {{ s.count }} bemor</title>
            </circle>
            <text
              x="100"
              y="100"
              text-anchor="middle"
              class="overview-donut-total"
            >
              {{ filtered.length }}
            </text>
            <text x="100" y="122" text-anchor="middle">bemor</text></svg
          ><button
            v-for="s in breakdown"
            :key="s.name"
            :aria-pressed="selectedService === s.name"
            @click="selectedService = selectedService === s.name ? '' : s.name"
          >
            <i :style="{ background: s.color }" />{{ s.name
            }}<b
              >{{ s.count }} ·
              {{ Math.round((s.count / filtered.length) * 100) }}%</b
            >
          </button>
        </div>
        <p v-else class="overview-empty">
          Xizmatlar bo‘yicha ma’lumot mavjud emas.
        </p>
      </article>
    </div>
    <article class="overview-card">
      <header class="overview-detail-head">
        <div>
          <h2>Qabullar tafsiloti</h2>
          <p>
            {{ selectedDate || "Tanlangan davr" }} ·
            {{ selectedService || "Barcha xizmatlar" }} · {{ detail.length }} ta
          </p>
        </div>
        <button v-if="selectedDate || selectedService" @click="reset">
          Tanlovni tozalash</button
        ><button @click="emit('navigate', 'Qabullar')">
          Barchasini ko‘rish <ArrowRight :size="16" />
        </button>
      </header>
      <div class="overview-table">
        <table>
          <thead>
            <tr>
              <th>Sana / vaqt</th>
              <th>Bemor</th>
              <th>Filial</th>
              <th>Xizmat</th>
              <th>Holat</th>
              <th>Namuna summa</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in detail.slice(0, 12)" :key="r.id">
              <td>{{ r.date }} · {{ r.time }}</td>
              <td>{{ r.name }}</td>
              <td>{{ r.branch }}</td>
              <td>{{ r.service }}</td>
              <td>{{ r.status }}</td>
              <td class="overview-money">{{ format(r.fee) }} so‘m</td>
            </tr>
            <tr v-if="!detail.length">
              <td colspan="6">Ma’lumot mavjud emas.</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="detail.length > 12">
        {{ detail.length }} ta namuna yozuvdan dastlabki 12 tasi.
      </p>
    </article>
    <article class="overview-card overview-detail-head">
      <h2>Navbatlar</h2>
      <p>
        {{ filtered.filter((r) => r.status === "Kutilmoqda").length }} ta
        namunaviy kutilayotgan yozuv
      </p>
      <button @click="emit('navigate', 'Navbatlar')">
        Barchasini ko‘rish <ArrowRight :size="16" />
      </button>
    </article>
  </section>
</template>
<style scoped>
.overview {
  display: grid;
  gap: 20px;
}
.overview-head,
.overview-filters,
.overview-detail-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
}
.overview h1,
.overview h2,
.overview p {
  margin: 0;
}
.overview-head p,
.overview-card > p {
  color: #6481aa;
  margin-top: 8px;
}
.overview-filters label {
  display: grid;
  gap: 5px;
}
.overview-filters input,
.overview-filters select {
  padding: 8px 10px;
  border: 1px solid #dce7fa;
  border-radius: 8px;
  background: white;
  color: #183559;
}
.overview-metrics {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 16px;
}
.overview-metrics article {
  padding: 20px;
  display: flex;
  gap: 16px;
  border: 1px solid #dfeafa;
  background: white;
  border-radius: 12px;
  align-items: center;
}
.overview-metrics svg {
  color: #1672ff;
  flex: none;
}
.overview-metrics strong {
  display: block;
  font-size: 24px;
  white-space: nowrap;
}
.overview-charts {
  display: grid;
  grid-template-columns: minmax(0, 1.7fr) minmax(280px, 1fr);
  gap: 20px;
}
.overview-card {
  padding: 20px;
  border: 1px solid #dfeafa;
  background: white;
  border-radius: 12px;
  min-width: 0;
}
.overview button {
  display: inline-flex;
  gap: 8px;
  align-items: center;
  padding: 8px 12px;
  border: 1px solid #dce7fa;
  border-radius: 7px;
  background: white;
  color: #176cff;
  cursor: pointer;
}
.overview-legend {
  display: flex;
  gap: 10px;
  margin: 14px 0;
}
.overview button[aria-pressed="false"] {
  opacity: 0.6;
}
.overview-chart svg {
  width: 100%;
  height: auto;
  max-height: 210px;
}
.overview-chart text {
  font:
    13px "Employees Inter",
    Arial;
  fill: #59759f;
}
.overview-chart p {
  font-weight: 600;
}
.overview-chart g {
  cursor: pointer;
}
.overview-chart g:focus-visible,
.overview-donut circle:focus-visible {
  outline: 2px solid #176cff;
  outline-offset: 2px;
}
.overview-tooltip {
  min-height: 42px;
  background: #f0f6ff;
  padding: 10px;
  border-radius: 8px;
  font-size: 13px;
}
.overview-donut svg {
  width: 100%;
  max-height: 220px;
}
.overview-donut text {
  font-size: 14px;
  fill: #14254a;
}
.overview-donut .overview-donut-total {
  font-size: 28px;
  font-weight: 700;
}
.overview-donut button {
  display: flex;
  width: 100%;
  margin-top: 8px;
  text-align: left;
  color: #21395e;
}
.overview-donut button b {
  margin-left: auto;
  white-space: nowrap;
}
.overview-donut i {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex: none;
}
.overview-donut circle {
  cursor: pointer;
}
.overview-table {
  overflow: auto;
  margin-top: 18px;
}
.overview-table table {
  min-width: 780px;
}
.overview-table td,
.overview-table th {
  border-bottom: 1px solid #e5edfa;
  text-align: left;
}
.overview-table th {
  background: #f3f7fd;
}
.overview-money {
  white-space: nowrap;
}
.overview-empty {
  padding: 30px;
  color: #6b86ab;
}
@media (max-width: 1200px) {
  .overview-metrics {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .overview-charts {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 600px) {
  .overview-metrics {
    grid-template-columns: 1fr;
  }
  .overview-filters {
    width: 100%;
    align-items: stretch;
  }
  .overview-filters label {
    width: 100%;
  }
}
</style>

<style scoped src="../../styles/legacy-pages.css"></style>
