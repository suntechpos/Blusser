import {allServerGrants} from "./access.fixture";
import { test, expect, type Page } from "@playwright/test";
import { mkdir } from "node:fs/promises";
const output =
  process.env.FACILITY_SCREENSHOTS ??
  "/tmp/blusser-departments-rooms-screenshots";
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
    // Access-control fixture: the shell now reads authorized branches.
    if(path === '/api/v1/clinic/branches')return route.fulfill({json:{data:[]}});
    if (path === "/api/v1/clinic/context")
      return route.fulfill({
        json: { grants: allServerGrants, branches: [], timezone: "Asia/Tashkent" },
      });
    return route.fulfill({
      status: 500,
      json: { message: "Unexpected UI test request" },
    });
  });
  await page.route("**/sanctum/csrf-cookie", (route) =>
    route.fulfill({ status: 204 }),
  );
  await page.goto("/#bolimlar-xonalar");
  return { requests, errors };
}
async function shot(page: Page, name: string) {
  await mkdir(output, { recursive: true });
  await page.evaluate(() => document.fonts.ready);
  if (await page.getByRole("dialog").isVisible())
    await page.getByRole("dialog").getByRole("heading").click();
  await page.screenshot({ path: `${output}/${name}.png`, fullPage: false });
}
const dialog = (page: Page) => page.getByRole("dialog");
const roomsTab = (page: Page) =>
  page.getByRole("tab", { name: "Xonalar", exact: true });
