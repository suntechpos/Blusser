import { test, expect, type Page } from "@playwright/test";
import { mkdir, readFile } from "node:fs/promises";
import { serviceSession, uuid } from "./services-api.fixture";

test.use({ viewport: { width: 1536, height: 1024 } });
const dialog = (page: Page) => page.getByRole("dialog");
const search = (page: Page) =>
  page.getByLabel("Xizmat nomi yoki kodi", { exact: true });
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
async function fillService(page: Page, name = "API yangi xizmat") {
  await dialog(page).getByLabel("Xizmat nomi", { exact: true }).fill(name);
  await dialog(page).getByLabel("Narxi", { exact: true }).fill("123000.25");
}
async function shot(page: Page, name: string) {
  await mkdir("/tmp/blusser-services-screenshots", { recursive: true });
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({
    path: `/tmp/blusser-services-screenshots/${name}.png`,
  });
}

test("API statistics, real branch/category filters, server pagination and empty states replace demo rows", async ({
  page,
}) => {
  const server = await serviceSession(page);
  await expect(page.locator(".sv-stats strong")).toHaveText([
    "12",
    "11",
    "2",
    "1",
  ]);
  await expect(page.locator(".sv-tabs button")).toHaveCount(3);
  await expect(page.locator(".sv-page tbody tr")).toHaveCount(10);
  await expect(page.locator(".sv-page")).not.toContainText("lokal namuna");
  await shot(page, "01-services-api");
  await page.getByLabel("Sahifa 2", { exact: true }).click();
  await expect(page.locator(".sv-pagination")).toContainText("11–12 / 12");
  expect(
    server.requests.some(
      (r) => r.method === "GET" && r.path.includes("page=2"),
    ),
  ).toBe(true);
  await search(page).fill("XZ-003");
  await expect(page.locator(".sv-page tbody tr")).toHaveCount(1);
  await expect(page.locator(".sv-page tbody")).toContainText("API xizmat 3");
  await expect(page.locator(".sv-pagination")).toContainText("1–1 / 1");
  await search(page).fill("");
  await page.getByLabel("Filial filtri").selectOption(uuid(4));
  await expect(page.locator(".sv-page tbody tr")).toHaveCount(6);
  await page
    .locator(".sv-tabs")
    .getByRole("button", { name: "API qabul 6", exact: true })
    .click();
  await expect(page.locator(".sv-page tbody")).toContainText(
    "Xizmat topilmadi",
  );
  await page
    .getByRole("button", { name: "Filtrlarni tozalash", exact: true })
    .click();
  await expect(page.locator(".sv-page tbody tr")).toHaveCount(10);
  server.state.empty = true;
  await page.reload();
  await expect(page.locator(".sv-page tbody")).toContainText(
    "Katalog hali bo‘sh",
  );
});

test("category create/edit uses API, preserves historical assignments and survives reload", async ({
  page,
}) => {
  const server = await serviceSession(page);
  await expect(page.locator(".sv-page tbody tr")).toHaveCount(10);
  await page
    .getByRole("button", { name: "Kategoriya qo‘shish", exact: true })
    .click();
  await save(page);
  await expect(dialog(page)).toContainText("Kategoriya nomini kiriting.");
  await dialog(page)
    .getByLabel("Kategoriya nomi", { exact: true })
    .fill("  API laboratoriya  ");
  await save(page);
  await expect(dialog(page)).toContainText("Bu nomli kategoriya mavjud.");
  await dialog(page)
    .getByLabel("Kategoriya nomi", { exact: true })
    .fill("  API yangi guruh  ");
  await dialog(page).getByLabel("Kategoriya tavsifi").fill("Server tavsifi");
  await dialog(page)
    .getByRole("button", { name: "Rang: Binafsha", exact: true })
    .click();
  await shot(page, "02-category-api");
  await save(page);
  await expect(dialog(page)).toHaveCount(0);
  expect(server.categories.at(-1)).toMatchObject({
    name: "API yangi guruh",
    description: "Server tavsifi",
    color: "purple",
  });
  const request = server.requests.find(
    (r) => r.method === "POST" && r.path === "service-categories",
  )!;
  expect(request.headers["idempotency-key"]).toBeTruthy();
  await page.reload();
  await expect(page.locator(".sv-tabs")).toContainText("API yangi guruh");
  await page
    .getByRole("button", { name: "Kategoriyalarni boshqarish" })
    .click();
  await dialog(page)
    .getByRole("button", {
      name: "API qabul kategoriyasini tahrirlash",
      exact: true,
    })
    .click();
  await dialog(page)
    .getByRole("switch", { name: "Kategoriya faol holati", exact: true })
    .click();
  await save(page);
  await expect(dialog(page)).toHaveAttribute("aria-label", "Kategoriyalar");
  await dialog(page)
    .getByRole("button", { name: "Yopish", exact: true })
    .last()
    .click();
  expect(server.categories[0]!.active).toBe(false);
  expect(server.services.filter((s) => s.category_id === uuid(1))).toHaveLength(
    6,
  );
  await createService(page);
  await expect(
    dialog(page)
      .getByLabel("Kategoriya", { exact: true })
      .locator(`option[value="${uuid(1)}"]`),
  ).toHaveCount(0);
});

