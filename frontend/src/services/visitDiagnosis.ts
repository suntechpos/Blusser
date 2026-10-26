import { clinicRequest } from "./api";

export interface VisitDiagnosis {
  id: string;
  number: string;
  visit_id: string;
  patient_id: string;
  diagnosis_text: string;
  diagnosis_type: "preliminary" | "final";
  icd_system: "ICD-10" | null;
  icd_code: string | null;
  version: number;
  updated_at: string;
}

export async function getVisitDiagnosis(visitId: string): Promise<VisitDiagnosis | null> {
  const response = await clinicRequest<{ data: VisitDiagnosis | null }>(
    "visits/" + encodeURIComponent(visitId) + "/diagnosis",
  );
  return response.data.data;
}

export async function saveVisitDiagnosis(
  visitId: string,
  body: {
    expected_version: number;
    diagnosis_text: string;
    diagnosis_type: "preliminary" | "final";
    icd_code: string | null;
  },
): Promise<VisitDiagnosis> {
  const response = await clinicRequest<{ data: VisitDiagnosis }>(
    "visits/" + encodeURIComponent(visitId) + "/diagnosis",
    { method: "PUT", body },
  );
  return response.data.data;
}
