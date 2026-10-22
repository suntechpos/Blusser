/** Local role editor contract. Never used to authorize routes, APIs or the real session. */
import { createEmployees, clone, type Employee } from "./employees.ts";
export interface Permission {
  code: string;
  label: string;
  server: boolean;
}
export interface PermissionSection {
  parent?: string;
  id: string;
  label: string;
  permissions: Permission[];
}
const section = (
  id: string,
  label: string,
  actions: [string, string][],
  server = false,
): PermissionSection => ({
  id,
  label,
  permissions: actions.map(([action, label]) => ({
    code: `${server ? "" : "demo."}${id}.${action}`,
    label,
    server,
  })),
});
export const permissionSections: PermissionSection[] = [
  section("cashier", "Kassa", [
    ["view", "Kassani ko‘rish"],
    ["shift", "Smena ochish va yopish"],
    ["pay", "To‘lov qabul qilish"],
    ["history", "To‘lovlar tarixini ko‘rish"],
    ["discount", "Chegirma berish"],
    ["refund", "Pul qaytarish"],
  ]),
  section(
    "dashboard",
    "Bosh sahifa",
    [["view", "Bosh sahifani ko‘rish"]],
    true,
  ),
  section(
    "reception",
    "Registratura",
    [["view", "Registraturani ko‘rish"]],
    true,
  ),
  section("reception_actions", "Registratura amallari", [
    ["view", "Registratura amallarini ko‘rish"],
    ["book", "Yozilish va navbatsiz qabul"],
    ["arrive", "Kelgan bemorni qabulga olish"],
  ]),
  section(
    "patients",
    "Bemorlar",
    [
      ["view", "Bemorlarni ko‘rish"],
      ["create", "Bemor qo‘shish"],
      ["update", "Bemorni tahrirlash"],
      ["archive", "Arxivlash"],
      ["restore", "Arxivdan tiklash"],
      ["export", "Bemorlar eksporti"],
    ],
    true,
  ),
  section(
    "appointments",
    "Qabullar",
    [
      ["view", "Qabullarni ko‘rish"],
      ["create", "Qabul yaratish"],
      ["update", "Qabulni tahrirlash va holatini o‘zgartirish"],
      ["export", "Qabullar eksporti"],
      ["check_in", "Kelganini belgilash"],
      ["cancel", "Qabulni bekor qilish"],
      ["no_show", "Kelmaganini belgilash"],
    ],
    true,
  ),
  section(
    "queues",
    "Navbatlar",
    [
      ["view", "Navbatlarni ko‘rish"],
      ["call", "Chaqirish"],
      ["start", "Qabulni boshlash"],
    ],
    true,
  ),
  section("display", "Tablo boshqaruvi", [
    ["view", "Tablolarni ko‘rish"],
    ["create", "Ekran qo‘shish"],
    ["update", "Ekranni sozlash"],
    ["delete", "Ekranni o‘chirish"],
    ["preview", "TV ko‘rinishini ochish"],
  ]),
  section("workspace", "Shifokor kabineti", [
    ["view", "Shifokor kabinetini ko‘rish"],
    ["consult", "Ko‘rik ma’lumotlarini saqlash"],
    ["prescribe", "Retsept yozish"],
    ["refer", "Yo‘llanma berish"],
    ["upload", "Fayl biriktirish"],
    ["complete", "Qabulni yakunlash"],
    ["export", "Natijani PDF / chop etish"],
  ]),
  section("laboratory", "Laboratoriya", [
    ["view", "Laboratoriyani ko‘rish"],
    ["sample", "Namuna olish"],
    ["start", "Tahlilni boshlash"],
    ["result", "Natija kiritish"],
    ["review", "Tekshirishga yuborish"],
    ["return", "Qayta ishlashga qaytarish"],
    ["confirm", "Natijani tasdiqlash"],
    ["amend", "Tuzatish kiritish"],
    ["export", "PDF / chop etish"],
  ]),
  section("diagnostics", "UZI va Diagnostika", [
    ["view", "Diagnostikani ko‘rish"],
    ["start", "Tekshiruvni boshlash"],
    ["result", "Natija va fayllar kiritish"],
    ["confirm", "Natijani tasdiqlash"],
    ["amend", "Tuzatish kiritish"],
    ["export", "PDF / chop etish"],
  ]),
  section(
    "services",
    "Xizmatlar",
    [
      ["view", "Xizmatlarni ko‘rish"],
      ["create", "Xizmat qo‘shish"],
      ["update", "Xizmatni tahrirlash"],
      ["delete", "Xizmatni faolsizlantirish / faollashtirish"],
    ],
    true,
  ),
  section("categories", "Xizmat kategoriyalari", [
    ["view", "Kategoriyalarni ko‘rish"],
    ["create", "Kategoriya qo‘shish"],
    ["update", "Kategoriyani tahrirlash"],
    ["status", "Kategoriya holatini o‘zgartirish"],
    ["export", "Xizmatlar CSV eksporti"],
  ]),
  section("finance", "Moliya", [["view", "Moliya sahifasini ko‘rish"]]),
  section("inventory", "Ombor", [["view", "Ombor sahifasini ko‘rish"]]),
  section(
    "employees",
    "Xodimlar",
    [
      ["view", "Xodimlarni ko‘rish"],
      ["create", "Xodim qo‘shish"],
      ["update", "Xodimni tahrirlash"],
      ["assign_role", "Ruxsat rolini biriktirish"],
    ],
    true,
  ),
  section("employee_schedule", "Xodim ish jadvali", [
    ["view", "Ish jadvalini ko‘rish"],
    ["update", "Jadval va istisnolarni boshqarish"],
  ]),
  section(
    "branches",
    "Filiallar",
    [["view", "Filiallar sahifasini ko‘rish"]],
    true,
  ),
  section(
    "rooms",
    "Bo‘limlar va Xonalar",
    [
      ["view", "Xonalarni ko‘rish"],
      ["create", "Xona qo‘shish"],
      ["update", "Xonani tahrirlash"],
    ],
    true,
  ),
  section("departments", "Bo‘limlar boshqaruvi", [
    ["view", "Bo‘limlarni ko‘rish"],
    ["create", "Bo‘lim qo‘shish"],
    ["update", "Bo‘limni tahrirlash"],
    ["status", "Bo‘lim / xona holatini o‘zgartirish"],
  ]),
  section("reports", "Hisobotlar", [["view", "Hisobotlar sahifasini ko‘rish"]]),
  section(
    "roles",
    "Sozlamalar — rollar",
    [
      ["view", "Rollarni ko‘rish"],
      ["create", "Rol qo‘shish"],
      ["update", "Rolni tahrirlash"],
      ["delete", "Rolni o‘chirish"],
      ["manage_permissions", "Ruxsatlarni boshqarish"],
    ],
    true,
  ),
];
const parentViews: Record<string, string> = {
  reception_actions: "reception.view",
  categories: "services.view",
  employee_schedule: "employees.view",
  departments: "rooms.view",
};
for (const section of permissionSections)
  section.parent = parentViews[section.id];
