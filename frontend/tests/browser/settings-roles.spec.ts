import { test, expect, type Page } from "@playwright/test";
import { editorSession } from "./access-editor.fixture";
test.use({ viewport: { width: 1536, height: 1024 } });
async function open(page: Page) {
  const state = await editorSession(page);
  await page.getByRole("button", { name: "Sozlamalar", exact: true }).click();
  await expect(page.locator(".sr-role")).toHaveCount(6);
  return state;
}
async function role(page: Page, name: string) {
  await page
    .locator(".sr-role")
    .filter({ has: page.getByText(name, { exact: true }) })
    .click();
}
async function create(page: Page, name = "API Yordamchi") {
  await page.getByRole("button", { name: "Rol qo‘shish", exact: true }).click();
  await page.getByLabel("Rol nomi", { exact: true }).fill(name);
  await page.getByRole("button", { name: /^Bemorlar \d/ }).click();
  await page
    .getByRole("checkbox", { name: "Bemorlarni ko‘rish", exact: true })
    .check();
  await page
    .getByLabel("Bemorlarni ko‘rish doirasi", { exact: true })
    .selectOption("assigned_branches");
  await page
    .getByRole("button", { name: "Rolni yaratish", exact: true })
    .click();
  await expect(page.getByRole("status")).toContainText("serverda saqlandi");
}
test("server catalog, role counts, protected editor, search and upcoming tabs", async ({
  page,
}) => {
  const s = await open(page);
  await role(page, "Administrator");
  await expect(page.getByLabel("Rol nomi", { exact: true })).toBeDisabled();
  await page.getByLabel("Rolni qidirish").fill("labor");
  await expect(page.locator(".sr-role")).toHaveCount(1);
  await page.getByLabel("Rolni qidirish").fill("");
  for (const tab of ["Umumiy", "To‘lovlar"]) {
    await page.getByRole("button", { name: tab, exact: true }).click();
    await expect(page.getByText("Tez orada", { exact: true })).toBeVisible();
  }
  expect(s.requests.some((r) => r.path.endsWith("/permissions"))).toBe(true);
});
test("API role create, scope, note, reload and confirmed delete", async ({
  page,
}) => {
  const s = await open(page);
  await create(page);
  const saved = s.roles.find((r) => r.name === "API Yordamchi")!;
  expect(saved.grants).toEqual([
    { code: "patients.view", scope: "assigned_branches" },
  ]);
  expect(
    s.requests.find((r) => r.method === "POST" && r.path.endsWith("/roles"))
      ?.headers["idempotency-key"],
  ).toBeTruthy();
  await page.reload();
  await page.getByRole("button", { name: "Sozlamalar", exact: true }).click();
  await role(page, "API Yordamchi");
  await page.getByRole("button", { name: "Rol amallari" }).click();
  await page
    .getByRole("button", { name: "Rolni o‘chirish", exact: true })
    .click();
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "O‘chirishni tasdiqlash" })
    .click();
  await expect(page.locator(".sr-role")).toHaveCount(6);
  expect(s.roles.some((r) => r.id === saved.id)).toBe(false);
});
test("view dependency removes actions and cancel restores server grants", async ({
  page,
}) => {
  await open(page);
  await role(page, "Kassir");
  await page
    .getByRole("checkbox", { name: "Kassani ko‘rish", exact: true })
    .uncheck();
  await expect(
    page.getByRole("checkbox", { name: "To‘lov qabul qilish", exact: true }),
  ).toBeDisabled();
  await expect(page.getByRole("checkbox", { checked: true })).toHaveCount(0);
  await page.getByRole("button", { name: "Bekor qilish", exact: true }).click();
  await expect(
    page.getByRole("checkbox", { name: "To‘lov qabul qilish", exact: true }),
  ).toBeChecked();
});
test("stale API version preserves draft and sends If-Match; unsaved navigation is guarded", async ({
  page,
}) => {
  const s = await open(page);
  await role(page, "Kassir");
  await page.getByLabel("Izoh", { exact: true }).fill("Retained API draft");
  s.roles.find((r) => r.id === "cashier")!.version++;
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  const confirm = page
    .getByRole("dialog")
    .getByRole("button", { name: "Saqlashni tasdiqlash" });
  if (await confirm.count()) await confirm.click();
  await expect(page.getByRole("alert")).toContainText("Ma’lumot o‘zgargan");
  await expect(page.getByLabel("Izoh", { exact: true })).toHaveValue(
    "Retained API draft",
  );
  expect(s.requests.find((r) => r.method === "PUT")?.headers["if-match"]).toBe(
    '"1"',
  );
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await expect(page.getByRole("dialog")).toBeVisible();
});

test("server field validation is displayed beside note and retains draft", async ({
  page,
}) => {
  await open(page);
  await role(page, "Kassir");
  await page.route("**/api/v1/clinic/roles/cashier", (route) =>
    route.request().method() === "PUT"
      ? route.fulfill({
          status: 422,
          json: { errors: { note: ["Server note validation"] } },
        })
      : route.fallback(),
  );
  await page.getByLabel("Izoh", { exact: true }).fill("Retained invalid note");
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.locator("#role-note-error")).toContainText(
    "Server note validation",
  );
  await expect(page.getByLabel("Izoh", { exact: true })).toHaveValue(
    "Retained invalid note",
  );
});

test("new role has no protected state and reception explains shared action grants", async ({
  page,
}) => {
  await open(page);
  await role(page, "Administrator");
  await expect(
    page.getByText(
      "Himoyalangan tizim roli: tahrirlash va o‘chirish bloklangan.",
    ),
  ).toBeVisible();
  await page.getByRole("button", { name: "Rol qo‘shish", exact: true }).click();
  await expect(
    page.getByText(
      "Himoyalangan tizim roli: tahrirlash va o‘chirish bloklangan.",
    ),
  ).toHaveCount(0);
  await expect(page.getByLabel("Rol nomi", { exact: true })).toHaveValue("");
  await expect(page.getByLabel("Rol nomi", { exact: true })).toBeEnabled();
  await page.getByRole("button", { name: /^Registratura \d/ }).click();
  const help = page.getByLabel("Registratura amallari bog‘liqligi");
  await expect(help).toContainText("appointments.create");
  await expect(help).toContainText("patients.create");
  await expect(help).toContainText("appointments.check_in");
  await expect(help).toContainText("visits.create");
  await expect(help).toContainText("queues.create");
  await expect(help).toContainText("Qabullar →");
  await expect(help).toContainText("Bemorlar →");
  await expect(help).toContainText("Navbatlar →");
  expect(
    await help.evaluate(
      (el) =>
        el.getBoundingClientRect().width /
        el.parentElement!.getBoundingClientRect().width,
    ),
  ).toBeGreaterThan(0.8);
  expect(await help.evaluate((el) => el.scrollWidth <= el.clientWidth)).toBe(
    true,
  );
});
