<script setup lang="ts">
import { computed, ref, watch, onMounted } from "vue";
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
import BookingConfigurationDialog from "../components/reception/BookingConfigurationDialog.vue";
import "../components/reception/reception.css";
import EmployeeForm from "../components/employees/EmployeeForm.vue";
import ScheduleDialog from "../components/employees/ScheduleDialog.vue";
import ExceptionDialog from "../components/employees/ExceptionDialog.vue";
import {
  positions,
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
import { session, can } from "../services/session";
import { clinicRequest, errorText } from "../services/api";
import axios from "axios";
const managementOpen = ref(false),
  managementBranch = ref("");
const canManageBooking = computed(() =>
  [
    "employees.view",
    "doctors.view",
    "doctors.create",
    "rooms.view",
    "rooms.create",
    "schedules.view",
    "schedules.create",
    "services.view",
    "services.create",
  ].every((code) => session.grants?.[code] === "entire_clinic"),
);
const branches = computed(() => session.branches);
const branchLabel = (id: string) =>
  session.branches.find((b) => b.id === id)?.name ?? id;
const rolesStore = useRolesStore();
onMounted(() => rolesStore.refresh("employees"));
const employees = computed(() => rolesStore.state.value.employees);
const saveError = ref("");
import SettingsDialog from "../components/settings/SettingsDialog.vue";
const resetOpen = ref(false),
  resetCurrent = ref(""),
  resetPassword = ref("");
async function resetAccount() {
  if (!current.value?.membershipId || saving.value) return;
  saving.value = true;
  try {
    await clinicRequest(
      `employees/${current.value.membershipId}/password-reset`,
      {
        method: "POST",
        body: {
          current_password: resetCurrent.value,
          temporary_password: resetPassword.value,
        },
      },
    );
    resetOpen.value = false;
    saveError.value = "";
  } catch (e) {
    saveError.value = errorText(e);
  } finally {
    resetCurrent.value = "";
    resetPassword.value = "";
    saving.value = false;
  }
}
const successNotice = ref("");
async function linkAccount(
  e: Employee,
  pin?: { value: string; confirmation: string },
) {
  if (saving.value) return;
  saving.value = true;
  try {
    const id = await rolesStore.saveEmployee(
      { ...e, access: false, login: "", roleId: "" },
      undefined,
      pin,
    );
    const saved = rolesStore.state.value.employees.find(
      (row) => row.id === id,
    )!;
    await clinicRequest("links", {
      method: "POST",
      body: {
        login: e.login,
        role_id: e.roleId,
        branch_ids: e.branchIds ?? [e.branch],
        employee_id: id,
      },
      etag: `"${saved.version}"`,
    });
    screen.value = "list";
    successNotice.value =
      "Bog‘lash so‘rovi yuborildi. Hisob egasi rozilik bergach kirish ochiladi; parol o‘zgartirilmadi.";
    await rolesStore.refresh("employees");
  } catch (err) {
    saveError.value = errorText(err);
  } finally {
    saving.value = false;
  }
}
const committedEmployeeId = ref("");
const employeeRecoveryRequired = ref(false);
async function recoverEmployee() {
  await rolesStore.refresh("employees");
  const row = rolesStore.state.value.employees.find(
    (e) => e.id === committedEmployeeId.value,
  );
  if (!rolesStore.error.value && row) {
    editing.value = row;
    employeeRecoveryRequired.value = false;
    saveError.value =
      "Xodim serverdan qayta yuklandi. Jadvalni saqlashni davom ettirish mumkin.";
  }
}
const employeeForm = ref<InstanceType<typeof EmployeeForm>>();
const saving = ref(false);
const serverErrors = ref<Record<string, string>>({});
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
  managementBranch.value = e.branchIds?.includes(e.branch)
    ? e.branch
    : (e.branchIds?.[0] ?? "");
}
function form(e: Employee | null = null) {
  committedEmployeeId.value = "";
  employeeRecoveryRequired.value = false;
  editing.value = e ? clone(e) : null;
  screen.value = "form";
}
async function save(
  e: Employee,
  password?: string,
  pin?: { value: string; confirmation: string },
) {
  if (saving.value) return;
  saving.value = true;
  serverErrors.value = {};
  let employeeReloaded = false;
  try {
    const id = await rolesStore.saveEmployee(e, password, pin, (id) => {
      committedEmployeeId.value = id;
    });
    const saved = rolesStore.state.value.employees.find((row) => row.id === id);
    if (!saved)
      throw Error(
        "Xodim serverga yozildi, lekin qayta yuklanmadi. Sahifani yangilang; takroriy xodim yaratmang.",
      );
    employeeReloaded = true;
    editing.value = saved;
    await employeeForm.value?.commitSchedule(saved);
    committedEmployeeId.value = "";
    screen.value = "list";
    saveError.value = "";
  } catch (err) {
    employeeRecoveryRequired.value =
      !!committedEmployeeId.value && !employeeReloaded;
    saveError.value = errorText(err);
    if (axios.isAxiosError(err))
      serverErrors.value = Object.fromEntries(
        Object.entries(err.response?.data?.errors ?? {}).map(([k, v]) => [
          (
            {
              role_id: "role",
              branch_id: "branch",
              temporary_password: "password",
            } as Record<string, string>
          )[k] ?? k.replace("profile.", ""),
          (v as string[])[0] ?? "Tekshiring.",
        ]),
      );
  } finally {
    saving.value = false;
  }
}
function saveSchedule(_value: Schedule) {
  saveError.value = "Xodim jadvali bu API oqimiga hali ulanmagan.";
}
function openException(e: Exception | null = null) {
  exception.value = e ? clone(e) : null;
  exceptionOpen.value = true;
}
function saveException(_value: Exception) {
  saveError.value = "Istisnolar bu API oqimiga hali ulanmagan.";
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
    <SettingsDialog
      v-if="resetOpen"
      title="Parolni almashtirish"
      @close="
        resetOpen = false;
        resetCurrent = '';
        resetPassword = '';
      "
      ><form @submit.prevent="resetAccount">
        <label
          >Joriy shaxsiy parolingiz<input
            v-model="resetCurrent"
            type="password"
            autocomplete="current-password"
            required /></label
        ><label
          >Yangi vaqtinchalik parol<input
            v-model="resetPassword"
            type="password"
            autocomplete="new-password"
            minlength="12"
            required
        /></label>
        <p>
          Hisob sessiyalari bekor qilinadi. Xodim keyingi kirishda vaqtinchalik
          parolni almashtiradi.
        </p>
        <p v-if="saveError" role="alert">{{ saveError }}</p>
        <footer>
          <button
            type="button"
            class="e-button"
            @click="
              resetOpen = false;
              resetCurrent = '';
              resetPassword = '';
            "
          >
            Bekor qilish</button
          ><button class="e-button primary" :disabled="saving">
            Parolni tasdiqlash
          </button>
        </footer>
      </form></SettingsDialog
    >

    <p v-if="saveError || rolesStore.error.value" class="e-error" role="alert">
      {{ saveError || rolesStore.error.value }}
    </p>
    <button
      v-if="rolesStore.error.value"
      class="e-button"
      @click="rolesStore.refresh('employees')"
    >
      Qayta urinish
    </button>
    <p v-if="successNotice" role="status">{{ successNotice }}</p>
    <button
      v-if="employeeRecoveryRequired"
      type="button"
      class="e-button"
      @click="recoverEmployee"
    >
      Saqlangan xodimni qayta yuklash
    </button>
    <EmployeeForm
      ref="employeeForm"
      v-if="screen === 'form'"
      :employee="editing"
      :saving="saving || employeeRecoveryRequired"
      :server-errors="serverErrors"
      @cancel="screen = 'list'"
      @save="save"
      @link="linkAccount"
    /><template v-else-if="rolesStore.loading.value"
      ><p role="status">Xodimlar yuklanmoqda…</p></template
    ><template v-else-if="screen === 'list'"
      ><div class="e-page-head">
        <div>
          <h1>Xodimlar</h1>
          <p>Klinika xodimlari va ularning ma’lumotlarini boshqaring.</p>
        </div>
        <button
          class="e-button primary"
          v-if="can('employees.create')"
          @click="form()"
        >
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
            <option v-for="b in branches" :key="b.id" :value="b.id">
              {{ b.name }}
            </option></select
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
                <td>{{ branchLabel(e.branch) }}</td>
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
          <template v-if="canManageBooking && current.membershipId">
            <select
              v-model="managementBranch"
              aria-label="Shifokor jadvali filiali"
              class="e-select"
            >
              <option value="">Filialni tanlang</option>
              <option
                v-for="b in branches.filter(
                  (b) => b.active && current?.branchIds?.includes(b.id),
                )"
                :key="b.id"
                :value="b.id"
              >
                {{ b.name }}
              </option>
            </select>
            <button
              class="e-button"
              :disabled="!managementBranch"
              @click="managementOpen = true"
            >
              Shifokor profili / ish jadvali
            </button>
          </template>
          <button class="e-button" @click="screen = 'list'">
            <ArrowLeft :size="19" />Orqaga</button
          ><button
            class="e-button primary"
            v-if="can('employees.update')"
            @click="form(current)"
          >
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
                  {
                    icon: Building2,
                    label: 'Filial',
                    value: branchLabel(current.branch),
                  },
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
            <button
              v-if="current.membershipId && can('employees.reset_password')"
              class="e-button"
              @click="resetOpen = true"
            >
              Parolni almashtirish
            </button>
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
              ><button
                class="e-button primary"
                disabled
                title="Jadval bu API oqimiga hali ulanmagan"
                @click="scheduleOpen = true"
              >
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
            <button
              class="e-button"
              disabled
              title="Istisnolar bu API oqimiga hali ulanmagan"
              @click="openException()"
            >
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
    <BookingConfigurationDialog
      v-if="managementOpen && current?.membershipId"
      :branch-id="managementBranch"
      :membership-id="current.membershipId"
      :date="today()"
      @close="managementOpen = false"
    />
    <p class="e-hint employee-demo-disclosure">
      Xodim profili, hisob va ruxsatlar server orqali saqlanadi. Shifokorning
      haqiqiy qabul jadvali “Shifokor profili / ish jadvali” orqali
      boshqariladi. Umumiy xodim jadvali va istisnolar hali ulanmagan.
    </p>
  </div>
</template>
<style src="../components/employees/employees.css"></style>
