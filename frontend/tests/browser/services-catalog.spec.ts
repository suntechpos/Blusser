import { test, expect, type Page } from "@playwright/test";
import { mkdir, readFile } from "node:fs/promises";
import { receptionSession } from "./reception.fixture";
test.use({ viewport: { width: 1536, height: 1024 } });
async function open(page: Page) {
  const requests = await receptionSession(page);
  await page.getByRole("button", { name: "Xizmatlar", exact: true }).click();
  return requests;
}
const dialog = (page: Page) => page.getByRole("dialog");
async function createService(page: Page) {
  await page
    .getByRole("button", { name: "Xizmat qo‘shish", exact: true })
    .first()
    .click();
}
async function save(page: Page) {
  await dialog(page)
    .getByRole("button", { name: "Saqlash", exact: true })
    .click();
}
async function shot(page: Page, name: string) {
  await mkdir("/tmp/blusser-services-screenshots", { recursive: true });
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({
    path: `/tmp/blusser-services-screenshots/${name}.png`,
  });
}
test("three reference screenshots and catalog counts; shared shell; no API operations", async ({
  page,
}) => {
  const requests = await open(page);
  await expect(page.locator(".sv-stats")).toContainText("24");
  await expect(page.locator(".sv-stats")).toContainText("22");
  await expect(page.locator(".sv-tabs button")).toHaveCount(6);
  await expect(page.locator(".sv-page tbody tr")).toHaveCount(10);
  await expect(page.locator(".clinic-demo-note")).toHaveCount(0);
  await shot(page, "01-services");
  await page
    .getByRole("button", { name: "Kategoriya qo‘shish", exact: true })
    .click();
  await shot(page, "02-category");
  await dialog(page)
    .getByRole("button", { name: "Bekor qilish", exact: true })
    .click();
  await createService(page);
  await shot(page, "03-service");
  await page.keyboard.press("Escape");
  expect(
    requests.filter(
      (r) => r.path.includes("/clinic/") && !r.path.includes("/context"),
    ),
  ).toEqual([]);
});
test("category required/duplicate, trim and color, manager edit, inactive category preserves historical services", async ({
  page,
}) => {
  await open(page);
  await page
    .getByRole("button", { name: "Kategoriya qo‘shish", exact: true })
    .click();
  await save(page);
  await expect(
    dialog(page).getByText("Kategoriya nomini kiriting.", { exact: true }),
  ).toBeVisible();
  await dialog(page)
    .getByLabel("Kategoriya nomi", { exact: true })
    .fill("  laboratoriya  ");
  await save(page);
  await expect(dialog(page)).toContainText("Bu nomli kategoriya mavjud.");
  await dialog(page)
    .getByLabel("Kategoriya nomi", { exact: true })
    .fill("  Yangi guruh  ");
  await dialog(page).getByRole("button", { name: "Rang: Binafsha" }).click();
  await expect(
    dialog(page).locator(".sv-category-preview .sv-category"),
  ).toHaveClass(/purple/);
  await save(page);
  await expect(page.locator(".sv-tabs")).toContainText("Yangi guruh");
  await page
    .getByRole("button", { name: "Kategoriyalarni boshqarish" })
    .click();
  await dialog(page)
    .getByRole("button", { name: "Shifokor qabuli kategoriyasini tahrirlash" })
    .click();
  await dialog(page)
    .getByRole("switch", { name: "Kategoriya faol holati" })
    .click();
  await save(page);
  await expect(dialog(page)).toHaveAttribute("aria-label", "Kategoriyalar");
  await dialog(page)
    .getByRole("button", { name: "Yopish", exact: true })
    .last()
    .click();
  await expect(page.locator(".sv-stats article").nth(1)).toContainText("22");
  await expect(page.locator(".sv-page tbody")).toContainText(
    "Kardiolog konsultatsiyasi",
  );
  await createService(page);
  await expect(
    dialog(page)
      .getByLabel("Kategoriya", { exact: true })
      .locator('option[value="cat-1"]'),
  ).toHaveCount(0);
  await page.keyboard.press("Escape");
  await page.getByLabel("XZ-001 tahrirlash").click();
  await expect(
    dialog(page).getByLabel("Kategoriya", { exact: true }),
  ).toHaveValue("cat-1");
  await dialog(page)
    .getByLabel("Xizmat nomi", { exact: true })
    .fill("Tarixiy xizmat");
  await save(page);
  await expect(page.locator(".sv-page tbody")).toContainText("Tarixiy xizmat");
});
test("service validation, branch selection, functional type and separate lab turnaround; create/view/edit retains code", async ({
  page,
}) => {
  const requests = await open(page);
  await createService(page);
  await save(page);
  await expect(dialog(page)).toContainText("Xizmat nomini kiriting.");
  await expect(dialog(page)).toContainText("Narxni manfiy");
  await dialog(page)
    .getByLabel("Xizmat nomi", { exact: true })
    .fill("Namuna laboratoriya");
  await dialog(page).getByLabel("Narxi", { exact: true }).fill("-1");
  await dialog(page)
    .getByLabel("Xizmat turi", { exact: true })
    .selectOption("laboratory");
  await expect(
    dialog(page).getByLabel("Davomiyligi", { exact: true }),
  ).toHaveCount(0);
  await expect(
    dialog(page).getByRole("switch", { name: "Vaqt bo‘yicha yozilish" }),
  ).toHaveAttribute("aria-checked", "false");
  await dialog(page)
    .getByLabel("Natija tayyor bo‘lish muddati", { exact: true })
    .fill("0");
  await save(page);
  await expect(dialog(page)).toContainText("Musbat natija muddatini");
  await dialog(page).getByLabel("Narxi", { exact: true }).fill("0");
  await dialog(page)
    .getByLabel("Natija tayyor bo‘lish muddati", { exact: true })
    .fill("2");
  await dialog(page).getByLabel("Muddat birligi").selectOption("day");
  await dialog(page)
    .getByRole("button", { name: "Asosiy filialni olib tashlash" })
    .click();
  await save(page);
  await expect(dialog(page)).toContainText("Kamida bitta filial");
  await dialog(page)
    .getByRole("button", { name: "Filiallarni tanlash" })
    .click();
  await dialog(page)
    .getByRole("checkbox", { name: "Andijon filiali", exact: true })
    .check();
  await dialog(page)
    .getByRole("checkbox", { name: "Yunusobod", exact: true })
    .check();
  await save(page);
  await expect(dialog(page)).toHaveCount(0);
  await expect(page.locator(".sv-page tbody")).toContainText(
    "Namuna laboratoriya",
  );
  await expect(page.locator(".sv-stats")).toContainText("25");
  await page.getByLabel("XZ-025 ko‘rish").click();
  await expect(dialog(page)).toContainText("2 kun");
  await expect(dialog(page)).toContainText("0 so‘m");
  await expect(dialog(page)).toContainText("Andijon filiali, Yunusobod");
  await expect(dialog(page)).toContainText("Yo‘q");
  await dialog(page)
    .getByRole("button", { name: "Tahrirlash", exact: true })
    .click();
  await expect(dialog(page).getByLabel("Xizmat kodi")).toHaveValue("XZ-025");
  await expect(dialog(page).getByLabel("Xizmat kodi")).toHaveAttribute(
    "readonly",
    "",
  );
  await dialog(page).getByLabel("Xizmat turi").selectOption("consultation");
  await dialog(page).getByLabel("Davomiyligi", { exact: true }).fill("");
  await save(page);
  await expect(dialog(page)).toContainText("Davomiylik 1–1440");
  await dialog(page).getByLabel("Davomiyligi", { exact: true }).fill("45");
  await dialog(page).getByLabel("Narxi", { exact: true }).fill("123000");
  await save(page);
  await page.getByLabel("XZ-025 ko‘rish").click();
  await expect(dialog(page)).toContainText("45 daqiqa");
  await expect(dialog(page)).toContainText("123 000 so‘m");
  await expect(dialog(page)).not.toContainText("Natija tayyor bo‘lish muddati");
  expect(requests.filter((r) => r.method !== "GET")).toEqual([]);
});
test("category is independent of service type; consultation duration, optional timing and cancelled dirty drafts", async ({
  page,
}) => {
  await open(page);
  await createService(page);
  await dialog(page)
    .getByLabel("Kategoriya", { exact: true })
    .selectOption("cat-2");
  await expect(
    dialog(page).getByLabel("Davomiyligi", { exact: true }),
  ).toBeVisible();
  await expect(
    dialog(page).getByLabel("Natija tayyor bo‘lish muddati", { exact: true }),
  ).toHaveCount(0);
  await dialog(page)
    .getByLabel("Xizmat turi", { exact: true })
    .selectOption("other");
  await dialog(page)
    .getByRole("switch", { name: "Vaqt bo‘yicha yozilish" })
    .click();
  await expect(
    dialog(page).getByLabel("Davomiyligi", { exact: true }),
  ).toHaveCount(0);
  await dialog(page)
    .getByLabel("Xizmat nomi", { exact: true })
    .fill("Unsaved draft");
  await page.keyboard.press("Escape");
  await expect(dialog(page).getByRole("alert")).toContainText(
    "Saqlanmagan o‘zgarishlar",
  );
  await expect(dialog(page)).toContainText("Saqlanmagan o‘zgarishlar");
  await dialog(page)
    .getByRole("button", { name: "Davom etish", exact: true })
    .click();
  await expect(
    dialog(page).getByLabel("Xizmat nomi", { exact: true }),
  ).toHaveValue("Unsaved draft");
  await dialog(page)
    .getByRole("button", { name: "Bekor qilish", exact: true })
    .click();
  await dialog(page)
    .getByRole("button", { name: "O‘zgarishlarni bekor qilish", exact: true })
    .click();
  await expect(dialog(page)).toHaveCount(0);
  await expect(page.locator(".sv-stats article").first()).toContainText("24");
  await expect(
    page.getByRole("button", { name: "Xizmat qo‘shish", exact: true }).first(),
  ).toBeFocused();
});
test("status confirmation cancel/accept and reactivation; filter/page counts and CSV include all filtered pages", async ({
  page,
}) => {
  await open(page);
  await page.getByLabel("XZ-001 amallar").click();
  await page
    .getByRole("button", { name: "Faolsizlantirish", exact: true })
    .click();
  await dialog(page)
    .getByRole("button", { name: "Bekor qilish", exact: true })
    .click();
  await expect(page.locator(".sv-page tbody tr").first()).toContainText("Faol");
  await page.getByLabel("XZ-001 amallar").click();
  await page
    .getByRole("button", { name: "Faolsizlantirish", exact: true })
    .click();
  await dialog(page)
    .getByRole("button", { name: "Faolsizlantirish", exact: true })
    .click();
  await page.getByLabel("Holat filtri").selectOption("inactive");
  await expect(page.locator(".sv-page tbody tr")).toHaveCount(3);
  await page.getByLabel("XZ-001 amallar").click();
  await page
    .getByRole("button", { name: "Qayta faollashtirish", exact: true })
    .click();
  await expect(page.locator(".sv-page tbody tr")).toHaveCount(2);
  await page.getByLabel("Holat filtri").selectOption("");
  await page.getByLabel("Sahifa 3", { exact: true }).click();
  await expect(page.locator(".sv-pagination")).toContainText("21–24 / 24");
  await page
    .getByLabel("Xizmat nomi yoki kodi", { exact: true })
    .fill("XZ-007");
  await expect(page.locator(".sv-page tbody tr")).toHaveCount(1);
  await expect(page.locator(".sv-pagination")).toContainText("1–1 / 1");
  await page.getByLabel("Xizmat nomi yoki kodi", { exact: true }).fill("");
  await page.getByLabel("Sahifadagi yozuvlar").selectOption("20");
  await expect(page.locator(".sv-page tbody tr")).toHaveCount(20);
  const download = page.waitForEvent("download");
  await page.getByRole("button", { name: "Eksport", exact: true }).click();
  const file = await download;
  expect(file.suggestedFilename()).toBe("xizmatlar.csv");
  const csv = await readFile((await file.path())!, "utf8");
  expect(csv.split("\r\n")).toHaveLength(25);
  await page.getByRole("button", { name: /^Laboratoriya 7$/ }).click();
  await page.getByLabel("Filial filtri").selectOption("andijan");
  const count = await page.locator(".sv-page tbody tr").count();
  expect(count).toBeGreaterThan(0);
  await page
    .getByLabel("Xizmat nomi yoki kodi", { exact: true })
    .fill("missing");
  await expect(page.locator(".sv-page tbody")).toContainText(
    "Xizmat topilmadi",
  );
});
test("small viewport, modal footer, keyboard focus loop and shared navigation remain usable", async ({
  page,
}) => {
  await open(page);
  await page.setViewportSize({ width: 390, height: 844 });
  await createService(page);
  await shot(page, "04-mobile-service");
  const bounds = await dialog(page).boundingBox();
  expect(bounds!.y + bounds!.height).toBeLessThanOrEqual(845);
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  const close = dialog(page).getByRole("button", {
    name: "Yopish",
    exact: true,
  });
  await close.focus();
  await page.keyboard.press("Shift+Tab");
  await expect(
    dialog(page).getByRole("button", { name: "Saqlash", exact: true }),
  ).toBeFocused();
  await page.keyboard.press("Tab");
  await expect(close).toBeFocused();
  await page.keyboard.press("Escape");
  await page.setViewportSize({ width: 1536, height: 1024 });
  for (const label of [
    "Registratura",
    "Bemorlar",
    "Shifokor kabineti",
    "Xizmatlar",
  ]) {
    await page.getByRole("button", { name: label, exact: true }).click();
    expect(
      await page
        .locator(".clinic-nav-item")
        .first()
        .evaluate((e) => getComputedStyle(e).fontSize),
    ).toBe("14px");
  }
});

