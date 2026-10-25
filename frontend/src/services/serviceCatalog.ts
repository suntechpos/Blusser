import { clinicRequest } from "./api";

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
  "consultation" | "laboratory" | "diagnostic" | "procedure" | "other";
export const serviceTypes: { id: ServiceType; name: string }[] = [
  { id: "consultation", name: "Konsultatsiya" },
  { id: "laboratory", name: "Laboratoriya" },
  { id: "diagnostic", name: "Diagnostika" },
  { id: "procedure", name: "Muolaja" },
  { id: "other", name: "Boshqa" },
];
export interface CatalogBranch {
  id: string;
  name: string;
  active: boolean;
}
export interface Category {
  id: string;
  name: string;
  description: string;
  color: CategoryColor;
  active: boolean;
  service_count: number;
  version?: number;
}
export type CategoryDraft = Pick<
  Category,
  "name" | "description" | "color" | "active"
>;
export interface CatalogService {
  id: string;
  code: string;
  name: string;
  categoryId: string | null;
  type: ServiceType;
  branchIds: string[];
  price: string | null;
  timed: boolean;
  duration: number | null;
  turnaround: string | null;
  turnaroundUnit: "hour" | "day";
  description: string;
  active: boolean;
  version?: number;
}
export type ServiceDraft = Omit<
  CatalogService,
  "id" | "price" | "duration" | "turnaround" | "version"
> & {
  price: string;
  duration: string;
  turnaround: string;
};
export interface Catalog {
  categories: Category[];
  services: CatalogService[];
}
export interface CatalogFilter {
  q?: string;
  category_id?: string;
  branch_id?: string;
  status?: string;
  page?: number;
  per_page?: number;
}
interface ServiceRecord {
  id: string;
  code: string;
  name: string;
  category_id: string | null;
  service_type: ServiceType;
  branch_ids: string[];
  price: string | null;
  timed: boolean;
  duration_minutes: number | null;
  turnaround: string | null;
  turnaround_unit: "hour" | "day";
  description: string;
  active: boolean;
  version?: number;
}
export interface CatalogResponse {
  data: ServiceRecord[];
  meta: { total: number; page?: number; per_page?: number };
  stats: {
    total: number;
    active: number;
    inactive: number;
    categories: number;
  };
  categories: Category[];
  branches: CatalogBranch[];
}
const path = "appointments/configuration-services";
const fromRecord = (s: ServiceRecord): CatalogService => ({
  id: s.id,
  code: s.code,
  name: s.name,
  categoryId: s.category_id,
  type: s.service_type ?? "other",
  branchIds: s.branch_ids ?? [],
  price: s.price == null ? null : String(s.price),
  timed: s.timed ?? false,
  duration: s.duration_minutes ?? null,
  turnaround: s.turnaround == null ? null : String(s.turnaround),
  turnaroundUnit: s.turnaround_unit ?? "hour",
  description: s.description ?? "",
  active: s.active,
  version: s.version,
});
export async function loadCatalog(params: CatalogFilter) {
  const response = (
    await clinicRequest<CatalogResponse>(path, { params: { ...params } })
  ).data;
  return { ...response, data: response.data.map(fromRecord) };
}
export async function readService(id: string) {
  return fromRecord((await clinicRequest<ServiceRecord>(`${path}/${id}`)).data);
}
const etag = (version?: number) =>
  version == null ? undefined : `"${version}"`;
