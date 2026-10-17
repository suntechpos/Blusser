import { test, expect, type Page } from "@playwright/test";
async function login(page: Page, name = "synthetic.a") {
  await page.goto("/#bemorlar");
  await page.getByLabel("Login", { exact: true }).fill(name);
  await page
    .getByLabel("Parol", { exact: true })
    .fill("Synthetic-password-42!");
  await page.getByRole("button", { name: "Kirish", exact: true }).click();
  if (name === "synthetic.a")
    await page
      .getByRole("button", {
        name: "Synthetic Clinic a · Custom synthetic role",
        exact: true,
      })
      .click();
}
async function logout(page: Page) {
  await page.getByRole("button", { name: "Profil menyusi" }).click();
  await page
    .getByRole("button", { name: "Tizimdan chiqish", exact: true })
    .click();
  await expect(page.getByLabel("Login", { exact: true })).toBeVisible();
}
async function add(page: Page, name: string) {
  await page.getByRole("button", { name: "Yangi bemor", exact: true }).click();
  const dialog = page.getByRole("dialog");
  await dialog.getByLabel("F.I.Sh. *", { exact: true }).fill(name);
  await dialog
    .getByLabel("Tug‘ilgan sana *", { exact: true })
    .fill("1990-01-01");
  await dialog
    .getByLabel("Filial", { exact: true })
    .selectOption({ label: "Synthetic branch" });
  await dialog.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(dialog).toBeHidden();
  await expect(page.locator("tbody")).toContainText(name);
}
test.describe.serial("real Sanctum / PostgreSQL Patients flows", () => {
  test("create, fresh detail, ETag update, conflict, validation, reload, branch and clinic switching, logout", async ({
    page,
  }) => {
    await login(page);
    await expect(
      page.getByRole("heading", { name: "Bemorlar", exact: true }),
    ).toBeVisible();
    await expect(page.locator("tbody")).toContainText("Bemor topilmadi");
    await add(page, "Synthetic UI patient A");
    const download = page.waitForEvent("download");
    await page.getByRole("button", { name: "Eksport", exact: true }).click();
    expect((await download).suggestedFilename()).toBe("bemorlar.csv");
    await page
      .locator("tbody tr")
      .filter({ hasText: "Synthetic UI patient A" })
      .getByRole("button", { name: "Ko‘rish", exact: true })
      .click();
    await expect(page.getByRole("dialog")).toContainText(
      "Synthetic UI patient A",
    );
    await page
      .getByRole("dialog")
      .getByRole("button", { name: "Yopish" })
      .click();
    await page
      .locator("tbody tr")
      .filter({ hasText: "Synthetic UI patient A" })
      .getByRole("button", { name: "Tahrirlash", exact: true })
      .click();
    const dialog = page.getByRole("dialog");
    await dialog
      .getByLabel("F.I.Sh. *", { exact: true })
      .fill("Synthetic UI patient updated");
    const write = page.waitForRequest(
      (r) => r.method() === "PATCH" && r.url().includes("/clinic/patients/"),
    );
    await dialog.getByRole("button", { name: "Saqlash", exact: true }).click();
    expect((await write).headers()["if-match"]).toBe('"1"');
    await expect(dialog).toBeHidden();
    await expect(page.locator("tbody")).toContainText(
      "Synthetic UI patient updated",
    );
    // Force a real stale ETag through another authorized session request, not a fake API success.
    const row = page
      .locator("tbody tr")
      .filter({ hasText: "Synthetic UI patient updated" });
    await row.getByRole("button", { name: "Tahrirlash", exact: true }).click();
    await page.evaluate(async () => {
      const { api } = await import("/src/services/api.ts");
      const { data } = await api.get("/api/v1/clinic/patients");
      const p = data.data[0];
      const r = await api.get("/api/v1/clinic/patients/" + p.id);
      await api.patch(
        "/api/v1/clinic/patients/" + p.id,
        { name: "Synthetic concurrent writer" },
        {
          headers: {
            "If-Match": r.headers.etag,
            "Idempotency-Key": crypto.randomUUID(),
          },
        },
      );
    });
    await dialog
      .getByLabel("F.I.Sh. *", { exact: true })
      .fill("Synthetic stale writer");
    await dialog.getByRole("button", { name: "Saqlash", exact: true }).click();
    await expect(dialog.getByRole("alert")).toContainText("Ma’lumot o‘zgargan");
    await dialog.getByRole("button", { name: "Yopish" }).click();
    await page
      .getByPlaceholder("F.I.Sh., bemor raqami yoki telefon...")
      .fill("Synthetic concurrent");
    await expect(page.locator("tbody")).toContainText(
      "Synthetic concurrent writer",
    );
    await page.reload();
    await expect(page.locator("tbody")).toContainText(
      "Synthetic concurrent writer",
    );
    await page
      .getByRole("button", { name: "Yangi bemor", exact: true })
      .click();
    await dialog
      .getByLabel("F.I.Sh. *", { exact: true })
      .fill("Synthetic validation");
    await dialog
      .getByLabel("Tug‘ilgan sana *", { exact: true })
      .fill("2999-01-01");
    await dialog
      .getByLabel("Filial", { exact: true })
      .selectOption({ label: "Synthetic branch" });
    await dialog.getByRole("button", { name: "Saqlash", exact: true }).click();
    await expect(dialog.getByRole("alert")).toBeVisible();
    await dialog.getByRole("button", { name: "Yopish" }).click();
    const branch = page.getByLabel("Filial filtri", { exact: true });
    await branch.selectOption({ label: "Synthetic second branch" });
    await expect(page.locator("tbody")).not.toContainText(
      "Synthetic concurrent writer",
    );
    await expect(page.locator("tbody")).toContainText("Bemor topilmadi");
    await page
      .getByLabel("Faol klinika", { exact: true })
      .selectOption({ label: "Synthetic Clinic b" });
    await expect(page.locator("tbody")).toContainText("Bemor topilmadi");
    await add(page, "Synthetic UI patient B");
    await expect(page.locator("tbody")).not.toContainText(
      "Synthetic concurrent writer",
    );
    await page.screenshot({
      path: process.env.PATIENT_SCREENSHOT ?? "/tmp/blusser-patients.png",
      fullPage: true,
    });
    await logout(page);
  });
  test("read-only actor cannot create or edit and has no role directory dependency", async ({
    page,
  }) => {
    await login(page, "synthetic.reader");
    await expect(
      page.getByRole("heading", { name: "Bemorlar", exact: true }),
    ).toBeVisible();
    await expect(
      page.getByRole("button", { name: "Yangi bemor", exact: true }),
    ).toHaveCount(0);
    await expect(
      page.getByRole("button", { name: "Tahrirlash", exact: true }),
    ).toHaveCount(0);
    await page
      .locator("tbody tr")
      .filter({ hasText: "Synthetic concurrent writer" })
      .getByRole("button", { name: "Ko‘rish", exact: true })
      .click();
    await expect(page.getByRole("dialog")).toContainText(
      "Synthetic concurrent writer",
    );
  });
  test("platform operator cannot enter clinical pages", async ({ page }) => {
    await login(page, "synthetic.platform");
    await expect(
      page.getByRole("heading", { name: "Platform administratori" }),
    ).toBeVisible();
    await expect(page.locator(".patients")).toHaveCount(0);
  });
  test("temporary password must be changed before any clinic data loads", async ({
    page,
  }) => {
    const clinical: string[] = [];
    page.on("request", (r) => {
      if (r.url().includes("/api/v1/clinic/")) clinical.push(r.url());
    });
    await login(page, "synthetic.temporary");
    await expect(
      page.getByRole("heading", { name: "Vaqtinchalik parolni almashtiring" }),
    ).toBeVisible();
    expect(clinical).toEqual([]);
    await page
      .getByLabel("Joriy parol", { exact: true })
      .fill("Synthetic-password-42!");
    await page
      .getByLabel("Yangi parol", { exact: true })
      .fill("Synthetic-new-password-42!");
    await page
      .getByLabel("Yangi parolni tasdiqlang")
      .fill("Synthetic-new-password-42!");
    await page
      .getByRole("button", { name: "Parolni almashtirish", exact: true })
      .click();
    await expect(
      page.getByRole("heading", { name: "Bemorlar", exact: true }),
    ).toBeVisible();
  });
  test("session expiry clears clinical content and returns to real login", async ({
    page,
  }) => {
    await login(page, "synthetic.reader");
    await expect(page.locator("tbody")).toContainText(
      "Synthetic concurrent writer",
    );
    await page.context().clearCookies();
    await page.reload();
    await expect(page.getByLabel("Login", { exact: true })).toBeVisible();
    await expect(page.locator(".patients")).toHaveCount(0);
  });
  test("failed list never becomes demo data; retry and denied access are explicit", async ({
    page,
  }) => {
    await page.route("**/api/v1/clinic/patients?*", (route) =>
      route.fulfill({
        status: 503,
        contentType: "application/json",
        body: '{"message":"Unavailable"}',
      }),
    );
    await login(page, "synthetic.reader");
    await expect(page.getByRole("alert")).toContainText(
      "Server bilan bog‘lanib bo‘lmadi",
    );
    await expect(page.locator("tbody tr")).toHaveCount(0);
    await page.unroute("**/api/v1/clinic/patients?*");
    await page
      .getByRole("button", { name: "Qayta urinish", exact: true })
      .click();
    await expect(page.locator("tbody")).toContainText(
      "Synthetic concurrent writer",
    );
    await page.route("**/api/v1/clinic/patients?*", (route) =>
      route.fulfill({
        status: 403,
        contentType: "application/json",
        body: '{"message":"Denied"}',
      }),
    );
    await page
      .getByPlaceholder("F.I.Sh., bemor raqami yoki telefon...")
      .fill("Synthetic");
    await expect(page.getByRole("alert")).toContainText("ruxsat yo‘q");
    await expect(page.locator("tbody tr")).toHaveCount(0);
  });
  test("late previous-clinic response cannot repopulate the next clinic", async ({
    page,
  }) => {
    let release!: () => void;
    const gate = new Promise<void>((r) => (release = r));
    let captured!: () => void;
    const ready = new Promise<void>((r) => (captured = r));
    let first = true;
    await page.route("**/api/v1/clinic/patients?*", async (route) => {
      if (!first) {
        await route.continue();
        return;
      }
      first = false;
      const response = await route.fetch();
      captured();
      await gate;
      try {
        await route.fulfill({ response });
      } catch {
        /* The aborted old request is intentionally no longer fulfillable. */
      }
    });
    await login(page);
    await ready;
    await page
      .getByLabel("Faol klinika", { exact: true })
      .selectOption({ label: "Synthetic Clinic b" });
    release();
    await expect(page.locator("tbody")).toContainText("Synthetic UI patient B");
    await expect(page.locator("tbody")).not.toContainText(
      "Synthetic concurrent writer",
    );
  });
  test("late auth/me cannot restore another tab's previous clinic", async ({
    page,
    context,
  }) => {
    await login(page);
    await expect(page.locator("tbody")).toContainText(
      "Synthetic concurrent writer",
    );
    const other = await context.newPage();
    await other.goto("/#bemorlar");
    await expect(other.locator("tbody")).toContainText(
      "Synthetic concurrent writer",
    );
    let release!: () => void, captured!: () => void;
    const gate = new Promise<void>((r) => (release = r)),
      ready = new Promise<void>((r) => (captured = r));
    let first = true;
    await page.route("**/api/v1/auth/me", async (route) => {
      if (!first) {
        await route.continue();
        return;
      }
      first = false;
      const response = await route.fetch();
      captured();
      await gate;
      await route.fulfill({ response });
    });
    await page.reload({ waitUntil: "domcontentloaded" });
    await ready;
    await other
      .getByLabel("Faol klinika", { exact: true })
      .selectOption({ label: "Synthetic Clinic b" });
    await expect(other.locator("tbody")).toContainText(
      "Synthetic UI patient B",
    );
    release();
    await expect(page.getByLabel("Faol klinika", { exact: true })).toHaveValue(
      await other.getByLabel("Faol klinika", { exact: true }).inputValue(),
    );
    await expect(page.locator("tbody")).toContainText("Synthetic UI patient B");
    await expect(page.locator("tbody")).not.toContainText(
      "Synthetic concurrent writer",
    );
    await other.close();
  });
});
