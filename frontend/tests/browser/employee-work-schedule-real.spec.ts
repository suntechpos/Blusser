import { test, expect, type Page } from "@playwright/test";
test.use({ viewport: { width: 1536, height: 1024 } });
async function login(page: Page, name: string) {
  await page.goto("/");
  await page.getByLabel("Login", { exact: true }).fill(name);
  await page
    .getByLabel("Parol", { exact: true })
    .fill("Synthetic-password-42!");
  await page.getByRole("button", { name: "Kirish", exact: true }).click();
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
}
async function openSaved(page: Page, name: string) {
  await page.getByLabel("Xodimlarni qidirish").fill(name);
  await page.getByRole("row").filter({ hasText: name }).click();
  await page.getByRole("tab", { name: "Ish jadvali", exact: true }).click();
  return page.getByRole("table", { name: "Haftalik ish jadvali", exact: true });
}
const section = (page: Page) =>
  page.getByRole("region", { name: "Ish jadvali", exact: true });
for (const [position, suffix] of [
  ["Registrator", "Reception"],
  ["Hamshira", "Nurse"],
  ["Shifokor", "Hirurg"],
]) {
  test(`${position} without login or catalogs saves, reloads and edits a real work schedule`, async ({
    page,
  }) => {
    await login(page, "work.operator");
    await page.evaluate(async () => {
      const path = "/src/services/api.ts";
      const { clinicRequest } = await import(path);
      for (const resource of [
        "specialties",
        "appointments/configuration-services",
        "rooms",
      ]) {
        const r = (await clinicRequest(resource)).data;
        if (r.data.length)
          throw Error("Expected an actually empty clinical catalog");
      }
    });
    const clinical: string[] = [];
    let employeePosts = 0;
    page.on("request", (r) => {
      if (r.method() === "POST" && r.url().endsWith("/clinic/employees"))
        employeePosts++;
      if (
        /\/clinic\/(doctors|specialties|rooms|schedules|appointments\/configuration-services)(\/|\?|$)/.test(
          r.url(),
        )
      )
        clinical.push(r.url());
    });
    await page
      .getByRole("button", { name: "Xodim qo‘shish", exact: true })
      .click();
    await page.getByLabel("Familiya *", { exact: true }).fill(`Work${suffix}`);
    await page.getByLabel("Ism *", { exact: true }).fill("Synthetic");
    await page
      .getByLabel("Telefon *", { exact: true })
      .fill("+998 90 765 43 28");
    await page.getByLabel("Lavozim *", { exact: true }).selectOption(position);
    if (position === "Shifokor")
      await page
        .getByPlaceholder("Mutaxassislikni kiriting", { exact: true })
        .fill("Hirurg");
    await page
      .getByRole("switch", { name: "Tizimga kirishga ruxsat berish" })
      .click();
    const card = section(page);
    await card.getByLabel("Ish jadvalini saqlash", { exact: true }).check();
    if (position === "Shifokor")
      await expect(
        card.getByLabel("Bemor qabul qiladi", { exact: true }),
      ).not.toBeChecked();
    await expect(card.getByLabel("Qabul xonasi", { exact: true })).toHaveCount(
      0,
    );
    for (let i = 0; i < 2; i++)
      await card
        .getByRole("button", { name: "Yana bir kun qo‘shish", exact: true })
        .click();
    await card.getByLabel("Dushanba tugash", { exact: true }).fill("16:00");
    await card.getByLabel("Seshanba boshlanish", { exact: true }).fill("10:00");
    if (suffix === "Reception") {
      let failed = false;
      await page.route(
        "**/api/v1/clinic/employee-work-schedules/**",
        (route) => {
          if (route.request().method() === "PUT" && !failed) {
            failed = true;
            return route.fulfill({
              status: 503,
              json: { message: "Synthetic work write failure" },
            });
          }
          return route.continue();
        },
      );
      await page.getByRole("button", { name: "Saqlash", exact: true }).click();
      await expect(card.getByRole("alert")).toContainText("Xodim saqlandi");
      expect(employeePosts).toBe(1);
    }
    await page.getByRole("button", { name: "Saqlash", exact: true }).click();
    await expect(page.getByLabel("Xodimlarni qidirish")).toBeVisible();
    expect(employeePosts).toBe(1);
    let table = await openSaved(page, `Work${suffix}`);
    await expect(table.getByRole("row", { name: /Dushanba/ })).toContainText(
      "09:00–16:00",
    );
    await expect(table.getByRole("row", { name: /Seshanba/ })).toContainText(
      "10:00–17:00",
    );
    await expect(table.getByRole("row", { name: /Yakshanba/ })).toContainText(
      "Dam olish",
    );
    await expect(
      table
        .getByRole("row", { name: /Dushanba/ })
        .locator("td")
        .nth(1),
    ).toHaveText("—");
    await page.reload();
    table = await openSaved(page, `Work${suffix}`);
    await expect(table.getByRole("row", { name: /Dushanba/ })).toContainText(
      "09:00–16:00",
    );
    await page.getByRole("button", { name: "Tahrirlash", exact: true }).click();
    await card.getByLabel("Ish jadvalini saqlash", { exact: true }).check();
    await card.getByLabel("Dushanba tugash", { exact: true }).fill("15:00");
    if (position === "Shifokor")
      await expect(
        page.getByPlaceholder("Mutaxassislikni kiriting", { exact: true }),
      ).toHaveValue("Hirurg");
    await card
      .getByRole("button", {
        name: "Seshanba kunini olib tashlash",
        exact: true,
      })
      .click();
    await expect(
      card.getByLabel("Seshanba tugash", { exact: true }),
    ).toBeVisible();
    await card.getByRole("button", { name: "Tasdiqlash", exact: true }).click();
    await page.getByRole("button", { name: "Saqlash", exact: true }).click();
    await expect(page.getByLabel("Xodimlarni qidirish")).toBeVisible();
    table = await openSaved(page, `Work${suffix}`);
    await expect(table.getByRole("row", { name: /Dushanba/ })).toContainText(
      "09:00–15:00",
    );
    await expect(table.getByRole("row", { name: /Seshanba/ })).toContainText(
      "Dam olish",
    );
    expect(employeePosts).toBe(1);
    expect(clinical).toEqual([]);
  });
}
test("doctor can receive patients on Monday and only work Tuesday, then disable and reenable without duplicate clinical rules", async ({
  page,
}) => {
  await page.clock.setFixedTime(new Date("2030-01-08T21:30:00Z"));
  await login(page, "booking.doctor");
  await page
    .getByRole("button", { name: "Xodim qo‘shish", exact: true })
    .click();
  await page.getByLabel("Familiya *", { exact: true }).fill("MixedWork");
  await page.getByLabel("Ism *", { exact: true }).fill("Synthetic");
  await page.getByLabel("Telefon *", { exact: true }).fill("+998 90 765 43 27");
  await page.getByLabel("Lavozim *", { exact: true }).selectOption("Shifokor");
  await page
    .getByPlaceholder("Mutaxassislikni kiriting", { exact: true })
    .fill("Hirurg");
  await page
    .getByLabel("Login *", { exact: true })
    .fill("synthetic.mixed-work");
  await page
    .getByLabel("Ruxsat roli *", { exact: true })
    .selectOption({ label: "Synthetic booking setup" });
  await page
    .getByLabel("Yangi hisob paroli", { exact: true })
    .fill("Synthetic-new-password-42!");
  const cashier = page.getByLabel("Bo‘lim: Kassa", { exact: true });
  if (await cashier.isChecked()) await cashier.uncheck();
  const card = section(page);
  await card.getByLabel("Ish jadvalini saqlash", { exact: true }).check();
  await card.getByLabel("Bemor qabul qiladi", { exact: true }).check();
  await expect(
    card
      .getByLabel("Qabul xonasi", { exact: true })
      .locator("option")
      .filter({ hasText: "B101" }),
  ).toHaveCount(1);
  await card
    .getByLabel("Qabul xonasi", { exact: true })
    .selectOption({ label: "B101" });
  for (let i = 0; i < 2; i++)
    await card
      .getByRole("button", { name: "Yana bir kun qo‘shish", exact: true })
      .click();
  await card.getByLabel("Dushanba boshlanish", { exact: true }).fill("18:00");
  await card.getByLabel("Dushanba tugash", { exact: true }).fill("19:00");
  await card.getByLabel("Seshanba bemor qabuli", { exact: true }).uncheck();
  let posts = 0;
  page.on("request", (r) => {
    if (r.method() === "POST" && r.url().endsWith("/clinic/schedules")) posts++;
  });
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByLabel("Xodimlarni qidirish")).toBeVisible();
  expect(posts).toBe(1);
  let table = await openSaved(page, "MixedWork");
  await expect(table.getByRole("row", { name: /Dushanba/ })).toContainText(
    "B101",
  );
  await expect(
    table
      .getByRole("row", { name: /Seshanba/ })
      .locator("td")
      .nth(1),
  ).toHaveText("—");
  await page.reload();
  await openSaved(page, "MixedWork");
  await page.getByRole("button", { name: "Tahrirlash", exact: true }).click();
  await card.getByLabel("Ish jadvalini saqlash", { exact: true }).check();
  await expect(
    card.getByLabel("Seshanba bemor qabuli", { exact: true }),
  ).not.toBeChecked();
  await card.getByLabel("Bemor qabul qiladi", { exact: true }).click();
  await card.getByRole("button", { name: "Tasdiqlash", exact: true }).click();
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByLabel("Xodimlarni qidirish")).toBeVisible();
  table = await openSaved(page, "MixedWork");
  await expect(table.getByRole("row", { name: /Dushanba/ })).toContainText(
    "18:00–19:00",
  );
  await expect(
    table
      .getByRole("row", { name: /Dushanba/ })
      .locator("td")
      .nth(1),
  ).toHaveText("—");
  await page.getByRole("button", { name: "Tahrirlash", exact: true }).click();
  await card.getByLabel("Ish jadvalini saqlash", { exact: true }).check();
  await card.getByLabel("Bemor qabul qiladi", { exact: true }).check();
  await expect(card.getByLabel("Qabul xonasi", { exact: true })).toHaveValue(
    /.+/,
  );
  await card.getByLabel("Seshanba bemor qabuli", { exact: true }).uncheck();
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByLabel("Xodimlarni qidirish")).toBeVisible();
  expect(posts).toBe(1);
});
