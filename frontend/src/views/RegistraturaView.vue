<script setup lang="ts">
import { computed, ref, watch } from "vue";
import {
  Users,
  CalendarDays,
  Stethoscope,
  Clock3,
  CheckCircle2,
  Coins,
  UserRoundPlus,
  Search,
  Eye,
  Check,
  MoreHorizontal,
  Filter,
  ChevronLeft,
  ChevronRight,
  ClipboardList,
} from "lucide-vue-next";
import BookingDialog from "../components/reception/BookingDialog.vue";
import ArrivalDialog from "../components/reception/ArrivalDialog.vue";
import ReceptionDialog from "../components/reception/ReceptionDialog.vue";
import {
  createReceptionState,
  doctors,
  branches,
  departments,
  services,
  statusLabels,
  initials,
  localDate,
  uzDate,
  nextPatient,
  checkIn,
  validatePatient,
  validateBooking,
  timeText,
  minutes,
  blankBooking,
  availability,
  type ReceptionPatient,
  type ReceptionAppointment,
  type PatientDraft,
  type BookingDraft,
} from "../mocks/reception";
import "../components/reception/reception.css";
import { canReception } from "../services/receptionAccess";
const state = ref(createReceptionState());
const tab = ref("all"),
  query = ref(""),
  specialty = ref(""),
  doctor = ref(""),
  status = ref(""),
  page = ref(1),
  toast = ref("");
const booking = ref(false),
  arrival = ref(false),
  preset = ref<ReceptionAppointment>(),
  bookingPreset = ref<Partial<BookingDraft>>({}),
  detail = ref<ReceptionAppointment>(),
  menu = ref("");
const scheduleDoctor = ref("karimov");
const today = localDate();
const todays = computed(() =>
  state.value.appointments.filter((a) => a.date === today),
);
const listDate = ref(today);
const listed = computed(() =>
  state.value.appointments.filter((a) => a.date === listDate.value),
);
function tabCounts(appointments: ReceptionAppointment[]) {
  return [
    { id: "all", name: "Barcha bemorlar", count: appointments.length },
    {
      id: "walk_in",
      name: "Jonli navbat",
      count: appointments.filter(
        (a) => a.kind === "walk_in" && a.status === "waiting",
      ).length,
    },
    {
      id: "booked",
      name: "Oldindan yozilgan",
      count: appointments.filter((a) => a.status === "booked").length,
    },
    {
      id: "in_progress",
      name: "Qabulda",
      count: appointments.filter((a) => a.status === "in_progress").length,
    },
    {
      id: "completed",
      name: "Yakunlangan",
      count: appointments.filter((a) => a.status === "completed").length,
    },
  ];
}
const tabs = computed(() => tabCounts(listed.value));
const todayTabs = computed(() => tabCounts(todays.value));

const stats = computed(() => [
  {
    name: "Bugungi bemorlar",
    value: String(todayTabs.value[0]!.count),
    icon: Users,
    tone: "blue",
  },
  {
    name: "Oldindan yozilgan",
    value: String(todayTabs.value[2]!.count),
    icon: CalendarDays,
    tone: "green",
  },
  {
    name: "Jonli navbat",
    value: String(todayTabs.value[1]!.count),
    icon: Stethoscope,
    tone: "amber",
  },
  {
    name: "Qabulda",
    value: String(todayTabs.value[3]!.count),
    icon: Clock3,
    tone: "rose",
  },
  {
    name: "Yakunlangan",
    value: String(todayTabs.value[4]!.count),
    icon: CheckCircle2,
    tone: "violet",
  },
  { name: "Tushum", value: "—", icon: Coins, tone: "teal" },
]);
const person = (a: ReceptionAppointment) =>
  state.value.patients.find((p) => p.id === a.patientId)!;
const physician = (a: ReceptionAppointment) =>
  doctors.find((d) => d.id === a.doctorId)!;
