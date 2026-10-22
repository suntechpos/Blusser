import { test, expect, type Page } from "@playwright/test";
import { mkdir } from "node:fs/promises";
import { receptionSession } from "./reception.fixture";
test.use({ viewport: { width: 1536, height: 1024 } });
const editor = (p: Page) => p.getByRole("region", { name: "Rol sozlamalari" });
const dlg = (p: Page) => p.getByRole("dialog");
async function open(p: Page) {
  const requests = await receptionSession(p);
  await p.getByRole("button", { name: "Sozlamalar", exact: true }).click();
  return requests;
}
async function role(p: Page, name: string) {
  await p
    .locator(".sr-role")
    .filter({ has: p.getByText(name, { exact: true }) })
    .click();
}
async function create(p: Page, name = "Yordamchi") {
  await p.getByRole("button", { name: "Rol qo‘shish", exact: true }).click();
  await p.getByLabel("Rol nomi", { exact: true }).fill(name);
  await p.getByRole("button", { name: /^Bemorlar \d/ }).click();
  await p
    .getByRole("checkbox", { name: "Bemorlarni ko‘rish", exact: true })
    .check();
  await p.getByRole("button", { name: "Rolni yaratish", exact: true }).click();
}
async function state(p: Page) {
  return p.evaluate(() =>
    JSON.parse(
      localStorage.getItem(
        Object.keys(localStorage).find((k) =>
          k.startsWith("blusser.settings.roles.demo.v1."),
        )!,
      )!,
    ),
  );
}
async function shot(p: Page, name: string) {
  await mkdir("/tmp/blusser-settings-screenshots", { recursive: true });
  await p.screenshot({
    path: `/tmp/blusser-settings-screenshots/${name}.png`,
    fullPage: true,
  });
}
test("reference, tabs, search, independent catalog and zero-count roles", async ({
  page,
}) => {
  const req = await open(page);
  await expect(page.getByLabel("Rol nomi", { exact: true })).toHaveValue(
    "Kassir",
  );
  await expect(page.getByRole("checkbox", { checked: true })).toHaveCount(4);
  await shot(page, "01-roles");
  await expect(page.locator(".sr-role")).toHaveCount(6);
  await page.getByLabel("Rolni qidirish").fill("yoqnom");
  await expect(page.getByText(/Rol topilmadi/)).toBeVisible();
  await page.getByLabel("Rolni qidirish").fill("labor");
  await expect(page.locator(".sr-role")).toHaveCount(1);
  await page.getByLabel("Rolni qidirish").fill("");
  for (const tab of ["Umumiy", "To‘lovlar"]) {
    await page.getByRole("button", { name: tab, exact: true }).click();
    await expect(page.getByText("Tez orada", { exact: true })).toBeVisible();
    await expect(page.locator(".sr-editor")).toHaveCount(0);
  }
  await page
    .getByRole("button", { name: "Rollar va ruxsatlar", exact: true })
    .click();
  await role(page, "Laborant");
  await expect(editor(page)).toContainText("0 ta xodim");
  await page.getByRole("button", { name: /^Laboratoriya \d/ }).click();
  await expect(
    page.getByRole("checkbox", { name: "Laboratoriyani ko‘rish" }),
  ).toBeChecked();
  await page.getByRole("button", { name: /^UZI va Diagnostika \d/ }).click();
  await expect(
    page.getByRole("checkbox", { name: "Diagnostikani ko‘rish" }),
  ).not.toBeChecked();
  expect(req.filter((r) => /roles|permissions|employees/.test(r.path))).toEqual(
    [],
  );
});
test("inline creation validates name, useful permission, duplicates, delete and reload", async ({
  page,
}) => {
  await open(page);
  await page.getByRole("button", { name: "Rol qo‘shish", exact: true }).click();
  await expect(dlg(page)).toHaveCount(0);
  await expect(editor(page)).toContainText("Yangi rol");
  await page.getByRole("button", { name: "Rolni yaratish" }).click();
  await expect(page.getByRole("alert")).toContainText("nomi");
  await page.getByLabel("Rol nomi", { exact: true }).fill("  kaSSir  ");
  await page.getByRole("button", { name: "Rolni yaratish" }).click();
  await expect(page.getByRole("alert")).toContainText("takrorlangan");
  await page.getByLabel("Rol nomi", { exact: true }).fill("Yordamchi");
  await page.getByRole("button", { name: "Rolni yaratish" }).click();
  await expect(page.getByRole("alert")).toContainText("foydali");
  await page.getByRole("button", { name: /^Bemorlar \d/ }).click();
  await page
    .getByRole("checkbox", { name: "Bemorlarni ko‘rish", exact: true })
    .check();
  await page.getByRole("button", { name: "Rolni yaratish" }).click();
  await expect(page.getByRole("status")).toContainText("saqlandi");
  await page.reload();
  await role(page, "Yordamchi");
  await expect(page.getByLabel("Rol nomi", { exact: true })).toHaveValue(
    "Yordamchi",
  );
  await page.getByRole("button", { name: "Rol amallari" }).click();
  await page
    .getByRole("button", { name: "Rolni o‘chirish", exact: true })
    .click();
  await dlg(page)
    .getByRole("button", { name: "Bekor qilish", exact: true })
    .click();
  await expect(page.locator(".sr-role")).toHaveCount(7);
  await page.getByRole("button", { name: "Rol amallari" }).click();
  await page
    .getByRole("button", { name: "Rolni o‘chirish", exact: true })
    .click();
  await dlg(page)
    .getByRole("button", { name: "O‘chirishni tasdiqlash" })
    .click();
  await expect(page.locator(".sr-role")).toHaveCount(6);
});
test("drafts, view dependencies, assigned-role confirmation and stable rename persist", async ({
  page,
}) => {
  await open(page);
  await page
    .getByRole("checkbox", { name: "Kassani ko‘rish", exact: true })
    .uncheck();
  await expect(page.getByRole("checkbox", { checked: true })).toHaveCount(0);
  await expect(
    page.getByRole("checkbox", { name: "To‘lov qabul qilish", exact: true }),
  ).toBeDisabled();
  await page.getByRole("button", { name: "Bekor qilish", exact: true }).click();
  await expect(page.getByRole("checkbox", { checked: true })).toHaveCount(4);
  await page
    .getByRole("checkbox", { name: "Pul qaytarish", exact: true })
    .check();
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(dlg(page)).toContainText("barcha 2 ta xodim");
  await dlg(page)
    .getByRole("button", { name: "Bekor qilish", exact: true })
    .click();
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await dlg(page).getByRole("button", { name: "Saqlashni tasdiqlash" }).click();
  const before = await state(page);
  await page.getByLabel("Rol nomi", { exact: true }).fill("Hisobchi");
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  const after = await state(page);
  expect(after.employees.map((e: any) => e.roleId)).toEqual(
    before.employees.map((e: any) => e.roleId),
  );
  await page.reload();
  await role(page, "Hisobchi");
  await expect(
    page.getByRole("checkbox", { name: "Pul qaytarish", exact: true }),
  ).toBeChecked();
});
test("unsaved role/tab/sidebar/hash guards, cancel creation, focus and small viewport", async ({
  page,
}) => {
  await open(page);
  await page.getByLabel("Rol nomi", { exact: true }).fill("Draft");
  await role(page, "Registrator");
  await expect(dlg(page)).toBeVisible();
  await dlg(page).getByRole("button", { name: "Shu yerda qolish" }).click();
  await expect(page.getByLabel("Rol nomi", { exact: true })).toHaveValue(
    "Draft",
  );
  await page.getByRole("button", { name: "Umumiy", exact: true }).click();
  await dlg(page)
    .getByRole("button", { name: "O‘zgarishlarni bekor qilib o‘tish" })
    .click();
  await expect(page.getByText("Tez orada", { exact: true })).toBeVisible();
  await page
    .getByRole("button", { name: "Rollar va ruxsatlar", exact: true })
    .click();
  await expect(page.getByLabel("Rol nomi", { exact: true })).toHaveValue(
    "Kassir",
  );
  await page.getByLabel("Izoh", { exact: true }).fill("Draft");
  await page.getByRole("button", { name: "Kassa", exact: true }).click();
  await dlg(page).getByRole("button", { name: "Shu yerda qolish" }).click();
  await page.evaluate(() => (location.hash = "bemorlar"));
  await expect(dlg(page)).toBeVisible();
  await dlg(page).getByRole("button", { name: "Shu yerda qolish" }).focus();
  await page.keyboard.press("Shift+Tab");
  await expect(dlg(page).getByRole("button", { name: "Yopish" })).toBeFocused();
  await page.keyboard.press("Escape");
  await page.getByRole("button", { name: "Bekor qilish", exact: true }).click();
  await page.getByRole("button", { name: "Rol qo‘shish", exact: true }).click();
  await page.getByRole("button", { name: "Bekor qilish", exact: true }).click();
  await expect(page.getByLabel("Rol nomi", { exact: true })).toHaveValue(
    "Kassir",
  );
  await page.setViewportSize({ width: 760, height: 900 });
  await shot(page, "02-small");
  await page.setViewportSize({ width: 390, height: 844 });
  expect(
    await page
      .locator(".settings-roles-ui")
      .evaluate((e) => e.scrollWidth <= e.clientWidth + 1),
  ).toBe(true);
  await shot(page, "03-mobile");

  expect(
    await page
      .locator(".settings-roles-ui")
      .evaluate((e) => e.scrollWidth <= e.clientWidth + 1),
  ).toBe(true);
});
test("delete restrictions, protected management, no live sidebar enforcement", async ({
  page,
}) => {
  await open(page);
  await page.getByRole("button", { name: "Rol amallari" }).click();
  await page
    .getByRole("button", { name: "Rolni o‘chirish", exact: true })
    .click();
  await expect(dlg(page)).toContainText("Avval xodimlarga boshqa rol");
  await expect(
    dlg(page).getByRole("button", { name: "O‘chirishni tasdiqlash" }),
  ).toHaveCount(0);
  await page.keyboard.press("Escape");
  await role(page, "Administrator");
  await page.getByRole("button", { name: "Rol amallari" }).click();
  await page
    .getByRole("button", { name: "Rolni o‘chirish", exact: true })
    .click();
  await expect(dlg(page)).toContainText("Himoyalangan");
  await page.keyboard.press("Escape");
  await page.getByRole("button", { name: /^Sozlamalar — rollar \d/ }).click();
  await page
    .getByRole("checkbox", { name: "Ruxsatlarni boshqarish", exact: true })
    .uncheck();
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByRole("alert")).toContainText("Himoyalangan");
  await expect(
    page.getByRole("button", { name: "Bemorlar", exact: true }),
  ).toBeVisible();
});
test("single employee role source, new role, stable assignment/rename, job independence and persisted schedule", async ({
  page,
}) => {
  await open(page);
  await create(page);
  const data = await state(page);
  const id = data.roles.find((r: any) => r.name === "Yordamchi").id;
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page
    .getByRole("row", { name: "Sardor Usmonov profilini ochish" })
    .click();
  await page.getByRole("button", { name: "Tahrirlash", exact: true }).click();
  const select = page.getByLabel("Ruxsat roli *", { exact: true });
  await expect(select.locator("option", { hasText: "Yordamchi" })).toHaveCount(
    1,
  );
  await expect(select.locator("option", { hasText: "Qorovul" })).toHaveCount(0);
  await select.selectOption(id);
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await page.getByRole("button", { name: "Sozlamalar", exact: true }).click();
  await role(page, "Yordamchi");
  await expect(editor(page)).toContainText("1 ta xodim");
  await page.getByLabel("Rol nomi", { exact: true }).fill("Yangi nom");
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await page.reload();
  await role(page, "Yangi nom");
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page
    .getByRole("row", { name: "Sardor Usmonov profilini ochish" })
    .click();
  await expect(page.locator(".employees-ui")).toContainText("Yangi nom");
  await page.getByRole("button", { name: "Tahrirlash", exact: true }).click();
  await expect(select).toHaveValue(id);
  await expect(page.getByLabel("Lavozim *", { exact: true })).toHaveValue(
    "Registrator",
  );
});
test("failed write preserves published roles and draft; no extra APIs", async ({
  page,
}) => {
  const req = await open(page);
  await page.evaluate(() => {
    Storage.prototype.setItem = () => {
      throw Error("quota test");
    };
  });
  await page.getByLabel("Rol nomi", { exact: true }).fill("Saqlanmadi");
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByRole("alert")).toContainText("quota");
  await expect(
    page.locator(".sr-role").filter({ hasText: "Kassir" }),
  ).toHaveCount(1);
  await expect(page.getByLabel("Rol nomi", { exact: true })).toHaveValue(
    "Saqlanmadi",
  );
  expect(req.some((r) => /roles|permissions|employees/.test(r.path))).toBe(
    false,
  );
});

