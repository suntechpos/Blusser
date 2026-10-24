/** Synthetic employee fixtures. Settings demo store owns optional local persistence; no API or passwords. */
export const positions = [
  "Shifokor",
  "Hamshira",
  "Laborant",
  "Boshqa",
  "Registrator",
  "Kassir",
  "Qorovul",
  "Haydovchi",
  "Tez yordam haydovchisi",
  "Farrosh",
  "Administrator",
];
export const branches = ["Asosiy filial", "Yunusobod"];
export const departments = [
  "Kardiologiya",
  "Terapiya",
  "Registratura",
  "Moliya",
  "Xo‘jalik",
];
export const rooms: Record<string, string[]> = {
  "Asosiy filial": ["201-kabinet", "202-kabinet"],
  Yunusobod: ["105-kabinet", "106-kabinet"],
};
export const weekdays = [
  "Dushanba",
  "Seshanba",
  "Chorshanba",
  "Payshanba",
  "Juma",
  "Shanba",
  "Yakshanba",
];
export interface Shift {
  branch: string;
  room: string;
  start: string;
  end: string;
  nextDay: boolean;
  breakStart: string;
  breakEnd: string;
}
export interface WorkDay {
  enabled: boolean;
  shifts: Shift[];
}
export interface Schedule {
  effective: string;
  days: WorkDay[];
}
export interface Exception {
  id: string;
  from: string;
  to: string;
  kind: "off" | "changed";
  reason: string;
  note: string;
  shift: Shift;
}
export interface Employee {
  id: string;
  first: string;
  last: string;
  patronymic: string;
  phone: string;
  dob: string;
  gender: string;
  address: string;
  photo: string;
  position: string;
  branch: string;
  department: string;
  hired: string;
  active: boolean;
  access: boolean;
  login: string;
  role: string;
  /** Stable local demo role ID; legacy role label is retained for migration. */
  roleId?: string;
  version?: number;
  membershipId?: string | null;
  branchIds?: string[];
  pageGrants?: { code: string; scope: string }[] | null;
  cashierPinSet?: boolean;
  specialty: string;
  duration: number;
  note: string;
  schedule: Schedule | null;
  exceptions: Exception[];
}
export const clone = <T>(value: T): T => JSON.parse(JSON.stringify(value));
export function blankShift(branch = branches[0]!): Shift {
  return {
    branch,
    room: rooms[branch]![0]!,
    start: "09:00",
    end: "17:00",
    nextDay: false,
    breakStart: "",
    breakEnd: "",
  };
}
export function blankSchedule(): Schedule {
  return {
    effective: today(),
    days: weekdays.map((_, i) => ({ enabled: i < 6, shifts: [blankShift()] })),
  };
}
export function today() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}
export function blankEmployee(): Employee {
  return {
    id: "",
    first: "",
    last: "",
    patronymic: "",
    phone: "",
    dob: "",
    gender: "",
    address: "",
    photo: "",
    position: "Registrator",
    branch: branches[0]!,
    department: "Registratura",
    hired: today(),
    active: true,
    access: true,
    login: "",
    role: "Registrator",
    specialty: "",
    duration: 30,
    note: "",
    schedule: null,
    exceptions: [],
  };
}
const names = [
  ["Aziz", "Karimov"],
  ["Dilnoza", "Rasulova"],
  ["Madina", "Aliyeva"],
  ["Sardor", "Usmonov"],
  ["Akmal", "Tursunov"],
  ["Rustam", "Qodirov"],
  ["Jasur", "Ergashev"],
  ["Malika", "Sobirova"],
  ["Nodir", "Rahimov"],
  ["Behzod", "Saidov"],
  ["Shahnoza", "Nazarova"],
  ["Bekzod", "Olimov"],
  ["Saida", "Rasulova"],
  ["Anvar", "Sobirov"],
  ["Gulnoza", "Aliyeva"],
  ["Diyor", "Usmonov"],
  ["Dilshod", "Tursunov"],
  ["Zarina", "Qodirova"],
  ["Javohir", "Ergashev"],
  ["Munisa", "Saidova"],
  ["Oybek", "Rahimov"],
  ["Shahzod", "Karimov"],
  ["Nilufar", "Nazarova"],
  ["Bobur", "Olimov"],
];
const jobs = [
  "Shifokor",
  "Shifokor",
  "Hamshira",
  "Registrator",
  "Kassir",
  "Qorovul",
  "Haydovchi",
  "Administrator",
  "Farrosh",
  "Tez yordam haydovchisi",
];
export function createEmployees(): Employee[] {
  return names.map(([first, last], i) => {
    const e = {
      ...blankEmployee(),
      id: `X${String(i + 1).padStart(3, "0")}`,
      first: first!,
      last: last!,
      phone: `+998 90 000 ${String(i + 1).padStart(2, "0")} ${String(i + 1).padStart(2, "0")}`,
      position: jobs[i % jobs.length]!,
      branch: i % 3 === 1 ? "Yunusobod" : "Asosiy filial",
      hired: "2024-07-15",
      dob: "1988-03-12",
      gender: i % 2 ? "Ayol" : "Erkak",
      address: "Toshkent shahri, Yunusobod tumani",
      active: ![7, 23].includes(i),
      access: ![5, 6, 8, 9].includes(i % 10),
      login: `demo.xodim.${i + 1}`,
      note:
        i === 0
          ? "Kardiologiya bo‘limida kattalar qabuli bilan shug‘ullanadi."
          : "",
    };
    e.access = e.access && e.active;
    e.role = e.position;
    e.department =
      e.position === "Shifokor"
        ? i === 0
          ? "Kardiologiya"
          : "Terapiya"
        : e.position === "Hamshira"
          ? "Terapiya"
          : e.position === "Registrator" || e.position === "Administrator"
            ? "Registratura"
            : e.position === "Kassir"
              ? "Moliya"
              : "Xo‘jalik";
    e.specialty =
      e.position === "Shifokor" ? (i === 0 ? "Kardiolog" : "Terapevt") : "";
    if (i === 0) {
      e.schedule = blankSchedule();
      e.schedule.effective = "2026-10-19";
      e.schedule.days.forEach((d, j) => {
        const s = d.shifts[0]!;
        s.branch = j % 2 && j !== 5 ? "Yunusobod" : "Asosiy filial";
        s.room = rooms[s.branch]![0]!;
        s.end = j === 5 ? "13:00" : j % 2 ? "15:00" : "17:00";
        if (j < 5) {
          s.breakStart = j % 2 ? "12:00" : "13:00";
          s.breakEnd = j % 2 ? "12:30" : "14:00";
        }
      });
      e.exceptions = [
        {
          id: "demo-ex-1",
          from: "2026-10-26",
          to: "2026-10-26",
          kind: "off",
          reason: "Dam olish",
          note: "Shaxsiy sabab",
          shift: blankShift(),
        },
        {
          id: "demo-ex-2",
          from: "2026-11-02",
          to: "2026-11-02",
          kind: "changed",
          reason: "Qisqa ish kuni",
          note: "",
          shift: { ...blankShift(), end: "12:00" },
        },
      ];
    }
    return e;
  });
}
export function fullName(e: Employee) {
  return `${e.first} ${e.last}`.trim();
}
export function initials(e: Employee) {
  return `${e.first[0] ?? ""}${e.last[0] ?? ""}`;
}
export function dateLabel(value: string) {
  if (!value) return "—";
  const [y, m, d] = value.split("-");
  return `${d}.${m}.${y}`;
}
export function elapsed(value: string, now = today()) {
  if (!value || value > now) return "—";
  const d = new Date(value + "T12:00:00"),
    n = new Date(now + "T12:00:00");
  let months =
    (n.getFullYear() - d.getFullYear()) * 12 + n.getMonth() - d.getMonth();
  if (n.getDate() < d.getDate()) months--;
  return `${Math.floor(months / 12)} yil${months % 12 ? ` ${months % 12} oy` : ""}`;
}
const minutes = (s: string) => {
  const [h, m] = s.split(":").map(Number);
  return h! * 60 + m!;
};
export function shiftError(s: Shift, doctor: boolean): string {
  if (!s.branch || (doctor && !rooms[s.branch]?.includes(s.room)))
    return "Filial va tegishli kabinetni tanlang.";
  if (
    !/^([01]\d|2[0-3]):[0-5]\d$/.test(s.start) ||
    !/^([01]\d|2[0-3]):[0-5]\d$/.test(s.end)
  )
    return "Ish boshlanishi va tugashini kiriting.";
  const start = minutes(s.start),
    end = minutes(s.end) + (s.nextDay ? 1440 : 0);
  if (end <= start || end - start > 1440)
    return "Ish vaqti noto‘g‘ri. Tungi smenada keyingi kunni belgilang.";
  if (Boolean(s.breakStart) !== Boolean(s.breakEnd))
    return "Tanaffusning ikkala vaqtini kiriting.";
  if (s.breakStart) {
    if (
      !/^([01]\d|2[0-3]):[0-5]\d$/.test(s.breakStart) ||
      !/^([01]\d|2[0-3]):[0-5]\d$/.test(s.breakEnd)
    )
      return "Tanaffus vaqtini HH:mm formatida kiriting.";
    let a = minutes(s.breakStart),
      b = minutes(s.breakEnd);
    if (s.nextDay && a < start) a += 1440;
    if (s.nextDay && b < start) b += 1440;
    if (a < start || b > end || b <= a)
      return "Tanaffus ish vaqti ichida bo‘lishi kerak.";
  }
  return "";
}
export function scheduleError(s: Schedule, doctor: boolean): string {
  if (!s.effective) return "Amal qilish sanasini kiriting.";
  const intervals: [number, number][] = [];
  for (const [i, day] of s.days.entries())
    if (day.enabled) {
      if (!day.shifts.length)
        return `${weekdays[i]}: vaqt oralig‘ini kiriting.`;
      for (const shift of day.shifts) {
        const error = shiftError(shift, doctor);
        if (error) return `${weekdays[i]}: ${error}`;
        const a = i * 1440 + minutes(shift.start),
          b = i * 1440 + minutes(shift.end) + (shift.nextDay ? 1440 : 0);
        intervals.push([a, b]);
      }
    }
  // Duplicate intervals across week boundaries to catch Sunday overnight -> Monday.
  for (let i = 0; i < intervals.length; i++)
    for (let j = i + 1; j < intervals.length; j++)
      for (const offset of [-10080, 0, 10080]) {
        const a = intervals[i]!,
          b = intervals[j]!;
        if (a[0] < b[1] + offset && b[0] + offset < a[1])
          return "Bir xodimning vaqt oraliqlari to‘qnashmoqda.";
      }
  return "";
}
export function exceptionError(e: Exception, doctor: boolean): string {
  if (!e.from || !e.to || e.to < e.from)
    return "Sanalarni tekshiring: tugash boshlanishdan oldin bo‘lmasin.";
  if (!e.reason) return "Sababni tanlang.";
  return e.kind === "changed" ? shiftError(e.shift, doctor) : "";
}
