import { clinicRequest } from "./api";
export interface ReferralService { id: string; name: string; type: "laboratory" | "diagnostic" | "consultation"; branch_ids: string[] }
export interface ReferralBranch { id: string; name: string }
export interface ReferralPayload {
  location: "internal" | "external";
  referral_type: ReferralService["type"];
  service_id: string;
  target_branch_id: string | null;
  external_clinic: string;
  note: string;
}
export interface VisitReferral extends ReferralPayload {
  id: string; visit_id: string; service_name: string; target_branch_name: string | null;
  status: string; created_at: string;
}
const base = (visitId: string) => "visits/" + encodeURIComponent(visitId);
export async function referralOptions(visitId: string) {
  return (await clinicRequest<{services:ReferralService[];branches:ReferralBranch[]}>(base(visitId)+"/referral-options")).data;
}
export async function visitReferrals(visitId: string) {
  return (await clinicRequest<{data:VisitReferral[]}>(base(visitId)+"/referrals")).data.data;
}
export async function createVisitReferral(visitId: string, payload: ReferralPayload) {
  return (await clinicRequest<{data:{id:string;visit_id:string}}>(base(visitId)+"/referrals", {method:"POST",body:payload})).data.data;
}