test("service create/view/edit keeps exact decimal, server code, type/timing and real branch IDs after reload", async ({
  page,
}) => {
  const server = await serviceSession(page);
  await createService(page);
  await save(page);
  await expect(dialog(page)).toContainText("Xizmat nomini kiriting.");
  await fillService(page, "API laboratoriya test");
  await dialog(page)
    .getByLabel("Xizmat turi", { exact: true })
    .selectOption("laboratory");
  await expect(
    dialog(page).getByLabel("Davomiyligi", { exact: true }),
  ).toHaveCount(0);
  await dialog(page)
    .getByLabel("Natija tayyor bo‘lish muddati", { exact: true })
    .fill("0");
  await save(page);
  await expect(dialog(page)).toContainText("Musbat natija muddatini");
  await dialog(page)
    .getByLabel("Natija tayyor bo‘lish muddati", { exact: true })
    .fill("2");
  await dialog(page).getByLabel("Muddat birligi").selectOption("day");
  await dialog(page)
    .getByRole("button", { name: "Asosiy filialni olib tashlash", exact: true })
    .click();
  await save(page);
  await expect(dialog(page)).toContainText("Kamida bitta filial");
  await dialog(page)
    .getByRole("button", { name: "Filiallarni tanlash", exact: true })
    .click();
  await dialog(page)
    .getByRole("checkbox", { name: "Ikkinchi filial", exact: true })
    .check();
  await shot(page, "03-service-api");
  await save(page);
  await expect(dialog(page)).toHaveCount(0);
  const created = server.services.find(
    (s) => s.name === "API laboratoriya test",
  )!;
  expect(created).toMatchObject({
    code: "XZ-013",
    price: "123000.25",
    branch_ids: [uuid(4)],
    service_type: "laboratory",
    duration_minutes: null,
    turnaround: "2",
    turnaround_unit: "day",
  });
  const post = server.requests.find(
    (r) =>
      r.method === "POST" && r.path === "appointments/configuration-services",
  )!;
  expect(post.body).not.toHaveProperty("code");
  expect(post.headers["idempotency-key"]).toBeTruthy();
  await page.reload();
  await expect(page.locator(".sv-page tbody")).toContainText(
    "API laboratoriya test",
  );
  await page.getByLabel("XZ-013 ko‘rish", { exact: true }).click();
  await expect(dialog(page)).toContainText("123 000.25 so‘m");
  await expect(dialog(page)).toContainText("2 kun");
  await expect(dialog(page)).toContainText("Ikkinchi filial");
  await dialog(page)
    .getByRole("button", { name: "Tahrirlash", exact: true })
    .click();
  await expect(dialog(page).getByLabel("Xizmat kodi")).toHaveValue("XZ-013");
  await dialog(page)
    .getByLabel("Xizmat turi", { exact: true })
    .selectOption("consultation");
  await dialog(page).getByLabel("Davomiyligi", { exact: true }).fill("");
  await save(page);
  await expect(dialog(page)).toContainText("Davomiylik 1–480");
  await dialog(page).getByLabel("Davomiyligi", { exact: true }).fill("45");
  await save(page);
  await expect(dialog(page)).toHaveCount(0);
  expect(created.duration_minutes).toBe(45);
  const update = server.requests.find(
    (r) => r.method === "PUT" && r.path.endsWith(created.id),
  )!;
  expect(update.headers["if-match"]).toBe('"1"');
});

