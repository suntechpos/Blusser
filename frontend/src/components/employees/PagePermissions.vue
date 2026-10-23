<script setup lang="ts">
import { computed, ref } from "vue";
import {
  ShieldCheck,
  Users,
  Stethoscope,
  Wallet,
  Settings,
  Search,
  ChevronDown,
  Home,
  UserRound,
  CalendarDays,
  ClipboardList,
  Monitor,
  FlaskConical,
  Activity,
  LayoutGrid,
  Tags,
  Package,
  Building2,
  Map,
  ChartNoAxesColumnIncreasing,
  FileClock,
  CalendarClock,
  BriefcaseMedical,
  Layers,
  Info,
} from "lucide-vue-next";
interface PageOption {
  code: string;
  label: string;
  parent?: string;
}
const props = defineProps<{
  options: PageOption[];
  grants: { code: string; scope: string }[];
  editable: boolean;
  allowedScopes: (code: string) => string[];
}>();
const emit = defineEmits<{
  toggle: [code: string, on: boolean];
  scope: [code: string, scope: string];
}>();
const search = ref("");
const collapsed = ref<string[]>([]);
const confirmingClear = ref(false);
const scopeLabels: Record<string, string> = {
  own: "O‘ziga tegishli",
  assigned_branches: "Biriktirilgan filiallar",
  entire_clinic: "Butun klinika",
};
const definitions = [
  {
    id: "patients",
    label: "Bemorlar va qabullar",
    icon: Users,
    codes: [
      "reception.view",
      "patients.view",
      "appointments.view",
      "queues.view",
      "display.view",
      "schedules.view",
      "visits.view",
    ],
  },
  {
    id: "clinical",
    label: "Klinik jarayonlar",
    icon: Stethoscope,
    codes: [
      "workspace.view",
      "doctors.view",
      "laboratory.view",
      "diagnostics.view",
      "services.view",
      "categories.view",
    ],
  },
  {
    id: "finance",
    label: "Moliya va ombor",
    icon: Wallet,
    codes: ["cashier.view", "finance.view", "inventory.view"],
  },
  {
    id: "management",
    label: "Xodimlar va boshqaruv",
    icon: Settings,
    codes: [
      "dashboard.view",
      "employees.view",
      "employee_schedule.view",
      "branches.view",
      "departments.view",
      "rooms.view",
      "reports.view",
      "audit.view",
      "roles.view",
    ],
  },
];
const icons: Record<string, typeof Users> = {
  "reception.view": BriefcaseMedical,
  "patients.view": Users,
  "appointments.view": CalendarDays,
  "queues.view": ClipboardList,
  "display.view": Monitor,
  "schedules.view": CalendarClock,
  "visits.view": UserRound,
  "workspace.view": Stethoscope,
  "doctors.view": UserRound,
  "laboratory.view": FlaskConical,
  "diagnostics.view": Activity,
  "services.view": LayoutGrid,
  "categories.view": Tags,
  "cashier.view": Wallet,
  "finance.view": ChartNoAxesColumnIncreasing,
  "inventory.view": Package,
  "dashboard.view": Home,
  "employees.view": Users,
  "employee_schedule.view": CalendarClock,
  "branches.view": Building2,
  "departments.view": Layers,
  "rooms.view": Map,
  "reports.view": ChartNoAxesColumnIncreasing,
  "audit.view": FileClock,
  "roles.view": Settings,
};
// Presentation labels only. Codes, scopes and catalog membership remain server-owned.
const labels: Record<string, string> = {
  "branches.view": "Filiallar",
  "departments.view": "Bo‘limlar boshqaruvi",
  "rooms.view": "Bo‘limlar va Xonalar",
  "roles.view": "Sozlamalar — rollar",
};
const options = computed(() =>
  props.options.map((p) => ({ ...p, label: labels[p.code] ?? p.label })),
);
const selected = (code: string) => props.grants.some((g) => g.code === code);
const selectedCount = computed(
  () => options.value.filter((p) => selected(p.code)).length,
);
const groups = computed(() => {
  const known = new Set(definitions.flatMap((g) => g.codes));
  return [
    ...definitions.map((g) => ({
      ...g,
      pages: g.codes.flatMap((code) =>
        options.value.filter((p) => p.code === code),
      ),
    })),
    {
      id: "other",
      label: "Boshqa bo‘limlar",
      icon: Layers,
      pages: options.value.filter((p) => !known.has(p.code)),
    },
  ]
    .map((g) => ({
      ...g,
      visible: g.pages.filter((p) =>
        p.label
          .toLocaleLowerCase()
          .includes(search.value.trim().toLocaleLowerCase()),
      ),
    }))
    .filter((g) => g.visible.length);
});
function assignable(p: PageOption) {
  return (
    props.editable &&
    props.allowedScopes(p.code).length > 0 &&
    (!p.parent || props.allowedScopes(p.parent).length > 0)
  );
}
const canSelectMore = computed(() =>
  options.value.some((p) => !selected(p.code) && assignable(p)),
);
function selectAll() {
  for (const p of options.value)
    if (!selected(p.code) && assignable(p)) emit("toggle", p.code, true);
}
function clearSelection() {
  if (!props.editable) return;
  for (const p of options.value)
    if (selected(p.code)) emit("toggle", p.code, false);
  confirmingClear.value = false;
}
function collapse(id: string) {
  collapsed.value = collapsed.value.includes(id)
    ? collapsed.value.filter((v) => v !== id)
    : [...collapsed.value, id];
}
</script>

