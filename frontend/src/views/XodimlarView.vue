<script setup lang="ts">
import { computed, ref, watch } from "vue";
import {
  Users,
  UserRound,
  Plus,
  Search,
  ChevronRight,
  ChevronLeft,
  ArrowLeft,
  Pencil,
  Building2,
  CalendarDays,
  Clock3,
  Phone,
  MapPin,
  Shield,
  LockKeyhole,
  Stethoscope,
  BadgeCheck,
  Info,
  FileText,
} from "lucide-vue-next";
import EmployeeForm from "../components/employees/EmployeeForm.vue";
import ScheduleDialog from "../components/employees/ScheduleDialog.vue";
import ExceptionDialog from "../components/employees/ExceptionDialog.vue";
import {
  positions,
  branches,
  fullName,
  initials,
  dateLabel,
  elapsed,
  today,
  clone,
  type Employee,
  type Schedule,
  type Exception,
} from "../mocks/employees";
import { useRolesStore } from "../components/settings/store";
const rolesStore = useRolesStore();
const employees = computed(() => rolesStore.state.value.employees);
const saveError = ref("");
function changeEmployees(fn: (es: Employee[]) => void) {
  try {
    rolesStore.transact((s) => fn(s.employees));
    saveError.value = "";
    return true;
  } catch (e) {
    saveError.value = (e as Error).message;
    return false;
  }
}
function roleLabel(e: Employee) {
  return (
    rolesStore.state.value.roles.find((r) => r.id === e.roleId)?.name ??
    (e.role || "—")
  );
}
const screen = ref<"list" | "form" | "profile">("list");
const selected = ref("");
const editing = ref<Employee | null>(null);
const search = ref(""),
  position = ref(""),
  branch = ref(""),
  status = ref("");
const page = ref(1);
const tab = ref("general");
const scheduleOpen = ref(false),
  exceptionOpen = ref(false);