test("status confirmations update API statistics/filters and export downloads every filtered server row", async ({
  page,
}) => {
  const server = await serviceSession(page);
  await expect(
    page.getByLabel("XZ-001 amallar", { exact: true }),
  ).toBeVisible();
  await page.getByLabel("XZ-001 amallar", { exact: true }).click();
  await page
    .getByRole("button", { name: "Faolsizlantirish", exact: true })
    .click();
  await dialog(page)
    .getByRole("button", { name: "Bekor qilish", exact: true })
    .click();
  expect(server.services[0]!.active).toBe(true);
  await page.getByLabel("XZ-001 amallar", { exact: true }).click();
  await page
    .getByRole("button", { name: "Faolsizlantirish", exact: true })
    .click();
  await dialog(page)
    .getByRole("button", { name: "Faolsizlantirish", exact: true })
    .click();
  await expect(dialog(page)).toHaveCount(0);
  await page.getByLabel("Holat filtri").selectOption("inactive");
  await expect(page.locator(".sv-page tbody tr")).toHaveCount(2);
  await page.getByLabel("XZ-001 amallar", { exact: true }).click();
  await page
    .getByRole("button", { name: "Qayta faollashtirish", exact: true })
    .click();
  await expect(page.locator(".sv-page tbody tr")).toHaveCount(1);
  await page.getByLabel("Holat filtri").selectOption("");
  await expect(page.locator(".sv-page tbody tr")).toHaveCount(10);
  const download = page.waitForEvent("download");
  await page.getByRole("button", { name: "Eksport", exact: true }).click();
  const file = await download;
  expect(file.suggestedFilename()).toBe("xizmatlar.csv");
  const csv = await readFile((await file.path())!, "utf8");
  expect(csv).toContain("XZ-012");
  expect(csv).toContain("120000.25");
  expect(csv.split("\r\n")).toHaveLength(13);
  expect(
    server.requests.some(
      (r) =>
        r.path.startsWith("appointments/configuration-services/export") &&
        r.method === "GET",
    ),
  ).toBe(true);
});

test("load and write failures are visible, drafts survive, retries do not fake success and stale edits conflict", async ({
  page,
}) => {
  const server = await serviceSession(page);
  await expect(page.locator(".sv-page tbody tr")).toHaveCount(10);
  server.state.failLoad = true;
  await page.reload();
  await expect(page.locator(".sv-page [role=alert]")).toBeVisible();
  server.state.failLoad = false;
  await page
    .getByRole("button", { name: "Qayta urinish", exact: true })
    .click();
  await expect(page.locator(".sv-page tbody tr")).toHaveCount(10);
  await createService(page);
  await fillService(page, "Retry catalog");
  server.state.failNextWrite = true;
  await save(page);
  await expect(dialog(page).getByRole("alert")).toBeVisible();
  await expect(
    dialog(page).getByLabel("Xizmat nomi", { exact: true }),
  ).toHaveValue("Retry catalog");
  expect(server.services).toHaveLength(12);
  await save(page);
  await expect(dialog(page)).toHaveCount(0);
  expect(
    server.services.filter((s) => s.name === "Retry catalog"),
  ).toHaveLength(1);
  const row = server.services.find((s) => s.name === "Retry catalog")!;
  await page.getByLabel(`${row.code} tahrirlash`, { exact: true }).click();
  await dialog(page)
    .getByLabel("Xizmat nomi", { exact: true })
    .fill("Stale payload");
  server.state.conflictNextWrite = true;
  await save(page);
  await expect(dialog(page).getByRole("alert")).toBeVisible();
  expect(row.name).toBe("Retry catalog");
});