export interface LocalRole {
  id: string;
  name: string;
  note: string;
  permissions: string[];
  protected?: boolean;
}
export interface RolesState {
  schema: 1;
  revision: number;
  roles: LocalRole[];
  employees: Employee[];
}
export const storagePrefix = "blusser.settings.roles.demo.v1.";
export const normalizeName = (s: string) =>
  s.trim().replace(/\s+/g, " ").toLocaleLowerCase("uz");
export const allCodes = permissionSections.flatMap((s) =>
  s.permissions.map((p) => p.code),
);
export const viewCode = (s: PermissionSection) => s.permissions[0]!.code;
export function canDemo(role: LocalRole | undefined, code: string): boolean {
  const s = permissionSections.find((s) =>
    s.permissions.some((p) => p.code === code),
  );
  return (
    !!role &&
    !!s &&
    role.permissions.includes(viewCode(s)) &&
    (!s.parent || canDemo(role, s.parent)) &&
    role.permissions.includes(code)
  );
}
export function employeeCanDemo(s: RolesState, e: Employee, code: string) {
  return (
    e.active &&
    e.access &&
    canDemo(
      s.roles.find((r) => r.id === e.roleId),
      code,
    )
  );
}
export function setPermission(role: LocalRole, code: string, on: boolean) {
  const s = permissionSections.find((s) =>
    s.permissions.some((p) => p.code === code),
  );
  if (!s) throw Error("Noma’lum ruxsat.");
  if (on && s.parent && !canDemo(role, s.parent)) return;
  if (code !== viewCode(s) && !canDemo(role, viewCode(s))) return;
  const values = new Set(role.permissions);
  if (on) values.add(code);
  else values.delete(code);
  if (code === viewCode(s) && !on)
    s.permissions.forEach((p) => values.delete(p.code));
  for (const child of permissionSections)
    if (child.parent && !values.has(child.parent))
      child.permissions.forEach((p) => values.delete(p.code));
  role.permissions = [...values];
}
const codes = (...ids: string[]) =>
  permissionSections
    .filter((s) => ids.includes(s.id))
    .flatMap((s) => s.permissions.map((p) => p.code));
