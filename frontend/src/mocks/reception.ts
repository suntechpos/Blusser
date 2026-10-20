/** Reception-only in-memory fixtures. Never writes to the Patients API. */
export type PatientDraft = {
  lastName: string;
  firstName: string;
  middleName: string;
  phone: string;
  dob: string;
  gender: string;
};
export type ReceptionPatient = PatientDraft & { id: string; name: string };
export type AppointmentStatus =
  | "booked"
  | "waiting"
  | "in_progress"
  | "completed";
export type ReceptionAppointment = {
  id: string;
  patientId: string;
  doctorId: string;
  branchId: string;
  departmentId: string;
  serviceId: string;
  date: string;
  time: string;
  status: AppointmentStatus;
  kind: "scheduled" | "walk_in";
  note: string;
};
export type BookingDraft = Pick<
  ReceptionAppointment,
  | "branchId"
  | "departmentId"
  | "doctorId"
  | "serviceId"
  | "date"
  | "time"
  | "note"
>;
export const branches = [
  { id: "main", name: "Asosiy filial" },
  { id: "yunus", name: "Yunusobod" },
];
export const departments = [
  { id: "cardio", name: "Kardiologiya" },
  { id: "therapy", name: "Terapiya" },
  { id: "neuro", name: "Nevrologiya" },
  { id: "pediatric", name: "Pediatriya" },
  { id: "endo", name: "Endokrinologiya" },
  { id: "uzi", name: "UZI va diagnostika" },
];
export const doctors = [
  {
    id: "karimov",
    name: "Karimov A.",
    fullName: "Aziz Karimov",
    specialty: "Kardiolog",
    departmentId: "cardio",
    branches: ["main", "yunus"],
  },
  {
    id: "rahmonova",
    name: "Rahmonova D.",
    fullName: "Dilnoza Rahmonova",
    specialty: "Terapevt",
    departmentId: "therapy",
    branches: ["main"],
  },
  {
    id: "jabborov",
    name: "Jabborov S.",
    fullName: "Sardor Jabborov",
    specialty: "Nevrolog",
    departmentId: "neuro",
    branches: ["main"],
  },
  {
    id: "alimova",
    name: "Alimova N.",
    fullName: "Nigora Alimova",
    specialty: "Pediatr",
    departmentId: "pediatric",
    branches: ["main", "yunus"],
  },
  {
    id: "rasulova",
    name: "Rasulova M.",
    fullName: "Madina Rasulova",
    specialty: "Endokrinolog",
    departmentId: "endo",
    branches: ["main"],
  },
  {
    id: "ismoilov",
    name: "Ismoilov B.",
    fullName: "Botir Ismoilov",
    specialty: "UZI mutaxassisi",
    departmentId: "uzi",
    branches: ["main"],
  },
];
export const services = [
  { id: "initial", name: "Birlamchi konsultatsiya", duration: 30 },
  { id: "followup", name: "Takroriy konsultatsiya", duration: 30 },
  { id: "extended", name: "Kengaytirilgan konsultatsiya", duration: 60 },
];
export const statusLabels: Record<AppointmentStatus, string> = {
  booked: "Yozilgan",
  waiting: "Navbatda",
  in_progress: "Qabulda",
  completed: "Yakunlangan",
};
export function localDate(date = new Date()) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}
export function addDays(date: string, count: number) {
  const d = new Date(`${date}T12:00:00`);
  d.setDate(d.getDate() + count);
  return localDate(d);
}
export const blankPatient = (): PatientDraft => ({
  lastName: "",
  firstName: "",
  middleName: "",
  phone: "",
  dob: "",
  gender: "",
});
export const blankBooking = (): BookingDraft => ({
  branchId: "main",
  departmentId: "cardio",
  doctorId: "karimov",
  serviceId: "initial",
  date: localDate(),
  time: "",
  note: "",
});
export function patientName(d: PatientDraft) {
  return [d.lastName, d.firstName, d.middleName]
    .map((v) => v.trim())
    .filter(Boolean)
    .join(" ");
}
export function initials(name: string) {
  return name
    .split(/\s+/)
    .slice(0, 2)
    .map((n) => n[0])
    .join("");
}
export function validatePatient(d: PatientDraft) {
  const e: Record<string, string> = {};
  if (!d.lastName.trim()) e.lastName = "Familiyani kiriting.";
  if (!d.firstName.trim()) e.firstName = "Ismni kiriting.";
  if (patientName(d).length > 255)
    e.lastName = "F.I.Sh. 255 belgidan oshmasin.";
  // Matches current Patient model: name + date_of_birth required; phone/gender optional.
  if (
    !/^\d{4}-\d{2}-\d{2}$/.test(d.dob) ||
    !Number.isFinite(Date.parse(d.dob)) ||
    localDate(new Date(`${d.dob}T12:00:00`)) !== d.dob ||
    d.dob > localDate()
  )
    e.dob = "To‘g‘ri tug‘ilgan sanani kiriting.";
  if (d.phone && !/^\+?[\d\s()-]{7,32}$/.test(d.phone))
    e.phone = "Telefon raqamini tekshiring.";
  return e;
}
export function createReceptionState() {
  const names = [
    ["Aliyeva", "Mohira", "+998 90 123 45 67"],
    ["Usmonov", "Dilshod", "+998 93 456 78 90"],
    ["Karimova", "Nigora", "+998 93 456 78 12"],
    ["Rahimov", "Sardor", "+998 97 111 22 33"],
    ["Toshboyev", "Akmal", "+998 91 789 01 23"],
    ["Saidova", "Malika", "+998 90 987 65 43"],
    ["Yusupov", "Aziz", "+998 93 765 43 21"],
    ["Ahmedova", "Dilnoza", "+998 90 654 32 10"],
  ];
  const patients: ReceptionPatient[] = names.map((n, i) => ({
    id: `P-${String(124 + i).padStart(5, "0")}`,
    lastName: n[0]!,
    firstName: n[1]!,
    middleName: "",
    name: `${n[0]} ${n[1]}`,
    phone: n[2]!,
    dob: i === 0 ? "1988-03-12" : "1990-01-12",
    gender: i % 2 ? "male" : "female",
  }));
  const doctorIds = [
    "karimov",
    "karimov",
    "jabborov",
    "alimova",
    "rahmonova",
    "karimov",
    "rasulova",
    "ismoilov",
  ];
  const statuses: AppointmentStatus[] = [
    "in_progress",
    "waiting",
    "booked",
    "booked",
    "completed",
    "waiting",
    "booked",
    "booked",
  ];
  const times = [
    "09:00",
    "09:30",
    "10:30",
    "11:30",
    "08:30",
    "12:00",
    "13:00",
    "14:00",
  ];
  const appointments: ReceptionAppointment[] = patients.map((p, i) => ({
    id: `A-${i + 1}`,
    patientId: p.id,
    doctorId: doctorIds[i]!,
    departmentId: doctors.find((d) => d.id === doctorIds[i])!.departmentId,
    branchId: "main",
    serviceId: "initial",
    date: localDate(),
    time: times[i]!,
    status: statuses[i]!,
    kind: i === 1 ? "walk_in" : "scheduled",
    note: "",
  }));
  return { patients, appointments };
}
export function searchPatients(patients: ReceptionPatient[], query: string) {
  const q = query.trim().toLocaleLowerCase().replace(/[‘’']/g, "");
  const digits = query.replace(/\D/g, "");
  return patients.filter(
    (p) =>
      !q ||
      `${p.name} ${p.id}`
        .toLocaleLowerCase()
        .replace(/[‘’']/g, "")
        .includes(q) ||
      (digits.length >= 3 && p.phone.replace(/\D/g, "").includes(digits)),
  );
}
export const minutes = (time: string) =>
  Number(time.slice(0, 2)) * 60 + Number(time.slice(3));
export const timeText = (m: number) =>
  `${String(Math.floor(m / 60)).padStart(2, "0")}:${String(m % 60).padStart(2, "0")}`;
export function availability(
  draft: BookingDraft,
  appointments: ReceptionAppointment[],
) {
  const doctor = doctors.find(
    (d) =>
      d.id === draft.doctorId &&
      d.branches.includes(draft.branchId) &&
      d.departmentId === draft.departmentId,
  );
  const service = services.find((s) => s.id === draft.serviceId);
  const day = new Date(`${draft.date}T12:00:00`).getDay();
  if (
    !doctor ||
    !service ||
    !/^\d{4}-\d{2}-\d{2}$/.test(draft.date) ||
    !Number.isFinite(day) ||
    day === 0
  )
    return { scheduled: false, slots: [] as { time: string; busy: boolean }[] };
  const slots = [];
  const end = day === 6 ? 13 * 60 : 17 * 60;
  for (let m = 9 * 60; m + service.duration <= end; m += 30) {
    if (m < 14 * 60 && m + service.duration > 12 * 60) continue;
    const busy = appointments.some(
      (a) =>
        a.doctorId === draft.doctorId &&
        a.date === draft.date &&
        m <
          minutes(a.time) +
            (services.find((s) => s.id === a.serviceId)?.duration ?? 30) &&
        m + service.duration > minutes(a.time),
    );
    slots.push({ time: timeText(m), busy });
  }
  return { scheduled: true, slots };
}
export function validateBooking(
  d: BookingDraft,
  appointments: ReceptionAppointment[],
) {
  const e: Record<string, string> = {};
  if (!branches.some((b) => b.id === d.branchId))
    e.branchId = "Filialni tanlang.";
  if (!departments.some((x) => x.id === d.departmentId))
    e.departmentId = "Bo‘limni tanlang.";
  if (
    !doctors.some(
      (x) =>
        x.id === d.doctorId &&
        x.departmentId === d.departmentId &&
        x.branches.includes(d.branchId),
    )
  )
    e.doctorId = "Mos shifokorni tanlang.";
  if (!services.some((s) => s.id === d.serviceId))
    e.serviceId = "Qabul turini tanlang.";
  if (
    !/^\d{4}-\d{2}-\d{2}$/.test(d.date) ||
    !Number.isFinite(Date.parse(d.date)) ||
    localDate(new Date(`${d.date}T12:00:00`)) !== d.date ||
    d.date < localDate()
  )
    e.date = "Bugungi yoki kelajakdagi sanani tanlang.";
  if (
    !availability(d, appointments).slots.some(
      (s) => s.time === d.time && !s.busy,
    )
  )
    e.time = "Bo‘sh vaqtni tanlang.";
  return e;
}
export function nextPatient(
  patients: ReceptionPatient[],
  draft: PatientDraft,
): ReceptionPatient {
  const n =
    Math.max(131, ...patients.map((p) => Number(p.id.slice(2)) || 0)) + 1;
  return {
    ...draft,
    id: `P-${String(n).padStart(5, "0")}`,
    name: patientName(draft),
  };
}
export function checkIn(
  appointment: ReceptionAppointment,
  today = localDate(),
) {
  if (appointment.date !== today || appointment.status !== "booked")
    return false;
  appointment.status = "waiting";
  return true;
}

export function uzDate(date: string) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return "Sanani tanlang";
  const months = [
    "yanvar",
    "fevral",
    "mart",
    "aprel",
    "may",
    "iyun",
    "iyul",
    "avgust",
    "sentabr",
    "oktabr",
    "noyabr",
    "dekabr",
  ];
  const [year, month, day] = date.split("-");
  return `${Number(day)}-${months[Number(month) - 1]}, ${year}`;
}
