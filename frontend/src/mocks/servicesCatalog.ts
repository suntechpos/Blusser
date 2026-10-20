/** Services-only in-memory catalog. No API calls, persistence or cross-module pricing. */
export const palette = [
  "blue",
  "cyan",
  "green",
  "purple",
  "orange",
  "pink",
  "slate",
] as const;
export type CategoryColor = (typeof palette)[number];
export type ServiceType =
  | "consultation"
  | "laboratory"
  | "diagnostic"
  | "procedure"
  | "other";
export const serviceTypes: { id: ServiceType; name: string }[] = [
  { id: "consultation", name: "Konsultatsiya" },
  { id: "laboratory", name: "Laboratoriya" },
  { id: "diagnostic", name: "Diagnostika" },
  { id: "procedure", name: "Muolaja" },
  { id: "other", name: "Boshqa" },
];
export const catalogBranches = [
  { id: "main", name: "Asosiy filial" },
  { id: "andijan", name: "Andijon filiali" },
  { id: "yunus", name: "Yunusobod" },
];
export type Category = {
  id: string;
  name: string;
  description: string;
  color: CategoryColor;
  active: boolean;
};
export type CatalogService = {
  id: string;
  code: string;
  name: string;
  categoryId: string;
  type: ServiceType;
  branchIds: string[];
  price: number;
  timed: boolean;
  duration: number | null;
  turnaround: number | null;
  turnaroundUnit: "hour" | "day";
  description: string;
  active: boolean;
};
export type ServiceDraft = Omit<
  CatalogService,
  "id" | "price" | "duration" | "turnaround"
> & { price: string; duration: string; turnaround: string };
export type CategoryDraft = Omit<Category, "id">;
export type Catalog = { categories: Category[]; services: CatalogService[] };
export const normalize = (s: string) =>
  s.trim().normalize("NFKC").toLocaleLowerCase().replace(/\s+/g, " ");
export const money = (n: number) =>
  `${String(n).replace(/\B(?=(\d{3})+(?!\d))/g, " ")} so‘m`;
