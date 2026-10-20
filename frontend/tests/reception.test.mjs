import { test } from "node:test";
import assert from "node:assert/strict";
import {
  createReceptionState,
  blankPatient,
  blankBooking,
  validatePatient,
  validateBooking,
  availability,
  localDate,
  addDays,
  searchPatients,
  checkIn,
  nextPatient,
} from "../src/mocks/reception.ts";
const future = () => {
  let date = addDays(localDate(), 16);
  while (new Date(`${date}T12:00:00`).getDay() === 0) date = addDays(date, 1);
  return date;
};
test("Patient required fields follow current model; optional phone/gender, invalid/future dates rejected", () => {
  const draft = {
    ...blankPatient(),
    lastName: "Test",
    firstName: "Synthetic",
    dob: "2000-02-29",
  };
  assert.deepEqual(validatePatient(draft), {});
  assert.ok(validatePatient({ ...draft, dob: "2001-02-29" }).dob);
  assert.ok(validatePatient({ ...draft, dob: addDays(localDate(), 1) }).dob);
  assert.ok(validatePatient({ ...draft, lastName: "" }).lastName);
  assert.ok(validatePatient({ ...draft, phone: "invalid" }).phone);
});
test("Duration overlaps across branches; missing schedule differs from fully booked", () => {
  const date = future(),
    draft = { ...blankBooking(), date, serviceId: "extended", time: "09:00" };
  const appointment = {
    ...draft,
    id: "A-test",
    patientId: "P-test",
    serviceId: "initial",
    time: "09:30",
    status: "booked",
    kind: "scheduled",
  };
  assert.ok(
    !availability(draft, [appointment]).slots.some(
      (s) => s.time === "09:00" && !s.busy,
    ),
  );
  assert.ok(validateBooking(draft, [appointment]).time);
  assert.ok(
    validateBooking({ ...draft, branchId: "yunus" }, [appointment]).time,
  );
  assert.ok(
    !availability(
      {
        ...draft,
        doctorId: "jabborov",
        departmentId: "neuro",
        branchId: "yunus",
      },
      [],
    ).scheduled,
  );
  let sunday = addDays(date, 1);
  while (new Date(`${sunday}T12:00:00`).getDay() !== 0)
    sunday = addDays(sunday, 1);
  assert.equal(availability({ ...draft, date: sunday }, []).scheduled, false);
  const normal = { ...draft, serviceId: "initial" };
  const all = availability(normal, []).slots.map((s, i) => ({
    ...appointment,
    id: `A-${i}`,
    time: s.time,
    date,
    serviceId: "initial",
  }));
  const filled = availability(normal, all);
  assert.equal(filled.scheduled, true);
  assert.ok(filled.slots.length > 0 && filled.slots.every((s) => s.busy));
  assert.ok(validateBooking({ ...normal, date: "2026-02-30" }, []).date);
});
test("Check-in accepts only today booked, once, without altering other encounters", () => {
  const { appointments } = createReceptionState();
  const appointment = appointments.find((a) => a.status === "booked");
  const other = structuredClone(appointments[0]);
  assert.equal(checkIn(appointment), true);
  assert.equal(appointment.status, "waiting");
  assert.equal(checkIn(appointment), false);
  assert.deepEqual(appointments[0], other);
  assert.equal(
    checkIn({
      ...appointment,
      status: "booked",
      date: addDays(localDate(), 1),
    }),
    false,
  );
  assert.equal(checkIn({ ...appointment, status: "completed" }), false);
});
test("Phone matching never auto-selects/creates; local patient identifiers stay unique", () => {
  const { patients } = createReceptionState();
  const initial = patients.length;
  const matches = searchPatients(patients, "901234567");
  assert.equal(matches.length, 1);
  assert.equal(patients.length, initial);
  const draft = {
    ...blankPatient(),
    lastName: "Another",
    firstName: "Person",
    dob: "2000-01-01",
    phone: matches[0].phone,
  };
  const first = nextPatient(patients, draft);
  patients.push(first);
  const second = nextPatient(patients, draft);
  assert.notEqual(first.id, second.id);
  assert.equal(searchPatients(patients, draft.phone).length, 2);
  assert.equal(searchPatients(patients, first.id)[0].id, first.id);
});
