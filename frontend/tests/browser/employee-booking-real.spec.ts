import { test, expect } from "@playwright/test";
test.use({ viewport: { width: 1536, height: 1024 } });
test("employee management creates real doctor links and schedule then books a persisted appointment", async ({
  page,
}) => {
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
  await page
    .getByRole("button", {
      name: "Shifokor profili / ish jadvali",
      exact: true,
    })
    .click();
  const c = page.getByRole("dialog", {
    name: "Shifokor profili va ish jadvali",
    exact: true,
  });
  await c
    .getByLabel("Yangi mutaxassislik kodi", { exact: true })
    .fill("configured-specialty");
  await c.getByLabel("Nomi", { exact: true }).fill("Configured specialty");
  await c
    .getByRole("button", { name: "Mutaxassislik yaratish", exact: true })
    .click();
  await expect(c.getByLabel("Mutaxassislik", { exact: true })).not.toHaveValue(
    "",
  );
  await c
    .getByLabel("Yangi xizmat kodi", { exact: true })
    .fill("configured-consultation");
  await c
    .getByLabel("Xizmat nomi", { exact: true })
    .fill("Configured consultation");
  await c.getByRole("button", { name: "Xizmat yaratish", exact: true }).click();
  await expect(c.getByLabel("Xizmat", { exact: true })).not.toHaveValue("");
  await c
    .getByLabel("Faol xodim hisobi", { exact: true })
    .selectOption({ label: "Booking configurable clinician" });
  await c
    .getByRole("button", { name: "Shifokor profilini yaratish", exact: true })
    .click();
  await expect(
    c.getByLabel("Mavjud shifokor", { exact: true }),
  ).not.toHaveValue("");
  await c
    .getByLabel("Yangi xona raqami", { exact: true })
    .fill("Configured R2");
  await c.getByRole("button", { name: "Xona yaratish", exact: true }).click();
  await expect(c.getByLabel("Mavjud xona", { exact: true })).not.toHaveValue(
    "",
  );
  await c.getByLabel("Jadval boshlanishi", { exact: true }).fill("2030-01-09");
  await c.getByLabel("Jadval tugashi", { exact: true }).fill("2030-01-09");
  await c.getByLabel("Hafta kuni", { exact: true }).selectOption("3");
  const schedule = page.waitForResponse(
    (r) =>
      r.request().method() === "POST" && r.url().endsWith("/clinic/schedules"),
  );
  await c.getByRole("button", { name: "Jadval yaratish", exact: true }).click();
  expect((await schedule).status()).toBe(201);
  await page.setViewportSize({ width: 390, height: 844 });
  expect(await c.evaluate((el) => el.scrollWidth <= el.clientWidth)).toBe(true);
  await c.screenshot({ path: "/tmp/reception-configuration.png" });
  await c.getByRole("button", { name: "Yopish", exact: true }).last().click();
  await page.setViewportSize({ width: 1536, height: 1024 });
  await page
    .getByRole("button", {
      name: "Shifokor profili / ish jadvali",
      exact: true,
    })
    .click();
  await expect(c).toContainText("Bu xodimning shifokor profili mavjud");
  await expect(
    c.getByRole("button", { name: "Shifokor profilini yaratish", exact: true }),
  ).toBeDisabled();
  await c.getByRole("button", { name: "Yopish", exact: true }).last().click();
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
  await d.getByLabel("Qabul sanasi", { exact: true }).fill("2030-01-08");
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