test.use({ viewport: { width: 1672, height: 941 }, locale: "uz-UZ" });
test("four reference views and no operations API requests", async ({
  page,
}) => {
  const { requests, errors } = await setup(page);
  await expect(
    page.getByRole("heading", { name: "Bo‘limlar va Xonalar" }),
  ).toBeVisible();
  const initial = [...requests];
  await expect(page.locator(".dr-departments tbody tr")).toHaveCount(8);
  await shot(page, "01-departments");
  const add = page.getByRole("button", {
    name: "Bo‘lim qo‘shish",
    exact: true,
  });
  await add.click();
  await dialog(page)
    .getByLabel("Bo‘lim nomi", { exact: true })
    .fill("Kardiologiya");
  await dialog(page)
    .getByLabel("Filial", { exact: true })
    .selectOption("Asosiy filial");
  await shot(page, "02-department-modal");
  await page.keyboard.press("Escape");
  await expect(add).toBeFocused();
  await roomsTab(page).click();
  await expect(page.locator(".dr-pagination")).toContainText(
    "1 – 8 / 23 ta xona",
  );
  await shot(page, "03-rooms");
  await page
    .getByRole("button", { name: "Xona qo‘shish", exact: true })
    .click();
  await dialog(page)
    .getByLabel("Xona nomi yoki raqami", { exact: true })
    .fill("204-kabinet");
  await dialog(page)
    .getByLabel("Filial", { exact: true })
    .selectOption("Asosiy filial");
  await dialog(page)
    .getByLabel("Bo‘lim", { exact: true })
    .selectOption("B-001");
  await dialog(page)
    .getByLabel("Xona turi", { exact: true })
    .selectOption("Qabul kabineti");
  await dialog(page).getByLabel("Qavat", { exact: true }).fill("2");
  await shot(page, "04-room-modal");
  await dialog(page).getByRole("button", { name: "Yopish" }).click();
  expect(requests).toEqual(initial);
  expect(errors).toEqual([]);
});
test("department filters, empty state and room-count drilldown", async ({
  page,
}) => {
  await setup(page);
  await page.getByLabel("Bo‘limlarni qidirish").fill("kardio");
  await expect(page.locator("tbody tr")).toHaveCount(1);
  await page.getByLabel("Bo‘limlarni qidirish").fill("NONE");
  await expect(page.locator("tbody")).toContainText("Bo‘lim topilmadi");
  await page.getByLabel("Bo‘limlarni qidirish").fill("");
  await page.getByLabel("Bo‘limlar filial filtri").selectOption("Yunusobod");
  await expect(page.locator("tbody tr")).toHaveCount(3);
  await page.getByLabel("Bo‘limlar holat filtri").selectOption("false");
  await expect(page.locator("tbody tr")).toHaveCount(1);
  await page
    .getByRole("button", { name: "Fizioterapiya xonalarini ko‘rish" })
    .click();
  await expect(roomsTab(page)).toHaveAttribute("aria-selected", "true");
  await expect(page.getByLabel("Xonalar filial filtri")).toHaveValue(
    "Yunusobod",
  );
  await expect(page.getByLabel("Xonalar bo‘lim filtri")).toHaveValue("B-008");
  await expect(page.locator("tbody")).toContainText("Xona topilmadi");
  await page.getByLabel("Xonalar filial filtri").selectOption("Asosiy filial");
  await expect(page.getByLabel("Xonalar bo‘lim filtri")).toHaveValue("");
});
test("department required/duplicate validation, create/edit, cancel and focus trap", async ({
  page,
}) => {
  await setup(page);
  const add = page.getByRole("button", {
    name: "Bo‘lim qo‘shish",
    exact: true,
  });
  await add.click();
  let d = dialog(page);
  await d.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(d.locator(".dr-error")).toHaveCount(2);
  await d.getByLabel("Bo‘lim nomi", { exact: true }).fill("  KARDIOLOGIYA  ");
  await d.getByLabel("Filial", { exact: true }).selectOption("Asosiy filial");
  await d.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(d.locator(".dr-error")).toContainText("shu nomli");
  await d.getByLabel("Bo‘lim nomi", { exact: true }).fill("Demo bo‘lim");
  await d.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(d).toBeHidden();
  await expect(add).toBeFocused();
  await page.getByLabel("Bo‘limlarni qidirish").fill("Demo bo‘lim");
  await expect(page.locator("tbody tr")).toHaveCount(1);
  const edit = page.getByRole("button", {
    name: "Demo bo‘lim bo‘limini tahrirlash",
  });
  await edit.click();
  d = dialog(page);
  await expect(
    d.getByRole("heading", { name: "Bo‘limni tahrirlash" }),
  ).toBeVisible();
  await d.getByLabel("Bo‘lim nomi", { exact: true }).fill("Bekor nom");
  await d.getByRole("button", { name: "Bekor qilish", exact: true }).click();
  await expect(page.locator("tbody")).toContainText("Demo bo‘lim");
  await edit.click();
  d = dialog(page);
  for (let i = 0; i < 10; i++) {
    await page.keyboard.press("Shift+Tab");
    expect(await d.evaluate((el) => el.contains(document.activeElement))).toBe(
      true,
    );
  }
  await d.getByLabel("Bo‘lim nomi", { exact: true }).fill("Demo yangilangan");
  await d.getByLabel("Holati", { exact: true }).selectOption("false");
  await d.getByRole("button", { name: "Saqlash", exact: true }).click();
  await page.getByLabel("Bo‘limlarni qidirish").fill("Demo yangilangan");
  await expect(page.locator("tbody")).toContainText("Faol emas");
});
test("room filters, pagination, scoped departments, required fields and duplicate names", async ({
  page,
}) => {
  await setup(page);
  await roomsTab(page).click();
  await page.getByRole("button", { name: "Keyingi xonalar sahifasi" }).click();
  await expect(page.locator(".dr-pagination")).toContainText("9 – 16");
  await page.getByLabel("Xonalarni qidirish").fill("201");
  await expect(page.locator("tbody tr")).toHaveCount(1);
  await page.getByLabel("Xonalarni qidirish").fill("");
  await page.getByLabel("Xonalar holat filtri").selectOption("false");
  await expect(page.locator("tbody tr")).toHaveCount(1);
  await page.getByLabel("Xonalar holat filtri").selectOption("");
  await page
    .getByRole("button", { name: "Xona qo‘shish", exact: true })
    .click();
  const d = dialog(page);
  await expect(d.getByLabel("Holati", { exact: true })).toHaveValue("true");
  await expect(d.getByLabel("Bo‘lim", { exact: true })).toBeDisabled();
  await d.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(d.locator(".dr-error")).toHaveCount(4);
  await d.getByLabel("Filial", { exact: true }).selectOption("Asosiy filial");
  await d.getByLabel("Bo‘lim", { exact: true }).selectOption("B-001");
  await d.getByLabel("Filial", { exact: true }).selectOption("Yunusobod");
  await expect(d.getByLabel("Bo‘lim", { exact: true })).toHaveValue("");
  await expect(
    d.getByLabel("Bo‘lim", { exact: true }).locator("option"),
  ).not.toContainText(["Kardiologiya"]);
  await d.getByLabel("Filial", { exact: true }).selectOption("Asosiy filial");
  await d.getByLabel("Bo‘lim", { exact: true }).selectOption("B-001");
  await d
    .getByLabel("Xona turi", { exact: true })
    .selectOption("Qabul kabineti");
  await d
    .getByLabel("Xona nomi yoki raqami", { exact: true })
    .fill("  201-KABINET ");
  await d.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(d.locator(".dr-error")).toContainText("shu nomli");
  await d
    .getByLabel("Xona nomi yoki raqami", { exact: true })
    .fill("Demo xona");
  await d.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(d).toBeHidden();
  await page.getByLabel("Xonalarni qidirish").fill("Demo xona");
  await expect(page.locator("tbody tr")).toHaveCount(1);
  await expect(page.locator("tbody")).toContainText("—");
  await page
    .getByRole("button", { name: "Demo xona xonasini tahrirlash" })
    .click();
  await expect(
    dialog(page).getByRole("heading", { name: "Xonani tahrirlash" }),
  ).toBeVisible();
  await dialog(page).getByLabel("Qavat", { exact: true }).fill("4");
  await dialog(page)
    .getByRole("button", { name: "Saqlash", exact: true })
    .click();
  await expect(page.locator("tbody")).toContainText("4-qavat");
  await page
    .getByRole("button", { name: "Demo xona xonasini tahrirlash" })
    .click();
  await dialog(page)
    .getByLabel("Xona nomi yoki raqami", { exact: true })
    .fill("O‘chadigan");
  await page.keyboard.press("Escape");
  await expect(page.locator("tbody")).toContainText("Demo xona");
  await page.getByRole("tab", { name: "Bo‘limlar", exact: true }).click();
  await page
    .getByRole("button", { name: "Kardiologiya xonalarini ko‘rish" })
    .click();
  await expect(page.locator(".dr-pagination")).toContainText("/ 5 ta xona");
});
test("mobile modal stays in viewport, tables scroll locally, demo resets on reload", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await setup(page);
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  await roomsTab(page).click();
  const box = page.locator(".dr-scroll");
  expect(await box.evaluate((el) => el.scrollWidth > el.clientWidth)).toBe(
    true,
  );
  await page
    .getByRole("button", { name: "Xona qo‘shish", exact: true })
    .click();
  expect(
    await dialog(page).evaluate(
      (el) =>
        el.getBoundingClientRect().width <= innerWidth &&
        el.getBoundingClientRect().top >= 0 &&
        el.getBoundingClientRect().bottom <= innerHeight,
    ),
  ).toBe(true);
  await page.keyboard.press("Escape");
  await page.reload();
  await expect(
    page.getByRole("heading", { name: "Bo‘limlar va Xonalar" }),
  ).toBeVisible();
});

