import { test, expect, type Page } from "@playwright/test";
import { mkdir } from "node:fs/promises";
const output =
  process.env.EMPLOYEE_SCREENSHOTS ?? "/tmp/blusser-employees-screenshots";
/** UI-only network fixtures. Production authentication code is never bypassed or changed. */
async function setup(
  page: Page,
  kind: "clinic" | "platform" | "temporary" | "guest" = "clinic",
) {
  let signedIn = kind !== "guest";
  const requests: string[] = [];
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("request", (r) => {
    if (r.url().includes("/api/") || r.url().includes("/sanctum/"))
      requests.push(`${r.method()} ${new URL(r.url()).pathname}`);
  });
  const user = {
    id: "ui-synthetic-user",
    login: "demo.ceo",
    is_platform_admin: kind === "platform",
    must_change_password: kind === "temporary",
    active_tenant_id: "ui-clinic",
  };
  await page.route("**/api/v1/**", async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === "/api/v1/auth/logout") {
      signedIn = false;
      return route.fulfill({ status: 204 });
    }
    if (path === "/api/v1/auth/login") {
      signedIn = true;
      return route.fulfill({ json: user });
    }
    if (path === "/api/v1/auth/me")
      return route.fulfill(
        signedIn
          ? { json: user }
          : { status: 401, json: { message: "Unauthenticated." } },
      );
    if (path === "/api/v1/auth/clinics")
      return route.fulfill({
        json: {
          data: [{ id: "ui-clinic", name: "Namuna klinika", role: "CEO" }],
        },
      });
    if (path === "/api/v1/clinic/context")
      return route.fulfill({
        json: { grants: {}, branches: [], timezone: "Asia/Tashkent" },
      });
    return route.fulfill({
      status: 500,
      json: { message: "Unexpected UI test request" },
    });
  });
  await page.route("**/sanctum/csrf-cookie", (route) =>
    route.fulfill({ status: 204 }),
  );
  await page.goto("/#xodimlar");
  return { requests, errors };
}
async function profile(page: Page, name = "Aziz Karimov") {
  await page.getByRole("row", { name: name + " profilini ochish" }).click();
}
async function screenshot(page: Page, name: string) {
  await mkdir(output, { recursive: true });
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({ path: `${output}/${name}.png`, fullPage: false });
}
test.use({ viewport: { width: 1672, height: 941 }, locale: "uz-UZ" });
test("six reference screens, modal focus, no API writes and schedule save/cancel", async ({
  page,
}) => {
  const { requests, errors } = await setup(page);
  await expect(
    page.getByRole("heading", { name: "Xodimlar", exact: true }),
  ).toBeVisible();
  await expect(page.locator(".e-stats")).toContainText("24");
  await expect(page.locator(".e-stats")).toContainText("22");
  await screenshot(page, "01-employees-list");
  await page
    .getByRole("button", { name: "Xodim qo‘shish", exact: true })
    .click();
  await screenshot(page, "02-employee-create");
  await page.getByRole("button", { name: "Bekor qilish", exact: true }).click();
  await profile(page);
  await screenshot(page, "03-employee-profile");
  await page.getByRole("tab", { name: "Ish jadvali", exact: true }).click();
  await expect(
    page.locator(".schedule-display").first().locator("tbody tr"),
  ).toHaveCount(7);
  await screenshot(page, "04-employee-schedule");
  const trigger = page.getByRole("button", {
    name: "Jadvalni tahrirlash",
    exact: true,
  });
  await trigger.click();
  let dialog = page.getByRole("dialog");
  await expect(dialog).toBeVisible();
  await screenshot(page, "05-schedule-dialog");
  // Native modal must trap focus and Escape must restore it to the opener.
  for (let i = 0; i < 8; i++) {
    await page.keyboard.press("Shift+Tab");
    expect(
      await dialog.evaluate((el) => el.contains(document.activeElement)),
    ).toBe(true);
  }
  await page.keyboard.press("Escape");
  await expect(dialog).toBeHidden();
  await expect(trigger).toBeFocused();
  await trigger.click();
  dialog = page.getByRole("dialog");
  await dialog.getByLabel("Amal qilish sanasi").fill("2026-10-20");
  await dialog
    .getByRole("button", { name: "O‘zgarishlarni saqlash", exact: true })
    .click();
  await expect(dialog).toBeHidden();
  await expect(page.locator(".e-section-head").first()).toContainText(
    "20.10.2026",
  );
  await trigger.click();
  await page
    .getByRole("dialog")
    .getByLabel("Amal qilish sanasi")
    .fill("2026-10-21");
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "Bekor qilish", exact: true })
    .click();
  await expect(page.locator(".e-section-head").first()).toContainText(
    "20.10.2026",
  );
  await page
    .getByRole("button", { name: "Istisno qo‘shish", exact: true })
    .click();
  await screenshot(page, "06-exception-dialog");
  await page.keyboard.press("Escape");
  expect(
    requests.filter((r) => r.startsWith("POST") || r.startsWith("PATCH")),
  ).toEqual([]);
  expect(errors).toEqual([]);
});
test("search, every filter, paging, inactive access and non-doctor empty schedule", async ({
  page,
}) => {
  await setup(page);
  await expect(page.locator(".employee-list tbody tr")).toHaveCount(9);
  await page.getByRole("button", { name: "Keyingi sahifa" }).click();
  await expect(page.locator(".e-pagination")).toContainText("10 – 18");
  await page.getByLabel("Xodimlarni qidirish").fill("Aziz");
  await expect(page.locator(".employee-list tbody tr")).toHaveCount(1);
  await page.getByLabel("Xodimlarni qidirish").fill("998900000101");
  await expect(page.locator(".employee-list tbody")).toContainText("Aziz");
  await page.getByLabel("Xodimlarni qidirish").fill("NO MATCH");
  await expect(page.locator("tbody")).toContainText("Xodim topilmadi");
  await page.getByLabel("Xodimlarni qidirish").fill("");
  await page
    .getByLabel("Lavozim bo‘yicha filtr")
    .selectOption("Tez yordam haydovchisi");
  await expect(page.locator(".e-pagination")).toContainText("/ 2 xodim");
  await page.getByLabel("Lavozim bo‘yicha filtr").selectOption("");
  await page.getByLabel("Filial bo‘yicha filtr").selectOption("Yunusobod");
  await expect(page.locator(".e-pagination")).toContainText("/ 8 xodim");
  await page.getByLabel("Filial bo‘yicha filtr").selectOption("");
  await page.getByLabel("Holat bo‘yicha filtr").selectOption("false");
  await expect(page.locator("tbody tr")).toHaveCount(2);
  await expect(page.locator("tbody tr").first()).toContainText("Ruxsat yo‘q");
  await page.getByLabel("Holat bo‘yicha filtr").selectOption("");
  await profile(page, "Rustam Qodirov");
  await expect(
    page.getByText("Tizimga kirish ruxsati berilmagan", { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Kasbiy ma’lumotlar" }),
  ).toHaveCount(0);
  await page.getByRole("tab", { name: "Ish jadvali", exact: true }).click();
  await expect(
    page.getByText("Ish jadvali hali yaratilmagan.", { exact: false }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Jadval yaratish", exact: true })
    .click();
  await expect(
    page.getByRole("columnheader", { name: "Kabinet", exact: true }),
  ).toHaveCount(0);
  await page.keyboard.press("Escape");
});
test("employee validation, local photo, access toggle, doctor fields, editing and no password storage", async ({
  page,
}) => {
  const { requests, errors } = await setup(page);
  await page
    .getByRole("button", { name: "Xodim qo‘shish", exact: true })
    .click();
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.locator(".e-error")).not.toHaveCount(0);
  await page.getByLabel("Familiya", { exact: false }).fill("Namunov");
  await page.getByLabel("Ism *", { exact: true }).fill("Namuna");
  await page.getByLabel("Telefon", { exact: false }).fill("+998 90 000 99 99");
  await page.locator("input[type=file]").setInputFiles({
    name: "avatar.png",
    mimeType: "image/png",
    buffer: Buffer.from(
      "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=",
      "base64",
    ),
  });
  await expect(page.getByAltText("Xodim surati")).toBeVisible();
  await page.getByLabel("Lavozim *", { exact: true }).selectOption("Shifokor");
  await expect(
    page.getByLabel("Mutaxassislik", { exact: false }),
  ).toBeVisible();
  await page.getByLabel("Mutaxassislik", { exact: false }).fill("Nevrolog");
  await page
    .getByRole("switch", { name: "Tizimga kirishga ruxsat berish" })
    .click();
  await expect(page.getByLabel("Login *", { exact: true })).toHaveCount(0);
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await page.getByLabel("Xodimlarni qidirish").fill("Namuna");
  await expect(page.locator("tbody")).toContainText("Namuna Namunov");
  await profile(page, "Namuna Namunov");
  await expect(
    page.getByText("Nevrolog", { exact: true }).first(),
  ).toBeVisible();
  await page.getByRole("button", { name: "Tahrirlash", exact: true }).click();
  await expect(page.getByLabel("Familiya", { exact: false })).toHaveValue(
    "Namunov",
  );
  await page.getByLabel("Familiya", { exact: false }).fill("O‘zgartirilgan");
  await page
    .getByRole("switch", { name: "Tizimga kirishga ruxsat berish" })
    .click();
  await page.getByLabel("Login *", { exact: true }).fill("demo.new");
  await page
    .getByLabel("Ruxsat roli *", { exact: true })
    .selectOption("Shifokor");
  await page
    .getByRole("button", { name: "Parol yaratish", exact: true })
    .click();
  expect(
    (await page.getByLabel("Demo parol", { exact: true }).inputValue()).length,
  ).toBe(16);
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.locator("tbody")).toContainText("O‘zgartirilgan");
  expect(
    await page.evaluate(() =>
      JSON.stringify({ ...localStorage, ...sessionStorage }),
    ),
  ).not.toContain("demo.new");
  expect(requests.some((r) => r.includes("/employees"))).toBe(false);
  expect(errors).toEqual([]);
  await page.reload();
  await page.getByLabel("Xodimlarni qidirish").fill("Namuna");
  await expect(page.locator("tbody")).toContainText("Xodim topilmadi");
});
test("schedule copy, additional intervals, validation and exception editing without duplicates", async ({
  page,
}) => {
  await setup(page);
  await profile(page);
  await page.getByRole("tab", { name: "Ish jadvali", exact: true }).click();
  await page
    .getByRole("button", { name: "Jadvalni tahrirlash", exact: true })
    .click();
  const d = page.getByRole("dialog");
  await d
    .getByRole("button", { name: "Dushanba jadvalidan nusxalash", exact: true })
    .click();
  await d
    .getByRole("group", { name: "Nusxalash kunlari" })
    .getByLabel("Chorshanba", { exact: true })
    .check();
  await d.getByRole("button", { name: "Qo‘llash", exact: true }).click();
  await d
    .getByRole("button", { name: "Vaqt oralig‘i qo‘shish", exact: true })
    .click();
  await d.getByRole("button", { name: "Qo‘shish", exact: true }).click();
  const extra = d.locator("tbody tr").nth(1);
  await extra.getByLabel("Ish boshlanishi", { exact: true }).fill("10:00");
  await extra.getByLabel("Ish tugashi", { exact: true }).fill("11:00");
  await d
    .getByRole("button", { name: "O‘zgarishlarni saqlash", exact: true })
    .click();
  await expect(d.getByRole("alert")).toContainText("to‘qnashmoqda");
  await d
    .getByRole("button", { name: "Vaqt oralig‘ini olib tashlash" })
    .click();
  await d
    .getByRole("button", { name: "O‘zgarishlarni saqlash", exact: true })
    .click();
  await expect(d).toBeHidden();
  await page
    .getByRole("button", { name: "Istisno qo‘shish", exact: true })
    .click();
  let ex = page.getByRole("dialog");
  await ex.getByLabel("Boshlanish sanasi", { exact: false }).fill("2026-11-05");
  await ex.getByLabel("Tugash sanasi", { exact: false }).fill("2026-11-04");
  await ex.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(ex.getByRole("alert")).toContainText("Sanalarni");
  await ex.getByLabel("Tugash sanasi", { exact: false }).fill("2026-11-10");
  await ex.getByLabel("Ish vaqti o‘zgaradi", { exact: true }).check();
  await expect(ex.getByRole("columnheader", { name: "Kabinet" })).toBeVisible();
  await ex.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(ex).toBeHidden();
  const rows = page.locator(".schedule-display").nth(1).locator("tbody tr");
  await expect(rows).toHaveCount(3);
  await page
    .getByRole("button", { name: "05.11.2026 istisnosini tahrirlash" })
    .click();
  ex = page.getByRole("dialog");
  await expect(ex.getByLabel("Tugash sanasi", { exact: false })).toHaveValue(
    "2026-11-10",
  );
  await ex
    .getByLabel("Izoh (ixtiyoriy)", { exact: true })
    .fill("Yangilangan demo izoh");
  await ex.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(rows).toHaveCount(3);
  await expect(rows.last()).toContainText("Yangilangan");
});
test("mobile width stays within viewport and tables scroll locally", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await setup(page);
  await expect(
    page.getByRole("heading", { name: "Xodimlar", exact: true }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  await profile(page);
  await page.getByRole("tab", { name: "Ish jadvali", exact: true }).click();
  await page
    .getByRole("button", { name: "Jadvalni tahrirlash", exact: true })
    .click();
  expect(
    await page
      .getByRole("dialog")
      .evaluate((el) => el.getBoundingClientRect().right <= innerWidth),
  ).toBe(true);
  await page.keyboard.press("Escape");
});
test("existing logout and login/CSRF wiring survives Employees navigation (network fixtures)", async ({
  page,
}) => {
  const { requests } = await setup(page);
  await page.getByRole("button", { name: "Profil menyusi" }).click();
  await page
    .getByRole("button", { name: "Tizimdan chiqish", exact: true })
    .click();
  await expect(page.getByLabel("Login", { exact: true })).toBeVisible();
  await page.getByLabel("Login", { exact: true }).fill("demo.ceo");
  await page
    .getByLabel("Parol", { exact: true })
    .fill("Synthetic-only-test-input");
  await page.getByRole("button", { name: "Kirish", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Xodimlar", exact: true }),
  ).toBeVisible();
  expect(requests).toContain("GET /sanctum/csrf-cookie");
  expect(requests).toContain("POST /api/v1/auth/login");
  expect(requests).toContain("POST /api/v1/auth/logout");
});
for (const kind of ["platform", "temporary"] as const)
  test(`${kind} gate still blocks Employees`, async ({ page }) => {
    await setup(page, kind);
    await expect(page.locator(".employees-ui")).toHaveCount(0);
  });
