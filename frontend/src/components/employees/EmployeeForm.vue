<script setup lang="ts">
import { computed, ref, onBeforeUnmount, watch } from "vue";
import PagePermissions from "./PagePermissions.vue";
import EmployeeScheduleSection from "./EmployeeScheduleSection.vue";
import "./page-permissions.css";
import DateField from "./DateField.vue";
import { UserRound, Upload, Save, Eye, EyeOff, Info } from "lucide-vue-next";
import {
  blankEmployee,
  clone,
  positions,
  today,
  type Employee,
} from "../../mocks/employees";
import { useRolesStore } from "../settings/store";
const workSchedule = ref<InstanceType<typeof EmployeeScheduleSection>>();
defineExpose({
  commitSchedule: (employee: Employee) => workSchedule.value?.commit(employee),
});
const rolesStore = useRolesStore();
const branches = computed(() => session.branches);
import { session, can } from "../../services/session";
const props = defineProps<{
  employee: Employee | null;
  saving?: boolean;
  serverErrors?: Record<string, string>;
}>();
const emit = defineEmits<{
  cancel: [];
  save: [Employee, string?, { value: string; confirmation: string }?];
  link: [Employee, { value: string; confirmation: string }?];
}>();
const draft = ref(clone(props.employee ?? blankEmployee()));
if (!props.employee) {
  draft.value.branch = session.branches[0]?.id ?? "";
  draft.value.roleId = "";
  draft.value.pageGrants = [];
  draft.value.branchIds = draft.value.branch ? [draft.value.branch] : [];
}
watch(
  () => props.employee,
  (e) => {
    if (e) {
      draft.value.id = e.id;
      draft.value.version = e.version;
      draft.value.membershipId = e.membershipId;
      draft.value.cashierPinSet = e.cashierPinSet;
    }
  },
);
const errors = ref<Record<string, string>>({});
watch(
  () => props.serverErrors,
  (v) => {
    errors.value = { ...errors.value, ...v };
  },
  { deep: true },
);
const password = ref("");
const showPassword = ref(false);
const cashierPin = ref("");
const cashierPinConfirmation = ref("");
const showPin = ref(false);
const pageOptions = computed(() =>
  rolesStore.sections.value.map((section) => ({
    code: section.permissions[0]!.code,
    label: section.label,
    parent: section.parent,
  })),
);
const pageGrants = computed(
  () =>
    draft.value.pageGrants ??
    rolesStore.state.value.roles
      .find((r) => r.id === draft.value.roleId)
      ?.grants.filter((g) => g.code.endsWith(".view")) ??
    [],
);
function setPageScope(code: string, scope: string) {
  if (
    !can("employees.assign_role") ||
    !rolesStore.allowedScopes(code).includes(scope)
  )
    return;
  draft.value.pageGrants = pageGrants.value.map((g) => ({
    ...g,
    scope: g.code === code ? scope : g.scope,
  }));
}
const cashierSelected = computed(() =>
  pageGrants.value.some((g) => g.code === "cashier.view"),
);
function togglePage(code: string, on: boolean) {
  if (!can("employees.assign_role")) return;
  const grants = new Map(pageGrants.value.map((g) => [g.code, g.scope]));
  const option = pageOptions.value.find((p) => p.code === code);
  if (on) {
    for (const c of [option?.parent, code].filter((c): c is string => !!c)) {
      const scopes = rolesStore.allowedScopes(c);
      if (!scopes.length) return;
      if (!grants.has(c))
        grants.set(
          c,
          scopes.includes("assigned_branches")
            ? "assigned_branches"
            : scopes[0]!,
        );
    }
  } else {
    grants.delete(code);
    for (const child of pageOptions.value.filter((p) => p.parent === code))
      grants.delete(child.code);
  }
  draft.value.pageGrants = [...grants].map(([code, scope]) => ({
    code,
    scope,
  }));
}
watch(cashierSelected, (selected) => {
  if (!selected) {
    cashierPin.value = "";
    cashierPinConfirmation.value = "";
  }
});
let preview = "";
watch(
  () => draft.value.active,
  (active) => {
    if (!active) draft.value.access = false;
  },
);
watch(
  () => draft.value.access,
  (access) => {
    if (!access) password.value = "";
  },
);
onBeforeUnmount(() => {
  if (preview) URL.revokeObjectURL(preview);
  password.value = "";
  cashierPin.value = "";
  cashierPinConfirmation.value = "";
});
function photo(event: Event) {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;
  if (
    !["image/jpeg", "image/png"].includes(file.type) ||
    file.size > 2 * 1024 * 1024
  ) {
    errors.value.photo = "JPG yoki PNG, maksimal 2 MB.";
    input.value = "";
    return;
  }
  delete errors.value.photo;
  if (preview) URL.revokeObjectURL(preview);
  preview = URL.createObjectURL(file);
  const reader = new FileReader();
  reader.onload = () => {
    draft.value.photo = String(reader.result);
  };
  reader.readAsDataURL(file);
}
function generate() {
  const alphabet = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#";
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  password.value = Array.from(bytes, (b) => alphabet[b % alphabet.length]).join(
    "",
  );
}
function save(link = false) {
  if (link && workSchedule.value && !workSchedule.value.validateLink()) return;
  if (workSchedule.value && !workSchedule.value.validate()) return;
  const d = draft.value;
  const e: Record<string, string> = {};
  for (const key of ["last", "first", "phone", "position", "branch"] as const)
    if (!d[key].trim()) e[key] = "Majburiy maydon.";
  if (d.phone && !/^998\d{9}$/.test(d.phone.replace(/\D/g, "")))
    e.phone = "Telefonni +998 va 9 ta raqam bilan kiriting.";
  if (d.dob && d.dob > today()) e.dob = "Tug‘ilgan sana kelajakda bo‘lmasin.";
  if (d.position === "Shifokor") {
    if (!d.specialty.trim()) e.specialty = "Mutaxassislikni kiriting.";
    if (!Number.isInteger(d.duration) || d.duration < 5 || d.duration > 480)
      e.duration = "5–480 daqiqa kiriting.";
  }
  if (d.access) {
    if (!d.login.trim()) e.login = "Loginni kiriting.";
    if (!rolesStore.state.value.roles.some((r) => r.id === d.roleId))
      e.role = "Ruxsat rolini tanlang.";
    if (!link && !props.employee?.membershipId && password.value.length < 12)
      e.password = "Yangi hisob paroli kamida 12 belgi bo‘lsin.";
    if (password.value && password.value.length < 12)
      e.password = "Kamida 12 belgi kiriting.";
  }
  if (
    cashierSelected.value &&
    (!props.employee?.cashierPinSet ||
      cashierPin.value ||
      cashierPinConfirmation.value)
  ) {
    if (!/^[0-9]{4}$/.test(cashierPin.value))
      e.cashier_pin = "4 raqamli PIN-kod kiriting.";
    if (cashierPin.value !== cashierPinConfirmation.value)
      e.cashier_pin_confirmation = "PIN-kodlar mos emas.";
  }
  errors.value = e;
  if (Object.keys(e).length) return;
  if (!d.access) {
    d.login = "";
    d.role = "";
    d.roleId = "";
  }
  if (d.access)
    d.role =
      rolesStore.state.value.roles.find((r) => r.id === d.roleId)?.name ??
      d.role;
  if (d.position !== "Shifokor") d.specialty = "";
  const secret = password.value;
  password.value = "";
  if (cashierSelected.value && cashierPin.value && d.pageGrants == null)
    d.pageGrants = clone(pageGrants.value);
  const pin =
    cashierSelected.value && cashierPin.value
      ? { value: cashierPin.value, confirmation: cashierPinConfirmation.value }
      : undefined;
  cashierPin.value = "";
  cashierPinConfirmation.value = "";
  if (link) emit("link", clone(d), pin);
  else emit("save", clone(d), secret || undefined, pin);
}
</script>
<template>
  <form class="employees-form" novalidate @submit.prevent="save()">
    <fieldset :disabled="saving" style="display: contents">
      <div class="e-breadcrumb">
        <button type="button" @click="emit('cancel')">Xodimlar</button> /
        {{ employee ? "Xodimni tahrirlash" : "Xodim qo‘shish" }}
      </div>
      <div class="e-page-head">
        <div>
          <h1>{{ employee ? "Xodimni tahrirlash" : "Xodim qo‘shish" }}</h1>
          <p>
            {{
              employee
                ? "Xodim ma’lumotlarini yangilang."
                : "Yangi xodim ma’lumotlarini kiriting."
            }}
          </p>
        </div>
        <div class="e-actions">
          <button type="button" class="e-button" @click="emit('cancel')">
            Bekor qilish</button
          ><button class="e-button primary" type="submit">
            <Save :size="18" />Saqlash
          </button>
        </div>
      </div>
      <div class="e-two-columns">
        <div>
          <section class="e-card">
            <h2>Shaxsiy ma’lumotlar</h2>
            <div class="e-photo-row">
              <span class="e-photo"
                ><img
                  v-if="draft.photo"
                  :src="draft.photo"
                  alt="Xodim surati" /><UserRound v-else :size="40"
              /></span>
              <div>
                <label class="e-button upload"
                  ><Upload :size="17" />Surat yuklash<input
                    type="file"
                    accept="image/jpeg,image/png"
                    disabled
                    @change="photo"
                /></label>
                <p class="e-hint">Surat yuklash backendga hali ulanmagan.</p>
                <small v-if="errors.photo" class="e-error">{{
                  errors.photo
                }}</small>
              </div>
            </div>
            <div class="e-form-grid">
              <label
                >Familiya <em>*</em
                ><input
                  v-model="draft.last"
                  aria-label="Familiya *"
                  aria-required="true"
                  placeholder="Familiyani kiriting"
                  :aria-invalid="!!errors.last"
                /><small v-if="errors.last" class="e-error">{{
                  errors.last
                }}</small></label
              ><label
                >Ism <em>*</em
                ><input
                  v-model="draft.first"
                  aria-label="Ism *"
                  aria-required="true"
                  placeholder="Ismni kiriting"
                /><small v-if="errors.first" class="e-error">{{
                  errors.first
                }}</small></label
              ><label
                >Otasining ismi<input
                  v-model="draft.patronymic"
                  placeholder="Otasining ismini kiriting" /></label
              ><label
                >Telefon <em>*</em
                ><input
                  v-model="draft.phone"
                  aria-label="Telefon *"
                  aria-required="true"
                  type="tel"
                  placeholder="+998 90 123 45 67"
                /><small v-if="errors.phone" class="e-error">{{
                  errors.phone
                }}</small></label
              ><label
                >Tug‘ilgan sana<DateField
                  v-model="draft.dob"
                  label="Tug‘ilgan sana"
                  :max="today()"
                /><small v-if="errors.dob" class="e-error">{{
                  errors.dob
                }}</small></label
              ><label
                >Jins<select v-model="draft.gender" aria-label="Jins">
                  <option value="">Tanlang</option>
                  <option>Erkak</option>
                  <option>Ayol</option>
                </select></label
              ><label class="span-2"
                >Manzil<input
                  v-model="draft.address"
                  placeholder="Manzilni kiriting"
              /></label>
            </div>
          </section>
          <section class="e-card">
            <h2>Ish ma’lumotlari</h2>
            <div class="e-form-grid">
              <label
                >Lavozim <em>*</em
                ><select v-model="draft.position" aria-label="Lavozim *">
                  <option v-for="p in positions" :key="p">
                    {{ p }}
                  </option></select
                ><small v-if="errors.position" class="e-error">{{
                  errors.position
                }}</small></label
              ><label
                >Filial <em>*</em
                ><select v-model="draft.branch" aria-label="Filial *">
                  <option v-for="b in branches" :key="b.id" :value="b.id">
                    {{ b.name }}
                  </option></select
                ><small v-if="errors.branch" class="e-error">{{
                  errors.branch
                }}</small></label
              >
              <div v-if="cashierSelected" class="e-label employee-pages">
                <label
                  >Kassa PIN-kodi<input
                    v-model="cashierPin"
                    aria-label="Kassa PIN-kodi"
                    :type="showPin ? 'text' : 'password'"
                    inputmode="numeric"
                    maxlength="4"
                    autocomplete="new-password"
                /></label>
                <label
                  >PIN-kodni tasdiqlash<input
                    v-model="cashierPinConfirmation"
                    aria-label="PIN-kodni tasdiqlash"
                    :type="showPin ? 'text' : 'password'"
                    inputmode="numeric"
                    maxlength="4"
                    autocomplete="new-password"
                /></label>
                <button
                  type="button"
                  class="e-button"
                  @click="showPin = !showPin"
                >
                  {{ showPin ? "PINni yashirish" : "PINni ko‘rsatish" }}
                </button>
                <small v-if="employee?.cashierPinSet" class="e-hint"
                  >Yangi PIN kiritilmasa, amaldagi PIN saqlanadi.</small
                >
                <small
                  v-if="errors.cashier_pin || errors.cashier_pin_confirmation"
                  class="e-error"
                  >{{
                    errors.cashier_pin || errors.cashier_pin_confirmation
                  }}</small
                >
              </div>
              <label
                >Klinik bo‘lim (ixtiyoriy)<input
                  v-model="draft.department"
                  aria-label="Klinik bo‘lim (ixtiyoriy)" /></label
              ><label
                >Ishga kirgan sana<DateField
                  v-model="draft.hired"
                  label="Ishga kirgan sana" /></label
              ><label
                >Holati<select
                  v-model="draft.active"
                  aria-label="Holati"
                  :class="{ green: draft.active }"
                >
                  <option :value="true">● Faol</option>
                  <option :value="false">Faol emas</option>
                </select></label
              ><template v-if="draft.position === 'Shifokor'"
                ><label
                  >Mutaxassislik <em>*</em
                  ><input
                    v-model="draft.specialty"
                    placeholder="Mutaxassislikni kiriting"
                  /><small v-if="errors.specialty" class="e-error">{{
                    errors.specialty
                  }}</small></label
                ><label
                  >Qabul davomiyligi <em>*</em
                  ><input
                    v-model.number="draft.duration"
                    type="number"
                    min="5"
                    max="480"
                  /><small v-if="errors.duration" class="e-error">{{
                    errors.duration
                  }}</small></label
                ></template
              >
            </div>
          </section>
          <section class="e-card">
            <label
              >Izoh (ixtiyoriy)<textarea
                v-model="draft.note"
                rows="3"
                placeholder="Qo‘shimcha ma’lumot kiriting."
              />
            </label>
          </section>
        </div>
        <div>
          <section class="e-card access-card">
            <h2>Tizimga kirish</h2>
            <div class="access-toggle">
              <button
                type="button"
                class="e-switch"
                :class="{ on: draft.access }"
                role="switch"
                :aria-checked="draft.access"
                aria-label="Tizimga kirishga ruxsat berish"
                :disabled="!draft.active"
                @click="draft.access = !draft.access"
              >
                <i />
              </button>
              <div>
                <b>Tizimga kirishga ruxsat berish</b>
                <p class="e-hint">
                  Tizimga kirmaydigan xodimlar uchun o‘chirib qo‘ying.
                </p>
              </div>
            </div>
            <template v-if="draft.access"
              ><label class="e-label"
                >Login <em>*</em
                ><input
                  v-model="draft.login"
                  :readonly="!!employee?.membershipId"
                  aria-label="Login *"
                  aria-required="true"
                  autocomplete="off"
                  placeholder="Login kiriting"
                /><small v-if="errors.login" class="e-error">{{
                  errors.login
                }}</small></label
              ><label class="e-label"
                >Ruxsat roli <em>*</em
                ><select
                  v-model="draft.roleId"
                  aria-label="Ruxsat roli *"
                  :disabled="!can('employees.assign_role')"
                >
                  <option value="">Rolni tanlang</option>
                  <option
                    v-for="r in rolesStore.state.value.roles"
                    :key="r.id"
                    :value="r.id"
                    :disabled="r.protected || !r.active"
                  >
                    {{ r.name }}
                  </option></select
                ><small v-if="errors.role" class="e-error">{{
                  errors.role
                }}</small></label
              >
              <div class="e-label employee-branches">
                <span>Kirish filiallari</span>
                <label
                  v-for="branch in branches"
                  :key="branch.id"
                  class="e-hint"
                >
                  <input
                    type="checkbox"
                    v-model="draft.branchIds"
                    :value="branch.id"
                    :aria-label="'Kirish filiali: ' + branch.name"
                    :disabled="!can('employees.assign_branches')"
                  />
                  {{ branch.name }}
                </label>
                <small class="e-hint"
                  >Bu biriktirishlar xodimning asosiy filialidan alohida
                  boshqariladi.</small
                >
              </div>
              <template v-if="!employee?.membershipId"
                ><label class="e-label">Yangi hisob paroli *</label>
                <div class="password-row">
                  <div>
                    <input
                      v-model="password"
                      aria-label="Yangi hisob paroli"
                      :type="showPassword ? 'text' : 'password'"
                      autocomplete="new-password"
                    /><button
                      type="button"
                      class="e-icon"
                      :aria-label="
                        showPassword
                          ? 'Parolni yashirish'
                          : 'Parolni ko‘rsatish'
                      "
                      @click="showPassword = !showPassword"
                    >
                      <EyeOff v-if="showPassword" :size="19" /><Eye
                        v-else
                        :size="19"
                      />
                    </button>
                  </div>
                  <button class="e-button" type="button" @click="generate">
                    Parol yaratish
                  </button>
                </div>
                <small v-if="errors.password" class="e-error">{{
                  errors.password
                }}</small>
                <p class="e-hint">
                  Vaqtinchalik parol. Xodim birinchi kirishda uni almashtiradi.
                </p></template
              >
              <p v-else class="e-hint">
                Parol alohida profil amalidan almashtiriladi.
              </p></template
            >
            <p v-else class="e-hint">Tizimga kirish ruxsati berilmagan.</p>
            <button
              v-if="
                !employee?.membershipId &&
                can('employees.lookup_identity') &&
                can('employees.assign_role')
              "
              type="button"
              class="e-button"
              :disabled="!draft.access"
              @click="save(true)"
            >
              Mavjud hisobni bog‘lash
            </button>
            <p v-if="!employee?.membershipId" class="e-hint">
              Mavjud login uchun alohida rozilik so‘rovi yuboriladi. Yangi hisob
              yaratilmaydi.
            </p>
          </section>
          <div class="e-info">
            <Info :size="23" /><span
              ><b>Lavozim</b> — klinikadagi vazifasi.<br /><b>Ruxsat roli</b> —
              tizimdagi huquqlari.</span
            >
          </div>
          <p class="e-required">* Majburiy maydonlar</p>
        </div>
      </div>
      <section class="e-card employee-form-permissions-card">
        <PagePermissions
          :options="pageOptions"
          :grants="pageGrants"
          :editable="can('employees.assign_role')"
          :allowed-scopes="rolesStore.allowedScopes"
          @toggle="togglePage"
          @scope="setPageScope"
        />
      </section>
      <EmployeeScheduleSection
        ref="workSchedule"
        :position="draft.position"
        :membership-id="draft.membershipId"
        :branch-id="draft.branch"
      />
    </fieldset>
  </form>
</template>
