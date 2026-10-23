import { test, expect } from "@playwright/test";
// CI's existing BrowserFixtureTest prepares this separate synthetic clinic in
// blusser_test. No request interception, demo state or production patient writes.
test.use({ viewport: { width: 1536, height: 1024 } });
test("registrator logs in through Sanctum, books October 28 in PostgreSQL and sees it after refresh", async ({
  page,
}) => {
  await page.clock.setFixedTime(new Date("2026-10-23T03:01:00Z"));
  const writes: { path: string; body: any }[] = [];
  page.on("request", (r) => {
    if (r.method() === "POST" && r.url().includes("/clinic/"))
      writes.push({ path: new URL(r.url()).pathname, body: r.postDataJSON() });
  });
  await page.goto("/");
  await page.getByLabel("Login", { exact: true }).fill("booking.registrator");
  await page
    .getByLabel("Parol", { exact: true })
    .fill("Synthetic-password-42!");
  await page.getByRole("button", { name: "Kirish", exact: true }).click();
  await expect(page.locator(".clinic-sidebar")).toBeVisible();
  await page.getByRole("button", { name: "Registratura", exact: true }).click();
  await page
    .locator(".rp-heading-actions")
    .getByRole("button", { name: "Qabulga yozish", exact: true })
    .click();
  const dialog = page.getByRole("dialog", {
    name: "Qabulga yozish",
    exact: true,
  });
  await expect(
    dialog.getByRole("button", { name: "Oldin kelgan bemor", exact: true }),
  ).toBeVisible();
  await dialog
    .getByRole("button", { name: "Oldin kelgan bemor", exact: true })
    .click();
  await dialog
    .getByLabel("Bemorni qidirish", { exact: true })
    .fill("Booking test patient");
  await dialog.getByRole("button", { name: /Booking test patient/ }).click();
  await dialog
    .getByLabel("Bo‘lim *", { exact: true })
    .selectOption({ label: "Booking specialty" });
  await dialog
    .getByLabel("Shifokor *", { exact: true })
    .selectOption({ label: "Booking test doctor" });
  await dialog
    .getByLabel("Qabul turi *", { exact: true })
    .selectOption({ label: "Booking consultation · 30 daqiqa" });
  await dialog.getByLabel("Qabul sanasi", { exact: true }).fill("2026-10-28");
  await dialog.getByRole("button", { name: "10:30", exact: true }).click();
  await dialog
    .getByLabel("Izoh", { exact: true })
    .fill("Synthetic real browser booking");
  const response = page.waitForResponse(
    (r) =>
      r.request().method() === "POST" &&
      r.url().endsWith("/clinic/appointments"),
  );
  await dialog
    .getByRole("button", { name: "Qabulga yozish", exact: true })
    .click();
  const createdResponse = await response;
  expect(createdResponse.status()).toBe(201);
  const created = await createdResponse.json();
  expect(created.starts_at).toContain("2026-10-28T05:30:00");
  expect(created.note).toBe("Synthetic real browser booking");
  await expect(dialog).toHaveCount(0);
  await expect(page.getByLabel("Qabullar sanasi")).toHaveValue("2026-10-28");
  await expect(page.locator("tbody")).toContainText("Booking test patient");
  await expect(page.locator("tbody")).toContainText("10:30");
  await page.reload();
  await page.getByLabel("Qabullar sanasi").fill("2026-10-28");
  await expect(page.locator("tbody")).toContainText("Booking test patient");
  await page.getByLabel("Qabullar sanasi").fill("2026-10-23");
  await expect(page.locator("tbody")).not.toContainText("Booking test patient");
  expect(writes.filter((r) => r.path.endsWith("/patients"))).toHaveLength(0);
  const verify = await page.request.get(
    "/api/v1/clinic/appointments/" + created.id,
    {
      headers: { Origin: "http://localhost:5173", Accept: "application/json" },
    },
  );
  expect(verify.status()).toBe(200);
  expect((await verify.json()).note).toBe("Synthetic real browser booking");
});

