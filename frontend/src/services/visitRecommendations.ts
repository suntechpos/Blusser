import { clinicRequest } from "./api";

export interface VisitRecommendation {
  id: string;
  visit_id: string;
  patient_id: string;
  recommendation_text: string;
  version: number;
  updated_at: string;
}

export async function getVisitRecommendation(visitId: string): Promise<VisitRecommendation | null> {
  const response = await clinicRequest<{ data: VisitRecommendation | null }>(
    "visits/" + encodeURIComponent(visitId) + "/recommendations",
  );
  return response.data.data;
}

export async function saveVisitRecommendation(visitId: string, expected_version: number, recommendation_text: string): Promise<VisitRecommendation> {
  const response = await clinicRequest<{ data: VisitRecommendation }>(
    "visits/" + encodeURIComponent(visitId) + "/recommendations",
    { method: "PUT", body: { expected_version, recommendation_text } },
  );
  return response.data.data;
}