test("last active administrator cannot lose access or be reassigned", async ({
  page,
}) => {
  await open(page);
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page.getByLabel("Xodimlarni qidirish").fill("Zarina");
  await page
    .getByRole("row", { name: "Zarina Qodirova profilini ochish" })
    .click();
  await page.getByRole("button", { name: "Tahrirlash", exact: true }).click();
  await page
    .getByLabel("Ruxsat roli *", { exact: true })
    .selectOption("cashier");
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByRole("alert")).toContainText("boshqaruvchi");
  await page
    .getByLabel("Ruxsat roli *", { exact: true })
    .selectOption("administrator");
  await page
    .getByRole("switch", { name: "Tizimga kirishga ruxsat berish" })
    .click();
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByRole("alert")).toContainText("boshqaruvchi");
  await page.getByRole("button", { name: "Bekor qilish", exact: true }).click();
  await page
    .getByRole("row", { name: "Zarina Qodirova profilini ochish" })
    .click();
  await expect(page.locator(".employees-ui")).toContainText("Administrator");
});

test("a later role matching an unassigned job is never automatically assigned", async ({
  page,
}) => {
  await open(page);
  await create(page, "Qorovul");
  await expect(editor(page)).toContainText("0 ta xodim");
  await page.reload();
  await role(page, "Qorovul");
  await expect(editor(page)).toContainText("0 ta xodim");
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page.getByLabel("Xodimlarni qidirish").fill("Rustam");
  await page
    .getByRole("row", { name: "Rustam Qodirov profilini ochish" })
    .click();
  await page.getByRole("button", { name: "Tahrirlash", exact: true }).click();
  await page
    .getByRole("switch", { name: "Tizimga kirishga ruxsat berish" })
    .click();
  await expect(page.getByLabel("Ruxsat roli *", { exact: true })).toHaveValue(
    "",
  );
  await page.getByLabel("Login *", { exact: true }).fill("demo.guard");
  await page
    .getByRole("button", { name: "Parol yaratish", exact: true })
    .click();
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.locator(".e-error")).toContainText("Ruxsat rolini tanlang");
});

