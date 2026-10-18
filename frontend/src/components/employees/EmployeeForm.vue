<script setup lang="ts">
import { ref, onBeforeUnmount, watch } from "vue";
import DateField from "./DateField.vue";
import { UserRound, Upload, Save, Eye, EyeOff, Info } from "lucide-vue-next";
import {
  blankEmployee,
  clone,
  positions,
  branches,
  departments,
  today,
  type Employee,
} from "../../mocks/employees";
const props = defineProps<{ employee: Employee | null }>();
const emit = defineEmits<{ cancel: []; save: [Employee] }>();
const draft = ref(clone(props.employee ?? blankEmployee()));
const errors = ref<Record<string, string>>({});
const password = ref("");
const showPassword = ref(false);
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
function save() {
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
    if (!d.role) e.role = "Ruxsat rolini tanlang.";
    if (
      (!props.employee || !props.employee.access) &&
      password.value.length < 8
    )
      e.password = "Demo parol kamida 8 belgi bo‘lsin.";
    if (password.value && password.value.length < 8)
      e.password = "Kamida 8 belgi kiriting.";
  }
  errors.value = e;
  if (Object.keys(e).length) return;
  if (!d.access) {
    d.login = "";
    d.role = "";
  }
  if (d.position !== "Shifokor") d.specialty = "";
  password.value = "";
  emit("save", clone(d));
}
</script>
<template>
  <form class="employees-form" novalidate @submit.prevent="save">
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
                  @change="photo"
              /></label>
              <p class="e-hint">JPG, PNG format. Maksimal 2 MB.</p>
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
                <option v-for="p in positions" :key="p">{{ p }}</option></select
              ><small v-if="errors.position" class="e-error">{{
                errors.position
              }}</small></label
            ><label
              >Filial <em>*</em
              ><select v-model="draft.branch" aria-label="Filial *">
                <option v-for="b in branches" :key="b">{{ b }}</option></select
              ><small v-if="errors.branch" class="e-error">{{
                errors.branch
              }}</small></label
            ><label
              >Bo‘lim<select v-model="draft.department" aria-label="Bo‘lim">
                <option value="">Tanlang</option>
                <option v-for="d in departments" :key="d">{{ d }}</option>
              </select></label
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
                aria-label="Login *"
                aria-required="true"
                autocomplete="off"
                placeholder="Login kiriting"
              /><small v-if="errors.login" class="e-error">{{
                errors.login
              }}</small></label
            ><label class="e-label"
              >Ruxsat roli <em>*</em
              ><select v-model="draft.role" aria-label="Ruxsat roli *">
                <option v-for="p in positions" :key="p">{{ p }}</option></select
              ><small v-if="errors.role" class="e-error">{{
                errors.role
              }}</small></label
            ><label class="e-label">{{
              employee?.access ? "Yangi demo parol (ixtiyoriy)" : "Parol *"
            }}</label>
            <div class="password-row">
              <div>
                <input
                  v-model="password"
                  aria-label="Demo parol"
                  :type="showPassword ? 'text' : 'password'"
                  autocomplete="new-password"
                /><button
                  type="button"
                  class="e-icon"
                  :aria-label="
                    showPassword ? 'Parolni yashirish' : 'Parolni ko‘rsatish'
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
              Demo: hisob yaratilmaydi; parol almashtirish — namoyish.
            </p></template
          >
          <p v-else class="e-hint">Tizimga kirish ruxsati berilmagan.</p>
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
  </form>
</template>
