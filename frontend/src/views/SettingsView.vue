<script setup lang="ts">
import { computed, ref, onMounted, onBeforeUnmount } from "vue";
import {
  Shield,
  Search,
  Plus,
  ChevronRight,
  ChevronDown,
  Wallet,
  Users,
  Settings,
  Stethoscope,
  FlaskConical,
  Save,
  MoreHorizontal,
  Info,
} from "lucide-vue-next";
import { session } from "../services/session";
import { clone } from "../mocks/employees";
import {
  permissionSections,
  assigned,
  saveRole,
  deleteRole,
  setPermission,
  viewCode,
  canDemo,
  type LocalRole,
} from "../mocks/settingsRoles";
import { useRolesStore } from "../components/settings/store";
import SettingsDialog from "../components/settings/SettingsDialog.vue";
import "../components/settings/settings.css";
const store = useRolesStore();
const tab = ref("Rollar va ruxsatlar");
const tabs = ["Umumiy", "To‘lovlar", "Rollar va ruxsatlar"];
const search = ref("");
const selected = ref("cashier");
const creating = ref(false);
const menu = ref(false);
const blank = (): LocalRole => ({
  id: "",
  name: "",
  note: "",
  permissions: [],
});
const saved = computed(() =>
  store.state.value.roles.find((r) => r.id === selected.value),
);
const draft = ref<LocalRole>(clone(saved.value ?? blank()));
const baseline = ref(JSON.stringify(draft.value));
const dirty = computed(() => JSON.stringify(draft.value) !== baseline.value);
const filtered = computed(() =>
  store.state.value.roles.filter((r) =>
    r.name
      .toLocaleLowerCase()
      .includes(search.value.trim().toLocaleLowerCase()),
  ),
);
const count = computed(() =>
  creating.value ? 0 : assigned(store.state.value, selected.value),
);
const expanded = ref<string[]>(["cashier"]);
const notice = ref("");
const error = ref("");
const modal = ref<"leave" | "save" | "delete" | "">("");
let pending: (() => void) | undefined;
let timer: ReturnType<typeof setTimeout>;
function load(id: string) {
  selected.value = id;
  creating.value = false;
  draft.value = clone(
    store.state.value.roles.find((r) => r.id === id) ?? blank(),
  );
  baseline.value = JSON.stringify(draft.value);
  error.value = "";
  menu.value = false;
}
function guardLeave(fn: () => void) {
  if (!dirty.value) return false;
  pending = fn;
  modal.value = "leave";
  return true;
}
defineExpose({ guardLeave });
function go(fn: () => void) {
  if (!guardLeave(fn)) fn();
}
function discard() {
  draft.value = JSON.parse(baseline.value);
  modal.value = "";
  const fn = pending;
  pending = undefined;
  fn?.();
}
function create() {
  go(() => {
    creating.value = true;
    draft.value = blank();
    baseline.value = JSON.stringify(draft.value);
    error.value = "";
    menu.value = false;
  });
}
function cancel() {
  if (creating.value) load(selected.value);
  else load(selected.value);
}
function message(s: string) {
  notice.value = s;
  clearTimeout(timer);
  timer = setTimeout(() => (notice.value = ""), 3500);
}
function validateDraft() {
  const next = clone(store.state.value);
  saveRole(next, {
    ...draft.value,
    id: creating.value ? "new-validation-id" : draft.value.id,
  });
}
function requestSave() {
  try {
    validateDraft();
    error.value = "";
    if (
      !creating.value &&
      count.value &&
      JSON.stringify(saved.value?.permissions) !==
        JSON.stringify(draft.value.permissions)
    )
      modal.value = "save";
    else save();
  } catch (e) {
    error.value = (e as Error).message;
  }
}
function save() {
  try {
    const value = {
      ...clone(draft.value),
      id: creating.value ? `local-${crypto.randomUUID()}` : draft.value.id,
    };
    store.transact((s) => saveRole(s, value));
    load(value.id);
    modal.value = "";
    message("Rol saqlandi.");
  } catch (e) {
    error.value = (e as Error).message;
    modal.value = "";
  }
}
function remove() {
  try {
    store.transact((s) => deleteRole(s, selected.value));
    load(store.state.value.roles[0]!.id);
    modal.value = "";
    message("Rol o‘chirildi.");
  } catch (e) {
    error.value = (e as Error).message;
    modal.value = "";
  }
}
function toggle(id: string) {
  expanded.value = expanded.value.includes(id)
    ? expanded.value.filter((x) => x !== id)
    : [...expanded.value, id];
}
function navigation(e: MouseEvent) {
  const el = (e.target as Element)?.closest<HTMLElement>(
    ".clinic-sidebar button,.clinic-profile-menu button",
  );
  if (!el || !dirty.value) return;
  e.preventDefault();
  e.stopImmediatePropagation();
  guardLeave(() => el.click());
}
function clinicChange(e: Event) {
  const el = e.target as HTMLSelectElement;
  if (!el.matches?.(".clinic-selector select") || !dirty.value) return;
  const value = el.value;
  el.value = String(session.user?.active_tenant_id ?? "");
  e.stopImmediatePropagation();
  guardLeave(() => {
    el.value = value;
    el.dispatchEvent(new Event("change", { bubbles: true }));
  });
}
function unload(e: BeforeUnloadEvent) {
  if (dirty.value) {
    e.preventDefault();
    e.returnValue = "";
  }
}
onMounted(() => {
  document.addEventListener("click", navigation, true);
  document.addEventListener("change", clinicChange, true);
  window.addEventListener("beforeunload", unload);
});
onBeforeUnmount(() => {
  clearTimeout(timer);
  document.removeEventListener("click", navigation, true);
  document.removeEventListener("change", clinicChange, true);
  window.removeEventListener("beforeunload", unload);
});
</script>
<template>
  <div class="settings-roles-ui">
    <h1>Sozlamalar</h1>
    <p class="sr-subtitle">
      Klinika sozlamalari va xodimlar huquqlarini boshqaring.
    </p>
    <nav class="sr-tabs" aria-label="Sozlamalar bo‘limlari">
      <button
        v-for="t in tabs"
        :key="t"
        :aria-current="tab === t ? 'page' : undefined"
        :class="{ selected: tab === t }"
        @click="go(() => (tab = t))"
      >
        {{ t }}
      </button>
    </nav>
    <p v-if="notice" class="sr-success" role="status">{{ notice }}</p>
    <p v-if="store.error.value" class="sr-error" role="alert">
      {{ store.error.value }}
    </p>
    <div v-if="tab !== 'Rollar va ruxsatlar'" class="sr-coming">Tez orada</div>
    <div v-else class="sr-columns">
      <aside class="sr-card sr-role-list">
        <header>
          <h2>Rollar</h2>
          <button class="sr-button primary" @click="create">
            <Plus :size="18" />Rol qo‘shish
          </button>
        </header>
        <label class="sr-search"
          ><Search :size="20" /><input
            v-model="search"
            aria-label="Rolni qidirish"
            placeholder="Rolni qidirish..."
        /></label>
        <div class="sr-role-items">
          <button
            v-for="role in filtered"
            :key="role.id"
            class="sr-role"
            :class="{ selected: !creating && role.id === selected }"
            :aria-pressed="!creating && role.id === selected"
            @click="go(() => load(role.id))"
          >
            <Shield :size="23" /><b>{{ role.name }}</b
            ><small>{{ assigned(store.state.value, role.id) }} ta xodim</small
            ><ChevronRight :size="18" />
          </button>
        </div>
        <p v-if="!filtered.length" class="sr-empty">
          Rol topilmadi. Boshqa nom bilan qidiring.
        </p>
      </aside>
      <section class="sr-card sr-editor" aria-label="Rol sozlamalari">
        <header>
          <div class="sr-title">
            <h2>{{ creating ? "Yangi rol" : saved?.name }}</h2>
            <span v-if="!creating" class="sr-badge">{{ count }} ta xodim</span>
          </div>
          <div v-if="!creating" class="sr-more">
            <button
              class="sr-icon"
              aria-label="Rol amallari"
              :aria-expanded="menu"
              @click="menu = !menu"
            >
              <MoreHorizontal :size="22" />
            </button>
            <div v-if="menu" class="sr-menu">
              <button
                @click="
                  menu = false;
                  modal = 'delete';
                "
              >
                Rolni o‘chirish
              </button>
            </div>
          </div>
        </header>
        <form @submit.prevent="requestSave" novalidate>
          <div class="sr-fields">
            <label
              >Rol nomi <em>*</em
              ><input
                v-model="draft.name"
                aria-label="Rol nomi"
                aria-required="true"
                maxlength="80"
                :aria-invalid="!!error" /></label
            ><label
              >Izoh<input
                v-model="draft.note"
                aria-label="Izoh"
                maxlength="300"
                placeholder="Ixtiyoriy izoh"
            /></label>
          </div>
          <h3>Bo‘limlar va ruxsatlar</h3>
          <p class="sr-hint">
            Xodim faqat ruxsat berilgan bo‘limlarni ko‘radi.
          </p>
          <div class="sr-permissions">
            <section
              v-for="s in permissionSections"
              :key="s.id"
              class="sr-section"
              :data-section="s.id"
            >
              <button
                type="button"
                class="sr-accordion"
                :aria-expanded="expanded.includes(s.id)"
                :aria-controls="`permissions-${s.id}`"
                @click="toggle(s.id)"
              >
                <component
                  :is="
                    s.id === 'cashier'
                      ? Wallet
                      : s.id === 'workspace'
                        ? Stethoscope
                        : s.id === 'laboratory'
                          ? FlaskConical
                          : s.id === 'employees'
                            ? Users
                            : Settings
                  "
                  :size="22"
                /><b>{{ s.label }}</b
                ><small
                  >{{
                    s.permissions.filter((p) =>
                      draft.permissions.includes(p.code),
                    ).length
                  }}
                  / {{ s.permissions.length }}</small
                ><ChevronDown
                  :size="18"
                  :class="{ up: expanded.includes(s.id) }"
                />
              </button>
              <div
                v-if="expanded.includes(s.id)"
                :id="`permissions-${s.id}`"
                class="sr-checks"
              >
                <label v-for="p in s.permissions" :key="p.code"
                  ><input
                    type="checkbox"
                    :checked="draft.permissions.includes(p.code)"
                    :disabled="
                      (p.code !== viewCode(s) &&
                        !canDemo(draft, viewCode(s))) ||
                      (!!s.parent && !canDemo(draft, s.parent))
                    "
                    @change="
                      setPermission(
                        draft,
                        p.code,
                        ($event.target as HTMLInputElement).checked,
                      )
                    "
                  />{{ p.label }}</label
                >
                <p v-if="s.parent && !canDemo(draft, s.parent)">
                  <Info :size="18" />Avval asosiy sahifani ko‘rish ruxsatini
                  yoqing.
                </p>
                <p>
                  <Info :size="18" />Sahifani ko‘rish o‘chirilsa, ichki amallar
                  ham o‘chadi.
                </p>
              </div>
            </section>
          </div>
          <p class="sr-demo">
            <Info :size="17" />Lokal namuna: bu sozlamalar haqiqiy sessiya,
            sidebar yoki API himoyasini o‘zgartirmaydi.
          </p>
          <p v-if="!creating && count" class="sr-hint">
            Ruxsatlar saqlanganda shu rolga biriktirilgan {{ count }} ta xodimga
            taalluqli bo‘ladi.
          </p>
          <p v-if="error" class="sr-error" role="alert">{{ error }}</p>
          <footer>
            <button type="button" class="sr-button" @click="cancel">
              Bekor qilish</button
            ><button
              type="submit"
              class="sr-button primary"
              :disabled="!!store.error.value"
            >
              <Save :size="18" />{{ creating ? "Rolni yaratish" : "Saqlash" }}
            </button>
          </footer>
        </form>
      </section>
    </div>
    <SettingsDialog
      v-if="modal"
      :title="
        modal === 'leave'
          ? 'Saqlanmagan o‘zgarishlar'
          : modal === 'save'
            ? 'Ruxsatlarni saqlash'
            : 'Rolni o‘chirish'
      "
      @close="
        modal = '';
        pending = undefined;
      "
    >
      <template v-if="modal === 'leave'"
        ><p>
          Saqlanmagan o‘zgarishlar bor. O‘tishdan oldin ularni bekor qilasizmi?
        </p>
        <footer>
          <button
            class="sr-button"
            @click="
              modal = '';
              pending = undefined;
            "
          >
            Shu yerda qolish</button
          ><button class="sr-button primary" @click="discard">
            O‘zgarishlarni bekor qilib o‘tish
          </button>
        </footer></template
      >
      <template v-else-if="modal === 'save'"
        ><p>
          O‘zgargan ruxsatlar shu rolga biriktirilgan barcha {{ count }} ta
          xodimga taalluqli bo‘ladi.
        </p>
        <footer>
          <button class="sr-button" @click="modal = ''">Bekor qilish</button
          ><button class="sr-button primary" @click="save">
            Saqlashni tasdiqlash
          </button>
        </footer></template
      >
      <template v-else
        ><p v-if="saved?.protected">
          Himoyalangan tizim rolini o‘chirib bo‘lmaydi.
        </p>
        <p v-else-if="count">
          Bu rolga {{ count }} ta xodim biriktirilgan. Avval xodimlarga boshqa
          rol bering.
        </p>
        <p v-else>
          “{{ saved?.name }}” rolini o‘chirasizmi? Boshqa rollar o‘zgarmaydi.
        </p>
        <footer>
          <button class="sr-button" @click="modal = ''">Bekor qilish</button
          ><button
            v-if="!saved?.protected && !count"
            class="sr-button danger"
            @click="remove"
          >
            O‘chirishni tasdiqlash
          </button>
        </footer></template
      >
    </SettingsDialog>
  </div>
</template>
