import { clinicRequest } from "./api";

export interface VisitClinicalNote {
  id: string;
  visit_id: string;
  patient_id: string;
  complaints: string;
  anamnesis: string;
  examination: string;
  version: number;
  updated_at: string;
}

export interface VisitClinicalNoteBody {
  expected_version: number;
  complaints: string;
  anamnesis: string;
  examination: string;
}

export async function getVisitClinicalNote(visitId: string): Promise<VisitClinicalNote | null> {
  const response = await clinicRequest<{ data: VisitClinicalNote | null }>(
    "visits/" + encodeURIComponent(visitId) + "/clinical-notes",
  );
  return response.data.data;
}

export async function saveVisitClinicalNote(
  visitId: string,
  body: VisitClinicalNoteBody,
): Promise<VisitClinicalNote> {
  const response = await clinicRequest<{ data: VisitClinicalNote }>(
    "visits/" + encodeURIComponent(visitId) + "/clinical-notes",
    { method: "PUT", body },
  );
  return response.data.data;
}
