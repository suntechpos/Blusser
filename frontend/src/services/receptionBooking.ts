import axios from "axios";
import { errorText } from "./api";
import { clinicRequest } from "./api";
import type { Patient } from "./operations";
import type { ReceptionPatient } from "../mocks/reception";
export interface BookingService {
  id: string;
  name: string;
  duration_minutes: number | null;
}
export interface BookingDoctor {
  id: string;
  name: string;
  specialty_id: string;
  specialty_name: string;
  services: BookingService[];
}
export interface BookingSlot {
  doctor_id: string;
  branch_id: string;
  room_id: string;
  starts_at: string;
  ends_at: string;
}
export interface BookingAppointment extends BookingSlot {
  id: string;
  patient_id: string;
  service_id: string;
  status: string;
  note?: string | null;
}
export interface BookingPreset {
  branchId?: string;
  doctorId?: string;
  departmentId?: string;
  serviceId?: string;
  date?: string;
  slot?: BookingSlot;
}
export type BookingPatient = ReceptionPatient & {
  patientNumber: string;
  branchIds: string[];
};
export interface PatientResults {
  data: ReceptionPatient[];
  total: number;
  perPage: number;
}
export function patientChoice(p: Patient): BookingPatient {
  return {
    id: p.id,
    patientNumber: p.patient_number,
    name: p.name,
    phone: p.phone ?? "",
    dob: p.date_of_birth,
    gender: p.gender ?? "",
    firstName: "",
    lastName: "",
    middleName: "",
    branchIds: p.branch_ids,
  };
}
export async function findBookingPatients(
  q: string,
  page = 1,
): Promise<PatientResults> {
  const r = await clinicRequest<{ data: Patient[]; meta: { total: number } }>(
    "patients",
    { params: { q, page, per_page: 8, status: "active" } },
  );
  return {
    data: r.data.data.map(patientChoice),
    total: r.data.meta.total,
    perPage: 8,
  };
}
export { localDay, localTime, nextDay, dayRange } from "./receptionTime";
import { dayRange } from "./receptionTime";
export async function bookingCatalog(branchId: string) {
  const doctors: BookingDoctor[] = [];
  let timezone = "";
  for (let page = 1; ; page++) {
    const r = await clinicRequest<{
      data: BookingDoctor[];
      timezone: string;
      meta: { total: number };
    }>("appointments/booking-options", {
      params: { branch_id: branchId, page, per_page: 100 },
    });
    doctors.push(...r.data.data);
    timezone = r.data.timezone;
    if (doctors.length >= r.data.meta.total || !r.data.data.length) break;
  }
  return { doctors, timezone };
}
export async function bookingAvailability(
  doctorId: string,
  branchId: string,
  serviceId: string,
  date: string,
  zone: string,
) {
  const slots: BookingSlot[] = [];
  let unavailableReason: string | null | undefined;
  for (let page = 1; ; page++) {
    const r = await clinicRequest<{
      data: BookingSlot[];
      meta: { total: number; unavailable_reason?: string | null };
    }>(`doctors/${doctorId}/availability`, {
      params: {
        branch_id: branchId,
        service_id: serviceId,
        ...dayRange(date, zone),
        page,
        per_page: 100,
      },
    });
    unavailableReason = r.data.meta.unavailable_reason;
    slots.push(...r.data.data);
    if (slots.length >= r.data.meta.total || !r.data.data.length) break;
  }
  return Object.assign(
    slots.filter((s) => Date.parse(s.starts_at) > Date.now()),
    { unavailableReason },
  );
}

/** Preserve actionable HTTP failures; empty results are exclusively successful lists. */
export function bookingError(error: unknown): string {
  if (axios.isAxiosError(error)) {
    if (error.response && error.response.status >= 500)
      return `Server xatosi (${error.response.status}). Qayta urinib ko‘ring.`;
    if (!error.response) return "Tarmoq xatosi. Serverga ulanishni tekshiring.";
    return `${errorText(error)} (${error.response.status})`;
  }
  return errorText(error);
}
