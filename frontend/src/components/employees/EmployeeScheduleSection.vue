<script setup lang="ts">
import { computed, ref, watch, onBeforeUnmount } from "vue";
import { localDay } from "../../services/receptionTime";
import { CalendarClock, PlusCircle, Trash2, Save, Info } from "lucide-vue-next";
import { session, can } from "../../services/session";
import { clinicRequest, errorText } from "../../services/api";
import {
  readWork,
  writeWork,
  type WorkDay,
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
  employeeId?: string;
  membershipId?: string | null;
  branchId: string;
  profileSpecialty: string;
}>();
const receivesPatients = ref(false),
  patientDays = ref<Record<number, boolean>>({});
const workRows = ref<WorkDay[]>([]),
  workVersion = ref(0),
  workLoading = ref(false),
  committing = ref(false);
const removals = new Set<number>();
const pendingRemoval = ref<number>();
const pendingMove = ref<number>();
const pendingDisable = ref<number | "all">();
let workSequence = 0;
const emit = defineEmits<{ save: [] }>();
const roomUsage = ref<"shared" | "private">("shared");
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
  clinicZone = ref(""),
  serviceIds = ref<string[]>([]),
  room = ref("");
const newServiceName = ref("");
const serviceCreating = ref(false);
const serviceCreateError = ref("");
const days = ref<number[]>([]),
  separate = ref(true),
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
const specialty = computed(() => {
  const name = props.profileSpecialty
    .trim()
    .normalize("NFKC")
    .toLocaleLowerCase();
  if (!name) return doctor.value?.specialty_id ?? "";
  const matches = specialties.value.filter(
    (s) => s.name?.trim().normalize("NFKC").toLocaleLowerCase() === name,
  );
  return matches.length === 1
    ? matches[0]!.id
    : (doctor.value?.specialty_id ?? "");
});
const isDoctor = computed(() => props.position === "Shifokor");
let serial = 0;
onBeforeUnmount(() => {
  serial++;
  workSequence++;
});
function removeDay(day: number) {
  if (workRows.value.some((r) => r.weekday === day)) pendingRemoval.value = day;
  else days.value = days.value.filter((d) => d !== day);
}
function confirmRemoval() {
  const day = pendingRemoval.value;
  if (!day) return;
  removals.add(day);
  if (pendingMove.value) {
    const next = pendingMove.value;
    perDay.value[next] = { ...perDay.value[day]! };
    patientDays.value[next] = patientDays.value[day] ?? false;
    days.value = days.value.map((d) => (d === day ? next : d));
  } else {
    days.value = days.value.filter((d) => d !== day);
  }
  patientDays.value[day] = false;
  pendingMove.value = undefined;
  pendingRemoval.value = undefined;
}
function setPatientDay(day: number, event: Event) {
  const on = (event.target as HTMLInputElement).checked;
  if (
    !on &&
    existing.value.some((r) => r.weekday === day && r.active !== false)
  ) {
    pendingDisable.value = day;
    (event.target as HTMLInputElement).checked = true;
    return;
  }
  patientDays.value[day] = on;
}
function setReception(event: Event) {
  const on = (event.target as HTMLInputElement).checked;
  if (!on && existing.value.some((r) => r.active !== false)) {
    pendingDisable.value = "all";
    (event.target as HTMLInputElement).checked = true;
    return;
  }
  if (on && !days.value.some((d) => patientDays.value[d]))
    for (const day of days.value) patientDays.value[day] = true;
  receivesPatients.value = on;
}
function confirmDisable() {
  if (pendingDisable.value === "all") receivesPatients.value = false;
  else if (pendingDisable.value)
    patientDays.value[pendingDisable.value] = false;
  pendingDisable.value = undefined;
}
async function loadWork() {
  if (committing.value) return;
  const n = ++workSequence;
  workLoading.value = true;
  try {
    const saved = props.employeeId
      ? await readWork(props.employeeId, branch.value)
      : { version: 0, data: [] };
    if (n !== workSequence || committing.value) return;
    workVersion.value = saved.version;
    if (!enabled.value) {
      workRows.value = saved.data;
      days.value = saved.data.map((r) => r.weekday);
      perDay.value = Object.fromEntries(
        saved.data.map((r) => [
          r.weekday,
          { start: r.start_time, end: r.end_time },
        ]),
      );
      patientDays.value = Object.fromEntries(
        saved.data.map((r) => [r.weekday, r.receives_patients]),
      );
      receivesPatients.value =
        isDoctor.value && saved.data.some((r) => r.receives_patients);
      removals.clear();
    }
  } catch (e) {
    if (n === workSequence) error.value = errorText(e);
  } finally {
    if (n === workSequence) workLoading.value = false;
  }
}
watch(() => [props.employeeId, branch.value], loadWork, { immediate: true });
function addDay() {
  const day = names.map((_, i) => i + 1).find((d) => !days.value.includes(d));
  if (day) {
    perDay.value[day] ??= { start: start.value, end: end.value };
    days.value.push(day);
  }
}
function changeDay(old: number, event: Event) {
  const next = Number((event.target as HTMLSelectElement).value);
  if (!next || (next !== old && days.value.includes(next))) return;
  if (workRows.value.some((r) => r.weekday === old)) {
    pendingRemoval.value = old;
    pendingMove.value = next;
    (event.target as HTMLSelectElement).value = String(old);
    return;
  }
  patientDays.value[next] = patientDays.value[old] ?? false;
  perDay.value[next] = { ...perDay.value[old]! };
  days.value = days.value.map((d) => (d === old ? next : d));
}
async function cancelDraft() {
  newRule();
  enabled.value = false;
  minutes.value = 30;
  notice.value = "";
  await load();
}
function newRule() {
  roomUsage.value = "shared";
  enabled.value = true;
  editing.value = {};
  completed.clear();
  days.value = [];
  perDay.value = {};
  start.value = "09:00";
  end.value = "17:00";
  room.value = "";
  from.value = clinicZone.value ? localDay(new Date(), clinicZone.value) : "";
  to.value = "";
  separate.value = true;
  error.value = "";
  notice.value = "Yangi jadval. Mavjud jadvallar o‘zgarmaydi.";
}
function edit(rule: Rule) {
  roomUsage.value =
    rooms.value.find((r) => r.id === rule.room_id)?.usage_mode ?? "shared";
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
  separate.value = true;
}
async function load() {
  const n = ++serial;
  if (!isDoctor.value || !receivesPatients.value || committing.value) {
    loading.value = false;
    return;
  }
  loading.value = true;
  error.value = "";
  try {
    const [sp, sv, rm, d, context] = await Promise.all([
      scheduleList<Option>("specialties", { status: "active" }),
      session.grants?.["services.view"] === "entire_clinic"
        ? scheduleList<Option>("appointments/configuration-services", {
            status: "active",
          })
        : Promise.resolve([] as Option[]),
      scheduleList<Option>("rooms", {
        branch_id: branch.value,
        status: "active",
      }),
      props.membershipId
        ? employeeDoctor(props.membershipId)
        : Promise.resolve(undefined),
      clinicRequest<{ timezone: string }>("context"),
    ]);
    if (n !== serial) return;
    clinicZone.value = context.data.timezone;
    if (!Object.keys(editing.value).length)
      from.value = localDay(new Date(), clinicZone.value);
    specialties.value = sp;
    services.value = sv;
    rooms.value = rm.filter(
      (r) =>
        r.availability === "available" &&
        (r.usage_mode !== "private" || r.exclusive_doctor_id === d?.id),
    );
    doctor.value = d;
    const rules = d
      ? await scheduleList<Rule>("schedules", {
          doctor_id: d.id,
          branch_id: branch.value,
        })
      : [];
    if (n !== serial) return;
    existing.value = rules;
    editing.value = Object.fromEntries(
      [
        ...rules.filter((r) => r.active === false),
        ...rules.filter((r) => r.active !== false),
      ].map((r) => [r.weekday, r]),
    );
    if (!room.value)
      room.value =
        (rules.find((r) => r.active !== false) ?? rules[0])?.room_id ?? "";
    if (!serviceIds.value.length)
      serviceIds.value = [...(d?.service_ids ?? [])];
    if (!enabled.value || !days.value.length) {
      serviceIds.value = [...(d?.service_ids ?? [])];
      if (!workRows.value.length && existing.value[0]) edit(existing.value[0]);
      else {
        room.value =
          (existing.value.find((r) => r.active !== false) ?? existing.value[0])
            ?.room_id ?? "";
        serviceIds.value = [...(d?.service_ids ?? [])];
      }
    }
  } catch (e) {
    if (n === serial) error.value = errorText(e);
  } finally {
    if (n === serial) loading.value = false;
  }
}
watch(room, (id) => {
  roomUsage.value =
    rooms.value.find((r) => r.id === id)?.usage_mode ?? "shared";
});
watch(branch, () => {
  roomUsage.value = "shared";
  editing.value = {};
  existing.value = [];
  room.value = "";
});
watch(
  () => props.position,
  () => {
    if (!isDoctor.value) receivesPatients.value = false;
  },
);
watch(
  () => props.branchId,
  (b) => {
    if (!enabled.value) branch.value = b;
  },
);
watch(
  () => [
    props.position,
    props.membershipId,
    branch.value,
    receivesPatients.value,
  ],
  load,
  {
    immediate: true,
  },
);
watch(
  days,
  (ds) => {
    for (const day of ds) {
      perDay.value[day] ??= { start: start.value, end: end.value };
      patientDays.value[day] ??= receivesPatients.value;
    }
  },
  { deep: true },
);
async function createBookingService() {
  const name = newServiceName.value.trim();
  serviceCreateError.value = "";
  if (!name) {
    serviceCreateError.value = "Qabul xizmati nomini kiriting.";
    return;
  }
  if (name.length > 255) {
    serviceCreateError.value = "Xizmat nomi 255 belgidan oshmasin.";
    return;
  }
  if (!can("services.create") || session.grants?.["services.view"] !== "entire_clinic") {
    serviceCreateError.value = "Qabul xizmati yaratish uchun ruxsat yo‘q.";
    return;
  }
  const existingService = services.value.find(
    (s) => s.name?.trim().toLocaleLowerCase() === name.toLocaleLowerCase(),
  );
  if (existingService) {
    if (!serviceIds.value.includes(existingService.id))
      serviceIds.value = [...serviceIds.value, existingService.id];
    newServiceName.value = "";
    notice.value = "Mavjud xizmat tanlandi. Shifokorga biriktirish uchun xodimni saqlang.";
    return;
  }
  if (serviceCreating.value) return;
  serviceCreating.value = true;
  try {
    const code = "service-" + crypto.randomUUID().replace(/-/g, "").slice(0, 20);
    const result = await clinicRequest<Option>("appointments/configuration-services", {
      method: "POST",
      body: { code, name, active: true },
    });
    const created = { ...result.data, name };
    services.value = [...services.value, created].sort((a, b) =>
      (a.name ?? "").localeCompare(b.name ?? ""),
    );
    serviceIds.value = [...new Set([...serviceIds.value, created.id])];
    newServiceName.value = "";
    notice.value = "Xizmat katalogga qo‘shildi. Shifokorga biriktirish uchun xodimni saqlang.";
  } catch (e) {
    serviceCreateError.value = errorText(e);
  } finally {
    serviceCreating.value = false;
  }
}
function validate() {
  if (!enabled.value) return true;
  error.value = "";
  if (loading.value || workLoading.value)
    error.value = "Jadval ma’lumotlari yuklanishini kuting.";
  else if (!branch.value) error.value = "Filialni tanlang.";
  else if (
    isDoctor.value &&
    receivesPatients.value &&
    days.value.some((d) => patientDays.value[d]) &&
    !serviceIds.value.length
  )
    error.value = "Bemor qabuli uchun kamida bitta qabul xizmatini tanlang yoki qo‘shing.";
  else if (
    isDoctor.value &&
    receivesPatients.value &&
    days.value.some((d) => patientDays.value[d]) &&
    !room.value
  )
    error.value = "Qabul xonasini tanlang.";
  else if (
    isDoctor.value &&
    receivesPatients.value &&
    days.value.some((d) => patientDays.value[d]) &&
    (!Number.isInteger(minutes.value) ||
      minutes.value < 1 ||
      minutes.value > 480)
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
async function commitAppointments(employee: Employee) {
  if (!employee.membershipId || !employee.access)
    throw Error(
      "Xodim saqlandi. Shifokor jadvali uchun faol login hisobi kerak; avval hisobni bog‘lang.",
    );
  const desired = {
    branch: branch.value,
    specialty: specialty.value || null,
    services: [...serviceIds.value],
  };
  const rows: Rule[] = days.value
    .filter((day) => receivesPatients.value && patientDays.value[day])
    .map((day) => {
      const time = separate.value
        ? perDay.value[day]!
        : { start: start.value, end: end.value };
      const previous = editing.value[day];
      return {
        id: previous?.id,
        doctor_id: "",
        branch_id: desired.branch,
        room_id: room.value,
        room_usage: roomUsage.value,
        active: true,
        weekday: day,
        local_start: time.start,
        local_end: time.end,
        slot_minutes: minutes.value,
        effective_from:
          previous?.effective_from ?? localDay(new Date(), clinicZone.value),
        effective_to: previous ? previous.effective_to : null,
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
    const stored = await scheduleList<Rule>("schedules", {
      doctor_id: d.id,
      branch_id: desired.branch,
    });
    for (const day of rows.map((r) => r.weekday)) {
      const saved = editing.value[day];
      const actual = stored.find((r) => r.id === saved?.id);
      if (
        !actual ||
        actual.local_start !== saved?.local_start ||
        actual.local_end !== saved?.local_end ||
        actual.room_id !== saved?.room_id ||
        actual.slot_minutes !== saved?.slot_minutes
      )
        throw Error(
          "Saqlangan jadvalni serverdan tasdiqlab bo‘lmadi. Qayta urinib ko‘ring.",
        );
    }
    const storedRoom = (await clinicRequest<Option>(`rooms/${room.value}`))
      .data;
    if (
      storedRoom.usage_mode !== roomUsage.value ||
      (roomUsage.value === "private" && storedRoom.exclusive_doctor_id !== d.id)
    )
      throw Error("Xona biriktirishini serverdan tasdiqlab bo‘lmadi.");
    rooms.value = rooms.value.map((r) =>
      r.id === storedRoom.id ? storedRoom : r,
    );
    existing.value = stored;
    error.value = "";
  } catch (e) {
    error.value = `Xodim saqlandi. Jadval to‘liq saqlanmadi: ${errorText(e)} Saqlangan kunlar takror yaratilmaydi; qayta urinib ko‘ring.`;
    throw Error(error.value);
  }
}
async function commit(employee: Employee) {
  if (!enabled.value) return;
  if (!validate()) throw Error(error.value);
  committing.value = true;
  workSequence++;
  try {
    const desired = days.value.map((day) => ({
      weekday: day,
      start_time: separate.value ? perDay.value[day]!.start : start.value,
      end_time: separate.value ? perDay.value[day]!.end : end.value,
      receives_patients:
        isDoctor.value && receivesPatients.value && !!patientDays.value[day],
    }));
    const shape = (rows: WorkDay[]) =>
      JSON.stringify(
        rows
          .map(({ weekday, start_time, end_time, receives_patients }) => ({
            weekday,
            start_time,
            end_time,
            receives_patients,
          }))
          .sort((a, b) => a.weekday - b.weekday),
      );
    if (shape(desired) !== shape(workRows.value) || removals.size) {
      const saved = await writeWork(
        employee.id,
        branch.value,
        desired,
        [...removals],
        workVersion.value,
      );
      workVersion.value = saved.version;
      workRows.value = saved.data;
      removals.clear();
    }
    const verified = await readWork(employee.id, branch.value);
    if (shape(verified.data) !== shape(desired))
      throw Error("Ish jadvali serverdan tasdiqlanmadi. Qayta yuklang.");
    workVersion.value = verified.version;
    workRows.value = verified.data;
    // Explicitly disabling known appointment days preserves rows/history and existing bookings.
    for (const r of existing.value.filter(
      (r) =>
        r.active !== false &&
        (!days.value.includes(r.weekday) ||
          !receivesPatients.value ||
          !patientDays.value[r.weekday]),
    )) {
      const { id, ...body } = r;
      await clinicRequest(`schedules/${id}`, {
        method: "PUT",
        body: { ...body, active: false },
      });
      r.active = false;
    }
    if (
      isDoctor.value &&
      receivesPatients.value &&
      desired.some((r) => r.receives_patients)
    )
      await commitAppointments(employee);
    error.value = "";
  } catch (e) {
    error.value = `Xodim saqlandi. Jadval to‘liq saqlanmadi: ${errorText(e)} Qayta urinish xodimni qayta yaratmaydi.`;
    throw Error(error.value);
  } finally {
    committing.value = false;
  }
}
function validateLink() {
  if (!enabled.value) return true;
  error.value =
    "Mavjud hisobni bog‘lash roziligini avval yakunlang. Hozir jadval tanlovini o‘chiring; rozilikdan keyin xodimni tahrirlab jadvalni saqlang.";
  return false;
}
defineExpose({
  validate,
  validateLink,
  commit,
  needsReception: () =>
    isDoctor.value &&
    receivesPatients.value &&
    days.value.some((d) => patientDays.value[d]),
});
</script>
<template>
  <section class="e-card employee-inline-schedule" aria-label="Ish jadvali">
    <header class="employee-schedule-header">
      <span class="employee-schedule-icon"><CalendarClock :size="26" /></span>
      <div>
        <h2>Ish jadvali</h2>
        <p>Haftalik ish kunlari va vaqtlarini belgilang.</p>
      </div>
    </header>
    <div class="employee-schedule-body">
      <button
        v-if="
          isDoctor && receivesPatients && existing.length && !workRows.length
        "
        type="button"
        class="e-button"
        @click="newRule"
      >
        Yangi jadval
      </button>
      <label class="employee-schedule-enable"
        ><input
          v-model="enabled"
          type="checkbox"
          aria-label="Ish jadvalini saqlash"
          :disabled="workLoading"
        />Ish jadvalini saqlash / yangilash</label
      >
      <p class="e-hint">
        Ish jadvali xodimga biriktiriladi. Bemor qabuli alohida, ixtiyoriy
        sozlanadi.
      </p>
      <label v-if="isDoctor" class="employee-schedule-enable"
        ><input
          :checked="receivesPatients"
          type="checkbox"
          aria-label="Bemor qabul qiladi"
          :disabled="!enabled || workLoading || loading"
          @change="setReception"
        />Bemor qabul qiladi</label
      >
      <div v-if="pendingRemoval || pendingDisable" class="e-info" role="alert">
        <span
          >{{
            pendingRemoval
              ? names[pendingRemoval - 1] +
                " ish kunini olib tashlashni tasdiqlaysizmi?"
              : "Ushbu kunlarning haftalik bemor qabuli to‘xtatilishini tasdiqlaysizmi?"
          }}
          Mavjud qabul yozuvlari o‘chirilmaydi.</span
        >
        <button
          type="button"
          class="e-button"
          @click="pendingRemoval ? confirmRemoval() : confirmDisable()"
        >
          Tasdiqlash
        </button>
        <button
          type="button"
          class="e-button"
          @click="
            pendingRemoval = undefined;
            pendingMove = undefined;
            pendingDisable = undefined;
          "
        >
          Bekor qilish
        </button>
      </div>
      <p v-if="loading" role="status">Jadval ma’lumotlari yuklanmoqda…</p>
      <p v-if="error" class="e-error" role="alert">
        {{ error }}
        <button type="button" class="e-button" @click="load">
          Jadvalni qayta yuklash
        </button>
      </p>
      <p v-if="notice" role="status">{{ notice }}</p>
      <fieldset
        :disabled="!enabled || loading || workLoading"
        class="employee-schedule-fields"
      >
        <label class="employee-schedule-mode"
          ><input
            v-model="separate"
            type="checkbox"
            aria-label="Kunlarni alohida sozlash"
          />Kunlarni alohida sozlash</label
        >
        <p class="e-hint">
          Har bir kun uchun alohida ish vaqtini belgilash mumkin. Tanlanmagan
          kunlarda qabul o‘tkazilmaydi.
        </p>
        <template v-if="!separate">
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
        </template>
        <div
          v-if="!separate && isDoctor && receivesPatients"
          class="employee-weekdays"
        >
          <label v-for="day in days" :key="day"
            ><input
              type="checkbox"
              :checked="patientDays[day]"
              :aria-label="names[day - 1] + ' bemor qabuli'"
              @change="setPatientDay(day, $event)"
            />{{ names[day - 1] }} — bemor qabuli</label
          >
        </div>
        <div v-if="!separate" class="e-form-grid">
          <label
            >Ish boshlanishi<input
              v-model="start"
              type="time"
              aria-label="Ish boshlanishi" /></label
          ><label
            >Ish tugashi<input
              v-model="end"
              type="time"
              aria-label="Ish tugashi"
          /></label>
        </div>
        <div v-else class="employee-day-times">
          <div v-for="day in days" :key="day" class="employee-schedule-day">
            <label
              v-if="isDoctor && receivesPatients"
              class="employee-schedule-patient-day"
              ><input
                type="checkbox"
                :checked="patientDays[day]"
                :aria-label="names[day - 1] + ' bemor qabuli'"
                @change="setPatientDay(day, $event)"
              />Bemor qabul qiladi</label
            >
            <label
              >Hafta kuni<select
                :value="day"
                :aria-label="names[day - 1] + ' hafta kuni'"
                @change="changeDay(day, $event)"
              >
                <option
                  v-for="(name, i) in names"
                  :key="i"
                  :value="i + 1"
                  :disabled="i + 1 !== day && days.includes(i + 1)"
                >
                  {{ name }}
                </option>
              </select></label
            >
            <label
              >Ish boshlanishi<input
                v-model="perDay[day]!.start"
                type="time"
                :aria-label="names[day - 1] + ' boshlanish'"
            /></label>
            <label
              >Ish tugashi<input
                v-model="perDay[day]!.end"
                type="time"
                :aria-label="names[day - 1] + ' tugash'"
            /></label>
            <button
              type="button"
              class="employee-schedule-remove"
              :aria-label="names[day - 1] + ' kunini olib tashlash'"
              @click="removeDay(day)"
            >
              <Trash2 :size="20" />
            </button>
          </div>
          <button
            type="button"
            class="employee-schedule-add"
            :disabled="days.length === 7"
            @click="addDay"
          >
            <PlusCircle :size="19" />Yana bir kun qo‘shish
          </button>
        </div>
        <div class="e-form-grid employee-schedule-details">
          <label
            :class="{
              'employee-schedule-branch-only': !isDoctor || !receivesPatients,
            }"
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
          <label v-if="isDoctor && receivesPatients"
            >Qabul davomiyligi (daqiqada)<input
              v-model.number="minutes"
              type="number"
              min="1"
              max="480"
              aria-label="Jadval qabul davomiyligi"
          /></label>
          <template v-if="isDoctor && receivesPatients">
            <div class="employee-schedule-room">
              <label
                >Qabul xonasi<select
                  v-model="room"
                  aria-label="Qabul xonasi"
                  aria-describedby="employee-room-help"
                >
                  <option value="">Xonani tanlang</option>
                  <option v-for="r in rooms" :key="r.id" :value="r.id">
                    {{ r.number }}
                  </option>
                </select></label
              >
              <p id="employee-room-help" class="e-hint">
                {{
                  rooms.length
                    ? "Tanlangan filialning faol qabul xonalari."
                    : "Bu filialda qabul xonasi mavjud emas"
                }}
              </p>
            </div>
            <fieldset class="employee-room-usage">
              <legend>Xonadan foydalanish tartibi</legend>
              <label :class="{ 'is-selected': roomUsage === 'shared' }"
                ><input
                  v-model="roomUsage"
                  type="radio"
                  name="employee-room-usage"
                  value="shared"
                /><span
                  ><strong>Umumiy xona</strong
                  ><small>Bir nechta shifokor foydalanishi mumkin</small></span
                ></label
              >
              <label :class="{ 'is-selected': roomUsage === 'private' }"
                ><input
                  v-model="roomUsage"
                  type="radio"
                  name="employee-room-usage"
                  value="private"
                /><span
                  ><strong>Shaxsiy xona</strong
                  ><small>Faqat shu shifokorga biriktiriladi</small></span
                ></label
              >
            </fieldset>
            <div class="employee-room-info" role="status">
              <Info :size="20" />
              <p v-if="roomUsage === 'shared'">
                Boshqa shifokorlar ham bu xonadan foydalanishi mumkin. Bir xil
                vaqt oralig‘idagi bandlik va konfliktlar backend tomonidan
                tekshiriladi.
              </p>
              <p v-else>
                Bu xona faqat ushbu shifokor uchun biriktiriladi. Agar xona
                boshqa shifokorga eksklyuziv bog‘langan bo‘lsa, backend saqlash
                vaqtida rad etadi.
              </p>
            </div>
            <div class="employee-schedule-services">
              <label>Shifokorning qabul xizmatlari</label>
              <p v-if="!services.length" class="e-hint">
                Qabul xizmatlari hali qo‘shilmagan. Xizmatni quyida yarating yoki
                katalog ruxsatini tekshiring.
              </p>
              <label v-for="s in services" :key="s.id">
                <input
                  v-model="serviceIds"
                  type="checkbox"
                  :value="s.id"
                  :aria-label="'Shifokor xizmati: ' + s.name"
                />{{ s.name }}
              </label>
            </div>
            <div
              v-if="can('services.create') && session.grants?.['services.view'] === 'entire_clinic'"
              class="employee-booking-service-create"
              >
                <label>Yangi qabul xizmati
                  <input
                    v-model="newServiceName"
                    maxlength="255"
                    aria-label="Yangi qabul xizmati nomi"
                    placeholder="Masalan: Birlamchi konsultatsiya"
                    :disabled="serviceCreating"
                  />
                </label>
                <button
                  type="button"
                  class="e-button"
                  :disabled="serviceCreating || !newServiceName.trim()"
                  @click="createBookingService"
                >
                  <PlusCircle :size="17" />{{ serviceCreating ? "Saqlanmoqda…" : "Xizmat qo‘shish" }}
                </button>
                <p v-if="serviceCreateError" class="e-error" role="alert">{{ serviceCreateError }}</p>
                <p class="e-hint">
                  Xizmat katalogga darhol saqlanadi. Shifokorga biriktirish uchun xodimni saqlang.
                </p>
            </div></template
          >
        </div>
      </fieldset>
      <div
        v-if="isDoctor && receivesPatients && existing.length"
        class="employee-existing-rules"
      >
        <h3>Bemor qabuli jadvallari (serverda)</h3>
        <p v-for="r in existing" :key="r.id">
          {{ names[r.weekday - 1] }} · {{ r.local_start }}–{{ r.local_end }} ·
          {{ r.effective_from }} — {{ r.effective_to || "cheklanmagan" }}
          <span v-if="r.active === false"> · Qabul o‘chirilgan</span>
          <button
            v-if="!workRows.length"
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
      <p
        v-else-if="isDoctor && receivesPatients && !loading && !error"
        class="e-hint"
      >
        Bu filialda shifokor jadvali topilmadi. Yangi jadvalni aniq tanlab
        saqlang.
      </p>
      <footer class="employee-schedule-footer">
        <button
          type="button"
          class="e-button"
          :disabled="loading || workLoading"
          @click="cancelDraft"
        >
          Bekor qilish
        </button>
        <button
          type="button"
          class="e-button primary"
          :disabled="loading || workLoading || !enabled"
          @click="emit('save')"
        >
          <Save :size="18" />Jadvalni saqlash
        </button>
      </footer>
    </div>
  </section>
</template>