test("empty catalog and no-match states differ; many category tabs scroll locally", async ({
  page,
}) => {
  await open(page);
  await page.evaluate(async () => {
    const { createApp, h } = await import("/node_modules/.vite/deps/vue.js");
    const View = (await import("/src/views/ServicesView.vue")).default;
    const host = document.createElement("div");
    host.id = "empty-catalog";
    host.style.width = "760px";
    host.style.maxWidth = "100%";
    document.body.append(host);
    createApp({
      render: () =>
        h(View, { initialCatalog: { categories: [], services: [] } }),
    }).mount(host);
  });
  const host = page.locator("#empty-catalog");
  await expect(host).toContainText("Katalog hali bo‘sh");
  await expect(host).not.toContainText("Xizmat topilmadi");
  await host
    .getByRole("button", { name: "Xizmat qo‘shish", exact: true })
    .first()
    .click();
  await dialog(page)
    .getByLabel("Xizmat nomi", { exact: true })
    .fill("No category");
  await dialog(page).getByLabel("Narxi", { exact: true }).fill("10");
  await save(page);
  await expect(dialog(page)).toContainText("Faol kategoriyani tanlang");
  await page.keyboard.press("Escape");
  await dialog(page)
    .getByRole("button", { name: "O‘zgarishlarni bekor qilish", exact: true })
    .click();
  await page.evaluate(async () => {
    const { createApp, h } = await import("/node_modules/.vite/deps/vue.js");
    const View = (await import("/src/views/ServicesView.vue")).default;
    const { createServicesCatalog } = await import(
      "/src/mocks/servicesCatalog.ts"
    );
    const catalog = createServicesCatalog();
    for (let i = 6; i <= 30; i++)
      catalog.categories.push({
        id: `cat-${i}`,
        name: `Kategoriya ${i}`,
        description: "",
        color: "blue",
        active: true,
      });
    const host = document.createElement("div");
    host.id = "many-categories";
    host.style.width = "760px";
    host.style.maxWidth = "100%";
    document.body.append(host);
    createApp({ render: () => h(View, { initialCatalog: catalog }) }).mount(
      host,
    );
  });
  const many = page.locator("#many-categories");
  expect(
    await many
      .locator(".sv-tabs")
      .evaluate((el) => el.scrollWidth > el.clientWidth),
  ).toBe(true);
  await many
    .getByRole("button", { name: "Kategoriya 30 0", exact: true })
    .click();
  await expect(many).toContainText("Xizmat topilmadi");
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
});
