import { test, expect, type Page } from "@playwright/test";
import { readFile } from "node:fs/promises";
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
    await expect(page.locator(".patients-footer")).not.toContainText(
      "0 ta bemor",
    );
    await expect(page.locator(".patients-footer")).toContainText("—");
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
      try {
        await route.fulfill({ response });
      } catch {
        /* Context changes abort this superseded auth request. */
      }
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
  test("unchanged session recheck preserves an unsaved patient draft", async ({
    page,
  }) => {
    await login(page);
    await page
      .locator("tbody tr")
      .filter({ hasText: "Synthetic concurrent writer" })
      .getByRole("button", { name: "Tahrirlash", exact: true })
      .click();
    const dialog = page.getByRole("dialog");
    await dialog
      .getByLabel("F.I.Sh. *", { exact: true })
      .fill("Synthetic unsaved draft");
    const checked = page.waitForResponse((r) =>
      r.url().endsWith("/api/v1/clinic/context"),
    );
    await page.evaluate(() => window.dispatchEvent(new Event("focus")));
    await checked;
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel("F.I.Sh. *", { exact: true })).toHaveValue(
      "Synthetic unsaved draft",
    );
  });

  test("scoped view plus export grant does not expose an unusable CSV control", async ({
    page,
  }) => {
    await login(page, "synthetic.scopedexport");
    await expect(page.locator("tbody")).toContainText(
      "Synthetic concurrent writer",
    );
    await expect(
      page.getByRole("button", { name: "Eksport", exact: true }),
    ).toHaveCount(0);
    expect(
      await page.evaluate(async () => {
        const { api } = await import("/src/services/api.ts");
        try {
          await api.get("/api/v1/clinic/patients/export");
          return 200;
        } catch (e) {
          return e.response.status;
        }
      }),
    ).toBe(403);
  });
  test("CSV contains only the selected clinic, search and branch", async ({
    page,
  }) => {
    await login(page);
    await expect(page.locator("tbody")).toContainText(
      "Synthetic concurrent writer",
    );
    await page
      .getByPlaceholder("F.I.Sh., bemor raqami yoki telefon...")
      .fill("Synthetic concurrent");
    await expect(page.locator("tbody")).toContainText(
      "Synthetic concurrent writer",
    );
    const downloaded = page.waitForEvent("download");
    await page.getByRole("button", { name: "Eksport", exact: true }).click();
    const csv = await readFile((await (await downloaded).path())!, "utf8");
    expect(csv).toContain(
      "patient_number,name,phone,date_of_birth,gender,archived_at",
    );
    expect(csv).toContain("Synthetic concurrent writer");
    expect(csv).not.toContain("Synthetic UI patient B");
    await page
      .getByLabel("Filial filtri", { exact: true })
      .selectOption({ label: "Synthetic second branch" });
    await expect(page.locator("tbody")).toContainText("Bemor topilmadi");
    const emptyDownload = page.waitForEvent("download");
    await page.getByRole("button", { name: "Eksport", exact: true }).click();
    const empty = await readFile((await (await emptyDownload).path())!, "utf8");
    expect(empty.trim().split(/\r?\n/)).toHaveLength(1);
  });
  test("committed create with a lost response retries the same key without duplicates", async ({
    page,
  }) => {
    await login(page);
    await page
      .getByRole("button", { name: "Yangi bemor", exact: true })
      .click();
    const dialog = page.getByRole("dialog");
    await dialog
      .getByLabel("F.I.Sh. *", { exact: true })
      .fill("Synthetic uncertain create");
    await dialog
      .getByLabel("Tug‘ilgan sana *", { exact: true })
      .fill("1991-02-03");
    await dialog
      .getByLabel("Filial", { exact: true })
      .selectOption({ label: "Synthetic branch" });
    const keys: string[] = [];
    let first = true;
    await page.route("**/api/v1/clinic/patients", async (route) => {
      if (route.request().method() !== "POST") {
        await route.continue();
        return;
      }
      keys.push(route.request().headers()["idempotency-key"]!);
      if (!first) {
        await route.continue();
        return;
      }
      first = false;
      const response = await route.fetch();
      expect(response.status()).toBe(201);
      await route.abort("failed");
    });
    await dialog.getByRole("button", { name: "Saqlash", exact: true }).click();
    await expect(dialog.getByRole("alert")).toContainText(
      "Server bilan bog‘lanib bo‘lmadi",
    );
    await dialog.getByRole("button", { name: "Saqlash", exact: true }).click();
    await expect(dialog).toBeHidden();
    expect(keys).toHaveLength(2);
    expect(keys[1]).toBe(keys[0]);
    await page
      .getByPlaceholder("F.I.Sh., bemor raqami yoki telefon...")
      .fill("Synthetic uncertain create");
    await expect(page.locator("tbody tr")).toHaveCount(1);
    await expect(page.locator("tbody")).toContainText(
      "Synthetic uncertain create",
    );
  });
  test("changed authorization on focus purges patient details and drafts", async ({
    page,
  }) => {
    await login(page);
    await page
      .locator("tbody tr")
      .filter({ hasText: "Synthetic concurrent writer" })
      .getByRole("button", { name: "Tahrirlash", exact: true })
      .click();
    await expect(page.getByRole("dialog")).toBeVisible();
    await page.route("**/api/v1/clinic/context", async (route) => {
      const response = await route.fetch();
      const data = await response.json();
      delete data.grants["patients.view"];
      delete data.grants["patients.update"];
      await route.fulfill({ response, json: data });
    });
    await page.evaluate(() => window.dispatchEvent(new Event("focus")));
    await expect(page.getByRole("dialog")).toBeHidden();
    await expect(page.locator("tbody tr")).toHaveCount(0);
    await expect(page.getByRole("heading", { name: "403 — Ruxsat yo‘q", exact: true })).toBeVisible();
  });
  test("logout cannot be undone by a delayed auth response", async ({
    page,
  }) => {
    await login(page);
    await expect(page.locator("tbody")).toContainText(
      "Synthetic concurrent writer",
    );
    let release!: () => void, captured!: () => void, finished!: () => void;
    const gate = new Promise<void>((r) => (release = r)),
      ready = new Promise<void>((r) => (captured = r)),
      settled = new Promise<void>((r) => (finished = r));
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
      try {
        await route.fulfill({ response });
      } catch {
        /* Logout aborted the superseded request. */
      } finally {
        finished();
      }
    });
    await page.evaluate(() => window.dispatchEvent(new Event("focus")));
    await ready;
    await logout(page);
    release();
    await settled;
    await expect(page.getByLabel("Login", { exact: true })).toBeVisible();
    await expect(page.locator(".patients")).toHaveCount(0);
  });
  test("a failed context refresh after a server-side clinic change reports the failure", async ({
    page,
  }) => {
    await login(page);
    await expect(page.locator("tbody")).toContainText(
      "Synthetic concurrent writer",
    );
    await page.evaluate(async () => {
      const { api } = await import("/src/services/api.ts");
      const { data } = await api.get("/api/v1/auth/clinics");
      const clinic = data.data.find((c) => c.name === "Synthetic Clinic b");
      await api.post("/api/v1/auth/active-clinic", { tenant_id: clinic.id });
    });
    await page.route("**/api/v1/clinic/context", (route) =>
      route.fulfill({
        status: 503,
        contentType: "application/json",
        body: '{"message":"Unavailable"}',
      }),
    );
    await page.evaluate(() => window.dispatchEvent(new Event("focus")));
    await expect(
      page
        .getByRole("alert")
        .filter({ hasText: "Server bilan bog‘lanib bo‘lmadi" }),
    ).toBeVisible();
    await expect(page.locator("tbody tr")).toHaveCount(0);
    await expect(page.locator(".patients")).toHaveCount(0);
    await expect(page.locator("body")).not.toContainText("Synthetic concurrent writer");
  });
});