test("parent page view clears and disables related action groups without restoring old grants", async ({
  page,
}) => {
  await open(page);
  await role(page, "Registrator");
  await page.getByRole("button", { name: /^Registratura \d/ }).click();
  await page.getByRole("button", { name: /^Registratura amallari \d/ }).click();
  await expect(
    page.getByRole("checkbox", {
      name: "Yozilish va navbatsiz qabul",
      exact: true,
    }),
  ).toBeChecked();
  await page
    .getByRole("checkbox", { name: "Registraturani ko‘rish", exact: true })
    .uncheck();
  await expect(
    page.getByRole("checkbox", {
      name: "Registratura amallarini ko‘rish",
      exact: true,
    }),
  ).toBeDisabled();
  await expect(
    page.getByRole("checkbox", {
      name: "Yozilish va navbatsiz qabul",
      exact: true,
    }),
  ).not.toBeChecked();
  await page
    .getByRole("checkbox", { name: "Registraturani ko‘rish", exact: true })
    .check();
  await expect(
    page.getByRole("checkbox", {
      name: "Registratura amallarini ko‘rish",
      exact: true,
    }),
  ).not.toBeChecked();
  await expect(
    page.getByRole("checkbox", {
      name: "Yozilish va navbatsiz qabul",
      exact: true,
    }),
  ).toBeDisabled();
});
