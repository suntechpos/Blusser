<script setup lang="ts">
import { computed, ref, onMounted } from "vue";
import ReceptionDialog from "./ReceptionDialog.vue";
import DateField from "../employees/DateField.vue";
import { clinicRequest } from "../../services/api";
import { bookingError } from "../../services/receptionBooking";
import { can, session } from "../../services/session";
const props = defineProps<{ branchId: string; date: string }>();
const emit = defineEmits<{ close: []; saved: [] }>();
type Choice = {
  id: string;
  name?: string;
  number?: string;
  membership_id?: string;
  branch_ids?: string[];
  access?: boolean;
  branch_id?: string;
  specialty_id?: string;
  service_ids?: string[];
  active?: boolean;
};
const data = ref<Record<string, Choice[]>>({});
const error = ref(""),
  busy = ref(false),
  message = ref("");
const specialty = ref(""),
  service = ref(""),
  member = ref(""),
  doctor = ref(""),
  room = ref("");
const specialtyName = ref(""),
  specialtyCode = ref(""),
  serviceName = ref(""),
  serviceCode = ref(""),
  roomNumber = ref("");
const start = ref("09:00"),
  end = ref("17:00"),
  minutes = ref(30),
  date = ref(props.date),
  until = ref(props.date);
const weekday = ref(
  Number(new Date(props.date + "T12:00:00Z").getUTCDay()) || 7,
);
const branch = computed(() =>
  session.branches.find((b) => b.id === props.branchId),
);
const members = computed(() =>
  (data.value.employees ?? []).filter(
    (m) =>
      m.access &&
      m.active &&
      m.membership_id &&
      m.branch_ids?.includes(props.branchId),
  ),
);
async function listing(path: string) {
  const rows: Choice[] = [];
  for (let page = 1; ; page++) {
    const r = await clinicRequest<{ data: Choice[]; meta?: { total: number } }>(
      path,
      {
        params: {
          page,
          per_page: 50,
          ...(path !== "employees" ? { status: "active" } : {}),
          ...(path === "employees" ? { format: "profile" } : {}),
        },
      },
    );
    rows.push(...r.data.data);
    if (
      r.data.meta ? rows.length >= r.data.meta.total : r.data.data.length < 50
    )
      return rows;
  }
}
async function load() {
  error.value = "";
  busy.value = true;
  try {
    for (const [key, path] of Object.entries({
      employees: "employees",
      specialties: "specialties",
      services: "appointments/configuration-services",
      doctors: "doctors",
      rooms: "rooms",
    }))
      data.value[key] = await listing(path);
  } catch (e) {
    error.value = bookingError(e);
  } finally {
    busy.value = false;
  }
}
async function create(
  kind: string,
  body: unknown,
  select: (id: string) => void,
) {
  if (busy.value) return;
  busy.value = true;
  error.value = "";
  message.value = "";
  try {
    const r = await clinicRequest<Choice>(kind, { method: "POST", body });
    select(r.data.id);
    message.value = "Saqlandi.";
    emit("saved");
    busy.value = false;
    await load();
  } catch (e) {
    error.value = bookingError(e);
  } finally {
    busy.value = false;
  }
}
async function assignService() {
  const d = data.value.doctors?.find((d) => d.id === doctor.value);
  if (!d || !service.value || !can("doctors.update") || busy.value) return;
  busy.value = true;
  error.value = "";
  try {
    await clinicRequest(`doctors/${d.id}`, {
      method: "PUT",
      body: {
        membership_id: d.membership_id,
        specialty_id: d.specialty_id,
        branch_ids: d.branch_ids,
        service_ids: [...new Set([...(d.service_ids ?? []), service.value])],
        active: d.active,
      },
    });
    message.value = "Xizmat biriktirildi.";
    emit("saved");
    busy.value = false;
    await load();
  } catch (e) {
    error.value = bookingError(e);
  } finally {
    busy.value = false;
  }
}
onMounted(load);
</script>
<template>
  <ReceptionDialog
    title="Qabulni sozlash"
    subtitle="Faqat vakolatli administrator uchun"
    @close="!busy && emit('close')"
  >
    <div class="rp-dialog-body rp-configuration">
      <p class="rp-hint">
        {{ branch?.name }}. Mavjud hisoblar va biriktirishlar o‘zgartirilmaydi.
        Har bir bosqich alohida serverga saqlanadi.
      </p>
      <p v-if="error" role="alert" class="rp-error">
        {{ error }}
        <button type="button" class="rp-link" @click="load">Yangilash</button>
      </p>
      <p v-if="message" role="status">{{ message }}</p>
      <fieldset :disabled="busy" class="rp-section">
        <legend>1. Mutaxassislik va xizmat</legend>
        <div class="rp-form-grid">
          <label
            >Mutaxassislik<select
              aria-label="Mutaxassislik"
              v-model="specialty"
            >
              <option value="">Tanlang</option>
              <option v-for="s in data.specialties" :key="s.id" :value="s.id">
                {{ s.name }}
              </option>
            </select></label
          >
          <label
            >Xizmat<select aria-label="Xizmat" v-model="service">
              <option value="">Tanlang</option>
              <option v-for="s in data.services" :key="s.id" :value="s.id">
                {{ s.name }}
              </option>
            </select></label
          >
        </div>
        <form
          class="rp-form-grid"
          data-permission="doctors.create"
          @submit.prevent="
            create(
              'specialties',
              { code: specialtyCode, name: specialtyName, active: true },
              (id) => (specialty = id),
            )
          "
        >
          <label
            >Yangi mutaxassislik kodi<input
              v-model="specialtyCode"
              required
              maxlength="64" /></label
          ><label
            >Nomi<input
              v-model="specialtyName"
              required
              maxlength="255" /></label
          ><button class="rp-button" type="submit">
            Mutaxassislik yaratish
          </button>
        </form>
        <form
          class="rp-form-grid"
          data-permission="services.create"
          @submit.prevent="
            create(
              'appointments/configuration-services',
              { code: serviceCode, name: serviceName, active: true },
              (id) => (service = id),
            )
          "
        >
          <label
            >Yangi xizmat kodi<input
              v-model="serviceCode"
              required
              maxlength="64" /></label
          ><label
            >Xizmat nomi<input
              v-model="serviceName"
              required
              maxlength="255" /></label
          ><button class="rp-button" type="submit">Xizmat yaratish</button>
        </form>
      </fieldset>
      <fieldset :disabled="busy" class="rp-section">
        <legend>2. Shifokor</legend>
        <label
          >Mavjud shifokor<select aria-label="Mavjud shifokor" v-model="doctor">
            <option value="">Tanlang</option>
            <option v-for="d in data.doctors" :key="d.id" :value="d.id">
              {{
                data.employees?.find((e) => e.membership_id === d.membership_id)
                  ?.name || d.id
              }}
            </option>
          </select></label
        >
        <button
          v-if="can('doctors.update')"
          type="button"
          data-permission="doctors.update"
          class="rp-button"
          :disabled="!doctor || !service || busy"
          @click="assignService"
        >
          Tanlangan xizmatni shifokorga qo‘shish
        </button>
        <form
          data-permission="doctors.create"
          @submit.prevent="
            create(
              'doctors',
              {
                membership_id: member,
                specialty_id: specialty,
                service_ids: [service],
                branch_ids: [branchId],
                active: true,
              },
              (id) => (doctor = id),
            )
          "
        >
          <label
            >Faol xodim hisobi<select
              aria-label="Faol xodim hisobi"
              v-model="member"
              required
            >
              <option value="">Tanlang</option>
              <option v-for="m in members" :key="m.id" :value="m.membership_id">
                {{ m.name }}
              </option>
            </select></label
          >
          <p class="rp-hint">
            Tanlangan filialga kirishi bor hisob kerak. Hisob bo‘lmasa, avval
            Xodimlarda yarating. Mavjud shifokor biriktirishlari
            almashtirilmaydi.
          </p>
          <button
            class="rp-button"
            type="submit"
            :disabled="!member || !specialty || !service"
          >
            Shifokor profilini yaratish
          </button>
        </form>
      </fieldset>
      <fieldset :disabled="busy" class="rp-section">
        <legend>3. Xona</legend>
        <label
          >Mavjud xona<select aria-label="Mavjud xona" v-model="room">
            <option value="">Tanlang</option>
            <option
              v-for="r in data.rooms?.filter(
                (r) => r.active && r.branch_id === branchId,
              )"
              :key="r.id"
              :value="r.id"
            >
              {{ r.number }}
            </option>
          </select></label
        >
        <form
          data-permission="rooms.create"
          @submit.prevent="
            create(
              'rooms',
              {
                branch_id: branchId,
                number: roomNumber,
                service_type: 'consultation',
                active: true,
                availability: 'available',
              },
              (id) => (room = id),
            )
          "
        >
          <label
            >Yangi xona raqami<input
              v-model="roomNumber"
              required
              maxlength="255" /></label
          ><button class="rp-button" type="submit">Xona yaratish</button>
        </form>
      </fieldset>
      <form
        class="rp-section"
        data-permission="schedules.create"
        @submit.prevent="
          create(
            'schedules',
            {
              doctor_id: doctor,
              branch_id: branchId,
              room_id: room,
              weekday,
              local_start: start,
              local_end: end,
              slot_minutes: minutes,
              effective_from: date,
              effective_to: until,
              breaks: [],
            },
            () => {},
          )
        "
      >
        <h3>4. Ish jadvali</h3>
        <fieldset :disabled="busy" class="rp-form-grid">
          <label
            >Hafta kuni<select aria-label="Hafta kuni" v-model.number="weekday">
              <option
                v-for="(d, i) in [
                  'Dushanba',
                  'Seshanba',
                  'Chorshanba',
                  'Payshanba',
                  'Juma',
                  'Shanba',
                  'Yakshanba',
                ]"
                :key="i"
                :value="i + 1"
              >
                {{ d }}
              </option>
            </select></label
          >
          <label
            >Qabul davomiyligi (daqiqa)<input
              v-model.number="minutes"
              required
              type="number"
              min="1"
              max="480"
          /></label>
          <label>Boshlanish<input v-model="start" required type="time" /></label
          ><label>Tugash<input v-model="end" required type="time" /></label>
          <label
            >Amal qilish boshlanishi<DateField
              v-model="date"
              label="Jadval boshlanishi" /></label
          ><label
            >Amal qilish tugashi<DateField
              v-model="until"
              label="Jadval tugashi"
              :min="date"
          /></label>
        </fieldset>
        <p class="rp-hint">
          Vaqtlar filial vaqt mintaqasida. Bandlik va boshqa jadvallar backendda
          tekshiriladi. Jadval tanlangan hafta kunida amal qiladi.
        </p>
        <button
          class="rp-button rp-primary"
          type="submit"
          :disabled="busy || !doctor || !room || !date || !until"
        >
          Jadval yaratish
        </button>
      </form>
    </div>
    <template #footer
      ><button class="rp-button" :disabled="busy" @click="emit('close')">
        Yopish
      </button></template
    >
  </ReceptionDialog>
</template>