export async function writeService(
  draft: ServiceDraft,
  current?: CatalogService,
) {
  return fromRecord(
    (
      await clinicRequest<ServiceRecord>(
        current ? `${path}/${current.id}` : path,
        {
          method: current ? "PUT" : "POST",
          etag: etag(current?.version),
          body: {
            name: draft.name.trim(),
            category_id: draft.categoryId,
            service_type: draft.type,
            branch_ids: draft.branchIds,
            price: draft.price.trim(),
            timed: draft.type === "consultation" || draft.timed,
            duration_minutes:
              draft.type === "consultation" || draft.timed
                ? Number(draft.duration)
                : null,
            turnaround: draft.type === "laboratory" ? draft.turnaround : null,
            turnaround_unit: draft.turnaroundUnit,
            description: draft.description.trim(),
            active: draft.active,
          },
        },
      )
    ).data,
  );
}
export async function writeServiceStatus(
  service: CatalogService,
  active: boolean,
) {
  return (
    await clinicRequest(`${path}/${service.id}/status`, {
      method: "POST",
      body: { active },
      etag: etag(service.version),
    })
  ).data;
}
export async function writeCategory(draft: CategoryDraft, current?: Category) {
  return (
    await clinicRequest<Category>(
      current ? `service-categories/${current.id}` : "service-categories",
      {
        method: current ? "PUT" : "POST",
        etag: etag(current?.version),
        body: {
          name: draft.name.trim(),
          description: draft.description.trim(),
          color: draft.color,
          active: draft.active,
        },
      },
    )
  ).data;
}
export async function writeCategoryStatus(category: Category, active: boolean) {
  return (
    await clinicRequest<Category>(`service-categories/${category.id}/status`, {
      method: "POST",
      body: { active },
      etag: etag(category.version),
    })
  ).data;
}
export async function exportCatalog(params: CatalogFilter) {
  return (
    await clinicRequest<Blob>(`${path}/export`, {
      params: { ...params },
      download: true,
    })
  ).data;
}
export const normalize = (s: string) =>
  s.trim().normalize("NFKC").toLocaleLowerCase().replace(/\s+/g, " ");
export function money(value: string | null) {
  if (value === null) return "—";
  const [whole = "0", fraction = ""] = value.split(".");
  const decimal = fraction.replace(/0+$/, "");
  return `${whole.replace(/\B(?=(\d{3})+(?!\d))/g, " ")}${decimal ? "." + decimal : ""} so‘m`;
}
export const blankCategory = (): CategoryDraft => ({
  name: "",
  description: "",
  color: "blue",
  active: true,
});
export function blankService(
  categories: Category[],
  branches: CatalogBranch[],
): ServiceDraft {
  return {
    code: "Avtomatik",
    name: "",
    categoryId: categories.find((c) => c.active)?.id ?? "",
    type: "consultation",
    branchIds: branches
      .filter((b) => b.active)
      .slice(0, 1)
      .map((b) => b.id),
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
    price: s.price ?? "",
    duration: s.duration == null ? "" : String(s.duration),
    turnaround: s.turnaround ?? "",
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
  branches: CatalogBranch[],
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
    d.branchIds.some(
      (id) =>
        !branches.some(
          (b) => b.id === id && (b.active || editing?.branchIds.includes(id)),
        ),
    )
  )
    errors.branchIds = "Kamida bitta filialni tanlang.";
  if (!/^\d{1,12}(\.\d{1,2})?$/.test(d.price.trim()))
    errors.price =
      "Narxni manfiy bo‘lmagan so‘mda, ko‘pi bilan 2 kasr bilan kiriting.";
  if (
    (d.type === "consultation" || d.timed) &&
    (!/^\d+$/.test(d.duration) ||
      Number(d.duration) < 1 ||
      Number(d.duration) > 480)
  )
    errors.duration = "Davomiylik 1–480 daqiqa bo‘lsin.";
  if (
    d.type === "laboratory" &&
    (!/^\d{1,12}(\.\d{1,2})?$/.test(d.turnaround) ||
      !Number.isFinite(Number(d.turnaround)) ||
      Number(d.turnaround) <= 0)
  )
    errors.turnaround = "Musbat natija muddatini kiriting.";
  if (d.type === "laboratory" && !["hour", "day"].includes(d.turnaroundUnit))
    errors.turnaroundUnit = "Soat yoki kunni tanlang.";
  return errors;
}
