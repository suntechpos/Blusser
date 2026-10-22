import { test, expect, type Page } from "@playwright/test";
test.skip(
  !process.env.ACCESS_REAL_API,
  "Requires explicitly prepared isolated blusser_test backend.",
);
test.use({ viewport: { width: 1536, height: 1024 } });
const PASSWORD = "Synthetic-access-browser-42!";
async function login(page: Page, user: string, password = PASSWORD) {
  await page.goto("/");
  await page.getByLabel("Login", { exact: true }).fill(user);
  await page.getByLabel("Parol", { exact: true }).fill(password);
  await page.getByRole("button", { name: "Kirish", exact: true }).click();
  await expect(page.locator(".clinic-sidebar")).toBeVisible();
}
async function get(page: Page, path: string) {
  return page.request.get("/api/v1/clinic/" + path, {
    headers: { Origin: "http://localhost:5173", Accept: "application/json" },
  });
}
const editor = (p: Page) => p.getByRole("region", { name: "Rol sozlamalari" });
const modal = (p: Page) => p.getByRole("dialog").last();
test("real cashier, registrator and doctor logins get only allowed menus; direct URLs fail closed", async ({
  browser,
}) => {
  for (const [user, visible, hidden] of [
    ["cashier", "Kassa", "Bemorlar"],
    ["registrator", "Registratura", "Kassa"],
    ["doctor", "Shifokor kabineti", "Sozlamalar"],
  ]) {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await login(page, "accessdemo." + user);
    await expect(
      page.getByRole("button", { name: visible, exact: true }).first(),
    ).toBeVisible();
    await expect(
      page
        .locator(".clinic-sidebar")
        .getByRole("button", { name: hidden, exact: true }),
    ).toHaveCount(0);
    await expect(
      page
        .locator(".clinic-sidebar")
        .getByRole("button", { name: "Bosh sahifa", exact: true }),
    ).toHaveCount(0);
    await page.evaluate(() => (location.hash = "sozlamalar"));
    await expect(
      page.getByRole("heading", { name: "403 — Ruxsat yo‘q" }),
    ).toBeVisible();
    expect((await get(page, "roles")).status()).toBe(403);
    await page
      .getByRole("button", { name: "Ruxsat etilgan sahifaga qaytish" })
      .click();
    await expect(
      page.getByRole("heading", { name: "403 — Ruxsat yo‘q" }),
    ).toHaveCount(0);
    await ctx.close();
  }
});
test("real role CRUD, employee account, password change, live grants and access revocation", async ({
  page,
  browser,
}) => {
  await login(page, "accessdemo.admin");
  await page.getByRole("button", { name: "Sozlamalar", exact: true }).click();
  await expect(page.locator(".sr-role")).not.toHaveCount(0);
  const suffix = Date.now().toString(36);
  const roleName = "Browser " + suffix;
  await page.getByRole("button", { name: "Rol qo‘shish", exact: true }).click();
  await page.getByLabel("Rol nomi", { exact: true }).fill(roleName);
  await page.getByLabel("Izoh", { exact: true }).fill("Real API role");
  for (const label of [
    "Kassani ko‘rish",
    "Smena ochish va yopish",
    "To‘lov qabul qilish",
  ]) {
    await page.getByRole("checkbox", { name: label, exact: true }).check();
    await page
      .getByLabel(label + " doirasi", { exact: true })
      .selectOption("entire_clinic");
  }
  const created = page.waitForResponse(
    (r) => r.url().endsWith("/clinic/roles") && r.request().method() === "POST",
  );
  await page
    .getByRole("button", { name: "Rolni yaratish", exact: true })
    .click();
  expect((await created).status()).toBe(201);
  await expect(page.getByRole("status")).toContainText("serverda saqlandi");
  const roles = await (await get(page, "roles")).json();
  const role = roles.data.find((r: any) => r.name === roleName);
  expect(role.note).toBe("Real API role");
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page
    .getByRole("button", { name: "Xodim qo‘shish", exact: true })
    .click();
  await page.getByLabel("Familiya *", { exact: true }).fill("Browser" + suffix);
  await page.getByLabel("Ism *", { exact: true }).fill("Cashier");
  await page.getByLabel("Telefon *", { exact: true }).fill("+998 90 000 11 22");
  await page.getByLabel("Lavozim *", { exact: true }).selectOption("Kassir");
  await page.getByLabel("Login *", { exact: true }).fill("browser." + suffix);
  await page.getByLabel("Ruxsat roli *", { exact: true }).selectOption(role.id);
  const temporary = "Synthetic-new-browser-42!";
  await page.getByLabel("Yangi hisob paroli", { exact: true }).fill(temporary);
  await page
    .getByRole("checkbox", { name: "Bo‘lim: Kassa", exact: true })
    .check();
  await page.getByLabel("Kassa PIN-kodi", { exact: true }).fill("1122");
  await page.getByLabel("PIN-kodni tasdiqlash", { exact: true }).fill("1122");
  const staffSaved = page.waitForResponse(
    (r) =>
      r.url().endsWith("/clinic/employees") && r.request().method() === "POST",
  );
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  expect((await staffSaved).status()).toBe(201);
  await page.getByLabel("Xodimlarni qidirish").fill("Browser" + suffix);
  await expect(page.locator("tbody")).toContainText("Cashier");
  const ctx = await browser.newContext();
  const staff = await ctx.newPage();
  await staff.goto("/");
  await staff.getByLabel("Login", { exact: true }).fill("browser." + suffix);
  await staff.getByLabel("Parol", { exact: true }).fill(temporary);
  await staff.getByRole("button", { name: "Kirish", exact: true }).click();
  await expect(
    staff.getByRole("heading", { name: "Vaqtinchalik parolni almashtiring" }),
  ).toBeVisible();
  await staff.getByLabel("Joriy parol", { exact: true }).fill(temporary);
  await staff.getByLabel("Yangi parol", { exact: true }).fill(PASSWORD);
  await staff
    .getByLabel("Yangi parolni tasdiqlang", { exact: true })
    .fill(PASSWORD);
  await staff
    .getByRole("button", { name: "Parolni almashtirish", exact: true })
    .click();
  await expect(
    staff.getByRole("heading", { name: "Kassa", exact: true }),
  ).toBeVisible();
  expect((await get(staff, "patients")).status()).toBe(403);
  await staff
    .getByRole("button", { name: "Kassaga kirish", exact: true })
    .click();
  await modal(staff).getByLabel("4 xonali demo PIN").fill("1122");
  await modal(staff)
    .getByRole("button", { name: "Kirish", exact: true })
    .click();
  await modal(staff)
    .getByLabel("Boshlang‘ich naqd pul", { exact: true })
    .fill("0");
  await modal(staff)
    .getByRole("button", { name: "Kassani ochish", exact: true })
    .click();
  await expect(
    staff.getByRole("button", { name: "To‘lovni qabul qilish", exact: true }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Sozlamalar", exact: true }).click();
  await page.locator(".sr-role").filter({ hasText: roleName }).click();
  await page
    .getByRole("checkbox", { name: "To‘lov qabul qilish", exact: true })
    .uncheck();
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await modal(page)
    .getByRole("button", { name: "Saqlashni tasdiqlash" })
    .click();
  await expect(page.getByRole("status")).toContainText("serverda saqlandi");
  await staff.evaluate(() => window.dispatchEvent(new Event("focus")));
  await expect(
    staff.getByRole("button", { name: "Kassaga kirish", exact: true }),
  ).toBeVisible();
  await staff
    .getByRole("button", { name: "Kassaga kirish", exact: true })
    .click();
  await modal(staff).getByLabel("4 xonali demo PIN").fill("1122");
  await modal(staff)
    .getByRole("button", { name: "Kirish", exact: true })
    .click();
  await expect(
    staff.getByRole("button", { name: "To‘lovni qabul qilish", exact: true }),
  ).toBeHidden();
  // Programmatic clicks must also hit the live capture guard, even if hidden styling is bypassed.
  await staff
    .locator("button")
    .filter({ hasText: "To‘lovni qabul qilish" })
    .evaluate((el: HTMLButtonElement) => el.click());
  await expect(
    staff.getByRole("heading", { name: "To‘lov qabul qilindi", exact: true }),
  ).toHaveCount(0);
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page.getByLabel("Xodimlarni qidirish").fill("Browser" + suffix);
  await page
    .getByRole("row", {
      name: "Cashier Browser" + suffix + " profilini ochish",
    })
    .click();
  await page.getByRole("button", { name: "Tahrirlash", exact: true }).click();
  await page
    .getByRole("switch", { name: "Tizimga kirishga ruxsat berish" })
    .click();
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByLabel("Xodimlarni qidirish")).toBeVisible();
  await staff.evaluate(() => window.dispatchEvent(new Event("focus")));
  await expect(
    staff.getByRole("button", { name: "Kirish", exact: true }),
  ).toBeVisible();
  await ctx.close();
});
test("real loginless staff persists; photo/schedule are honestly unavailable; API conflict preserves draft", async ({
  page,
}) => {
  await login(page, "accessdemo.admin");
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page
    .getByRole("button", { name: "Xodim qo‘shish", exact: true })
    .click();
  const suffix = Date.now().toString(36);
  await page
    .getByLabel("Familiya *", { exact: true })
    .fill("NoAccount" + suffix);
  await page.getByLabel("Ism *", { exact: true }).fill("Person");
  await page.getByLabel("Telefon *", { exact: true }).fill("+998 90 000 22 33");
  await page
    .getByRole("switch", { name: "Tizimga kirishga ruxsat berish" })
    .click();
  await expect(page.getByLabel("Login *", { exact: true })).toHaveCount(0);
  await expect(page.locator("input[type=file]")).toBeDisabled();
  const saved = page.waitForResponse(
    (r) =>
      r.url().endsWith("/clinic/employees") && r.request().method() === "POST",
  );
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  expect((await saved).status()).toBe(201);
  await page.reload();
  await page.getByLabel("Xodimlarni qidirish").fill("NoAccount" + suffix);
  await page
    .getByRole("row", {
      name: "Person NoAccount" + suffix + " profilini ochish",
    })
    .click();
  await page.getByRole("button", { name: "Tahrirlash", exact: true }).click();
  await page.getByLabel("Familiya *", { exact: true }).fill("Retained draft");
  // Conflict response from the real endpoint using the stale version: a parallel API command advances it.
  const rows = await (await get(page, "employees?format=profile")).json();
  const row = rows.data.find(
    (e: any) => e.profile.last === "NoAccount" + suffix,
  );
  const xsrf = (await page.context().cookies()).find(
    (c) => c.name === "XSRF-TOKEN",
  )!.value;
  const body = {
    profile: { ...row.profile, last: "Parallel update" },
    active: row.active,
    access: false,
    branch_id: row.profile.branch,
    branch_ids: [],
  };
  delete body.profile.branch;
  const response = await page.request.patch(
    "/api/v1/clinic/employees/" + row.id,
    {
      headers: {
        Origin: "http://localhost:5173",
        "X-XSRF-TOKEN": decodeURIComponent(xsrf),
        "Idempotency-Key": crypto.randomUUID(),
        "If-Match": '"' + row.version + '"',
      },
      data: body,
    },
  );
  expect(response.status()).toBe(200);
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByRole("alert")).toContainText("Ma’lumot o‘zgargan");
  await expect(page.getByLabel("Familiya *", { exact: true })).toHaveValue(
    "Retained draft",
  );
});

