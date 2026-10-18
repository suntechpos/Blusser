<script setup lang="ts">
import { computed, ref, watch } from "vue";
import {
  Building2,
  DoorOpen,
  Heart,
  Stethoscope,
  Brain,
  FlaskConical,
  Image,
  Ambulance,
  Accessibility,
  UsersRound,
  Plus,
  Search,
  Pencil,
  ChevronLeft,
  ChevronRight,
} from "lucide-vue-next";
import FacilityForm from "../components/departments/FacilityForm.vue";
import RoomIcon from "../components/departments/RoomIcon";
import "../components/employees/employees.css";
import {
  createFacilities,
  FACILITY_BRANCHES,
  normalizeName,
  type Department,
  type Room,
} from "../mocks/departmentsRooms";
const fixtures = createFacilities();
const departments = ref(fixtures.departments);
const rooms = ref(fixtures.rooms);
const tab = ref<"departments" | "rooms">("departments");
const departmentQuery = ref(""),
  departmentBranch = ref(""),
  departmentStatus = ref("");
const roomQuery = ref(""),
  roomBranch = ref(""),
  roomDepartment = ref(""),
  roomStatus = ref("");
const page = ref(1),
  departmentPage = ref(1);
const modal = ref<"department" | "room" | null>(null);
const editing = ref<Department | Room | null>(null);
const icons: Record<string, typeof Building2> = {
  heart: Heart,
  stethoscope: Stethoscope,
  baby: UsersRound,
  brain: Brain,
  flask: FlaskConical,
  scan: Image,
  ambulance: Ambulance,
  activity: Accessibility,
  building: Building2,
};
const filteredDepartments = computed(() =>
  departments.value.filter(
    (d) =>
      (!departmentQuery.value ||
        normalizeName(d.name).includes(normalizeName(departmentQuery.value))) &&
      (!departmentBranch.value || d.branch === departmentBranch.value) &&
      (!departmentStatus.value || String(d.active) === departmentStatus.value),
  ),
);
const availableDepartments = computed(() =>
  departments.value.filter(
    (d) => !roomBranch.value || d.branch === roomBranch.value,
  ),
);
watch(roomBranch, () => {
  if (!availableDepartments.value.some((d) => d.id === roomDepartment.value))
    roomDepartment.value = "";
});
const filteredRooms = computed(() =>
  rooms.value.filter(
    (r) =>
      (!roomQuery.value ||
        normalizeName(r.name).includes(normalizeName(roomQuery.value))) &&
      (!roomBranch.value || r.branch === roomBranch.value) &&
      (!roomDepartment.value || r.departmentId === roomDepartment.value) &&
      (!roomStatus.value || String(r.active) === roomStatus.value),
  ),
);
const pageCount = computed(() =>
  Math.max(1, Math.ceil(filteredRooms.value.length / 8)),
);
const departmentPageCount = computed(() =>
  Math.max(1, Math.ceil(filteredDepartments.value.length / 8)),
);
const displayedRooms = computed(() =>
  filteredRooms.value.slice((page.value - 1) * 8, page.value * 8),
);
const displayedDepartments = computed(() =>
  filteredDepartments.value.slice(
    (departmentPage.value - 1) * 8,
    departmentPage.value * 8,
  ),
);
watch(
  [roomQuery, roomBranch, roomDepartment, roomStatus],
  () => (page.value = 1),
);
watch(
  [departmentQuery, departmentBranch, departmentStatus],
  () => (departmentPage.value = 1),
);
watch(pageCount, (n) => (page.value = Math.min(page.value, n)));
watch(
  departmentPageCount,
  (n) => (departmentPage.value = Math.min(departmentPage.value, n)),
);
function count(d: Department) {
  return rooms.value.filter(
    (r) => r.departmentId === d.id && r.branch === d.branch,
  ).length;
}
function showRooms(d: Department) {
  roomQuery.value = "";
  roomStatus.value = "";
  roomBranch.value = d.branch;
  roomDepartment.value = d.id;
  tab.value = "rooms";
  page.value = 1;
}
function open(
  kind: "department" | "room",
  item: Department | Room | null = null,
) {
  editing.value = item ? { ...item } : null;
  modal.value = kind;
}
function saveDepartment(d: Department) {
  if (d.id) {
    const i = departments.value.findIndex((x) => x.id === d.id);
    departments.value[i] = d;
  } else {
    d.id = `B-${String(Math.max(...departments.value.map((x) => Number(x.id.slice(2)))) + 1).padStart(3, "0")}`;
    departments.value.push(d);
  }
  modal.value = null;
}
function saveRoom(r: Room) {
  if (r.id) {
    const i = rooms.value.findIndex((x) => x.id === r.id);
    rooms.value[i] = r;
  } else {
    r.id = `XN-${String(Math.max(...rooms.value.map((x) => Number(x.id.slice(3)))) + 1).padStart(3, "0")}`;
    rooms.value.push(r);
  }
  modal.value = null;
}
function switchTab(value: "departments" | "rooms") {
  tab.value = value;
}
function arrowTab(value: "departments" | "rooms") {
  switchTab(value);
  document
    .getElementById(value === "rooms" ? "rooms-tab" : "departments-tab")
    ?.focus();
}
</script>
<template>
  <section
    class="employees-ui dr-ui"
    aria-description="Frontend namoyishi: faqat xotirada, bazaga yozilmaydi."
  >
    <header class="dr-heading">
      <div>
        <h1>Bo‘limlar va Xonalar</h1>
        <p>Klinika bo‘limlari va xonalarini boshqaring.</p>
      </div>
      <button
        class="e-button primary"
        @click="open(tab === 'departments' ? 'department' : 'room')"
      >
        <Plus :size="20" />{{
          tab === "departments" ? "Bo‘lim qo‘shish" : "Xona qo‘shish"
        }}
      </button>
    </header>
    <div class="dr-tabs" role="tablist" aria-label="Bo‘limlar va xonalar">
      <button
        id="departments-tab"
        role="tab"
        :aria-selected="tab === 'departments'"
        :tabindex="tab === 'departments' ? 0 : -1"
        aria-controls="facilities-panel"
        @click="switchTab('departments')"
        @keydown.right.prevent="arrowTab('rooms')"
      >
        <Building2 :size="23" />Bo‘limlar</button
      ><button
        id="rooms-tab"
        role="tab"
        :aria-selected="tab === 'rooms'"
        :tabindex="tab === 'rooms' ? 0 : -1"
        aria-controls="facilities-panel"
        @click="switchTab('rooms')"
        @keydown.left.prevent="arrowTab('departments')"
      >
        <DoorOpen :size="23" />Xonalar
      </button>
    </div>
    <article
      id="facilities-panel"
      class="dr-panel"
      role="tabpanel"
      :aria-labelledby="tab === 'departments' ? 'departments-tab' : 'rooms-tab'"
    >
      <template v-if="tab === 'departments'"
        ><div class="dr-filters">
          <label class="dr-search"
            ><Search :size="21" /><input
              v-model="departmentQuery"
              aria-label="Bo‘limlarni qidirish"
              placeholder="Bo‘lim nomi bo‘yicha qidirish..." /></label
          ><select
            v-model="departmentBranch"
            aria-label="Bo‘limlar filial filtri"
          >
            <option value="">Barcha filiallar</option>
            <option v-for="b in FACILITY_BRANCHES" :key="b">
              {{ b }}
            </option></select
          ><select
            v-model="departmentStatus"
            aria-label="Bo‘limlar holat filtri"
          >
            <option value="">Barcha holatlar</option>
            <option value="true">Faol</option>
            <option value="false">Faol emas</option>
          </select>
        </div>
        <div class="dr-scroll">
          <table class="dr-departments">
            <thead>
              <tr>
                <th>Bo‘lim nomi</th>
                <th>Filial</th>
                <th>Xonalar soni</th>
                <th>Holati</th>
                <th>Amallar</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="d in displayedDepartments" :key="d.id">
                <td>
                  <div class="dr-name">
                    <span class="dr-symbol"
                      ><component :is="icons[d.icon] ?? Building2" :size="25"
                    /></span>
                    <div>
                      <b>{{ d.name }}</b
                      ><small>ID: {{ d.id }}</small>
                    </div>
                  </div>
                </td>
                <td>{{ d.branch }}</td>
                <td>
                  <button
                    class="dr-count"
                    :aria-label="d.name + ' xonalarini ko‘rish'"
                    @click="showRooms(d)"
                  >
                    {{ count(d) }} ta
                  </button>
                </td>
                <td>
                  <span class="dr-badge" :class="{ inactive: !d.active }">{{
                    d.active ? "Faol" : "Faol emas"
                  }}</span>
                </td>
                <td>
                  <button
                    class="dr-edit"
                    :aria-label="d.name + ' bo‘limini tahrirlash'"
                    @click="open('department', d)"
                  >
                    <Pencil :size="21" />
                  </button>
                </td>
              </tr>
              <tr v-if="!displayedDepartments.length">
                <td colspan="5" class="dr-empty">
                  Bo‘lim topilmadi. Qidiruv yoki filtrlarni o‘zgartiring.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="dr-pagination">
          <span>Jami {{ filteredDepartments.length }} ta bo‘lim</span>
          <nav aria-label="Bo‘limlar sahifalari">
            <button
              aria-label="Oldingi bo‘limlar sahifasi"
              :disabled="departmentPage === 1"
              @click="departmentPage--"
            >
              <ChevronLeft :size="17" /></button
            ><button
              v-for="n in departmentPageCount"
              :key="n"
              :class="{ selected: departmentPage === n }"
              :aria-label="n + '-bo‘limlar sahifasi'"
              :aria-current="departmentPage === n ? 'page' : undefined"
              @click="departmentPage = n"
            >
              {{ n }}</button
            ><button
              aria-label="Keyingi bo‘limlar sahifasi"
              :disabled="departmentPage === departmentPageCount"
              @click="departmentPage++"
            >
              <ChevronRight :size="17" />
            </button>
          </nav></div></template
      ><template v-else
        ><div class="dr-filters rooms">
          <label class="dr-search"
            ><Search :size="21" /><input
              v-model="roomQuery"
              aria-label="Xonalarni qidirish"
              placeholder="Xona nomi yoki raqami bo‘yicha qidirish..." /></label
          ><select v-model="roomBranch" aria-label="Xonalar filial filtri">
            <option value="">Barcha filiallar</option>
            <option v-for="b in FACILITY_BRANCHES" :key="b">
              {{ b }}
            </option></select
          ><select v-model="roomDepartment" aria-label="Xonalar bo‘lim filtri">
            <option value="">Barcha bo‘limlar</option>
            <option v-for="d in availableDepartments" :key="d.id" :value="d.id">
              {{ d.name }}
            </option></select
          ><select v-model="roomStatus" aria-label="Xonalar holat filtri">
            <option value="">Barcha holatlar</option>
            <option value="true">Faol</option>
            <option value="false">Faol emas</option>
          </select>
        </div>
        <div class="dr-scroll">
          <table class="dr-rooms">
            <thead>
              <tr>
                <th>Xona</th>
                <th>Filial</th>
                <th>Bo‘lim</th>
                <th>Xona turi</th>
                <th>Qavat</th>
                <th>Holati</th>
                <th>Amallar</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in displayedRooms" :key="r.id">
                <td>
                  <div class="dr-name">
                    <span class="dr-symbol"><RoomIcon :size="25" /></span>
                    <div>
                      <b>{{ r.name }}</b
                      ><small>ID: {{ r.id }}</small>
                    </div>
                  </div>
                </td>
                <td>{{ r.branch }}</td>
                <td>
                  {{
                    departments.find((d) => d.id === r.departmentId)?.name ??
                    "—"
                  }}
                </td>
                <td>{{ r.type }}</td>
                <td>{{ r.floor ? r.floor + "-qavat" : "—" }}</td>
                <td>
                  <span class="dr-badge" :class="{ inactive: !r.active }">{{
                    r.active ? "Faol" : "Faol emas"
                  }}</span>
                </td>
                <td>
                  <button
                    class="dr-edit"
                    :aria-label="r.name + ' xonasini tahrirlash'"
                    @click="open('room', r)"
                  >
                    <Pencil :size="21" />
                  </button>
                </td>
              </tr>
              <tr v-if="!displayedRooms.length">
                <td colspan="7" class="dr-empty">
                  Xona topilmadi. Qidiruv yoki filtrlarni o‘zgartiring.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="dr-pagination">
          <span
            >{{ filteredRooms.length ? (page - 1) * 8 + 1 : 0 }} –
            {{ Math.min(page * 8, filteredRooms.length) }} /
            {{ filteredRooms.length }} ta xona</span
          >
          <nav aria-label="Xonalar sahifalari">
            <button
              aria-label="Oldingi xonalar sahifasi"
              :disabled="page === 1"
              @click="page--"
            >
              <ChevronLeft :size="17" /></button
            ><button
              v-for="n in pageCount"
              :key="n"
              :class="{ selected: page === n }"
              :aria-label="n + '-xonalar sahifasi'"
              :aria-current="page === n ? 'page' : undefined"
              @click="page = n"
            >
              {{ n }}</button
            ><button
              aria-label="Keyingi xonalar sahifasi"
              :disabled="page === pageCount"
              @click="page++"
            >
              <ChevronRight :size="17" />
            </button>
          </nav></div
      ></template>
    </article>
    <FacilityForm
      v-if="modal"
      :kind="modal"
      :item="editing"
      :departments="departments"
      :rooms="rooms"
      @close="modal = null"
      @save-department="saveDepartment"
      @save-room="saveRoom"
    />
  </section>
</template>
<style scoped src="../components/departments/departments.css"></style>
