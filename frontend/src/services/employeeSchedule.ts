import { clinicRequest } from "./api";
export interface DoctorRecord {
  id: string;
  membership_id: string;
  specialty_id: string;
  branch_ids: string[];
  service_ids: string[];
  active: boolean;
}
export interface Rule {
  id?: string;
  doctor_id: string;
  branch_id: string;
  room_id: string;
  room_usage?: "shared" | "private";
  weekday: number;
  local_start: string;
  local_end: string;
  slot_minutes: number;
  effective_from: string;
  effective_to: string | null;
  breaks: { local_start: string; local_end: string }[];
}
export interface Option {
  id: string;
  name?: string;
  number?: string;
  branch_id?: string;
  active?: boolean;
  availability?: string;
  usage_mode?: "shared" | "private";
  exclusive_doctor_id?: string | null;
}
export async function scheduleList<T>(
  path: string,
  params: Record<string, unknown> = {},
) {
  const data: T[] = [];
  for (let page = 1; ; page++) {
    const r = await clinicRequest<{ data: T[]; meta: { total: number } }>(
      path,
      { params: { ...params, page, per_page: 100 } },
    );
    data.push(...r.data.data);
    if (data.length >= r.data.meta.total || !r.data.data.length) return data;
  }
}
export async function employeeDoctor(membership: string) {
  return (await scheduleList<DoctorRecord>("doctors", { status: "all" })).find(
    (d) => d.membership_id === membership,
  );
}
/** Sequential API writes are not atomic. Completed rows retain their real IDs for retries. */
export async function saveRules(
  doctorId: string,
  rules: Rule[],
  completed: Map<string, Rule>,
  onSaved: (rule: Rule) => void,
) {
  for (const item of rules) {
    const { id, ...body } = { ...item, doctor_id: doctorId };
    const signature = JSON.stringify(body);
    if (completed.has(signature)) continue;
    const r = await clinicRequest<Rule>(id ? `schedules/${id}` : "schedules", {
      method: id ? "PUT" : "POST",
      body,
    });
    // A later edit replaces that row's checkpoint, so reverting a value still writes it.
    for (const [key, saved] of completed)
      if (saved.id === r.data.id) completed.delete(key);
    completed.set(signature, r.data);
    onSaved(r.data);
  }
}
