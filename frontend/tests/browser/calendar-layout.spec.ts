import { test, expect } from "@playwright/test";
import { receptionSession } from "./reception.fixture";

const doctors = Array.from({ length: 5 }, (_, i) => ({
  id: "doctor-" + i, name: "Synthetic doctor " + i,
  specialty_id: "specialty", specialty_name: "Kardiolog",
  services: [{ id: "service", name: "Konsultatsiya", duration_minutes: 30 }],
}));
const rows = doctors.map((d, i) => ({
  id: "rule-" + i, doctor_id: d.id, room_id: "room-" + i,
  local_start: "09:00", local_end: "18:00", slot_minutes: 30,
  breaks: [{ local_start: "12:00", local_end: "13:00" }],
  weekday: 3, effective_from: "2026-01-01", effective_to: null, active: true,
}));
const statuses = ["scheduled", "checked_in", "in_progress", "completed"];
const appointments = statuses.map((status, i) => ({
  id: "appointment-" + i, doctor_id: doctors[i]!.id, room_id: "room-" + i,
  branch_id: "branch-a", service_id: "service", patient_id: "synthetic-" + (i + 1),
  starts_at: "2026-11-18T04:00:00Z", ends_at: "2026-11-18T04:30:00Z", status,
}));
const list = (data: unknown[]) => ({ data, meta: { total: data.length } });

for (const viewport of [{ width: 1440, height: 900 }, { width: 1648, height: 928 }, { width: 1920, height: 1080 }]) {
  test("calendar viewport, sticky header and status colors at " + viewport.width, async ({ page }, testInfo) => {
    await page.setViewportSize(viewport);
    await page.clock.setFixedTime(new Date("2026-11-17T02:00:00Z"));
    await receptionSession(page);
    await page.route("**/api/v1/clinic/appointments/booking-options?**", r => r.fulfill({ json: { ...list(doctors), timezone: "Asia/Tashkent" } }));
    await page.route("**/api/v1/clinic/appointments?**", r => r.fulfill({ json: list(appointments) }));
    await page.route("**/api/v1/clinic/schedules?**", r => r.fulfill({ json: list(rows) }));
    await page.route("**/api/v1/clinic/schedule-exceptions?**", r => r.fulfill({ json: list([]) }));
    await page.route("**/api/v1/clinic/rooms?**", r => r.fulfill({ json: list(rows.map((w, i) => ({ id: w.room_id, number: String(101+i), active: true, availability: "available" }))) }));
    await page.route("**/api/v1/clinic/doctors/*/availability?**", r => {
      const id = new URL(r.request().url()).pathname.split("/").at(-2);
      const room = rows.find(w => w.doctor_id === id)?.room_id;
      return r.fulfill({ json: list([{ doctor_id: id, branch_id: "branch-a", room_id: room, starts_at: "2026-11-18T05:00:00Z", ends_at: "2026-11-18T05:30:00Z" }]) });
    });
    await page.getByRole("button", { name: "Qabullar va navbatlar", exact: true }).click();
    await page.getByLabel("Sana", { exact: true }).fill("2026-11-18");
    const grid = page.locator(".calendar-scroll");
    await expect(grid).toBeVisible();
    await expect(grid.locator(".doctor-head")).toHaveCount(5);
    const size = await grid.evaluate(el => ({ height: el.clientHeight, width: el.clientWidth, scrollWidth: el.scrollWidth }));
    expect(size.height).toBeGreaterThanOrEqual(420);
    expect(size.scrollWidth).toBeGreaterThan(size.width);
    const head = grid.locator(".doctor-head").first();
    await expect(head).toHaveCSS("position", "sticky");
    await expect(grid.locator(".times")).toHaveCSS("position", "sticky");
    for (const [i, color] of ["rgb(227, 239, 255)", "rgb(255, 240, 220)", "rgb(238, 229, 255)", "rgb(223, 247, 234)"].entries()) {
      await expect(grid.locator(".event." + statuses[i])).toHaveCSS("background-color", color);
    }
    await grid.evaluate(el => { el.scrollTop = 0; el.scrollLeft = 0; });
    await page.screenshot({ path: testInfo.outputPath("calendar-" + viewport.width + ".png"), fullPage: true });
    await grid.locator(".event.free").first().click();
    await expect(page.getByRole("dialog", { name: "Qabulga yozish", exact: true })).toBeVisible();
  });
}