test("mock validation preserves branch identity and normalized uniqueness", async () => {
  const { createFacilities, departmentErrors, roomErrors } =
    await import("../../src/mocks/departmentsRooms");
  const { departments, rooms } = createFacilities();
  expect(rooms).toHaveLength(23);
  expect(
    rooms.every((r) =>
      departments.some((d) => d.id === r.departmentId && d.branch === r.branch),
    ),
  ).toBe(true);
  const duplicate = { ...departments[0]!, id: "", name: "  KARDIOLOGIYA  " };
  expect(departmentErrors(duplicate, departments, rooms).name).toContain(
    "mavjud",
  );
  duplicate.branch = "Yunusobod";
  expect(departmentErrors(duplicate, departments, rooms)).toEqual({});
  const moved = { ...departments[0]!, branch: "Yunusobod" };
  expect(departmentErrors(moved, departments, rooms).branch).toContain(
    "o‘zgartirib",
  );
  expect(departmentErrors(departments[0]!, departments, rooms)).toEqual({});
  const room = { ...rooms[0]!, id: "", name: "  201-KABINET  " };
  expect(roomErrors(room, rooms, departments).name).toContain("mavjud");
  room.branch = "Yunusobod";
  expect(roomErrors(room, rooms, departments).departmentId).toBeTruthy();
  room.departmentId = "B-003";
  expect(roomErrors(room, rooms, departments)).toEqual({});
  room.floor = "qavat";
  expect(roomErrors(room, rooms, departments).floor).toBeTruthy();
});