test("registrator creates a real new patient then books it and reopens persisted server rows", async ({
  page,
}) => {
  await page.goto("/");
  await page.getByLabel("Login", { exact: true }).fill("booking.registrator");
  await page
    .getByLabel("Parol", { exact: true })
    .fill("Synthetic-password-42!");
  await page.getByRole("button", { name: "Kirish", exact: true }).click();
  await page.getByRole("button", { name: "Registratura", exact: true }).click();
  await page
    .locator(".rp-heading-actions")
    .getByRole("button", { name: "Qabulga yozish", exact: true })
    .click();
  const d = page.getByRole("dialog", { name: "Qabulga yozish", exact: true });
  await expect(d.getByLabel("Familiya *", { exact: true })).toBeVisible();
  await expect(
    d.getByRole("button", { name: "Qabulni sozlash", exact: true }),
  ).toHaveCount(0);
  await d.getByLabel("Familiya *", { exact: true }).fill("Synthetic");
  await d.getByLabel("Ism *", { exact: true }).fill("Newborn");
  await d.getByLabel("Tug‘ilgan sana", { exact: true }).fill("2000-01-02");
  await d
    .getByLabel("Bo‘lim *", { exact: true })
    .selectOption({ label: "Booking specialty" });
  await d
    .getByLabel("Shifokor *", { exact: true })
    .selectOption({ label: "Booking test doctor" });
  await d
    .getByLabel("Qabul turi *", { exact: true })
    .selectOption({ label: "Booking consultation · 30 daqiqa" });
  await d.getByLabel("Qabul sanasi", { exact: true }).fill("2026-10-28");
  await d.getByRole("button", { name: "11:00", exact: true }).click();
  const patientResponse = page.waitForResponse(
    (r) =>
      r.request().method() === "POST" && r.url().endsWith("/clinic/patients"),
  );
  const bookingResponse = page.waitForResponse(
    (r) =>
      r.request().method() === "POST" &&
      r.url().endsWith("/clinic/appointments"),
  );
  await d.getByRole("button", { name: "Qabulga yozish", exact: true }).click();
  const p = await patientResponse;
  expect(p.status()).toBe(201);
  const patient = await p.json();
  expect(patient.id).toMatch(/^[0-9a-f-]{36}$/);
  expect(patient.patient_number).toBeTruthy();
  expect((await bookingResponse).status()).toBe(201);
  await expect(d).toHaveCount(0);
  await expect(page.locator("tbody")).toContainText("Synthetic Newborn");
  await expect(page.locator("tbody")).toContainText(patient.patient_number);
  await page.reload();
  await page.getByLabel("Qabullar sanasi").fill("2026-10-28");
  await expect(page.locator("tbody")).toContainText("Synthetic Newborn");
});

test("authorized setup creates real service, specialty, doctor, room and schedule; availability reloads", async ({
  page,
}) => {
  await page.goto("/");
  await page.getByLabel("Login", { exact: true }).fill("booking.doctor");
  await page
    .getByLabel("Parol", { exact: true })
    .fill("Synthetic-password-42!");
  await page.getByRole("button", { name: "Kirish", exact: true }).click();
  await page.getByRole("button", { name: "Registratura", exact: true }).click();
  await page
    .locator(".rp-heading-actions")
    .getByRole("button", { name: "Qabulga yozish", exact: true })
    .click();
  const d = page.getByRole("dialog", { name: "Qabulga yozish", exact: true });
  await d.getByRole("button", { name: "Qabulni sozlash", exact: true }).click();
  const c = page.getByRole("dialog", { name: "Qabulni sozlash", exact: true });
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
    .getByLabel("Mavjud shifokor", { exact: true })
    .selectOption({ label: "Booking test doctor" });
  const assignment = page.waitForResponse(
    (r) =>
      r.request().method() === "PUT" && r.url().includes("/clinic/doctors/"),
  );
  await c
    .getByRole("button", {
      name: "Tanlangan xizmatni shifokorga qo‘shish",
      exact: true,
    })
    .click();
  const assigned = await assignment;
  expect(assigned.status()).toBe(200);
  const doctor = await assigned.json();
  expect(doctor.service_ids).toHaveLength(2);
  expect(doctor.branch_ids).toHaveLength(1);
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
});
