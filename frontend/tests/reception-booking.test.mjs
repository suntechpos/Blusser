import { test } from "node:test";
import assert from "node:assert/strict";
import {
  dayRange,
  localDay,
  localTime,
  nextDay,
} from "../src/services/receptionTime.ts";
test("future branch day stays October 28 regardless of browser timezone", () => {
  assert.deepEqual(dayRange("2026-10-28", "Asia/Tashkent"), {
    from: "2026-10-27T19:00:00.000Z",
    to: "2026-10-28T19:00:00.000Z",
  });
  assert.equal(localTime("2026-10-28T05:30:00Z", "Asia/Tashkent"), "10:30");
  assert.equal(
    localDay("2026-10-28T05:30:00Z", "America/Los_Angeles"),
    "2026-10-27",
  );
  assert.equal(nextDay("2026-12-31"), "2027-01-01");
});
test("local day boundaries honor DST rather than assuming 24 hours", () => {
  for (const [day, hours] of [
    ["2026-03-29", 23],
    ["2026-10-25", 25],
  ]) {
    const r = dayRange(day, "Europe/Berlin");
    assert.equal((Date.parse(r.to) - Date.parse(r.from)) / 3600000, hours);
    assert.equal(localDay(r.from, "Europe/Berlin"), day);
  }
  assert.throws(() => dayRange("2011-12-30", "Pacific/Apia"), /mavjud emas/);
});
