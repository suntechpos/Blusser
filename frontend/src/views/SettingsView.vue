<script setup lang="ts">
import axios from "axios";
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
import {errorText} from "../services/api";
import { session,can } from "../services/session";
import { clone } from "../mocks/employees";
import { useRolesStore, type ServerRole } from "../components/settings/store";
import SettingsDialog from "../components/settings/SettingsDialog.vue";
import "../components/settings/settings.css";
import { receptionActions } from "../services/receptionAccess";
const store = useRolesStore();
const permissionSections=computed(()=>store.sections.value);
const receptionRows = Object.entries(receptionActions).map(([key, action]) => ({key, label: key === 'patient' ? 'Yangi bemor yaratish' : key === 'checkIn' ? 'Kelganini belgilash — “Keldi”' : action.label, codes: [...action.codes] as string[]}));
function receptionState(codes: string[]) {
 const n = codes.filter(c => draft.value.permissions.includes(c)).length;
 return {checked:n === codes.length, partial:n > 0 && n < codes.length};
}
function receptionScopes(codes: string[]) { return store.allowedScopes(codes[0]!).filter(scope => codes.every(c => store.allowedScopes(c).includes(scope))); }
function receptionScope(codes: string[]) { const scopes=codes.map(c=>draft.value.grants.find(g=>g.code===c)?.scope); return scopes.every(s=>s===scopes[0]) ? scopes[0] ?? '' : ''; }
function toggleReception(codes: string[], on: boolean) {
 if(saved.value?.protected || saving.value || !hasDraftPermission(draft.value,'reception.view')) return;
 if(on && codes.some(c=>!store.allowedScopes(c).length)) return;
 const keep=new Set<string>();
 for(const row of receptionRows) if(row.codes !== codes && receptionState(row.codes).checked) row.codes.forEach(c=>keep.add(c));
 for(const section of permissionSections.value) if(section.permissions.some(p=>!codes.includes(p.code)&&draft.value.permissions.includes(p.code))) keep.add(viewCode(section));
 const grants=new Map(draft.value.grants.map(g=>[g.code,g.scope]));
 for(const code of codes) { if(on&&!grants.has(code)) grants.set(code,store.allowedScopes(code)[0]!); else if(!on&&!keep.has(code)) grants.delete(code); }
 draft.value.grants=[...grants].map(([code,scope])=>({code,scope})); draft.value.permissions=[...grants.keys()];
}
function setReceptionScope(codes: string[], scope: string) { if(saved.value?.protected||saving.value||!receptionScopes(codes).includes(scope))return; draft.value.grants.forEach(g=>{if(codes.includes(g.code))g.scope=scope;}); }
const viewCode=(s:{permissions:{code:string}[]})=>s.permissions[0]?.code??'';
function hasDraftPermission(r:ServerRole,code:string):boolean{const s=permissionSections.value.find(s=>s.permissions.some(p=>p.code===code));return !!s&&r.permissions.includes(viewCode(s))&&(!s.parent||hasDraftPermission(r,s.parent))&&r.permissions.includes(code);}
function setPermission(r:ServerRole,code:string,on:boolean){const s=permissionSections.value.find(s=>s.permissions.some(p=>p.code===code));if(!s)return;if(on&&(!store.allowedScopes(code).length||(s.parent&&!hasDraftPermission(r,s.parent))))return;const values=new Set(r.permissions);on?values.add(code):values.delete(code);if(code===viewCode(s)&&!on)s.permissions.forEach(p=>values.delete(p.code));for(const child of permissionSections.value)if(child.parent&&!values.has(child.parent))child.permissions.forEach(p=>values.delete(p.code));r.permissions=[...values];r.grants=r.permissions.map(c=>({code:c,scope:r.grants.find(g=>g.code===c)?.scope??store.allowedScopes(c)[0]??''}));}

