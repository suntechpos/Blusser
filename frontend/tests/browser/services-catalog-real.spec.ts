import { test, expect, type Page } from "@playwright/test";

// BrowserFixtureTest prepares only blusser_test. No intercepted requests or demo catalog.
test.use({ viewport: { width: 1536, height: 1024 } });
async function login(page: Page, name = "booking.doctor") {
  await page.goto("/login");
  await page.getByLabel("Login", { exact: true }).fill(name);
  await page
    .getByLabel("Parol", { exact: true })
    .fill("Synthetic-password-42!");
  await page.getByRole("button", { name: "Kirish", exact: true }).click();
  await expect(page.locator(".clinic-sidebar")).toBeVisible();
}
async function api(
  page: Page,
  path: string,
  options: Record<string, unknown> = {},
) {
  return page.evaluate(
    async ({ path, options }) => {
      const modulePath = "/src/services/api.ts";
      const { clinicRequest } = await import(modulePath);
      return (await clinicRequest(path, options)).data;
    },
    { path, options },
  );
}
function saved(page: Page, suffix: string, method: string) {
  return page.waitForResponse(
    (r) =>
      new URL(r.url()).pathname.endsWith(suffix) &&
      r.request().method() === method,
  );
}

test("category and decimal-priced service persist through UI; same service books an appointment and preserves its history", async ({
  page,
}) => {
  await login(page);
  const extraBranch = await api(page, "branches", {
    method: "POST",
    body: { name: "Service test second branch", code: "service-test" },
  });
  await page.getByRole("button", { name: "Xizmatlar", exact: true }).click();
  await expect(page.locator(".sv-page")).toBeVisible();
  await page
    .getByRole("button", { name: "Kategoriya qo‘shish", exact: true })
    .click();
  let d = page.getByRole("dialog");
  await d
    .getByLabel("Kategoriya nomi", { exact: true })
    .fill("API clinical services");
  await d
    .getByLabel("Kategoriya tavsifi", { exact: true })
    .fill("Synthetic PostgreSQL acceptance category");
  await d.getByRole("button", { name: "Rang: Binafsha", exact: true }).click();
  const categoryResponse = saved(page, "/clinic/service-categories", "POST");
  await d.getByRole("button", { name: "Saqlash", exact: true }).click();
  const categoryResult = await categoryResponse;
  expect(categoryResult.status()).toBe(201);
  const category = await categoryResult.json();
  await expect(d).toHaveCount(0);
  await page.reload();
  await expect(page.locator(".sv-tabs")).toContainText("API clinical services");

  await page
    .getByRole("button", { name: "Xizmat qo‘shish", exact: true })
    .first()
    .click();
  d = page.getByRole("dialog");
  await d
    .getByLabel("Xizmat nomi", { exact: true })
    .fill("Persisted decimal consultation");
  await d.getByLabel("Kategoriya", { exact: true }).selectOption(category.id);
  await d.getByLabel("Narxi", { exact: true }).fill("123456.78");
  await d.getByLabel("Davomiyligi", { exact: true }).fill("20");
  await d
    .getByRole("button", { name: "Filiallarni tanlash", exact: true })
    .click();
  await d
    .getByRole("checkbox", { name: "Service test second branch", exact: true })
    .check();
  const serviceResponse = saved(
    page,
    "/clinic/appointments/configuration-services",
    "POST",
  );
  await d.getByRole("button", { name: "Saqlash", exact: true }).click();
  const createdResponse = await serviceResponse;
  expect(createdResponse.status()).toBe(201);
  const service = await createdResponse.json();
  expect(service.code).toMatch(/^XZ-\d+$/);
  expect(service.price).toBe("123456.78");
  expect(service.duration_minutes).toBe(20);
  expect(service.branch_ids).toContain(extraBranch.id);
  await expect(d).toHaveCount(0);
  await page.reload();
  const row = page
    .getByRole("row")
    .filter({ hasText: "Persisted decimal consultation" });
  await expect(row).toBeVisible();
  await expect(row).toContainText("123 456.78");
  await row
    .getByRole("button", { name: `${service.code} tahrirlash`, exact: true })
    .click();
  await d.getByLabel("Narxi", { exact: true }).fill("123456.79");
  const updatedResponse = saved(
    page,
    `/configuration-services/${service.id}`,
    "PUT",
  );
  await d.getByRole("button", { name: "Saqlash", exact: true }).click();
  expect((await updatedResponse).status()).toBe(200);
  await expect(d).toHaveCount(0);
  await page
    .getByLabel("Filial filtri", { exact: true })
    .selectOption(extraBranch.id);
  await expect(page.locator(".sv-page tbody")).toContainText(
    "Persisted decimal consultation",
  );
  expect(
    (await api(page, `appointments/configuration-services/${service.id}`))
      .price,
  ).toBe("123456.79");

  // Use this exact catalog UUID in the existing doctor and appointment APIs.
  const appointment = await page.evaluate(async (serviceId: string) => {
    const modulePath = "/src/services/api.ts";
    const { clinicRequest } = await import(modulePath);
    const doctors = (
      await clinicRequest("doctors", { params: { per_page: 100 } })
    ).data.data;
    const doctor = doctors.find((x: any) => x.service_ids.length > 0);
    await clinicRequest(`doctors/${doctor.id}`, {
      method: "PUT",
      body: {
        membership_id: doctor.membership_id,
        specialty_id: doctor.specialty_id,
        branch_ids: doctor.branch_ids,
        service_ids: [...doctor.service_ids, serviceId],
        active: doctor.active,
      },
    });
    const branch = doctor.branch_ids[0];
    const options = (
      await clinicRequest("appointments/booking-options", {
        params: { branch_id: branch },
      })
    ).data;
    const slots = (
      await clinicRequest(`doctors/${doctor.id}/availability`, {
        params: {
          branch_id: branch,
          service_id: serviceId,
          from: "2030-01-07T00:00:00+05:00",
          to: "2030-01-08T00:00:00+05:00",
          per_page: 100,
        },
      })
    ).data.data;
    const slot = slots[0];
    if (!slot) throw new Error("Real availability slot was not returned");
    const patients = (
      await clinicRequest("patients", { params: { q: "Booking test patient" } })
    ).data.data;
    const created = (
      await clinicRequest("appointments", {
        method: "POST",
        body: {
          patient_id: patients[0].id,
          doctor_id: doctor.id,
          branch_id: branch,
          service_id: serviceId,
          room_id: slot.room_id,
          starts_at: slot.starts_at,
          ends_at: slot.ends_at,
        },
      })
    ).data;
    return { created, options, slot, doctor: doctor.id, branch };
  }, service.id);
  expect(JSON.stringify(appointment.options)).toContain(service.id);
  expect(
    Date.parse(appointment.slot.ends_at) -
      Date.parse(appointment.slot.starts_at),
  ).toBe(20 * 60000);
  expect(appointment.created.service_id).toBe(service.id);

  await row
    .getByRole("button", { name: `${service.code} amallar`, exact: true })
    .click();
  await page
    .getByRole("button", { name: "Faolsizlantirish", exact: true })
    .click();
  const statusResponse = saved(
    page,
    `/configuration-services/${service.id}/status`,
    "POST",
  );
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "Faolsizlantirish", exact: true })
    .click();
  expect((await statusResponse).status()).toBe(200);
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await page
    .getByLabel("Holat filtri", { exact: true })
    .selectOption("inactive");
  await expect(row).toContainText("Faol emas");
  const historical = await api(page, `appointments/${appointment.created.id}`);
  expect(historical.service_id).toBe(service.id);
  const inactiveOptions = await api(page, "appointments/booking-options", {
    params: { branch_id: appointment.branch },
  });
  expect(JSON.stringify(inactiveOptions)).not.toContain(service.id);
  await page.reload();
  await page
    .getByLabel("Holat filtri", { exact: true })
    .selectOption("inactive");
  await expect(row).toContainText("123 456.79");
});

test("reader without service grants cannot read the real catalog", async ({
  page,
}) => {
  await login(page, "synthetic.reader");
  await expect(
    page.getByRole("button", { name: "Xizmatlar", exact: true }),
  ).toHaveCount(0);
  const response = await page.request.get(
    "/api/v1/clinic/appointments/configuration-services",
    {
      headers: { Accept: "application/json", Origin: "http://localhost:5173" },
    },
  );
  expect(response.status()).toBe(403);
});
