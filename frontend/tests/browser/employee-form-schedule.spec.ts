import { test, expect } from "@playwright/test";
import { editorSession } from "./access-editor.fixture";
test("permission cards preserve actions and render four/two/one columns without overflow", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1536, height: 1024 });
  const s = await editorSession(page);
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page
    .getByRole("button", { name: "Xodim qo‘shish", exact: true })
    .click();
  const groups = page.locator(".employee-permissions-group");
  await expect(groups).toHaveCount(4);
  async function positions() {
    return groups.evaluateAll((nodes) =>
      nodes.map((n) => {
        const r = n.getBoundingClientRect();
        return { x: r.x, y: r.y, w: r.width };
      }),
    );
  }
  let p = await positions();
  expect(new Set(p.map((r) => Math.round(r.y))).size).toBe(1);
  expect(
    Math.max(...p.map((r) => r.w)) - Math.min(...p.map((r) => r.w)),
  ).toBeLessThan(2);
  await page.getByLabel("Bo‘limlarni qidirish").fill("Registratura");
  await expect(
    page.getByLabel("Bo‘lim: Registratura", { exact: true }),
  ).toBeVisible();
  await expect(groups).toHaveCount(1);
  await page.getByLabel("Bo‘limlarni qidirish").fill("");
  await page
    .getByRole("button", { name: "Barchasini belgilash", exact: true })
    .click();
  await expect(
    page.getByLabel("Bo‘lim: Registratura", { exact: true }),
  ).toBeChecked();
  await page
    .getByLabel("Registratura kirish doirasi")
    .selectOption("entire_clinic");
  await page.getByRole("button", { name: "Tozalash", exact: true }).click();
  // Confirmation is intentional; selection is never silently discarded.
  await page.getByRole("button", { name: "Ha, tozalash", exact: true }).click();
  await expect(
    page.getByLabel("Bo‘lim: Registratura", { exact: true }),
  ).not.toBeChecked();
  await page.screenshot({
    path: "/tmp/employee-form-cards-desktop.png",
    fullPage: true,
  });
  await page.setViewportSize({ width: 1100, height: 900 });
  p = await positions();
  expect(new Set(p.map((r) => Math.round(r.y))).size).toBe(2);
  await page.setViewportSize({ width: 600, height: 900 });
  p = await positions();
  expect(new Set(p.map((r) => Math.round(r.x))).size).toBe(1);
  expect(
    await page
      .locator(".employees-form")
      .evaluate((n) => n.scrollWidth <= n.clientWidth),
  ).toBe(true);
  await expect(
    page.getByRole("region", { name: "Ish jadvali", exact: true }),
  ).toBeVisible();
  expect(s.requests.filter((r) => r.method === "POST")).toHaveLength(0);
});

test("a committed employee is recovered after a list read failure and is not created twice", async ({
  page,
}) => {
  const s = await editorSession(page);
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page
    .getByRole("button", { name: "Xodim qo‘shish", exact: true })
    .click();
  await page.getByLabel("Familiya *", { exact: true }).fill("Recovery");
  await page.getByLabel("Ism *", { exact: true }).fill("Profile");
  await page.getByLabel("Telefon *", { exact: true }).fill("+998 90 123 45 67");
  await page
    .getByRole("switch", { name: "Tizimga kirishga ruxsat berish" })
    .click();
  let failRead = false,
    created = false;
  await page.route("**/api/v1/clinic/employees*", async (route) => {
    if (route.request().method() === "POST") {
      created = true;
      failRead = true;
      return route.fallback();
    }
    if (created && failRead && route.request().method() === "GET") {
      failRead = false;
      return route.fulfill({
        status: 503,
        json: { message: "Synthetic read failure" },
      });
    }
    return route.fallback();
  });
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(
    page.getByRole("button", {
      name: "Saqlangan xodimni qayta yuklash",
      exact: true,
    }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Saqlash", exact: true }),
  ).toBeDisabled();
  await page
    .getByRole("button", {
      name: "Saqlangan xodimni qayta yuklash",
      exact: true,
    })
    .click();
  await expect(
    page.getByRole("button", { name: "Saqlash", exact: true }),
  ).toBeEnabled();
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByLabel("Xodimlarni qidirish")).toBeVisible();
  expect(
    s.requests.filter(
      (r) => r.method === "POST" && r.path.endsWith("/employees"),
    ),
  ).toHaveLength(1);
  expect(
    s.requests.filter(
      (r) => r.method === "PATCH" && r.path.includes("/employees/"),
    ),
  ).toHaveLength(1);
});
