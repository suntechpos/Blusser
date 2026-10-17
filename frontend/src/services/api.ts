import axios from "axios";
import { contextGate, IntentKeys } from "./context";
export const api = axios.create({
  baseURL: "/",
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: "application/json" },
});
export interface AuthUser {
  id: string;
  login: string;
  is_platform_admin: boolean;
  must_change_password: boolean;
  active_tenant_id: string | null;
}
export async function loginUser(
  login: string,
  password: string,
): Promise<AuthUser> {
  await api.get("/sanctum/csrf-cookie");
  return (await api.post<AuthUser>("/api/v1/auth/login", { login, password }))
    .data;
}
export async function getCurrentUser(): Promise<AuthUser> {
  return (await api.get<AuthUser>("/api/v1/auth/me")).data;
}
export async function logoutUser() {
  await api.post("/api/v1/auth/logout");
}
export function errorText(e: unknown): string {
  if (!axios.isAxiosError(e))
    return e instanceof Error ? e.message : "Kutilmagan xatolik.";
  const status = e.response?.status;
  if (status === 422) {
    const labels: Record<string, string> = {
      name: "F.I.Sh.",
      phone: "Telefon",
      date_of_birth: "Tug‘ilgan sana",
      gender: "Jins",
      branch_id: "Filial",
      current_password: "Joriy parol",
      password: "Yangi parol",
      password_confirmation: "Parol tasdig‘i",
    };
    const fields = Object.keys(e.response?.data?.errors ?? {}).map(
      (field) => labels[field] ?? field,
    );
    return fields.length
      ? fields.join(", ") + ": kiritilgan qiymatni tekshiring."
      : "Maydonlarni tekshiring.";
  }
  return (
    (
      {
        401: "Sessiya tugadi. Qayta kiring.",
        419: "Sessiya yoki CSRF muddati tugadi. Qayta kiring.",
        403: "Bu amal uchun ruxsat yo‘q.",
        404: "Yozuv topilmadi yoki sizga ko‘rinmaydi.",
        409: "Ma’lumot o‘zgargan yoki vaqt band. Yangilang va qayta tekshiring.",
        429: "So‘rovlar ko‘p. Birozdan keyin urinib ko‘ring.",
      } as Record<number, string>
    )[status ?? 0] ?? "Server bilan bog‘lanib bo‘lmadi. Qayta urinib ko‘ring."
  );
}
const intents = new IntentKeys();
export function clearIntents() {
  intents.clear();
}
export async function clinicRequest<T>(
  path: string,
  options: {
    method?: string;
    body?: unknown;
    params?: Record<string, unknown>;
    etag?: string;
    download?: boolean;
  } = {},
) {
  const token = contextGate.capture();
  const method = options.method ?? "GET";
  const identity = JSON.stringify([method, path, options.body, options.etag]);
  const key = method === "GET" ? undefined : intents.get(identity);
  const response = await api.request<T>({
    url: "/api/v1/clinic/" + path,
    method,
    data: options.body,
    params: options.params,
    signal: token.signal,
    responseType: options.download ? "blob" : "json",
    headers: {
      ...(key ? { "Idempotency-Key": key } : {}),
      ...(options.etag ? { "If-Match": options.etag } : {}),
    },
  });
  if (!token.current()) throw new axios.CanceledError("Context changed");
  if (key) intents.complete(identity);
  return response;
}
export function downloadBlob(blob: Blob, name: string) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = name;
  a.click();
  URL.revokeObjectURL(url);
}