test("real cashier with zero page grants gets a stable no-page state", async ({
  page,
  browser,
}) => {
  await login(page, "accessdemo.admin");
  const rows = await (await get(page, "roles")).json();
  const role = rows.data.find((r: any) => r.code === "access_cashier");
  const detail = await (await get(page, "roles/" + role.id)).json();
  const token = decodeURIComponent(
    (await page.context().cookies()).find((c) => c.name === "XSRF-TOKEN")!
      .value,
  );
  const headers = {
    Origin: "http://localhost:5173",
    "X-XSRF-TOKEN": token,
    "Idempotency-Key": crypto.randomUUID(),
    "If-Match": '"' + detail.version + '"',
  };
  const response = await page.request.put("/api/v1/clinic/roles/" + role.id, {
    headers,
    data: {
      code: role.code,
      name: role.name,
      note: role.note,
      grants: [{ code: "audit.view", scope: "own" }],
    },
  });
  expect(response.status()).toBe(200);
  const ctx = await browser.newContext();
  try {
    const staff = await ctx.newPage();
    await staff.goto("/");
    await staff.getByLabel("Login", { exact: true }).fill("accessdemo.cashier");
    await staff.getByLabel("Parol", { exact: true }).fill(PASSWORD);
    await staff.getByRole("button", { name: "Kirish", exact: true }).click();
    await expect(
      staff.getByRole("heading", { name: "403 — Ruxsat yo‘q" }),
    ).toBeVisible();
    await expect(staff.locator(".clinic-sidebar button")).toHaveCount(0);
    expect((await get(staff, "roles")).status()).toBe(403);
  } finally {
    await ctx.close();
    const latest = await (await get(page, "roles/" + role.id)).json();
    expect(
      (
        await page.request.put("/api/v1/clinic/roles/" + role.id, {
          headers: {
            ...headers,
            "Idempotency-Key": crypto.randomUUID(),
            "If-Match": '"' + latest.version + '"',
          },
          data: {
            code: role.code,
            name: role.name,
            note: role.note,
            grants: detail.grants,
          },
        })
      ).status(),
    ).toBe(200);
  }
});

