import { test } from "node:test";
import assert from "node:assert/strict";
import {
  blankSchedule,
  blankShift,
  scheduleError,
  shiftError,
  exceptionError,
  elapsed,
  createEmployees,
} from "../node_modules/.tests/employees.js";
test("synthetic statistics and access independent from active state", () => {
  const es = createEmployees();
  assert.equal(es.length, 24);
  assert.equal(es.filter((e) => e.active).length, 22);
  assert(es.filter((e) => !e.active).every((e) => !e.access));
  assert(es.some((e) => e.active && !e.access));
  assert(es.some((e) => e.position === "Tez yordam haydovchisi"));
  assert(es.every((e) => !("password" in e)));
});
test("overnight requires explicit next-day and break stays inside shift", () => {
  const s = { ...blankShift(), start: "22:00", end: "06:00" };
  assert.match(shiftError(s, true), /keyingi/);
  s.nextDay = true;
  assert.equal(shiftError(s, true), "");
  s.breakStart = "02:00";
  s.breakEnd = "02:30";
  assert.equal(shiftError(s, true), "");
  s.breakEnd = "07:00";
  assert.match(shiftError(s, true), /Tanaffus/);
});
test("overlap rejected across branches and week boundary; touching allowed", () => {
  const s = blankSchedule();
  s.days.forEach((d) => (d.enabled = false));
  s.days[6] = {
    enabled: true,
    shifts: [{ ...blankShift(), start: "22:00", end: "10:00", nextDay: true }],
  };
  s.days[0] = {
    enabled: true,
    shifts: [{ ...blankShift("Yunusobod"), start: "09:00", end: "12:00" }],
  };
  assert.match(scheduleError(s, true), /to‘qnashmoqda/);
  s.days[0].shifts[0].start = "10:00";
  assert.equal(scheduleError(s, true), "");
  s.days[0].shifts.push({ ...blankShift(), start: "11:00", end: "13:00" });
  assert.match(scheduleError(s, true), /to‘qnashmoqda/);
});
test("room must belong to branch, optional for non-doctor", () => {
  const s = blankShift();
  s.room = "105-kabinet";
  assert.match(shiftError(s, true), /kabinet/);
  assert.equal(shiftError(s, false), "");
});
test("exception date order and changed shift validation", () => {
  const e = {
    id: "demo",
    from: "2026-11-10",
    to: "2026-11-05",
    kind: "off",
    reason: "Ta’til",
    note: "",
    shift: blankShift(),
  };
  assert.match(exceptionError(e, true), /Sanalarni/);
  e.to = e.from;
  assert.equal(exceptionError(e, true), "");
  e.kind = "changed";
  e.shift.end = "08:00";
  assert.match(exceptionError(e, true), /vaqti/);
});
test("elapsed age and tenure respect day and month boundaries", () => {
  assert.equal(elapsed("1988-03-12", "2026-10-18"), "38 yil 7 oy");
  assert.equal(elapsed("2024-07-15", "2026-10-14"), "2 yil 2 oy");
  assert.equal(elapsed("2027-01-01", "2026-10-18"), "—");
  assert.equal(elapsed(""), "—");
});