const visible = computed(() =>
  listed.value
    .filter(
      (a) =>
        (tab.value === "all" ||
          (tab.value === "walk_in"
            ? a.kind === "walk_in" && a.status === "waiting"
            : a.status === tab.value)) &&
        (!specialty.value || physician(a).departmentId === specialty.value) &&
        (!doctor.value || a.doctorId === doctor.value) &&
        (!status.value || a.status === status.value) &&
        `${person(a).name} ${person(a).id} ${person(a).phone}`
          .toLocaleLowerCase()
          .includes(query.value.toLocaleLowerCase()),
    )
    .sort((a, b) => a.time.localeCompare(b.time)),
);
const pageCount = computed(() =>
  Math.max(1, Math.ceil(visible.value.length / 8)),
);
const rows = computed(() =>
  visible.value.slice((page.value - 1) * 8, page.value * 8),
);
watch([tab, query, specialty, doctor, status], () => (page.value = 1), {
  flush: "sync",
});
watch(pageCount, (n) => {
  if (page.value > n) page.value = n;
});
const schedule = computed(() => {
  const doc = doctors.find((d) => d.id === scheduleDoctor.value)!;
  const available = availability(
    { ...blankBooking(), doctorId: doc.id, departmentId: doc.departmentId },
    state.value.appointments,
  );
  const times = [
    ...new Set([
      ...available.slots.map((s) => s.time),
      ...todays.value.filter((a) => a.doctorId === doc.id).map((a) => a.time),
    ]),
  ].sort();
  return times.map((time) => ({
    time,
    appointment: todays.value.find(
      (a) => a.doctorId === doc.id && a.time === time,
    ),
    busy: todays.value.some(
      (a) =>
        a.doctorId === doc.id &&
        minutes(time) >= minutes(a.time) &&
        minutes(time) <
          minutes(a.time) +
            (services.find((s) => s.id === a.serviceId)?.duration ?? 30),
    ),
    scheduled: available.scheduled,
  }));
});
function openBooking(partial: Partial<BookingDraft> = {}) {
  if (!canReception("book")) return;
  bookingPreset.value = partial;
  booking.value = true;
}
function openArrival(a?: ReceptionAppointment) {
  if (
    a
      ? !canReception("checkIn")
      : !canReception("checkIn") && !canReception("walkIn")
  )
    return;
  preset.value = a;
  arrival.value = true;
  menu.value = "";
}
function createPatient(d: PatientDraft) {
  if (!canReception("patient"))
    throw Error("Bemor qo‘shish uchun ruxsat yo‘q.");
  const p = nextPatient(state.value.patients, d);
  state.value.patients.push(p);
  return p;
}
function showAppointment(appointment: ReceptionAppointment) {
  listDate.value = appointment.date;
  tab.value = "all";
  query.value = "";
  doctor.value = "";
  specialty.value = "";
  status.value = "";
  page.value =
    Math.floor(visible.value.findIndex((a) => a.id === appointment.id) / 8) + 1;
}
function saveBooking(p: ReceptionPatient | PatientDraft, d: BookingDraft) {
  if (!canReception("book") || (!("id" in p) && !canReception("patient")))
    return;
  // Revalidate against current state before either local record is inserted.
  if (Object.keys(validateBooking(d, state.value.appointments)).length) return;
  if (!("id" in p) && Object.keys(validatePatient(p)).length) return;
  const patient =
    "id" in p
      ? state.value.patients.find((x) => x.id === p.id)
      : createPatient(p);
  if (!patient) return;
  const appointment: ReceptionAppointment = {
    ...d,
    id: `A-${state.value.appointments.length + 1}`,
    patientId: patient.id,
    status: "booked",
    kind: "scheduled",
  };
  state.value.appointments.push(appointment);
  showAppointment(appointment);
  booking.value = false;
  toast.value = "Qabulga yozildi — faqat lokal namuna ro‘yxatida.";
}
function arrive(
  p: ReceptionPatient,
  id: string | null,
  doctorId: string,
  branchId: string,
) {
  if (!canReception(id ? "checkIn" : "walkIn")) return;
  if (id) {
    const a = state.value.appointments.find(
      (a) => a.id === id && a.patientId === p.id,
    );
    if (!a || !checkIn(a)) return;
  } else {
    if (
      state.value.appointments.some(
        (a) => a.patientId === p.id && a.date === today,
      )
    ) {
      toast.value = "Bugungi mavjud qabulni tanlang. Bemor qayta qo‘shilmadi.";
      return;
    }
    const d = doctors.find(
      (d) => d.id === doctorId && d.branches.includes(branchId),
    );
    if (
      !d ||
      !availability(
        { ...blankBooking(), doctorId, branchId, departmentId: d.departmentId },
        state.value.appointments,
      ).scheduled
    )
      return;
    state.value.appointments.push({
      id: `A-${state.value.appointments.length + 1}`,
      patientId: p.id,
      doctorId,
      branchId,
      departmentId: d.departmentId,
      serviceId: "initial",
      date: today,
      time: new Date().toTimeString().slice(0, 5),
      status: "waiting",
      kind: "walk_in",
      note: "",
    });
  }
  const appointment =
    state.value.appointments.find((a) => a.id === id) ??
    state.value.appointments.at(-1);
  if (appointment) showAppointment(appointment);
  arrival.value = false;
  toast.value = "Keldi — navbatga qo‘shildi (faqat lokal namuna).";
}
function bookSlot(time: string) {
  const doc = doctors.find((d) => d.id === scheduleDoctor.value)!;
  openBooking({ doctorId: doc.id, departmentId: doc.departmentId, time });
}
function focusSearch() {
  document.getElementById("reception-query")?.focus();
}
</script>
<template>
  <section class="rp-page">
    <header class="rp-heading">
      <div>
        <h1>
          Registratura
          <small
            class="rp-demo-badge"
            title="O‘zgarishlar faqat sahifa xotirasida saqlanadi"
            >Lokal namuna</small
          >
        </h1>
        <p>Bemorlarni ro‘yxatga olish, qabulga yozish va navbatni boshqarish</p>
      </div>
      <div class="rp-heading-actions">
        <button
          class="rp-button rp-outline"
          data-permission="appointments.create"
          :disabled="!canReception('book')"
          @click="openBooking()"
        >
          <CalendarDays :size="19" /> Qabulga yozish</button
        ><button
          class="rp-button rp-primary"
          data-permission="reception.view"
          :disabled="!canReception('checkIn') && !canReception('walkIn')"
          @click="openArrival()"
        >
          <UserRoundPlus :size="19" /> Bemorni ro‘yxatga olish
        </button>
      </div>
    </header>

    <div v-if="toast" class="rp-toast" role="status">
      {{ toast }}
      <button aria-label="Xabarni yopish" @click="toast = ''">×</button>
    </div>
    <div class="rp-stats">
      <article v-for="s in stats" :key="s.name" class="rp-stat" :class="s.tone">
        <span class="rp-stat-icon"><component :is="s.icon" :size="24" /></span>
        <div>
          <p>{{ s.name }}</p>
          <strong>{{ s.value }}</strong
          ><small>{{
            s.name === "Tushum" ? "Ma’lumot mavjud emas" : "Lokal namuna holati"
          }}</small>
        </div>
      </article>
    </div>
    <div class="rp-layout">
      <section class="rp-panel rp-list">
        <nav class="rp-tabs" aria-label="Qabul holatlari">
          <button
            v-for="t in tabs"
            :key="t.id"
            :class="{ active: tab === t.id }"
            :aria-pressed="tab === t.id"
            data-permission="reception.view"
            @click="tab = t.id"
          >
            {{ t.name }} <span>{{ t.count }}</span>
          </button>
        </nav>
        <div v-if="listDate !== today" class="rp-date-context">
          <span>{{ uzDate(listDate) }} qabullari</span
          ><button
            class="rp-link"
            @click="
              listDate = today;
              tab = 'all';
              query = '';
              doctor = '';
              specialty = '';
              status = '';
              page = 1;
            "
          >
            Bugungi ro‘yxatga qaytish
          </button>
        </div>
        <div class="rp-filters">
          <label class="rp-search"
            ><Search :size="18" /><input
              id="reception-query"
              v-model="query"
              aria-label="Ro‘yxatdan bemor qidirish"
              placeholder="Bemor qidirish..." /></label
          ><select v-model="specialty" aria-label="Mutaxassislik">
            <option value="">Mutaxassislik</option>
            <option v-for="d in departments" :key="d.id" :value="d.id">
              {{ d.name }}
            </option></select
          ><select v-model="doctor" aria-label="Shifokor">
            <option value="">Shifokor</option>
            <option v-for="d in doctors" :key="d.id" :value="d.id">
              {{ d.name }}
            </option></select
          ><select v-model="status" aria-label="Holat">
            <option value="">Holat</option>
            <option v-for="(label, id) in statusLabels" :key="id" :value="id">
              {{ label }}
            </option></select
          ><button
            class="rp-icon rp-filter-reset"
            title="Filtrlarni tozalash"
            aria-label="Filtrlarni tozalash"
            @click="
              query = '';
              specialty = '';
              doctor = '';
              status = '';
              tab = 'all';
            "
          >
            <Filter :size="19" />
          </button>
        </div>
        <div class="rp-table-scroll">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Bemor</th>
                <th>Shifokor / Xizmat</th>
                <th>Holat</th>
                <th>Vaqt</th>
                <th>Amallar</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(a, i) in rows" :key="a.id">
                <td>{{ (page - 1) * 8 + i + 1 }}</td>
                <td>
                  <div class="rp-person">
                    <span class="rp-avatar">{{
                      initials(person(a).name)
                    }}</span>
                    <div>
                      <strong>{{ person(a).name }}</strong
                      ><small
                        >{{ person(a).id }} ·
                        {{ person(a).phone || "Telefon kiritilmagan" }}</small
                      >
                    </div>
                  </div>
                </td>
                <td>
                  <strong>{{ physician(a).name }}</strong
                  ><small>{{ physician(a).specialty }}</small>
                </td>
                <td>
                  <span class="rp-badge" :class="a.status"
                    ><CalendarDays
                      v-if="a.status === 'booked'"
                      :size="16"
                    /><Clock3
                      v-else-if="
                        a.status === 'waiting' || a.status === 'in_progress'
                      "
                      :size="16"
                    /><CheckCircle2 v-else :size="16" />{{
                      statusLabels[a.status]
                    }}</span
                  >
                </td>
                <td>
                  <strong>{{ a.time }}</strong>
                </td>
                <td>
                  <div class="rp-row-actions">
                    <button
                      v-if="
                        a.status === 'booked' &&
                        a.date === today &&
                        canReception('checkIn')
                      "
                      class="rp-button rp-outline"
                      @click="openArrival(a)"
                    >
                      <Check :size="16" /> Keldi</button
                    ><button
                      v-else
                      class="rp-button rp-soft"
                      data-permission="appointments.view"
                      @click="detail = a"
                    >
                      <Eye :size="16" /> Ko‘rish
                    </button>
                    <div class="rp-more">
                      <button
                        class="rp-icon"
                        :aria-label="`${person(a).name} amallari`"
                        :aria-expanded="menu === a.id"
                        @click="menu = menu === a.id ? '' : a.id"
                      >
                        <MoreHorizontal :size="18" />
                      </button>
                      <div v-if="menu === a.id" class="rp-menu">
                        <button
                          data-permission="appointments.view"
                          @click="
                            detail = a;
                            menu = '';
                          "
                        >
                          Qabulni ko‘rish</button
                        ><button @click="menu = ''">Yopish</button>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
              <tr v-if="!rows.length">
                <td colspan="6" class="rp-empty">
                  Bemor topilmadi. Qidiruv yoki filtrlarni o‘zgartiring.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <footer class="rp-pagination">
          <span
            >{{ visible.length ? (page - 1) * 8 + 1 : 0 }}–{{
              Math.min(page * 8, visible.length)
            }}
            / {{ visible.length }} ta bemor</span
          >
          <div>
            <button
              class="rp-icon"
              :disabled="page === 1"
              data-permission="reception.view"
              aria-label="Oldingi sahifa"
              @click="page--"
            >
              <ChevronLeft :size="18" /></button
            ><button
              class="rp-button rp-primary"
              data-permission="reception.view"
              :aria-label="`Sahifa ${page}`"
            >
              {{ page }}</button
            ><button
              class="rp-icon"
              :disabled="page === pageCount"
              data-permission="reception.view"
              aria-label="Keyingi sahifa"
              @click="page++"
            >
              <ChevronRight :size="18" />
            </button>
          </div>
        </footer>
      </section>
      <aside class="rp-side">
        <section class="rp-panel rp-schedule">
          <h2>Bugungi qabullar</h2>
          <p class="rp-schedule-date">
            <CalendarDays :size="16" />{{ uzDate(today) }}
          </p>
          <label class="rp-doctor"
            ><span class="rp-avatar">{{
              initials(doctors.find((d) => d.id === scheduleDoctor)!.fullName)
            }}</span>
            <div>
              <select
                v-model="scheduleDoctor"
                aria-label="Qabul jadvali shifokori"
              >
                <option v-for="d in doctors" :key="d.id" :value="d.id">
                  {{ d.name }}
                </option></select
              ><small>{{
                doctors.find((d) => d.id === scheduleDoctor)?.specialty
              }}</small>
            </div></label
          >
          <p v-if="!schedule.length" class="rp-empty">
            Bu kun uchun shifokor jadvali belgilanmagan.
          </p>
          <div
            class="rp-timeline"
            tabindex="0"
            aria-label="Bugungi shifokor jadvali"
          >
            <div
              v-for="s in schedule"
              :key="s.time"
              class="rp-timeline-row"
              :class="s.appointment?.status"
            >
              <span>{{ s.time }}</span>
              <div v-if="s.appointment">
                <strong>{{ person(s.appointment).name }}</strong
                ><small>{{ person(s.appointment).id }}</small>
              </div>
              <span v-else class="rp-slot-empty">{{
                !s.scheduled ? "Jadval yo‘q" : s.busy ? "Band" : "Bo‘sh"
              }}</span
              ><span
                v-if="s.appointment"
                class="rp-badge"
                :class="s.appointment.status"
                >{{ statusLabels[s.appointment.status] }}</span
              ><button
                v-else-if="!s.busy && s.scheduled"
                class="rp-button rp-outline"
                :aria-label="`${s.time} vaqtiga yozish`"
                data-permission="appointments.create"
                :disabled="!canReception('book')"
                @click="bookSlot(s.time)"
              >
                + Yozish
              </button>
            </div>
          </div>
        </section>
        <section class="rp-panel rp-quick">
          <h2>Tezkor amallar</h2>
          <div>
            <button @click="focusSearch">
              <Search :size="23" />Bemor qidirish</button
            ><button
              data-permission="appointments.create"
              :disabled="!canReception('book')"
              @click="openBooking()"
            >
              <CalendarDays :size="23" />Qabulga yozish</button
            ><button
              @click="
                tab = 'walk_in';
                doctor = '';
                specialty = '';
                status = '';
                query = '';
              "
            >
              <ClipboardList :size="23" />Navbatni ko‘rish
            </button>
          </div>
        </section>
      </aside>
    </div>
    <BookingDialog
      v-if="booking"
      :patients="state.patients"
      :appointments="state.appointments"
      :preset="bookingPreset"
      @close="booking = false"
      @save="saveBooking"
    /><ArrivalDialog
      v-if="arrival"
      :patients="state.patients"
      :appointments="state.appointments"
      :preset="preset"
      :create-patient="createPatient"
      @close="arrival = false"
      @arrive="arrive"
    /><ReceptionDialog
      v-if="detail"
      title="Qabul ma’lumotlari"
      subtitle="Lokal namuna · faqat ko‘rish uchun"
      @close="detail = undefined"
      ><div class="rp-dialog-body rp-details">
        <h3>{{ person(detail).name }}</h3>
        <p>
          {{ person(detail).id }} · {{ person(detail).dob }} ·
          {{ person(detail).phone }}
        </p>
        <p><b>Shifokor:</b> {{ physician(detail).fullName }}</p>
        <p>
          <b>Filial:</b>
          {{ branches.find((b) => b.id === detail!.branchId)?.name }}
        </p>
        <p>
          <b>Sana va vaqt:</b> {{ detail.date }} · {{ detail.time }}–{{
            timeText(
              minutes(detail.time) +
                (services.find((s) => s.id === detail!.serviceId)?.duration ??
                  30),
            )
          }}
        </p>
        <p><b>Holati:</b> {{ statusLabels[detail.status] }}</p>
        <p><b>Izoh:</b> {{ detail.note || "Izoh kiritilmagan" }}</p>
      </div>
      <template #footer
        ><button class="rp-button rp-primary" @click="detail = undefined">
          Yopish
        </button></template
      ></ReceptionDialog
    >
  </section>
</template>
