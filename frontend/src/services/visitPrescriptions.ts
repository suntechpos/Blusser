import { clinicRequest } from "./api";
import type { Medicine } from "../mocks/doctorWorkspace";
export interface VisitPrescription {
  id: string; visit_id: string; medicines: Medicine[]; note: string;
  version: number; created_at: string;
}
const base = (id: string) => "visits/" + encodeURIComponent(id) + "/prescriptions";
export async function listPrescriptions(visitId: string): Promise<VisitPrescription[]> {
  return (await clinicRequest<{data:VisitPrescription[]}>(base(visitId))).data.data;
}
export async function savePrescription(visitId: string, medicines: Medicine[], note: string, existing?: VisitPrescription): Promise<VisitPrescription> {
  const path = base(visitId) + (existing ? "/" + encodeURIComponent(existing.id) : "");
  const result = await clinicRequest<{data:VisitPrescription}>(path,{
    method: existing ? "PUT" : "POST",
    body: { expected_version: existing?.version ?? 0, medicines, note },
  });
  return result.data.data;
}
