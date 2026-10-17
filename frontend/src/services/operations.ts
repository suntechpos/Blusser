export interface Patient {
  id: string;
  patient_number: string;
  name: string;
  phone: string | null;
  date_of_birth: string;
  gender: string | null;
  archived_at: string | null;
  branch_ids: string[];
}