const exception = ref<Exception | null>(null);
const current = computed(() =>
  employees.value.find((e) => e.id === selected.value),
);
const filtered = computed(() =>
  employees.value.filter((e) => {
    const q = search.value.toLocaleLowerCase().trim();
    return (
      (!q ||
        fullName(e).toLocaleLowerCase().includes(q) ||
        e.phone
          .replace(/\D/g, "")
          .includes(q.replace(/\D/g, "") || "not-a-phone")) &&
      (!position.value || e.position === position.value) &&
      (!branch.value || e.branch === branch.value) &&
      (!status.value || String(e.active) === status.value)
    );
  }),
);
const pages = computed(() => Math.max(1, Math.ceil(filtered.value.length / 9)));
const visible = computed(() =>
  filtered.value.slice((page.value - 1) * 9, page.value * 9),
);
const active = computed(() => employees.value.filter((e) => e.active).length);
watch([search, position, branch, status], () => (page.value = 1));
watch(pages, (n) => (page.value = Math.min(page.value, n)));
function open(e: Employee) {
  selected.value = e.id;
  screen.value = "profile";
  tab.value = "general";
}
function form(e: Employee | null = null) {
  editing.value = e ? clone(e) : null;
  screen.value = "form";
}
function save(e: Employee) {
  const ok = changeEmployees((es) => {
    if (e.id) {
      const i = es.findIndex((x) => x.id === e.id);
      es[i] = e;
    } else {
      e.id = `X${String(Math.max(0, ...es.map((x) => Number(x.id.slice(1)) || 0)) + 1).padStart(3, "0")}`;
      es.push(e);
    }
  });
  if (ok) screen.value = "list";
}
function saveSchedule(value: Schedule) {
  if (
    current.value &&
    changeEmployees((es) => {
      es.find((e) => e.id === selected.value)!.schedule = value;
    })
  )
    scheduleOpen.value = false;
}
function openException(e: Exception | null = null) {
  exception.value = e ? clone(e) : null;
  exceptionOpen.value = true;
}
function saveException(value: Exception) {
  if (
    current.value &&
    changeEmployees((es) => {
      const e = es.find((e) => e.id === selected.value)!;
      const i = e.exceptions.findIndex((x) => x.id === value.id);
      if (i < 0) e.exceptions.push(value);
      else e.exceptions[i] = value;
    })
  )
    exceptionOpen.value = false;
}
const colors = [
  "blue",
  "pink",
  "purple",
  "mint",
  "orange",
  "purple",
  "cyan",
  "pink",
  "mint",
];
const age = (date: string) => elapsed(date).split(" yil")[0];
</script>
<template>
  <div class="employees-ui">
    <p v-if="saveError || rolesStore.error.value" class="e-error" role="alert">
      {{ saveError || rolesStore.error.value }}
    </p>
    <EmployeeForm
      v-if="screen === 'form'"
      :employee="editing"
      @cancel="screen = 'list'"
      @save="save"
    /><template v-else-if="screen === 'list'"
      ><div class="e-page-head">
        <div>
          <h1>Xodimlar</h1>
          <p>Klinika xodimlari va ularning ma’lumotlarini boshqaring.</p>
        </div>
        <button class="e-button primary" @click="form()">
          <Plus :size="20" />Xodim qo‘shish
        </button>
      </div>
      <section class="e-stats">
        <article
          v-for="(stat, i) in [
            { label: 'Jami', value: employees.length },
            { label: 'Faol', value: active },
            { label: 'Faol emas', value: employees.length - active },
          ]"
          :key="stat.label"
          class="e-card"
        >
          <span class="e-stat-icon" :class="['blue', 'mint', 'gray'][i]"
            ><Users v-if="i === 0" :size="28" /><UserRound v-else :size="28"
          /></span>
          <div>
            <span>{{ stat.label }}</span
            ><strong :class="{ green: i === 1 }">{{ stat.value }}</strong>
          </div>
        </article>
      </section>
      <section class="e-card e-list">
        <div class="e-filters">
          <label class="e-search"
            ><Search :size="20" /><input
              v-model="search"
              aria-label="Xodimlarni qidirish"
              placeholder="F.I.Sh. yoki telefon bo‘yicha qidirish..." /></label
          ><select v-model="position" aria-label="Lavozim bo‘yicha filtr">
            <option value="">Barcha lavozimlar</option>
            <option v-for="p in positions" :key="p">{{ p }}</option></select
          ><select v-model="branch" aria-label="Filial bo‘yicha filtr">
            <option value="">Barcha filiallar</option>
            <option v-for="b in branches" :key="b">{{ b }}</option></select
          ><select v-model="status" aria-label="Holat bo‘yicha filtr">
            <option value="">Barcha holatlar</option>
            <option value="true">Faol</option>
            <option value="false">Faol emas</option>
          </select>
        </div>
        <div class="e-table-scroll">
          <table class="employee-list">
            <thead>
              <tr>
                <th>Xodim</th>
                <th>Telefon</th>
                <th>Lavozim</th>
                <th>Bo‘lim</th>
                <th>Filial</th>
                <th>Tizimga kirish</th>
                <th>Holati</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(e, i) in visible"
                :key="e.id"
                tabindex="0"
                :aria-label="fullName(e) + ' profilini ochish'"
                @click="open(e)"
                @keydown.enter="open(e)"
                @keydown.space.prevent="open(e)"
              >
                <td>
                  <div class="e-person">
                    <span class="e-avatar" :class="colors[i % 9]"
                      ><img v-if="e.photo" :src="e.photo" alt="" /><template
                        v-else
                        >{{ initials(e) }}</template
                      ></span
                    >
                    <div>
                      <b>{{ fullName(e) }}</b
                      ><small>ID: {{ e.id }}</small>
                    </div>
                  </div>
                </td>
                <td>{{ e.phone || "—" }}</td>
                <td>
                  {{ e.position
                  }}<small v-if="e.specialty">{{ e.specialty }}</small>
                </td>
                <td>{{ e.department || "—" }}</td>
                <td>{{ e.branch }}</td>
                <td>
                  <span
                    class="e-badge"
                    :class="e.access && e.active ? 'blue' : 'gray'"
                    >{{
                      e.access && e.active ? "Ruxsat berilgan" : "Ruxsat yo‘q"
                    }}</span
                  >
                </td>
                <td>
                  <span class="e-badge" :class="e.active ? 'mint' : 'gray'">{{
                    e.active ? "Faol" : "Faol emas"
                  }}</span>
                </td>
                <td><ChevronRight :size="17" /></td>
              </tr>
              <tr v-if="!visible.length">
                <td colspan="8" class="e-empty">Xodim topilmadi.</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="e-pagination">
          <span
            >{{ filtered.length ? (page - 1) * 9 + 1 : 0 }} –
            {{ Math.min(page * 9, filtered.length) }} /
            {{ filtered.length }} xodim</span
          >
          <div>
            <button
              class="e-page"
              aria-label="Oldingi sahifa"
              :disabled="page === 1"
              @click="page--"
            >
              <ChevronLeft :size="17" /></button
            ><button
              v-for="n in pages"
              :key="n"
              class="e-page"
              :class="{ selected: page === n }"
              :aria-label="n + '-sahifa'"
              :aria-current="page === n ? 'page' : undefined"
              @click="page = n"
            >
              {{ n }}</button
            ><button
              class="e-page"
              aria-label="Keyingi sahifa"
              :disabled="page === pages"
              @click="page++"
            >
              <ChevronRight :size="17" />
            </button>
          </div>
        </div></section></template
    ><template v-else-if="current"
      ><div class="e-breadcrumb">
        <button @click="screen = 'list'">Xodimlar</button> /
        {{ fullName(current) }}
      </div>
      <div class="e-page-head">
        <div>
          <h1>{{ fullName(current) }}</h1>
          <p>Xodim ma’lumotlari va profil ko‘rinishi.</p>
        </div>
        <div class="e-actions">
          <button class="e-button" @click="screen = 'list'">
            <ArrowLeft :size="19" />Orqaga</button
          ><button class="e-button primary" @click="form(current)">
            <Pencil :size="18" />Tahrirlash
          </button>
        </div>
      </div>
      <section class="e-card e-profile-head">
        <span class="e-avatar large blue"
          ><img
            v-if="current.photo"
            :src="current.photo"
            alt="Xodim surati"
          /><template v-else>{{ initials(current) }}</template></span
        >
        <div>
          <h1>{{ fullName(current) }}</h1>
          <p>
            {{ current.position
            }}<template v-if="current.specialty">
              · {{ current.specialty }}</template
            >
          </p>
          <div class="e-profile-id">
            ID: {{ current.id }}
            <span class="e-badge" :class="current.active ? 'mint' : 'gray'">{{
              current.active ? "Faol" : "Faol emas"
            }}</span>
          </div>
        </div>
      </section>
      <div class="e-tabs" role="tablist">
        <button
          role="tab"
          :aria-selected="tab === 'general'"
          :class="{ selected: tab === 'general' }"
          @click="tab = 'general'"
        >
          Umumiy ma’lumotlar</button
        ><button
          role="tab"
          :aria-selected="tab === 'schedule'"
          :class="{ selected: tab === 'schedule' }"
          @click="tab = 'schedule'"
        >
          Ish jadvali
        </button>
      </div>
      <div v-if="tab === 'general'" class="e-profile-columns">
        <div>
          <section class="e-card">
            <h2>Ish ma’lumotlari</h2>
            <div class="e-detail-grid">
              <div
                v-for="item in [
                  {
                    icon: UserRound,
                    label: 'Lavozim',
                    value: current.position,
                  },
                  {
                    icon: Stethoscope,
                    label: 'Bo‘lim',
                    value: current.department,
                  },
                  { icon: Building2, label: 'Filial', value: current.branch },
                  {
                    icon: CalendarDays,
                    label: 'Ishga kirgan sana',
                    value: dateLabel(current.hired),
                  },
                  {
                    icon: Clock3,
                    label: 'Ishlash muddati',
                    value: elapsed(current.hired),
                  },
                  {
                    icon: UserRound,
                    label: 'Holati',
                    value: current.active ? 'Faol' : 'Faol emas',
                  },
                ]"
                :key="item.label"
                class="e-detail"
              >
                <span><component :is="item.icon" :size="23" /></span>
                <div>
                  <small>{{ item.label }}</small>
                  <div
                    :class="{
                      'e-badge mint': item.label === 'Holati' && current.active,
                    }"
                  >
                    {{ item.value || "—" }}
                  </div>
                </div>
              </div>
            </div>
          </section>
          <section class="e-card">
            <h2>Shaxsiy ma’lumotlar</h2>
            <div class="e-personal">
              <div
                v-for="item in [
                  { icon: Phone, label: 'Telefon', value: current.phone },
                  {
                    icon: CalendarDays,
                    label: 'Tug‘ilgan sana',
                    value: dateLabel(current.dob),
                  },
                  {
                    icon: UserRound,
                    label: 'Yoshi',
                    value:
                      current.dob && current.dob <= today()
                        ? age(current.dob) + ' yosh'
                        : '—',
                  },
                  { icon: UserRound, label: 'Jins', value: current.gender },
                  { icon: MapPin, label: 'Manzil', value: current.address },
                ]"
                :key="item.label"
                class="e-detail"
              >
                <span><component :is="item.icon" :size="23" /></span>
                <div>
                  <small>{{ item.label }}</small>
                  <div>{{ item.value || "—" }}</div>
                </div>
              </div>
            </div>
          </section>
          <section class="e-card e-note">
            <h2>Izoh</h2>
            <FileText :size="21" /><span>{{ current.note || "—" }}</span>
          </section>
        </div>
        <div>
          <section class="e-card">
            <h2>Tizimga kirish</h2>
            <div class="e-detail">
              <span><LockKeyhole :size="23" /></span><small>Ruxsat holati</small
              ><span
                class="e-badge"
                :class="current.access && current.active ? 'blue' : 'gray'"
                >{{
                  current.access && current.active
                    ? "Ruxsat berilgan"
                    : "Ruxsat yo‘q"
                }}</span
              >
            </div>
            <template v-if="current.access && current.active"
              ><div
                v-for="item in [
                  { icon: UserRound, label: 'Login', value: current.login },
                  {
                    icon: Shield,
                    label: 'Ruxsat roli',
                    value: roleLabel(current),
                  },
                ]"
                :key="item.label"
                class="e-detail"
              >
                <span><component :is="item.icon" :size="23" /></span>
                <div>
                  <small>{{ item.label }}</small>
                  <div>{{ item.value || "—" }}</div>
                </div>
              </div></template
            >
            <p v-else class="e-hint">Tizimga kirish ruxsati berilmagan</p>
          </section>
          <section v-if="current.position === 'Shifokor'" class="e-card">
            <h2>Kasbiy ma’lumotlar</h2>
            <div
              v-for="item in [
                {
                  icon: BadgeCheck,
                  label: 'Mutaxassislik',
                  value: current.specialty,
                },
                {
                  icon: Clock3,
                  label: 'Qabul davomiyligi',
                  value: current.duration + ' daqiqa',
                },
              ]"
              :key="item.label"
              class="e-detail"
            >
              <span><component :is="item.icon" :size="23" /></span>
              <div>
                <small>{{ item.label }}</small>
                <div>{{ item.value || "—" }}</div>
              </div>
            </div>
            <div class="e-info">
              <Info :size="27" /><span
                ><b>Qabul kunlari va kabinetlar</b><br />“Ish jadvali” bo‘limida
                boshqariladi.</span
              >
            </div>
          </section>
        </div>
      </div>
      <template v-else
        ><section class="e-card">
          <div class="e-section-head">
            <div>
              <h2>Haftalik ish jadvali</h2>
              <p v-if="current.schedule" class="e-hint">
                Amal qilish sanasi:
                {{ dateLabel(current.schedule.effective) }} dan
              </p>
            </div>
            <div class="e-actions">
              <span
                v-if="current.position === 'Shifokor'"
                class="e-badge blue duration"
                ><Clock3 :size="18" />Qabul: {{ current.duration }} daqiqa</span
              ><button class="e-button primary" @click="scheduleOpen = true">
                <Pencil :size="18" />{{
                  current.schedule ? "Jadvalni tahrirlash" : "Jadval yaratish"
                }}
              </button>
            </div>
          </div>
          <div v-if="current.schedule" class="e-table-scroll">
            <table class="schedule-display">
              <thead>
                <tr>
                  <th>Kun</th>
                  <th>Filial</th>
                  <th v-if="current.position === 'Shifokor'">Kabinet</th>
                  <th>Ish vaqti</th>
                  <th>Tanaffus</th>
                </tr>
              </thead>
              <tbody>
                <template
                  v-for="(day, index) in current.schedule.days"
                  :key="index"
                  ><tr
                    v-for="(shift, i) in day.enabled ? day.shifts : [null]"
                    :key="i"
                  >
                    <td>
                      {{
                        [
                          "Dushanba",
                          "Seshanba",
                          "Chorshanba",
                          "Payshanba",
                          "Juma",
                          "Shanba",
                          "Yakshanba",
                        ][index]
                      }}
                    </td>
                    <td>{{ shift?.branch || "—" }}</td>
                    <td v-if="current.position === 'Shifokor'">
                      {{ shift?.room || "—" }}
                    </td>
                    <td>
                      <template v-if="shift"
                        >{{ shift.start }} – {{ shift.end
                        }}<small v-if="shift.nextDay">
                          (keyingi kun)</small
                        ></template
                      ><span v-else class="e-badge gray">Dam olish</span>
                    </td>
                    <td>
                      {{
                        shift?.breakStart
                          ? shift.breakStart + " – " + shift.breakEnd
                          : "—"
                      }}
                    </td>
                  </tr></template
                >
              </tbody>
            </table>
          </div>
          <p v-else class="e-empty">
            Ish jadvali hali yaratilmagan. Jadval yaratish tugmasini bosing.
          </p>
        </section>
        <section class="e-card">
          <div class="e-section-head">
            <h2>Istisno sanalar</h2>
            <button class="e-button" @click="openException()">
              <Plus :size="20" />Istisno qo‘shish
            </button>
          </div>
          <div class="e-table-scroll">
            <table class="schedule-display">
              <thead>
                <tr>
                  <th>Sana</th>
                  <th>Sabab</th>
                  <th>Ish vaqti</th>
                  <th>Izoh</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="e in current.exceptions" :key="e.id">
                  <td>
                    {{ dateLabel(e.from)
                    }}<template v-if="e.to !== e.from">
                      – {{ dateLabel(e.to) }}</template
                    >
                  </td>
                  <td>{{ e.reason }}</td>
                  <td>
                    <span v-if="e.kind === 'off'" class="e-badge gray"
                      >Qabul yo‘q</span
                    ><template v-else
                      >{{ e.shift.start }} – {{ e.shift.end }}
                      {{ e.shift.nextDay ? "(keyingi kun)" : "" }}</template
                    >
                  </td>
                  <td>
                    {{
                      e.note ||
                      (e.kind === "changed"
                        ? e.shift.branch +
                          (current.position === "Shifokor"
                            ? " · " + e.shift.room
                            : "")
                        : "—")
                    }}
                  </td>
                  <td>
                    <button
                      class="e-icon"
                      :aria-label="
                        dateLabel(e.from) + ' istisnosini tahrirlash'
                      "
                      @click="openException(e)"
                    >
                      <Pencil :size="17" />
                    </button>
                  </td>
                </tr>
                <tr v-if="!current.exceptions.length">
                  <td colspan="5" class="e-empty">Istisno sanalar yo‘q.</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="e-hint e-info-line">
            <Info :size="17" />Istisno sanalar haftalik jadvaldan ustun turadi.
          </p>
        </section></template
      ><ScheduleDialog
        v-if="scheduleOpen"
        :employee="current"
        @close="scheduleOpen = false"
        @save="saveSchedule" /><ExceptionDialog
        v-if="exceptionOpen"
        :employee="current"
        :exception="exception"
        @close="exceptionOpen = false"
        @save="saveException"
    /></template>
    <p class="e-hint employee-demo-disclosure">
      Frontend namoyishi: barcha xodimlar namunaviy. O‘zgarishlar faqat joriy
      sahifa xotirasida; API va haqiqiy bazaga yozilmaydi.
    </p>
  </div>
</template>
<style src="../components/employees/employees.css"></style>
