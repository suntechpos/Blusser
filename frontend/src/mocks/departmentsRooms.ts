/** Volatile UI fixtures only. Independent of Employees and other modules. */
export const FACILITY_BRANCHES = ["Asosiy filial", "Yunusobod"] as const;
export const ROOM_TYPES = [
  "Qabul kabineti",
  "Diagnostika xonasi",
  "Tahlil olish xonasi",
  "Muolaja xonasi",
  "Operatsiya xonasi",
  "Palata",
  "Boshqa",
] as const;
export interface Department {
  id: string;
  name: string;
  branch: string;
  note: string;
  active: boolean;
  icon: string;
}
export interface Room {
  availability?: "available" | "unavailable";
  id: string;
  name: string;
  branch: string;
  departmentId: string;
  type: string;
  floor: string;
  active: boolean;
  note: string;
}
export const normalizeName = (value: string) =>
  value.normalize("NFKC").trim().replace(/\s+/g, " ").toLocaleLowerCase("uz");
export function createFacilities(): {
  departments: Department[];
  rooms: Room[];
} {
  const names = [
    "Kardiologiya",
    "Terapiya",
    "Pediatriya",
    "Nevrologiya",
    "Laboratoriya",
    "UZI va diagnostika",
    "Tez yordam",
    "Fizioterapiya",
  ];
  const departments = names.map((name, i) => ({
    id: `B-${String(i + 1).padStart(3, "0")}`,
    name,
    branch: [2, 5, 7].includes(i) ? "Yunusobod" : "Asosiy filial",
    note: "",
    active: i !== 7,
    icon: [
      "heart",
      "stethoscope",
      "baby",
      "brain",
      "flask",
      "scan",
      "ambulance",
      "activity",
    ][i]!,
  }));
  const rows: [string, number, string, string, boolean][] = [
    ["201-kabinet", 0, "Qabul kabineti", "2", true],
    ["202-kabinet", 0, "Qabul kabineti", "2", true],
    ["101-kabinet", 1, "Qabul kabineti", "1", true],
    ["105-kabinet", 2, "Qabul kabineti", "1", true],
    ["UZI-1", 5, "Diagnostika xonasi", "1", true],
    ["Laboratoriya-1", 4, "Tahlil olish xonasi", "1", true],
    ["301-palata", 1, "Palata", "3", true],
    ["203-kabinet", 0, "Qabul kabineti", "2", false],
  ];
  // 23 rooms with internally consistent, computed department counts.
  const extra: [string, number, string, string, boolean][] = [
    ["204-kabinet", 0, "Qabul kabineti", "2", true],
    ["102-kabinet", 1, "Qabul kabineti", "1", true],
    ["103-kabinet", 1, "Muolaja xonasi", "1", true],
    ["302-palata", 1, "Palata", "3", true],
    ["303-palata", 1, "Palata", "3", true],
    ["304-palata", 1, "Palata", "3", true],
    ["104-kabinet", 1, "Qabul kabineti", "1", true],
    ["106-kabinet", 2, "Qabul kabineti", "1", true],
    ["107-kabinet", 2, "Qabul kabineti", "1", true],
    ["205-kabinet", 3, "Qabul kabineti", "2", true],
    ["206-kabinet", 3, "Qabul kabineti", "2", true],
    ["Laboratoriya-2", 4, "Tahlil olish xonasi", "1", true],
    ["Laboratoriya-3", 4, "Tahlil olish xonasi", "1", true],
    ["UZI-2", 5, "Diagnostika xonasi", "1", true],
    ["Tez yordam-1", 6, "Boshqa", "1", true],
  ];
  const rooms = [...rows, ...extra].map(
    ([name, d, type, floor, active], i) => ({
      id: `XN-${String(i + 1).padStart(3, "0")}`,
      name,
      branch: departments[d]!.branch,
      departmentId: departments[d]!.id,
      type,
      floor,
      active,
      note: "",
    }),
  );
  return { departments, rooms };
}
export function departmentErrors(
  d: Department,
  departments: Department[],
  rooms: Room[],
) {
  const errors: Record<string, string> = {};
  if (!d.name.trim()) errors.name = "Bo‘lim nomini kiriting.";
  else if (
    departments.some(
      (x) =>
        x.id !== d.id &&
        x.branch === d.branch &&
        normalizeName(x.name) === normalizeName(d.name),
    )
  )
    errors.name = "Bu filialda shu nomli bo‘lim mavjud.";
  if (!FACILITY_BRANCHES.some((b) => b === d.branch))
    errors.branch = "Filialni tanlang.";
  if (rooms.some((r) => r.departmentId === d.id && r.branch !== d.branch))
    errors.branch =
      "Xonalari mavjud bo‘limning filialini o‘zgartirib bo‘lmaydi.";
  return errors;
}
export function roomErrors(r: Room, rooms: Room[], departments: Department[]) {
  const errors: Record<string, string> = {};
  if (!r.name.trim()) errors.name = "Xona nomi yoki raqamini kiriting.";
  else if (
    rooms.some(
      (x) =>
        x.id !== r.id &&
        x.branch === r.branch &&
        normalizeName(x.name) === normalizeName(r.name),
    )
  )
    errors.name = "Bu filialda shu nomli xona mavjud.";
  if (!FACILITY_BRANCHES.some((b) => b === r.branch))
    errors.branch = "Filialni tanlang.";
  if (
    !departments.some((d) => d.id === r.departmentId && d.branch === r.branch)
  )
    errors.departmentId = "Tanlangan filial bo‘limini tanlang.";
  if (!ROOM_TYPES.some((t) => t === r.type))
    errors.type = "Xona turini tanlang.";
  if (r.floor && !/^-?\d+$/.test(r.floor))
    errors.floor = "Qavatni butun son bilan kiriting yoki bo‘sh qoldiring.";
  return errors;
}