const tab = ref("Rollar va ruxsatlar");
const tabs = ["Umumiy", "To‘lovlar", "Rollar va ruxsatlar"];
const search = ref("");
const selected = ref("cashier");
const creating = ref(false);
const menu = ref(false);
const blank = (): ServerRole => ({
  id: "",
  name: "",
  note: "",
  permissions: [],code:"",grants:[],version:1,employee_count:0,active:true,
});
const saved = computed(() =>
  creating.value ? undefined : store.state.value.roles.find((r) => r.id === selected.value),
);
const draft = ref<ServerRole>(clone(saved.value ?? blank()));
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
  creating.value ? 0 : (saved.value?.employee_count??0),
);
const expanded = ref<string[]>(["cashier"]);
const notice = ref("");
const error = ref("");
const fieldErrors = ref<Record<string,string>>({});
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
  error.value = "";fieldErrors.value={};
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
    error.value = "";fieldErrors.value={};
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
// The backend rejects a child permission whose scope exceeds its page's
// view scope (or a secondary parent view). Explain the exact mismatch here
// rather than making the server return an opaque 422 "invalid_input".
const scopeNames: Record<string, string> = {
  own: "O‘ziga tegishli",
  assigned_branches: "Biriktirilgan filiallar",
  entire_clinic: "Butun klinika",
};
const scopeConflicts = computed(() => {
  const selectedGrants = new Map(
    draft.value.grants
      .filter((grant) => draft.value.permissions.includes(grant.code))
      .map((grant) => [grant.code, grant.scope]),
  );
  const labels = new Map(
    permissionSections.value.flatMap((section) =>
      section.permissions.map((p) => [p.code, p.label] as const),
    ),
  );
  const conflicts: string[] = [];
  for (const [code, scope] of selectedGrants) {
    const view = code.split(".")[0] + ".view";
    const extraParent: Record<string, string> = {
      categories: "services.view",
      reception_actions: "reception.view",
      employee_schedule: "employees.view",
      departments: "rooms.view",
    };
    const parents = [
      ...(code !== view && labels.has(view) ? [view] : []),
      ...(extraParent[code.split(".")[0]] ? [extraParent[code.split(".")[0]]!] : []),
    ];
    for (const parent of parents) {
      const granted = selectedGrants.get(parent);
      if (granted === "entire_clinic" || granted === scope) continue;
      const current = granted ? scopeNames[granted] ?? granted : "yoqilmagan";
      conflicts.push(
        `«${labels.get(code) ?? code}» — ${scopeNames[scope] ?? scope}; ` +
        `«${labels.get(parent) ?? parent}» — ${current}. ` +
        "Ichki amal doirasi ko‘rish doirasidan keng bo‘lmasin.",
      );
    }
  }
  return [...new Set(conflicts)];
});
function validateDraft() {
  if (!draft.value.name.trim()) throw Error("Rol nomini kiriting.");
  if (store.state.value.roles.some((r) =>
    r.id !== draft.value.id &&
    r.name.trim().replace(/\\s+/g, " ").toLowerCase() ===
      draft.value.name.trim().replace(/\\s+/g, " ").toLowerCase(),
  )) throw Error("Rol nomi takrorlangan.");
  if (!draft.value.permissions.length) throw Error("Kamida bitta foydali ruxsat tanlang.");
  if (scopeConflicts.value.length) throw Error("Ruxsat doiralari mos emas. Quyidagi xatolarni to‘g‘rilang.");
}
function requestSave() {
  try {
    validateDraft();
    error.value = "";fieldErrors.value={};
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
const saving=ref(false);
async function save(){if(saving.value)return;saving.value=true;try{const id=await store.saveRole(draft.value,creating.value);load(id);modal.value='';message('Rol serverda saqlandi.');}catch(e){error.value=errorText(e);if(axios.isAxiosError(e))fieldErrors.value=Object.fromEntries(Object.entries(e.response?.data?.errors??{}).map(([key,value])=>[key,(value as string[])[0]??'Tekshiring.']));modal.value='';}finally{saving.value=false;}}
async function remove(){if(saving.value||!saved.value)return;saving.value=true;try{await store.removeRole(saved.value);load(store.state.value.roles[0]?.id??'');modal.value='';message('Rol o‘chirildi.');}catch(e){error.value=errorText(e);modal.value='';}finally{saving.value=false;}}
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
onMounted(async () => {
  await store.refresh("roles");load(store.state.value.roles[0]?.id??"");
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
    <p v-else-if="store.loading.value" role="status">Yuklanmoqda…</p>
    <div v-else-if="store.error.value"><button class="sr-button" @click="store.refresh('roles')">Qayta urinish</button></div>
    <div v-else class="sr-columns">
      <aside class="sr-card sr-role-list">
        <header>
          <h2>Rollar</h2>
          <button class="sr-button primary" v-if="can('roles.create')&&can('roles.manage_permissions')" @click="create">
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
            ><small>{{ role.employee_count }} ta xodim</small
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
          <div v-if="!creating&&can('roles.delete')" class="sr-more">
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
        <form v-if="creating||saved" @submit.prevent="requestSave" novalidate>
          <div class="sr-fields">
            <label
              >Rol nomi <em>*</em
              ><input
                v-model="draft.name" :disabled="saving||!!saved?.protected&&!creating"
                aria-label="Rol nomi"
                aria-required="true"
                maxlength="80"
                :aria-invalid="!!error" aria-describedby="role-name-error" /><small id="role-name-error" v-if="fieldErrors.name" class="sr-error">{{fieldErrors.name}}</small></label
            ><label
              >Izoh<input
                v-model="draft.note" :disabled="saving||!!saved?.protected&&!creating"
                aria-label="Izoh"
                maxlength="300"
                placeholder="Ixtiyoriy izoh" aria-describedby="role-note-error"
            /><small id="role-note-error" v-if="fieldErrors.note" class="sr-error">{{fieldErrors.note}}</small></label>
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
                    type="checkbox" :aria-label="p.label"
                    :checked="draft.permissions.includes(p.code)"
                    :disabled="
                      ((!!saved?.protected&&!creating)||saving||(!store.allowedScopes(p.code).length&&!draft.permissions.includes(p.code)) || p.code !== viewCode(s) &&
                        !hasDraftPermission(draft, viewCode(s))) ||
                      (!!s.parent && !hasDraftPermission(draft, s.parent))
                    "
                    @change="
                      setPermission(
                        draft,
                        p.code,
                        ($event.target as HTMLInputElement).checked,
                      )
                    "
                  />{{ p.label }}<select v-if="draft.permissions.includes(p.code)" :aria-label="p.label+' doirasi'" :disabled="(!!saved?.protected&&!creating)||saving" v-model="draft.grants.find(g=>g.code===p.code)!.scope"><option v-for="scope in (s.id === 'reception' ? store.allowedScopes(p.code) : [...new Set([...(store.allowedScopes(p.code)),draft.grants.find(g=>g.code===p.code)?.scope??''])].filter(Boolean))" :key="scope" :value="scope">{{({own:'O‘ziga tegishli',assigned_branches:'Biriktirilgan filiallar',entire_clinic:'Butun klinika'} as Record<string,string>)[scope]}}</option></select></label
                >
                <template v-if="s.id === 'reception'">
                  <label v-for="action in receptionRows" :key="action.key">
                    <input type="checkbox" :aria-label="action.label" :checked="receptionState(action.codes).checked" :indeterminate="receptionState(action.codes).partial"
                      :disabled="!!saved?.protected || saving || !hasDraftPermission(draft, 'reception.view') || action.codes.some(code => !store.allowedScopes(code).length && !draft.permissions.includes(code))"
                      @change="toggleReception(action.codes, ($event.target as HTMLInputElement).checked)" />
                    {{ action.label }}<small v-if="receptionState(action.codes).partial">Qisman</small>
                    <select v-if="receptionState(action.codes).checked && receptionScopes(action.codes).length" :aria-label="action.label+' doirasi'" :disabled="!!saved?.protected || saving" :value="receptionScope(action.codes)" @change="setReceptionScope(action.codes, ($event.target as HTMLSelectElement).value)">
                      <option disabled value="">Turli doiralar</option>
                      <option v-for="scope in receptionScopes(action.codes)" :key="scope" :value="scope">{{({own:'O‘ziga tegishli',assigned_branches:'Biriktirilgan filiallar',entire_clinic:'Butun klinika'} as Record<string,string>)[scope]}}</option>
                    </select>
                  </label>
                  <p>Umumiy ruxsatlar boshqa yoqilgan amallar uchun saqlanadi.</p>
                </template>
                <p v-if="s.parent && !hasDraftPermission(draft, s.parent)">
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
          <div v-if="scopeConflicts.length" class="sr-error" role="alert">
            <strong>{{ scopeConflicts.length }} ta ruxsat doirasi mos emas:</strong>
            <p v-for="(issue, index) in scopeConflicts" :key="index">{{ issue }}</p>
          </div>
          <p class="sr-demo">
            <Info :size="17" />Rollar server orqali boshqariladi. Klinik demo sahifalarining backend amallari alohida ulanadi.
          </p>
          <p v-if="!creating && count" class="sr-hint">
            Ruxsatlar saqlanganda shu rolga biriktirilgan {{ count }} ta xodimga
            taalluqli bo‘ladi.
          </p>
          <p v-if="saved?.protected" class="sr-hint">Himoyalangan tizim roli: tahrirlash va o‘chirish bloklangan.</p><p v-if="error" class="sr-error" role="alert">{{ error }}</p>
          <footer>
            <button type="button" class="sr-button" @click="cancel">
              Bekor qilish</button
            ><button
              type="submit"
              class="sr-button primary"
              :disabled="saving||(!!saved?.protected&&!creating)||!can(creating?'roles.create':'roles.update')||!can('roles.manage_permissions')"
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
