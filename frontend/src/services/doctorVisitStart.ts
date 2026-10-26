import axios from "axios";
import { clinicRequest } from "./api";
import type { WorkspacePatientRow } from "./doctorWorkspace";
import { session } from "./session";

export interface DoctorStartSlot {
  room_id: string;
  starts_at: string;
  ends_at: string;
}
interface AppointmentProjection extends DoctorStartSlot {
  id: string;
  patient_id: string;
  doctor_id: string;
  branch_id: string;
  service_id: string;
  status: string;
}
interface AvailableSlot extends DoctorStartSlot {
  doctor_id: string;
  branch_id: string;
}

/** Date in the clinic's time zone, not the user's browser time zone. */
function localDay(instant: string, zone: string): string {
  const parts = new Intl.DateTimeFormat("en-US", {
    timeZone: zone, year: "numeric", month: "2-digit", day: "2-digit",
  }).formatToParts(new Date(instant));
  const part = (type: string) => parts.find((p) => p.type === type)?.value || "";
  return part("year") + "-" + part("month") + "-" + part("day");
}

function utc(time: number): string {
  return new Date(time).toISOString().replace(/\.\d{3}Z$/, "Z");
}

/**
 * The start command requires a real appointment reservation or a genuine free
 * schedule slot. Never synthesize a room UUID or guessed duration in the UI.
 * The existing start API authorizes and validates the slot again atomically.
 */
export async function resolveDoctorStartSlot(
  ticket: WorkspacePatientRow,
  doctorId: string,
  branchId: string,
  date: string,
  zone: string,
): Promise<DoctorStartSlot> {
  if (ticket.status !== "called" || !ticket.visit_id) {
    throw new Error("Faqat chaqirilgan bemor qabulini boshlash mumkin.");
  }
  // Own doctor gets only the appointment/slot already authorized for this
  // called ticket. This does not reveal clinic-wide services or schedules.
  if (session.grants?.["queues.start"] === "own") {
    let slot: DoctorStartSlot;
    try {
      slot = (await clinicRequest<DoctorStartSlot>(
        "queue-tickets/" + encodeURIComponent(ticket.id) + "/own-start-slot",
      )).data;
    } catch (cause) {
      if (axios.isAxiosError(cause) && cause.response?.status === 409) {
        throw new Error(
          "Bugungi ish jadvalida bu bemor uchun bo‘sh xona yoki vaqt yo‘q. " +
          "Qabul jadvali va xona bandligini tekshiring.",
        );
      }
      throw cause;
    }
    if (!slot.room_id || !slot.starts_at || !slot.ends_at ||
        Date.parse(slot.ends_at) <= Date.now() ||
        localDay(slot.starts_at, zone) !== date) {
      throw new Error("Bugungi qabul uchun bo‘sh xona va vaqt topilmadi.");
    }
    return slot;
  }
  if (ticket.appointment_id) {
    const appointment = (await clinicRequest<AppointmentProjection>(
      "appointments/" + encodeURIComponent(ticket.appointment_id),
    )).data;
    if (appointment.id !== ticket.appointment_id ||
      appointment.patient_id !== ticket.patient_id ||
      appointment.doctor_id !== doctorId ||
      appointment.branch_id !== branchId ||
      !appointment.room_id || !appointment.starts_at || !appointment.ends_at) {
      throw new Error("Qabul bronining xona yoki vaqti bemorga mos kelmayapti.");
    }
    if (Date.parse(appointment.ends_at) <= Date.now()) {
      throw new Error("Bron vaqti tugagan. Registraturada qabul vaqtini tekshiring.");
    }
    return {
      room_id: appointment.room_id,
      starts_at: appointment.starts_at,
      ends_at: appointment.ends_at,
    };
  }

  if (!ticket.service_id) {
    throw new Error("Bemor xizmati topilmadi. Tashrifni va ruxsatlarni yangilang.");
  }
  const from = Date.now() + 2000;
  const to = from + 36 * 60 * 60 * 1000;
  const response = await clinicRequest<{
    data: AvailableSlot[];
    meta?: { unavailable_reason?: string | null };
  }>("doctors/" + encodeURIComponent(doctorId) + "/availability", {
    params: {
      branch_id: branchId,
      service_id: ticket.service_id,
      from: utc(from),
      to: utc(to),
      per_page: 100,
    },
  });

  const matching = response.data.data.find((slot) =>
    slot.doctor_id === doctorId &&
    slot.branch_id === branchId &&
    !!slot.room_id &&
    Date.parse(slot.starts_at) >= from &&
    Date.parse(slot.ends_at) > Date.now() &&
    localDay(slot.starts_at, zone) === date,
  );
  if (!matching) {
    throw new Error(
      "Bugun ushbu xizmat uchun bo‘sh xona va jadval vaqti topilmadi. " +
      "Jadval yoki xona sozlamalarini tekshiring.",
    );
  }
  return {
    room_id: matching.room_id,
    starts_at: matching.starts_at,
    ends_at: matching.ends_at,
  };
}
