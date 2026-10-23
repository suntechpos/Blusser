import { test, expect, type Page } from "@playwright/test";
import { receptionSession } from "./reception.fixture";

const modal = (page: Page) => page.getByRole("dialog").last();
async function open(page: Page) {
  await receptionSession(page);
  await page.getByRole("button", { name: "Kassa", exact: true }).click();
  await page
    .getByRole("button", { name: "Kassaga kirish", exact: true })
    .click();
}
async function submit(page: Page, pin: string) {
  await modal(page).getByLabel("4 xonali PIN").fill(pin);
  await modal(page)
    .getByRole("button", { name: "Kirish", exact: true })
    .click();
}

test("private PIN is verified by API, with no demo PIN fallback or stored secret", async ({
  page,
}) => {
  await open(page);
  const bodies: unknown[] = [];
  await page.route("**/api/v1/clinic/cashier/unlock", async (route) => {
    const body = route.request().postDataJSON();
    bodies.push(body);
    await route.fulfill(
      body.cashier_pin === "4826"
        ? { status: 204 }
        : { status: 422, json: { code: "invalid_pin" } },
    );
  });
  await submit(page, "1122");
  await expect(modal(page).getByRole("alert")).toContainText("PIN noto‘g‘ri");
  await expect(modal(page).getByLabel("4 xonali PIN")).toHaveValue("");
  await submit(page, "4826");
  await expect(modal(page)).toContainText("Kassani ochish");
  expect(bodies).toEqual([{ cashier_pin: "1122" }, { cashier_pin: "4826" }]);
  const stored = await page.evaluate(() =>
    JSON.stringify([localStorage, sessionStorage]),
  );
  expect(stored).not.toContain("cashier_pin");
  expect(stored).not.toContain("$argon2id$");
  expect(stored).not.toContain('"4826"');
});

test("rate limit and network errors never unlock cashier", async ({ page }) => {
  await open(page);
  await page.route("**/api/v1/clinic/cashier/unlock", (route) =>
    route.fulfill({ status: 429, json: {} }),
  );
  await submit(page, "4826");
  await expect(modal(page).getByRole("alert")).toContainText("So‘rovlar ko‘p");
  await expect(
    page.getByRole("heading", { name: "Kassani ochish", exact: true }),
  ).toHaveCount(0);
  await page.route("**/api/v1/clinic/cashier/unlock", (route) =>
    route.abort("failed"),
  );
  await submit(page, "4826");
  await expect(modal(page).getByRole("alert")).toContainText(
    "Server bilan bog‘lanib bo‘lmadi",
  );
  await expect(
    page.getByRole("heading", { name: "Kassani ochish", exact: true }),
  ).toHaveCount(0);
});

test("pending verification cannot be duplicated or unlock after cancelling", async ({
  page,
}) => {
  await open(page);
  let release!: () => void;
  const hold = new Promise<void>((resolve) => {
    release = resolve;
  });
  let requests = 0;
  await page.route("**/api/v1/clinic/cashier/unlock", async (route) => {
    requests++;
    await hold;
    await route.fulfill({ status: 204 });
  });
  await submit(page, "4826");
  await expect(
    modal(page).getByRole("button", { name: "Kirish", exact: true }),
  ).toBeDisabled();
  await expect(modal(page).getByLabel("4 xonali PIN")).toBeDisabled();
  await modal(page)
    .getByRole("button", { name: "Bekor qilish", exact: true })
    .click();
  const response = page.waitForResponse((r) =>
    r.url().endsWith("/cashier/unlock"),
  );
  release();
  await response;
  await expect(
    page.getByRole("button", { name: "Kassaga kirish", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Kassani ochish", exact: true }),
  ).toHaveCount(0);
  expect(requests).toBe(1);
});