test("view-only users cannot mutate while create-only permission can save without update permission", async ({
  page,
}) => {
  await serviceSession(page, {
    "dashboard.view": "entire_clinic",
    "services.view": "entire_clinic",
  });
  await expect(page.locator(".sv-page tbody tr")).toHaveCount(10);
  await expect(
    page.getByRole("button", { name: "Xizmat qo‘shish", exact: true }),
  ).toHaveCount(0);
  await expect(
    page.getByRole("button", { name: "Kategoriya qo‘shish", exact: true }),
  ).toHaveCount(0);
  await expect(
    page.getByLabel("XZ-001 tahrirlash", { exact: true }),
  ).toHaveCount(0);
  await expect(page.getByLabel("XZ-001 amallar", { exact: true })).toHaveCount(
    0,
  );
  await page.getByLabel("XZ-001 ko‘rish", { exact: true }).click();
  await expect(dialog(page)).toContainText("API xizmat 1");
  await expect(
    dialog(page).getByRole("button", { name: "Tahrirlash", exact: true }),
  ).toHaveCount(0);
  await page.unrouteAll({ behavior: "wait" });
  const server = await serviceSession(page, {
    "dashboard.view": "entire_clinic",
    "services.view": "entire_clinic",
    "services.create": "entire_clinic",
  });
  await createService(page);
  await fillService(page, "Create only actor");
  await save(page);
  await expect(dialog(page)).toHaveCount(0);
  expect(server.services.some((s) => s.name === "Create only actor")).toBe(
    true,
  );
});

test("a delayed old search response cannot overwrite the newer query or page", async ({
  page,
}) => {
  const server = await serviceSession(page);
  await expect(page.locator(".sv-page tbody tr")).toHaveCount(10);
  let release!: () => void;
  server.state.delays.set(
    "XZ-001",
    new Promise<void>((resolve) => {
      release = resolve;
    }),
  );
  const oldRequest = page.waitForRequest(
    (req) => new URL(req.url()).searchParams.get("q") === "XZ-001",
  );
  await search(page).fill("XZ-001");
  await oldRequest;
  await search(page).fill("XZ-003");
  await expect(page.locator(".sv-page tbody")).toContainText("API xizmat 3");
  release();
  await expect
    .poll(() => server.state.completedQueries.includes("XZ-001"))
    .toBe(true);
  await expect(page.locator(".sv-page tbody tr")).toHaveCount(1);
  await expect(page.locator(".sv-page tbody")).not.toContainText(
    "API xizmat 1",
  );
  await expect(page.locator(".sv-pagination")).toContainText("1–1 / 1");
});

test("mobile modal, dirty cancellation, focus loop and type/category independence retain existing UX", async ({
  page,
}) => {
  const server = await serviceSession(page);
  await page.setViewportSize({ width: 390, height: 844 });
  await createService(page);
  await shot(page, "04-mobile-service-api");
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
  await dialog(page)
    .getByLabel("Kategoriya", { exact: true })
    .selectOption(uuid(2));
  await expect(
    dialog(page).getByLabel("Davomiyligi", { exact: true }),
  ).toBeVisible();
  await expect(
    dialog(page).getByLabel("Natija tayyor bo‘lish muddati", { exact: true }),
  ).toHaveCount(0);
  await dialog(page)
    .getByLabel("Xizmat nomi", { exact: true })
    .fill("Unsaved server draft");
  await page.keyboard.press("Escape");
  await expect(dialog(page).getByRole("alert")).toContainText(
    "Saqlanmagan o‘zgarishlar",
  );
  await dialog(page)
    .getByRole("button", { name: "Davom etish", exact: true })
    .click();
  await expect(
    dialog(page).getByLabel("Xizmat nomi", { exact: true }),
  ).toHaveValue("Unsaved server draft");
  await dialog(page)
    .getByRole("button", { name: "Bekor qilish", exact: true })
    .click();
  await dialog(page)
    .getByRole("button", { name: "O‘zgarishlarni bekor qilish", exact: true })
    .click();
  await expect(dialog(page)).toHaveCount(0);
  expect(server.services).toHaveLength(12);
  expect(server.requests.filter((r) => r.method !== "GET")).toEqual([]);
});