export function createRolesState(): RolesState {
  const roles: LocalRole[] = [
    {
      id: "administrator",
      name: "Administrator",
      note: "Klinika boshqaruvi",
      permissions: [...allCodes],
      protected: true,
    },
    {
      id: "registrator",
      name: "Registrator",
      note: "Bemorlarni ro‘yxatga olish va qabullar",
      permissions: [
        "dashboard.view",
        ...codes(
          "reception",
          "reception_actions",
          "patients",
          "appointments",
          "queues",
        ),
      ].filter(
        (c) =>
          !["patients.archive", "patients.restore", "patients.export"].includes(
            c,
          ),
      ),
    },
    {
      id: "doctor",
      name: "Shifokor",
      note: "Ko‘rik va davolash",
      permissions: [
        "dashboard.view",
        "patients.view",
        "appointments.view",
        "queues.view",
        ...codes("workspace"),
      ],
    },
    {
      id: "lab",
      name: "Laborant",
      note: "Namuna va tahlil natijalari",
      permissions: codes("laboratory").filter(
        (c) =>
          ![
            "demo.laboratory.confirm",
            "demo.laboratory.return",
            "demo.laboratory.amend",
          ].includes(c),
      ),
    },
    {
      id: "uzi",
      name: "UZI mutaxassisi",
      note: "UZI tekshiruvlari",
      permissions: codes("diagnostics"),
    },
    {
      id: "cashier",
      name: "Kassir",
      note: "To‘lovlarni qabul qilish va kassa bilan ishlash",
      permissions: [
        "demo.cashier.view",
        "demo.cashier.shift",
        "demo.cashier.pay",
        "demo.cashier.history",
      ],
    },
  ];
  return migrateState({
    schema: 1,
    revision: 0,
    roles,
    employees: createEmployees(),
  });
}
export function migrateState(state: RolesState): RolesState {
  const s = clone(state);
  for (const e of s.employees) {
    if (e.roleId === undefined)
      e.roleId =
        s.roles.find(
          (r) => normalizeName(r.name) === normalizeName(e.role ?? ""),
        )?.id ?? "";
  }
  return validateState(s);
}
export const assigned = (s: RolesState, id: string) =>
  s.employees.filter((e) => e.roleId === id).length;
export const management = (r: LocalRole) =>
  canDemo(r, "roles.manage_permissions") && canDemo(r, "roles.update");
