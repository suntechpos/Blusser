<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount, nextTick } from "vue";
import { Pencil, Search } from "lucide-vue-next";
import FacilityForm from "./FacilityForm.vue";
import { session, can } from "../../services/session";
import { clinicRequest, errorText } from "../../services/api";
import { scheduleList } from "../../services/employeeSchedule";
import type { Room } from "../../mocks/departmentsRooms";
interface ApiRoom {
  id: string;
  branch_id: string;
  number: string;
  service_type: string;
  active: boolean;
  availability: "available" | "unavailable";
}
const branch = ref(session.branch || session.branches[0]?.id || ""),
  query = ref(""),
  error = ref(""),
  saveError = ref(""),
  loading = ref(false),
  saving = ref(false);
const status = ref(""),
  page = ref(1);
const records = ref<ApiRoom[]>([]),
  editing = ref<Room | null>(null),
  opened = ref(false);
let revision = 0;
let committedId = "";
onBeforeUnmount(() => revision++);
const rooms = computed<Room[]>(() =>
  records.value.map((r) => ({
    id: r.id,
    name: r.number,
    branch: r.branch_id,
    type: r.service_type,
    active: r.active,
    availability: r.availability,
    departmentId: "",
    floor: "",
    note: "",
  })),
);
const visible = computed(() =>
  rooms.value.filter(
    (r) =>
      (!status.value || String(r.active) === status.value) &&
      r.name
        .toLocaleLowerCase()
        .includes(query.value.trim().toLocaleLowerCase()),
  ),
);
const pageCount = computed(() =>
  Math.max(1, Math.ceil(visible.value.length / 8)),
);
const displayed = computed(() =>
  visible.value.slice((page.value - 1) * 8, page.value * 8),
);
watch([query, status, branch], () => (page.value = 1));
watch(pageCount, (n) => (page.value = Math.min(page.value, n)));
async function load() {
  const n = ++revision;
  records.value = [];
  error.value = "";
  if (!branch.value) return;
  loading.value = true;
  try {
    const r = await scheduleList<ApiRoom>("rooms", {
      branch_id: branch.value,
      status: "all",
    });
    if (n === revision) records.value = r;
  } catch (e) {
    if (n === revision) error.value = errorText(e);
  } finally {
    if (n === revision) loading.value = false;
  }
}
watch(() => [branch.value, session.epoch], load, { immediate: true });
function open(r: Room | null = null) {
  if (!can(r ? "rooms.update" : "rooms.create")) return;
  editing.value = r ?? {
    id: "",
    name: "",
    branch: branch.value,
    type: "",
    active: true,
    availability: "available",
    departmentId: "",
    floor: "",
    note: "",
  };
  committedId = "";
  saveError.value = "";
  opened.value = true;
}
defineExpose({ open });
async function save(r: Room) {
  if (saving.value) return;
  saving.value = true;
  saveError.value = "";
  try {
    const id = committedId || r.id;
    const stored = (
      await clinicRequest<ApiRoom>(id ? `rooms/${id}` : "rooms", {
        method: id ? "PUT" : "POST",
        body: {
          branch_id: r.branch,
          number: r.name,
          service_type: r.type,
          active: r.active,
          availability: r.availability,
        },
      })
    ).data;
    committedId = stored.id;
    branch.value = stored.branch_id;
    await nextTick();
    await load();
    if (error.value)
      throw Error(
        "Xona saqlandi, ammo qayta yuklanmadi. Qayta urinish yangi xona yaratmaydi.",
      );
    opened.value = false;
  } catch (e) {
    saveError.value = errorText(e);
  } finally {
    saving.value = false;
  }
}
</script>
<template>
  <div class="dr-filters rooms">
    <label class="dr-search"
      ><Search :size="21" /><input
        v-model="query"
        aria-label="Xonalarni qidirish"
        placeholder="Xona nomi yoki raqami bo‘yicha qidirish..." /></label
    ><select v-model="branch" aria-label="Xonalar filial filtri">
      <option
        v-for="b in session.branches.filter((b) => b.active)"
        :key="b.id"
        :value="b.id"
      >
        {{ b.name }}
      </option>
    </select>
    <select v-model="status" aria-label="Xonalar holat filtri">
      <option value="">Barcha holatlar</option>
      <option value="true">Faol</option>
      <option value="false">Faol emas</option>
    </select>
  </div>
  <p v-if="loading" role="status">Xonalar yuklanmoqda…</p>
  <p v-else-if="error" role="alert" class="dr-error">{{ error }}</p>
  <div v-else class="dr-scroll">
    <table class="dr-rooms">
      <thead>
        <tr>
          <th>Xona</th>
          <th>Filial</th>
          <th>Xona turi</th>
          <th>Holati</th>
          <th>Foydalanishga yaroqliligi</th>
          <th>Amallar</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="r in displayed" :key="r.id">
          <td>
            <b>{{ r.name }}</b
            ><small>ID: {{ r.id }}</small>
          </td>
          <td>
            {{ session.branches.find((b) => b.id === r.branch)?.name || "—" }}
          </td>
          <td>{{ r.type }}</td>
          <td>{{ r.active ? "Faol" : "Faol emas" }}</td>
          <td>
            {{
              r.availability === "available"
                ? "Foydalanish mumkin"
                : "Foydalanib bo‘lmaydi"
            }}
          </td>
          <td>
            <button
              v-if="can('rooms.update')"
              class="dr-edit"
              :aria-label="r.name + ' xonasini tahrirlash'"
              @click="open(r)"
            >
              <Pencil :size="21" />
            </button>
          </td>
        </tr>
        <tr v-if="!visible.length">
          <td colspan="6" class="dr-empty">
            Bu filialda qabul xonasi mavjud emas
          </td>
        </tr>
      </tbody>
    </table>
  </div>
  <div v-if="!loading && !error" class="dr-pagination">
    <span
      >{{ visible.length ? (page - 1) * 8 + 1 : 0 }} –
      {{ Math.min(page * 8, visible.length) }} / {{ visible.length }} ta
      xona</span
    >
    <nav aria-label="Xonalar sahifalari">
      <button
        aria-label="Oldingi xonalar sahifasi"
        :disabled="page === 1"
        @click="page--"
      >
        ‹</button
      ><button
        v-for="n in pageCount"
        :key="n"
        :class="{ selected: page === n }"
        :aria-label="n + '-xonalar sahifasi'"
        @click="page = n"
      >
        {{ n }}</button
      ><button
        aria-label="Keyingi xonalar sahifasi"
        :disabled="page === pageCount"
        @click="page++"
      >
        ›
      </button>
    </nav>
  </div>
  <FacilityForm
    v-if="opened"
    kind="room"
    :api-rooms="true"
    :item="editing"
    :departments="[]"
    :rooms="rooms"
    :branches="session.branches.filter((b) => b.active)"
    :saving="saving"
    :server-error="saveError"
    @close="!saving && (opened = false)"
    @save-room="save"
  />
</template>
<style scoped>
small {
  display: block;
  color: #7487a3;
  font-size: 11px;
  margin-top: 4px;
}
</style>
<style scoped src="./departments.css"></style>
