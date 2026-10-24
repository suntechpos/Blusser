<script setup lang="ts">
import { computed, ref, watch, onBeforeUnmount } from "vue";
import DateField from "./DateField.vue";
import { session, can } from "../../services/session";
import { clinicRequest, errorText } from "../../services/api";
import {
  scheduleList,
  employeeDoctor,
  saveRules,
  type DoctorRecord,
  type Rule,
  type Option,
} from "../../services/employeeSchedule";
import type { Employee } from "../../mocks/employees";
const props = defineProps<{
  position: string;
  membershipId?: string | null;
  branchId: string;
}>();
const enabled = ref(false),
  loading = ref(false),
  error = ref(""),
  notice = ref("");
const doctor = ref<DoctorRecord>(),
  specialties = ref<Option[]>([]),
  services = ref<Option[]>([]),
  rooms = ref<Option[]>([]),
  existing = ref<Rule[]>([]);
const branch = ref(props.branchId),
  specialty = ref(""),
  serviceIds = ref<string[]>([]),
  room = ref("");
const days = ref<number[]>([]),
  separate = ref(false),
  start = ref("09:00"),
  end = ref("17:00"),
  minutes = ref(30),
  from = ref(""),
  to = ref("");
const perDay = ref<Record<number, { start: string; end: string }>>({});
const editing = ref<Record<number, Rule>>({});
const completed = new Map<string, Rule>();
const names = [
  "Dushanba",
  "Seshanba",
  "Chorshanba",
  "Payshanba",
  "Juma",
  "Shanba",
  "Yakshanba",
];
const isDoctor = computed(() => props.position === "Shifokor");
let serial = 0;
onBeforeUnmount(() => serial++);
function newRule() {
  enabled.value = true;
  editing.value = {};
  completed.clear();
  days.value = [];
  perDay.value = {};
  start.value = "09:00";
  end.value = "17:00";
  room.value = "";
  from.value = "";
  to.value = "";
  separate.value = false;
  error.value = "";
  notice.value = "Yangi jadval. Mavjud jadvallar o‘zgarmaydi.";
}
function edit(rule: Rule) {
  specialty.value = doctor.value?.specialty_id ?? "";
  serviceIds.value = [...(doctor.value?.service_ids ?? [])];
  room.value = rule.room_id;
  from.value = rule.effective_from;
  to.value = rule.effective_to ?? "";
  minutes.value = rule.slot_minutes;
  start.value = rule.local_start;
  end.value = rule.local_end;
  const group = existing.value.filter(
    (r) =>
      r.effective_from === rule.effective_from &&
      r.effective_to === rule.effective_to &&
      r.room_id === rule.room_id &&
      r.slot_minutes === rule.slot_minutes,
  );
  const seen = new Set<number>();
  // Multiple intervals per weekday remain separate; edit one explicitly instead of collapsing them.
  const unique = group.every(
    (r) => !seen.has(r.weekday) && !!seen.add(r.weekday),
  );
  const selected = unique ? group : [rule];
  days.value = selected.map((r) => r.weekday);
  editing.value = Object.fromEntries(selected.map((r) => [r.weekday, r]));
  perDay.value = Object.fromEntries(
    selected.map((r) => [
      r.weekday,
      { start: r.local_start, end: r.local_end },
    ]),
  );
  separate.value = selected.some(
    (r) => r.local_start !== rule.local_start || r.local_end !== rule.local_end,
  );
}
async function load() {
  const n = ++serial;
  if (!isDoctor.value) return;
  loading.value = true;
  error.value = "";
  try {
    const [sp, sv, rm, d] = await Promise.all([
      scheduleList<Option>("specialties", { status: "active" }),
      scheduleList<Option>("appointments/configuration-services", {
        status: "active",
      }),
      scheduleList<Option>("rooms", {
        branch_id: branch.value,
        status: "active",
      }),
      props.membershipId
        ? employeeDoctor(props.membershipId)
        : Promise.resolve(undefined),
    ]);
    if (n !== serial) return;
    specialties.value = sp;
    services.value = sv;
    rooms.value = rm.filter((r) => r.availability === "available");
    doctor.value = d;
    const rules = d
      ? await scheduleList<Rule>("schedules", {
          doctor_id: d.id,
          branch_id: branch.value,
        })
      : [];
    if (n !== serial) return;
    existing.value = rules;
    if (!enabled.value) {
      specialty.value = d?.specialty_id ?? "";
      serviceIds.value = [...(d?.service_ids ?? [])];
      if (existing.value[0]) edit(existing.value[0]);
    }
  } catch (e) {
    if (n === serial) error.value = errorText(e);
  } finally {
    if (n === serial) loading.value = false;
  }
}
watch(branch, () => {
  editing.value = {};
  existing.value = [];
  room.value = "";
});
watch(
  () => props.position,
  () => {
    if (!isDoctor.value) enabled.value = false;
  },
);
watch(
  () => props.branchId,
  (b) => {
    if (!enabled.value) branch.value = b;
  },
);
watch(() => [props.position, props.membershipId, branch.value], load, {
  immediate: true,
});
watch(
  days,
  (ds) => {
    for (const day of ds)
      perDay.value[day] ??= { start: start.value, end: end.value };
  },
  { deep: true },
);
watch([start, end], () => {
  if (!separate.value)
    for (const day of days.value)
      perDay.value[day] = { start: start.value, end: end.value };
});
function validate() {
  if (!enabled.value) return true;
  error.value = "";
  if (!isDoctor.value)
    error.value = "Bu lavozim uchun umumiy ish jadvali API mavjud emas.";
  else if (loading.value)
    error.value = "Jadval ma’lumotlari yuklanishini kuting.";
  else if (
    !branch.value ||
    !specialty.value ||
    !serviceIds.value.length ||
    !room.value ||
    !days.value.length ||
    !from.value
  )
    error.value =
      "Filial, mutaxassislik, xizmat, xona, kunlar va boshlanish sanasini tanlang.";
  else if (to.value && to.value < from.value)
    error.value = "Tugash sanasi boshlanishdan oldin bo‘lmasin.";
  else if (
    !Number.isInteger(minutes.value) ||
    minutes.value < 1 ||
    minutes.value > 480
  )
    error.value = "Qabul davomiyligi 1–480 daqiqa bo‘lsin.";
  else if (
    days.value.some((day) => {
      const time = separate.value
        ? perDay.value[day]
        : { start: start.value, end: end.value };
      return !time?.start || !time?.end || time.end <= time.start;
    })
  )
    error.value = "Har bir kunda tugash vaqti boshlanishdan keyin bo‘lsin.";
  return !error.value;
}
async function commit(employee: Employee) {
  if (!enabled.value) return;
  if (!validate()) throw Error(error.value);
  if (!employee.membershipId || !employee.access)
    throw Error(
      "Xodim saqlandi. Shifokor jadvali uchun faol login hisobi kerak; avval hisobni bog‘lang.",
    );
  const desired = {
    branch: branch.value,
    specialty: specialty.value,
    services: [...serviceIds.value],
  };
  const rows: Rule[] = days.value.map((day) => {
    const time = separate.value
      ? perDay.value[day]!
      : { start: start.value, end: end.value };
    const previous = editing.value[day];
    return {
      id: previous?.id,
      doctor_id: "",
      branch_id: desired.branch,
      room_id: room.value,
      weekday: day,
      local_start: time.start,
      local_end: time.end,
      slot_minutes: minutes.value,
      effective_from: from.value,
      effective_to: to.value || null,
      breaks: previous?.breaks ?? [],
    };
  });
  try {
    let d = await employeeDoctor(employee.membershipId);
    if (!d) {
      if (!can("doctors.create"))
        throw Error("Shifokor profili yaratish uchun ruxsat yo‘q.");
      d = (
        await clinicRequest<DoctorRecord>("doctors", {
          method: "POST",
          body: {
            membership_id: employee.membershipId,
            specialty_id: desired.specialty,
            branch_ids: [desired.branch],
            service_ids: desired.services,
            active: true,
          },
        })
      ).data;
    } else {
      if (!d.active)
        throw Error(
          "Shifokor profili faol emas. Avval mavjud boshqaruv oqimida faollashtiring.",
        );
      if (
        d.specialty_id !== desired.specialty ||
        !d.branch_ids.includes(desired.branch) ||
        d.service_ids.length !== desired.services.length ||
        desired.services.some((s) => !d!.service_ids.includes(s))
      ) {
        if (!can("doctors.update"))
          throw Error(
            "Shifokor biriktirishlarini yangilash uchun ruxsat yo‘q.",
          );
        d = (
          await clinicRequest<DoctorRecord>(`doctors/${d.id}`, {
            method: "PUT",
            body: {
              membership_id: d.membership_id,
              specialty_id: desired.specialty,
              branch_ids: [...new Set([...d.branch_ids, desired.branch])],
              service_ids: desired.services,
              active: d.active,
            },
          })
        ).data;
      }
    }
    doctor.value = d;
    await saveRules(d.id, rows, completed, (r) => {
      editing.value[r.weekday] = r;
      notice.value = `${completed.size} ta kun serverga saqlandi.`;
    });
    error.value = "";
  } catch (e) {
    error.value = `Xodim saqlandi. Jadval to‘liq saqlanmadi: ${errorText(e)} Saqlangan kunlar takror yaratilmaydi; qayta urinib ko‘ring.`;
    throw Error(error.value);
  }
}
function validateLink() {
  if (!enabled.value) return true;
  error.value =
    "Mavjud hisobni bog‘lash roziligini avval yakunlang. Hozir jadval tanlovini o‘chiring; rozilikdan keyin xodimni tahrirlab jadvalni saqlang.";
  return false;
}
defineExpose({ validate, validateLink, commit });
</script>
<template>
  <section class="e-card employee-inline-schedule" aria-label="Ish jadvali">
    <h2>Ish jadvali</h2>
    <button
      v-if="isDoctor && existing.length"
      type="button"
      class="e-button"
      @click="newRule"
    >
      Yangi jadval
    </button>
    <p v-if="!isDoctor" class="e-hint">
      Umumiy xodim ish jadvali uchun backend API mavjud emas. Bu lavozim jadvali
      serverga saqlanmaydi; xodim profilini odatdagidek saqlash mumkin.
    </p>
    <label v-else class="employee-schedule-enable"
      ><input
        v-model="enabled"
        type="checkbox"
        aria-label="Ish jadvalini saqlash"
      />Ish jadvalini saqlash / yangilash</label
    >
    <p v-if="isDoctor" class="e-hint">
      Login hisobi faol bo‘lsin. Yangi hisob egasi avval vaqtinchalik parolini
      almashtirishi kerak. Profil va jadval alohida API amallarida saqlanadi.
    </p>
    <p class="e-hint">
      Jadval filiali xodimning kirish filiallariga biriktirilgan bo‘lsin. Faqat
      tanlangan kunlar yaratiladi yoki yangilanadi. Boshqa mavjud jadvallar
      o‘chirilmaydi.
    </p>
    <p v-if="loading" role="status">Jadval ma’lumotlari yuklanmoqda…</p>
    <p v-if="error" class="e-error" role="alert">
      {{ error }}
      <button type="button" class="e-button" @click="load">
        Jadvalni qayta yuklash
      </button>
    </p>
    <p v-if="notice" role="status">{{ notice }}</p>
    <fieldset
      :disabled="!isDoctor || !enabled || loading"
      class="employee-schedule-fields"
    >
      <label>Ish kunlari</label>
      <div class="employee-weekdays">
        <label v-for="(name, i) in names" :key="i"
          ><input
            v-model="days"
            type="checkbox"
            :value="i + 1"
            :aria-label="'Ish kuni: ' + name"
          />{{ name }}</label
        >
      </div>
      <label
        ><input
          v-model="separate"
          type="checkbox"
          aria-label="Kunlarni alohida sozlash"
        />Kunlarni alohida sozlash</label
      >
      <div v-if="!separate" class="e-form-grid">
        <label
          >Ish boshlanishi<input
            v-model="start"
            type="time"
            aria-label="Ish boshlanishi" /></label
        ><label
          >Ish tugashi<input v-model="end" type="time" aria-label="Ish tugashi"
        /></label>
      </div>
      <div v-else class="employee-day-times">
        <div v-for="day in days" :key="day" class="e-form-grid">
          <strong>{{ names[day - 1] }}</strong
          ><label
            >Boshlanish<input
              v-model="perDay[day]!.start"
              type="time"
              :aria-label="names[day - 1] + ' boshlanish'" /></label
          ><label
            >Tugash<input
              v-model="perDay[day]!.end"
              type="time"
              :aria-label="names[day - 1] + ' tugash'"
          /></label>
        </div>
      </div>
      <div class="e-form-grid">
        <label
          >Filial<select v-model="branch" aria-label="Jadval filiali">
            <option value="">Tanlang</option>
            <option
              v-for="b in session.branches.filter((b) => b.active)"
              :key="b.id"
              :value="b.id"
            >
              {{ b.name }}
            </option>
          </select></label
        >
        <label
          >Amal qilish boshlanish sanasi<DateField
            v-model="from"
            label="Amal qilish boshlanish sanasi"
        /></label>
        <label
          >Amal qilish tugash sanasi<DateField
            v-model="to"
            label="Amal qilish tugash sanasi"
            :min="from"
        /></label>
        <template v-if="isDoctor"
          ><label
            >Mutaxassislik<select
              v-model="specialty"
              aria-label="Jadval mutaxassisligi"
            >
              <option value="">Tanlang</option>
              <option v-for="s in specialties" :key="s.id" :value="s.id">
                {{ s.name }}
              </option>
            </select></label
          ><label
            >Qabul xonasi<select v-model="room" aria-label="Qabul xonasi">
              <option value="">Tanlang</option>
              <option v-for="r in rooms" :key="r.id" :value="r.id">
                {{ r.number }}
              </option>
            </select></label
          ><label
            >Qabul davomiyligi<input
              v-model.number="minutes"
              type="number"
              min="1"
              max="480"
              aria-label="Jadval qabul davomiyligi"
          /></label>
          <div>
            <label>Shifokorning xizmatlari</label
            ><label v-for="s in services" :key="s.id"
              ><input
                v-model="serviceIds"
                type="checkbox"
                :value="s.id"
                :aria-label="'Shifokor xizmati: ' + s.name"
              />{{ s.name }}</label
            >
          </div></template
        >
      </div>
    </fieldset>
    <div v-if="isDoctor && existing.length" class="employee-existing-rules">
      <h3>Serverdagi jadvallar</h3>
      <p v-for="r in existing" :key="r.id">
        {{ names[r.weekday - 1] }} · {{ r.local_start }}–{{ r.local_end }} ·
        {{ r.effective_from }} — {{ r.effective_to || "cheklanmagan" }}
        <button
          type="button"
          class="e-button"
          @click="
            completed.clear();
            edit(r);
            enabled = true;
          "
        >
          Tahrirlash
        </button>
      </p>
    </div>
    <p v-else-if="isDoctor && !loading && !error" class="e-hint">
      Bu filialda shifokor jadvali topilmadi. Yangi jadvalni aniq tanlab
      saqlang.
    </p>
  </section>
</template>