<template>
  <section
    class="employee-pages employee-permissions"
    aria-label="Ishlashga ruxsat berilgan bo‘limlar"
  >
    <header class="employee-permissions-toolbar">
      <span class="employee-permissions-emblem"
        ><ShieldCheck :size="24"
      /></span>
      <div class="employee-permissions-title">
        <h3>Ishlashga ruxsat berilgan bo‘limlar</h3>
        <p>Xodim qaysi sahifalarda ishlashiga ruxsat berilishini tanlang.</p>
      </div>
      <span class="employee-permissions-count" role="status"
        >{{ selectedCount }} ta bo‘lim tanlangan</span
      >
      <div class="employee-permissions-actions">
        <label class="employee-permissions-search"
          ><Search :size="16" /><input
            v-model="search"
            aria-label="Bo‘limlarni qidirish"
            placeholder="Bo‘limlarni qidirish..."
            type="search"
        /></label>
        <button type="button" :disabled="!canSelectMore" @click="selectAll">
          Barchasini belgilash
        </button>
        <button
          type="button"
          class="employee-permissions-clear"
          :disabled="!editable || !selectedCount"
          @click="confirmingClear = true"
        >
          Tozalash
        </button>
      </div>
    </header>
    <div
      v-if="confirmingClear"
      role="group"
      aria-label="Tanlovlarni tozalashni tasdiqlash"
      class="employee-permissions-confirm"
    >
      <span
        >Barcha bo‘limlar tanlovi bekor qilinsinmi? O‘zgarishlar faqat
        saqlanganda qo‘llanadi.</span
      >
      <button type="button" @click="confirmingClear = false">
        Bekor qilish
      </button>
      <button type="button" @click="clearSelection">Ha, tozalash</button>
    </div>
    <div class="employee-permissions-grid">
      <section
        v-for="group in groups"
        :key="group.id"
        class="employee-permissions-group"
        :aria-label="group.label"
      >
        <button
          type="button"
          class="employee-permissions-group-header"
          :aria-expanded="!!search.trim() || !collapsed.includes(group.id)"
          @click="collapse(group.id)"
        >
          <component :is="group.icon" :size="21" /><span>{{ group.label }}</span
          ><small
            >{{ group.pages.filter((p) => selected(p.code)).length }} /
            {{ group.pages.length }}</small
          ><ChevronDown
            :size="16"
            :class="{
              'is-open': !!search.trim() || !collapsed.includes(group.id),
            }"
          />
        </button>
        <div
          v-show="search.trim() || !collapsed.includes(group.id)"
          class="employee-permissions-rows"
        >
          <div
            v-for="page in group.visible"
            :key="page.code"
            class="employee-permission-row"
            :class="{ 'is-selected': selected(page.code) }"
          >
            <label
              ><input
                type="checkbox"
                :aria-label="'Bo‘lim: ' + page.label"
                :checked="selected(page.code)"
                :disabled="
                  !editable || (!selected(page.code) && !assignable(page))
                "
                @change="
                  emit(
                    'toggle',
                    page.code,
                    ($event.target as HTMLInputElement).checked,
                  )
                "
              /><component
                :is="icons[page.code] ?? Layers"
                :size="17"
              /><span>{{ page.label }}</span></label
            >
            <select
              v-if="selected(page.code)"
              :aria-label="page.label + ' kirish doirasi'"
              :disabled="!editable || !allowedScopes(page.code).length"
              :value="grants.find((g) => g.code === page.code)?.scope"
              @change="
                emit(
                  'scope',
                  page.code,
                  ($event.target as HTMLSelectElement).value,
                )
              "
            >
              <option
                v-for="scope in allowedScopes(page.code)"
                :key="scope"
                :value="scope"
              >
                {{ scopeLabels[scope] ?? scope }}
              </option>
            </select>
          </div>
        </div>
      </section>
    </div>
    <p v-if="!groups.length" class="employee-permissions-empty">
      Bo‘lim topilmadi.
    </p>
    <p class="employee-permissions-hint">
      <Info :size="16" /><span
        >Tanlov sahifani ko‘rishga ruxsat beradi. Amallar ruxsat roli orqali
        boshqariladi. Klinik bo‘lim va lavozim bundan alohida.</span
      >
    </p>
  </section>
</template>