test("real employee view grants restrict menus, URL and API independently of the shared role", async ({
  page,
  browser,
}) => {
  await login(page, "accessdemo.admin");
  const roles = (await (await get(page, "roles")).json()).data;
  const role = roles.find((r: { code: string }) => r.code === "registrator");
  const suffix = Date.now().toString(36);
  const temporary = "Synthetic-page-browser-42!";
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page
    .getByRole("button", { name: "Xodim qo‘shish", exact: true })
    .click();
  await page.getByLabel("Familiya *", { exact: true }).fill("Pages" + suffix);
  await page.getByLabel("Ism *", { exact: true }).fill("Multi");
  await page.getByLabel("Telefon *", { exact: true }).fill("+998 90 000 11 22");
  await page.getByLabel("Login *", { exact: true }).fill("pages." + suffix);
  await page.getByLabel("Ruxsat roli *", { exact: true }).selectOption(role.id);
  await page.getByLabel("Yangi hisob paroli", { exact: true }).fill(temporary);
  for (const label of ["Registratura", "Kassa"])
    await page
      .getByRole("checkbox", { name: "Bo‘lim: " + label, exact: true })
      .check();
  await page.getByLabel("Kassa PIN-kodi", { exact: true }).fill("1122");
  await page.getByLabel("PIN-kodni tasdiqlash", { exact: true }).fill("1122");
  const response = page.waitForResponse(
    (r) =>
      r.url().endsWith("/clinic/employees") && r.request().method() === "POST",
  );
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  expect((await response).status()).toBe(201);
  const ctx = await browser.newContext();
  const staff = await ctx.newPage();
  await staff.goto("/");
  await staff.getByLabel("Login", { exact: true }).fill("pages." + suffix);
  await staff.getByLabel("Parol", { exact: true }).fill(temporary);
  await staff.getByRole("button", { name: "Kirish", exact: true }).click();
  await staff.getByLabel("Joriy parol", { exact: true }).fill(temporary);
  await staff.getByLabel("Yangi parol", { exact: true }).fill(PASSWORD);
  await staff
    .getByLabel("Yangi parolni tasdiqlang", { exact: true })
    .fill(PASSWORD);
  await staff
    .getByRole("button", { name: "Parolni almashtirish", exact: true })
    .click();
  const sidebar = staff.locator(".clinic-sidebar");
  await expect(
    sidebar.getByRole("button", { name: "Registratura", exact: true }),
  ).toBeVisible();
  await expect(
    sidebar.getByRole("button", { name: "Kassa", exact: true }),
  ).toBeVisible();
  await expect(
    sidebar.getByRole("button", { name: "Bemorlar", exact: true }),
  ).toHaveCount(0);
  const me = await (await staff.request.get("/api/v1/auth/me")).json();
  expect(Object.keys(me.grants).sort()).toEqual([
    "cashier.view",
    "reception.view",
  ]);
  expect((await get(staff, "patients")).status()).toBe(403);
  await staff.evaluate(() => (location.hash = "bemorlar"));
  await expect(
    staff.getByRole("heading", { name: "403 — Ruxsat yo‘q" }),
  ).toBeVisible();
  await ctx.close();
});
