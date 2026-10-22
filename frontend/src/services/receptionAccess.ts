import { can } from "./session";
/** Same existing action codes as booking/attendance APIs; reception has no duplicate grants. */
export const receptionActions = {
  book: {
    label: "Qabulga yozish",
    codes: ["appointments.view", "appointments.create", "patients.view"],
  },
  patient: {
    label: "Yangi bemor qo‘shish",
    codes: ["patients.view", "patients.create"],
  },
  checkIn: {
    label: "Yozilgan bemorni kelgan deb belgilash",
    codes: [
      "patients.view",
      "appointments.view",
      "appointments.check_in",
      "visits.view",
      "visits.create",
      "queues.view",
      "queues.create",
    ],
  },
  walkIn: {
    label: "Jonli navbatga qo‘shish",
    codes: [
      "patients.view",
      "visits.view",
      "visits.create",
      "queues.view",
      "queues.create",
    ],
  },
  detail: {
    label: "Qabul tafsilotlarini ko‘rish",
    codes: ["appointments.view"],
  },
} as const;
export function canReception(action: keyof typeof receptionActions) {
  return can("reception.view") && receptionActions[action].codes.every(can);
}
