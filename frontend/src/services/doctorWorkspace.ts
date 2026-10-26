import { clinicRequest } from "./api";

export interface WorkspaceDoctor {
  id: string;
  name: string;
  specialty: string;
  room?: string;
  branches?: WorkspaceBranch[];
}
export interface WorkspaceBranch {
  id: string;
  name: string;
  timezone: string;
}
export interface WorkspacePatientRow {
  id: string;
  patient_id: string;
  patient_number: string;
  name: string;
  dob: string;
  gender: string | null;
  phone: string | null;
  service: string;
  status: string;
  appointment_id: string | null;
  starts_at?: string;
  visit_id?: string;
  number?: number;
  kind?: "scheduled" | "walk_in";
  created_at?: string;
}
export interface DoctorWorkspaceFeed {
  manager: boolean;
  doctors: WorkspaceDoctor[];
  doctor: WorkspaceDoctor | null;
  branch: WorkspaceBranch | null;
  date: string | null;
  scheduled: WorkspacePatientRow[];
  tickets: WorkspacePatientRow[];
}

/** All access control is enforced by the server using the signed-in identity. */
export async function getDoctorWorkspace(params: {
  date?: string;
  doctor_id?: string;
  branch_id?: string;
} = {}): Promise<DoctorWorkspaceFeed> {
  const result = await clinicRequest<DoctorWorkspaceFeed>("doctor-workspace", {
    params,
  });
  return result.data;
}