export function validateState(s: RolesState): RolesState {
  if (
    s.schema !== 1 ||
    !Number.isInteger(s.revision) ||
    s.revision < 0 ||
    !Array.isArray(s.roles) ||
    !Array.isArray(s.employees)
  )
    throw Error("Lokal rollar formati mos emas.");
  if (
    new Set(s.roles.map((r) => r.id)).size !== s.roles.length ||
    new Set(s.employees.map((e) => e.id)).size !== s.employees.length
  )
    throw Error("Takrorlangan ID.");
  const names = new Set<string>();
  for (const r of s.roles) {
    if (
      !r.id ||
      !r.name.trim() ||
      r.name.length > 80 ||
      r.note.length > 300 ||
      names.has(normalizeName(r.name))
    )
      throw Error("Rol nomi bo‘sh yoki takrorlangan.");
    names.add(normalizeName(r.name));
    if (
      !r.permissions.length ||
      r.permissions.some((c) => !allCodes.includes(c))
    )
      throw Error("Kamida bitta foydali ruxsat tanlang.");
    for (const section of permissionSections)
      if (
        section.parent &&
        !r.permissions.includes(section.parent) &&
        section.permissions.some((p) => r.permissions.includes(p.code))
      )
        throw Error("Avval asosiy sahifani ko‘rish ruxsatini yoqing.");
    for (const section of permissionSections)
      if (
        !r.permissions.includes(viewCode(section)) &&
        section.permissions.some((p) => r.permissions.includes(p.code))
      )
        throw Error("Ichki amal uchun sahifani ko‘rish kerak.");
  }
  if (
    !s.roles.some(
      (r) => r.id === "administrator" && r.protected && management(r),
    )
  )
    throw Error("Administrator boshqaruv huquqlarini saqlashi kerak.");
  if (
    !s.employees.some(
      (e) =>
        e.active &&
        e.access &&
        s.roles.some((r) => r.id === e.roleId && management(r)),
    )
  )
    throw Error("Kamida bitta faol boshqaruvchi qolishi kerak.");
  for (const e of s.employees)
    if (e.roleId && !s.roles.some((r) => r.id === e.roleId))
      throw Error("Xodimning roli topilmadi.");
  return s;
}
export function saveRole(s: RolesState, role: LocalRole) {
  const current = s.roles.find((r) => r.id === role.id);
  if (current?.protected && (!role.protected || !management(role)))
    throw Error(
      "Himoyalangan Administrator boshqaruv huquqlarini o‘chirib bo‘lmaydi.",
    );
  const value = {
    ...clone(role),
    name: role.name.trim().replace(/\s+/g, " "),
    note: role.note.trim(),
    protected: current?.protected ?? false,
  };
  if (current) s.roles[s.roles.indexOf(current)] = value;
  else s.roles.push(value);
  validateState(s);
}
export function deleteRole(s: RolesState, id: string) {
  const r = s.roles.find((r) => r.id === id);
  if (!r) throw Error("Rol topilmadi.");
  if (r.protected) throw Error("Himoyalangan tizim rolini o‘chirib bo‘lmaydi.");
  if (assigned(s, id)) throw Error("Avval xodimlarga boshqa rol bering.");
  s.roles = s.roles.filter((r) => r.id !== id);
  validateState(s);
}
export type StoragePort = Pick<Storage, "getItem" | "setItem">;
export function loadRoles(storage: StoragePort, key: string) {
  const raw = storage.getItem(key);
  return raw ? migrateState(JSON.parse(raw)) : createRolesState();
}
export function commitRoles(
  s: RolesState,
  storage: StoragePort,
  key: string,
  mutate: (draft: RolesState) => void,
) {
  const disk = storage.getItem(key);
  if (disk && JSON.parse(disk).revision !== s.revision)
    throw Error("Boshqa oynada ma’lumot yangilangan. Sahifani yangilang.");
  const next = clone(s);
  mutate(next);
  next.revision++;
  validateState(next);
  storage.setItem(key, JSON.stringify(next));
  return next;
}