export const blankCategory = (): CategoryDraft => ({
  name: "",
  description: "",
  color: "blue",
  active: true,
});
export function nextCode(services: CatalogService[]) {
  let n =
    Math.max(
      0,
      ...services.map((s) => Number(/^XZ-(\d+)$/.exec(s.code)?.[1] ?? 0)),
    ) + 1;
  while (services.some((s) => s.code === `XZ-${String(n).padStart(3, "0")}`))
    n++;
  return `XZ-${String(n).padStart(3, "0")}`;
}
export function blankService(
  services: CatalogService[],
  categories: Category[],
): ServiceDraft {
  return {
    code: nextCode(services),
    name: "",
    categoryId: categories.find((c) => c.active)?.id ?? "",
    type: "consultation",
    branchIds: ["main"],
    price: "",
    timed: true,
    duration: "30",
    turnaround: "",
    turnaroundUnit: "hour",
    description: "",
    active: true,
  };
}
export function serviceDraft(s: CatalogService): ServiceDraft {
  return {
    ...s,
    branchIds: [...s.branchIds],
    price: String(s.price),
    duration: s.duration === null ? "" : String(s.duration),
    turnaround: s.turnaround === null ? "" : String(s.turnaround),
  };
}
export function validateCategory(
  d: CategoryDraft,
  categories: Category[],
  editingId?: string,
) {
  const errors: Record<string, string> = {};
  if (!d.name.trim()) errors.name = "Kategoriya nomini kiriting.";
  else if (d.name.trim().length > 120)
    errors.name = "Nom 120 belgidan oshmasin.";
  else if (
    categories.some(
      (c) => c.id !== editingId && normalize(c.name) === normalize(d.name),
    )
  )
    errors.name = "Bu nomli kategoriya mavjud.";
  if (!palette.includes(d.color)) errors.color = "Rangni tanlang.";
  return errors;
}
export function validateService(
  d: ServiceDraft,
  catalog: Catalog,
  editing?: CatalogService,
) {
  const errors: Record<string, string> = {};
  if (!d.name.trim()) errors.name = "Xizmat nomini kiriting.";
  else if (d.name.trim().length > 255)
    errors.name = "Nom 255 belgidan oshmasin.";
  const category = catalog.categories.find((c) => c.id === d.categoryId);
  if (!category || (!category.active && editing?.categoryId !== category.id))
    errors.categoryId = "Faol kategoriyani tanlang.";
  if (!serviceTypes.some((t) => t.id === d.type))
    errors.type = "Xizmat turini tanlang.";
  if (
    !d.branchIds.length ||
    d.branchIds.some((id) => !catalogBranches.some((b) => b.id === id))
  )
    errors.branchIds = "Kamida bitta filialni tanlang.";
  if (
    !/^\d+$/.test(d.price.trim()) ||
    !Number.isSafeInteger(Number(d.price)) ||
    Number(d.price) < 0
  )
    errors.price = "Narxni manfiy bo‘lmagan butun so‘mda kiriting.";
  if (d.type === "consultation" || d.timed) {
    if (
      !/^\d+$/.test(d.duration) ||
      !Number.isSafeInteger(Number(d.duration)) ||
      Number(d.duration) < 1 ||
      Number(d.duration) > 1440
    )
      errors.duration = "Davomiylik 1–1440 daqiqa bo‘lsin.";
  }
  if (
    d.type === "laboratory" &&
    (!/^\d+(\.\d+)?$/.test(d.turnaround) ||
      !Number.isFinite(Number(d.turnaround)) ||
      Number(d.turnaround) <= 0)
  )
    errors.turnaround = "Musbat natija muddatini kiriting.";
  if (d.type === "laboratory" && !["hour", "day"].includes(d.turnaroundUnit))
    errors.turnaroundUnit = "Soat yoki kunni tanlang.";
  return errors;
}
export function saveCategory(
  catalog: Catalog,
  draft: CategoryDraft,
  id?: string,
) {
  const errors = validateCategory(draft, catalog.categories, id);
  if (Object.keys(errors).length) return { errors };
  const current = catalog.categories.find((c) => c.id === id);
  const category: Category = {
    ...draft,
    name: draft.name.trim(),
    description: draft.description.trim(),
    id:
      current?.id ??
      `cat-${Math.max(0, ...catalog.categories.map((c) => Number(c.id.replace("cat-", "")) || 0)) + 1}`,
  };
  if (current) Object.assign(current, category);
  else catalog.categories.push(category);
  return { errors, category };
}
export function saveService(
  catalog: Catalog,
  draft: ServiceDraft,
  id?: string,
) {
  const current = catalog.services.find((s) => s.id === id);
  const errors = validateService(draft, catalog, current);
  if (Object.keys(errors).length) return { errors };
  const service: CatalogService = {
    ...draft,
    id:
      current?.id ??
      `service-${Math.max(0, ...catalog.services.map((s) => Number(s.id.replace("service-", "")) || 0)) + 1}`,
    code: current?.code ?? nextCode(catalog.services),
    name: draft.name.trim(),
    description: draft.description.trim(),
    branchIds: [...new Set(draft.branchIds)],
    price: Number(draft.price),
    timed: draft.type === "consultation" || draft.timed,
    duration:
      draft.type === "consultation" || draft.timed
        ? Number(draft.duration)
        : null,
    turnaround: draft.type === "laboratory" ? Number(draft.turnaround) : null,
    turnaroundUnit: draft.type === "laboratory" ? draft.turnaroundUnit : "hour",
  };
  if (current) Object.assign(current, service);
  else catalog.services.push(service);
  return { errors, service };
}
export type CatalogFilter = {
  query: string;
  categoryId: string;
  branchId: string;
  status: string;
};
export function filterServices(catalog: Catalog, f: CatalogFilter) {
  return catalog.services.filter(
    (s) =>
      (!f.query ||
        normalize(`${s.name} ${s.code}`).includes(normalize(f.query))) &&
      (!f.categoryId || s.categoryId === f.categoryId) &&
      (!f.branchId || s.branchIds.includes(f.branchId)) &&
      (!f.status || (f.status === "active") === s.active),
  );
}
function csvCell(value: unknown) {
  let s = String(value ?? "");
  if (/^[\s]*[=+@-]/.test(s)) s = "'" + s;
  return '"' + s.replace(/"/g, '""') + '"';
}
export function catalogCsv(services: CatalogService[], categories: Category[]) {
  return (
    "\uFEFF" +
    [
      [
        "Kod",
        "Xizmat nomi",
        "Kategoriya",
        "Xizmat turi",
        "Filiallar",
        "Narx (so‘m)",
        "Davomiylik (daqiqa)",
        "Natija muddati",
        "Holat",
      ],
      ...services.map((s) => [
        s.code,
        s.name,
        categories.find((c) => c.id === s.categoryId)?.name,
        serviceTypes.find((t) => t.id === s.type)?.name,
        s.branchIds
          .map((id) => catalogBranches.find((b) => b.id === id)?.name)
          .join("; "),
        s.price,
        s.duration ?? "",
        s.turnaround === null
          ? ""
          : `${s.turnaround} ${s.turnaroundUnit === "hour" ? "soat" : "kun"}`,
        s.active ? "Faol" : "Faol emas",
      ]),
    ]
      .map((r) => r.map(csvCell).join(","))
      .join("\r\n")
  );
}
export function createServicesCatalog(): Catalog {
  const categories: Category[] = [
    {
      id: "cat-1",
      name: "Shifokor qabuli",
      color: "blue",
      active: true,
      description: "Shifokor konsultatsiyalari",
    },
    {
      id: "cat-2",
      name: "Laboratoriya",
      color: "green",
      active: true,
      description: "",
    },
    {
      id: "cat-3",
      name: "Diagnostika",
      color: "purple",
      active: true,
      description: "",
    },
    {
      id: "cat-4",
      name: "Muolajalar",
      color: "orange",
      active: true,
      description: "",
    },
    {
      id: "cat-5",
      name: "Boshqa",
      color: "slate",
      active: true,
      description: "",
    },
  ];
  const rows: [string, number, ServiceType, number, number | null][] = [
    [
      "Kardiolog konsultatsiyasi / Birlamchi qabul",
      1,
      "consultation",
      150000,
      30,
    ],
    ["Kardiolog takroriy qabuli", 1, "consultation", 100000, 20],
    ["Terapevt konsultatsiyasi", 1, "consultation", 120000, 30],
    ["Umumiy qon tahlili", 2, "laboratory", 60000, null],
    ["Umumiy siydik tahlili", 2, "laboratory", 45000, null],
    ["Qonda glyukoza", 2, "laboratory", 35000, null],
    ["Qorin bo‘shlig‘i UZI", 3, "diagnostic", 120000, 20],
    ["EKG tekshiruvi", 3, "diagnostic", 70000, 15],
    ["Mushak orasiga inyeksiya", 4, "procedure", 25000, 10],
    ["Fizioterapiya seansi", 4, "procedure", 50000, 20],
    ["Nevrolog konsultatsiyasi", 1, "consultation", 130000, 30],
    ["Pediatr konsultatsiyasi", 1, "consultation", 120000, 30],
    ["Endokrinolog konsultatsiyasi", 1, "consultation", 140000, 30],
    ["Ortoped konsultatsiyasi", 1, "consultation", 140000, 30],
    ["LOR konsultatsiyasi", 1, "consultation", 110000, 20],
    ["Qon biokimyosi", 2, "laboratory", 180000, null],
    ["Lipid profili", 2, "laboratory", 110000, null],
    ["Vitamin D tahlili", 2, "laboratory", 160000, null],
    ["TSH tahlili", 2, "laboratory", 90000, null],
    ["Yurak UZI", 3, "diagnostic", 180000, 30],
    ["Qalqonsimon bez UZI", 3, "diagnostic", 100000, 20],
    ["Tomir ichiga inyeksiya", 4, "procedure", 35000, 15],
    ["Tibbiy ma’lumotnoma", 5, "other", 50000, null],
    ["Tibbiy hujjat nusxasi", 5, "other", 15000, null],
  ];
  return {
    categories,
    services: rows.map((r, i) => ({
      id: `service-${i + 1}`,
      code: `XZ-${String(i + 1).padStart(3, "0")}`,
      name: r[0],
      categoryId: `cat-${r[1]}`,
      type: r[2],
      branchIds: i % 3 === 0 ? ["main", "andijan"] : ["main"],
      price: r[3],
      timed: r[4] !== null,
      duration: r[4],
      turnaround: r[2] === "laboratory" ? 24 : null,
      turnaroundUnit: "hour",
      description: "",
      active: ![9, 23].includes(i),
    })),
  };
}
