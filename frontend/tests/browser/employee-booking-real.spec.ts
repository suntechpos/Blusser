import { test, expect } from "@playwright/test";
test.use({ viewport: { width: 1536, height: 1024 } });
test("employee management creates real doctor links and schedule then books a persisted appointment", async ({
  page,
}) => {
  await page.clock.setFixedTime(new Date("2030-01-08T21:30:00Z"));
  await page.goto("/");
  await page.getByLabel("Login", { exact: true }).fill("booking.doctor");
  await page
    .getByLabel("Parol", { exact: true })
    .fill("Synthetic-password-42!");
  await page.getByRole("button", { name: "Kirish", exact: true }).click();
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page
    .getByRole("row")
    .filter({ hasText: "configurable clinician" })
    .click();
  // Catalog creation is test setup through existing real endpoints; the removed modal is not restored.
  await page.evaluate(async () => {
    const path = "/src/services/api.ts";
    const { clinicRequest } = await import(path);
    await clinicRequest("specialties", {
      method: "POST",
      body: {
        code: "configured-specialty",
        name: "Configured specialty",
        active: true,
      },
    });
    await clinicRequest("appointments/configuration-services", {
      method: "POST",
      body: {
        code: "configured-consultation",
        name: "Configured consultation",
        active: true,
      },
    });
    const sessionPath = "/src/services/session.ts";
    const { session } = await import(sessionPath);
    await clinicRequest("rooms", {
      method: "POST",
      body: {
        branch_id: session.branch || session.branches[0]?.id,
        number: "Configured R2",
        service_type: "consultation",
        active: true,
        availability: "available",
      },
    });
  });
  await page.getByRole("button", { name: "Tahrirlash", exact: true }).click();
  await page.getByLabel("Telefon *", { exact: true }).fill("+998 90 765 43 30");
  await page.getByLabel("Lavozim *", { exact: true }).selectOption("Shifokor");
  await page
    .getByPlaceholder("Mutaxassislikni kiriting", { exact: true })
    .fill("Configured specialty");
  await page.getByLabel("Kassa PIN-kodi", { exact: true }).fill("7462");
  await page.getByLabel("PIN-kodni tasdiqlash", { exact: true }).fill("7462");
  const c = page.getByRole("region", { name: "Ish jadvali", exact: true });
  await c.getByLabel("Ish jadvalini saqlash", { exact: true }).check();
  await c.getByLabel("Bemor qabul qiladi", { exact: true }).check();
  await expect(
    c
      .getByLabel("Qabul xonasi", { exact: true })
      .locator("option")
      .filter({ hasText: "Configured R2" }),
  ).toHaveCount(1);
  await c.getByLabel("Ish jadvalini saqlash", { exact: true }).check();
  await c
    .getByLabel("Qabul xonasi", { exact: true })
    .selectOption({ label: "Configured R2" });
  await c
    .getByLabel("Shifokor xizmati: Configured consultation", { exact: true })
    .check();
  await c
    .getByRole("button", { name: "Yana bir kun qo‘shish", exact: true })
    .click();
  await c.getByLabel("Dushanba hafta kuni").selectOption("3");
  await c
    .getByRole("button", { name: "Jadvalni saqlash", exact: true })
    .click();
  await expect(page.getByLabel("Xodimlarni qidirish")).toBeVisible();
  await page
    .getByRole("row")
    .filter({ hasText: "configurable clinician" })
    .click();
  await page.getByRole("tab", { name: "Ish jadvali", exact: true }).click();
  await expect(
    page.getByRole("table", { name: "Haftalik ish jadvali", exact: true }),
  ).toContainText("Configured R2");
  await page.getByRole("button", { name: "Registratura", exact: true }).click();
  await page
    .locator(".rp-heading-actions")
    .getByRole("button", { name: "Qabulga yozish", exact: true })
    .click();
  const d = page.getByRole("dialog", { name: "Qabulga yozish", exact: true });
  await expect(
    d.getByRole("button", { name: "Qabulni sozlash", exact: true }),
  ).toHaveCount(0);
  await d
    .getByRole("button", { name: "Oldin kelgan bemor", exact: true })
    .click();
  await d
    .getByLabel("Bemorni qidirish", { exact: true })
    .fill("Booking test patient");
  await d.getByRole("button", { name: /Booking test patient/ }).click();
  await d
    .getByLabel("Bo‘lim *", { exact: true })
    .selectOption({ label: "Configured specialty" });
  await d
    .getByLabel("Shifokor *", { exact: true })
    .selectOption({ label: "Booking configurable clinician" });
  await d
    .getByLabel("Qabul turi *", { exact: true })
    .selectOption({ label: "Configured consultation · Jadval bo‘yicha" });
  await d.getByLabel("Qabul sanasi", { exact: true }).fill("2030-01-10");
  await expect(d).toContainText("Bu sana uchun ish jadvali sozlanmagan.");
  await d.getByLabel("Qabul sanasi", { exact: true }).fill("2030-01-09");
  await expect(
    d.getByRole("button", { name: "09:00", exact: true }),
  ).toBeVisible();
  await d.getByRole("button", { name: "09:00", exact: true }).click();
  const booked = page.waitForResponse(
    (r) =>
      r.request().method() === "POST" &&
      r.url().endsWith("/clinic/appointments"),
  );
  await d.getByRole("button", { name: "Qabulga yozish", exact: true }).click();
  expect((await booked).status()).toBe(201);
  await expect(page.locator("tbody")).toContainText("Configured consultation");
  await page.reload();
  await page.getByLabel("Qabullar sanasi").fill("2030-01-09");
  await expect(page.locator("tbody")).toContainText("Configured consultation");
});
