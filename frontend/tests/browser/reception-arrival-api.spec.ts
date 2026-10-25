import { test, expect, type Page } from "@playwright/test";
import { bookingSession, ids } from "./reception-booking.fixture";

const dialog = (page: Page) =>
  page.getByRole("dialog", { name: "Bemorni ro‘yxatga olish" });

async function open(page: Page) {
  await page.getByRole("button", { name: "Bemorni ro‘yxatga olish" }).click();
  await expect(dialog(page)).toBeVisible();
}

test.beforeEach(async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-23T03:00:00Z"));
});

test("existing patient joins real API-shaped walk-in queue once; ticket persists across refresh", async ({ page }) => {
  const fixture = await bookingSession(page);
  const tickets: Array<Record<string, unknown>> = [];
  await page.route("**/api/v1/clinic/queue-tickets**", async (route) => {
    const req = route.request();
    if (req.method() === "POST") {
      const body = req.postDataJSON();
      fixture.requests.push({
        method: "POST",
        path: new URL(req.url()).pathname,
        body,
        headers: req.headers(),
      });
      const ticket = {
        id: "80000000-0000-4000-8000-000000000001",
        visit_id: "90000000-0000-4000-8000-000000000001",
        patient_id: body.patient_id,
        doctor_id: body.doctor_id,
        branch_id: body.branch_id,
        number: 7, version: 1, kind: "walk_in", status: "waiting", local_date: "2026-10-23",
      };
      tickets.push(ticket);
      fixture.state.tickets.push({ ...ticket, appointment_id: null });
      return route.fulfill({ status: 201, json: { visit: { id: ticket.visit_id, status: "queued" }, ticket } });
    }
    const params = new URL(req.url()).searchParams;
    const active = tickets.filter((t) => !params.get("status") || t.status === params.get("status"));
    return route.fulfill({ json: { data: active, meta: { page: 1, per_page: 8, total: active.length } } });
  });
  await open(page);
  const form = dialog(page);
  await form.getByRole("button", { name: /Aliyeva Mohira/ }).click();
  await form.getByLabel("Jonli navbat shifokori").selectOption(ids.doctor);
  await form.getByLabel("Jonli navbat xizmati").selectOption(ids.service);
  await form.getByRole("button", { name: "Jonli navbatga qo‘shish" }).click();
  await expect(form).toContainText("Navbat raqami: 7");
  await expect(form.getByRole("button", { name: "Jonli navbatga qo‘shish" })).toHaveCount(0);
  expect(fixture.requests.filter((r) => r.method === "POST" && r.path.endsWith("/queue-tickets"))).toHaveLength(1);
  expect(fixture.requests.find((r) => r.method === "POST" && r.path.endsWith("/queue-tickets"))?.headers["idempotency-key"]).toMatch(/^[0-9a-f-]{36}$/);
  expect(fixture.requests.filter((r) => r.method === "POST" && r.path.endsWith("/visits"))).toHaveLength(0);
  await form.getByRole("button", { name: "Yopish" }).click();
  await expect(page.locator(".rp-tabs")).toContainText("Barcha bemorlar");
  await expect(page.locator(".rp-list tbody")).toContainText("Aliyeva Mohira");
  await expect(page.locator(".rp-list tbody")).toContainText("№7");
  await page.getByRole("button", { name: "Jonli navbat", exact: false }).first().click();
  await expect(page.locator(".rp-table-scroll")).toContainText("№7");
  await page.reload();
  await expect(page.locator(".rp-list tbody")).toContainText("№7");
  await expect(page.locator(".rp-stats")).toContainText("Jonli navbat");
  await page.getByRole("button", { name: "Jonli navbat", exact: false }).first().click();
  await expect(page.locator(".rp-table-scroll")).toContainText("№7");
});

test("new patient-only registration calls only patients API; never creates attendance", async ({ page }) => {
  const fixture = await bookingSession(page);
  await open(page);
  const form = dialog(page);
  await form.getByRole("button", { name: "Faqat ro‘yxatga olish" }).click();
  await form.getByRole("button", { name: "+ Yangi bemor qo‘shish" }).click();
  await form.getByLabel("Familiya *").fill("Rahimov");
  await form.getByLabel("Ism *").fill("Sanjar");
  await form.getByLabel("Tug‘ilgan sana *").fill("1995-05-15");
  await form.getByRole("button", { name: "Bemorni saqlash" }).click();
  await expect(form).toContainText("Bemor bazaga muvaffaqiyatli saqlandi");
  const writes = fixture.requests.filter((r) => r.method === "POST");
  expect(writes.filter((r) => r.path.endsWith("/patients"))).toHaveLength(1);
  expect(writes.filter((r) => /\/(appointments|visits|queue-tickets)$/.test(r.path))).toHaveLength(0);
});

test("scheduled patient check-in uses appointment version, shows server ticket and forbids duplicate click", async ({ page }) => {
  const fixture = await bookingSession(page);
  const appointment = {
    id: "70000000-0000-4000-8000-000000000011",
    patient_id: ids.patient, doctor_id: ids.doctor, branch_id: ids.branch,
    room_id: ids.room, service_id: ids.service,
    starts_at: "2026-10-23T04:00:00Z", ends_at: "2026-10-23T04:30:00Z",
    version: 3, status: "scheduled",
  };
  fixture.state.rows.push(appointment);
  await page.route("**/api/v1/clinic/patients/*/appointments**", async (route) => {
    await route.fulfill({ json: { data: [appointment], meta: { page: 1, per_page: 100, total: 1 } } });
  });
  let calls = 0;
  await page.route("**/api/v1/clinic/appointments/*/check-in", async (route) => {
    calls++;
    expect(route.request().postDataJSON()).toEqual({ expected_version: 3 });
    expect(route.request().headers()["idempotency-key"]).toMatch(/^[0-9a-f-]{36}$/);
    appointment.status = "checked_in";
    return route.fulfill({
      json: {
        appointment: appointment,
        visit: { id: "90000000-0000-4000-8000-000000000002", status: "queued" },
        ticket: { id: "80000000-0000-4000-8000-000000000002", number: 2, status: "waiting", kind: "scheduled" },
      },
    });
  });
  await page.route("**/api/v1/clinic/queue-tickets**", async (route) => {
    await route.fulfill({ json: { data: [], meta: { total: 0, page: 1, per_page: 8 } } });
  });
  await open(page);
  const form = dialog(page);
  await form.getByRole("button", { name: "Oldindan yozilgan" }).click();
  await form.getByRole("button", { name: /Aliyeva Mohira/ }).click();
  await expect(form.getByText("Bugungi yozilgan qabullar")).toBeVisible();
  await form.locator('input[name="arrival-appointment"]').check();
  await form.getByRole("button", { name: "Keldi — navbatga qo‘shish" }).click();
  await expect(form).toContainText("Navbat raqami: 2");
  expect(calls).toBe(1);
});
