import { test, expect } from "@playwright/test";
import { editorSession } from "./access-editor.fixture";
test.use({ viewport: { width: 1536, height: 1024 } });
test("server profiles load; search and profile retain job independently from permission role", async ({
  page,
}) => {
  const s = await editorSession(page);
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await expect(page.locator(".e-stats")).toContainText("24");
  await page.getByLabel("Xodimlarni qidirish").fill("Aziz");
  await expect(page.locator("tbody")).toContainText("Aziz");
  expect(
    s.requests.some((r) => r.path.endsWith("/employees") && r.method === "GET"),
  ).toBe(true);
  await page
    .getByRole("row", { name: "Aziz Karimov profilini ochish" })
    .click();
  await page.getByRole("button", { name: "Tahrirlash", exact: true }).click();
  await expect(page.getByLabel("Lavozim *", { exact: true })).toBeVisible();
  await expect(page.getByLabel("Ruxsat roli *", { exact: true })).toBeVisible();
  await expect(page.locator("input[type=file]")).toBeDisabled();
});
test("loginless profile writes through API, survives reload, never stores password", async ({
  page,
}) => {
  const s = await editorSession(page);
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page
    .getByRole("button", { name: "Xodim qo‘shish", exact: true })
    .click();
  await page.getByLabel("Familiya *", { exact: true }).fill("APIProfile");
  await page.getByLabel("Ism *", { exact: true }).fill("Independent");
  await page.getByLabel("Telefon *", { exact: true }).fill("+998 90 123 45 67");
  await page
    .getByRole("switch", { name: "Tizimga kirishga ruxsat berish" })
    .click();
  await expect(page.getByLabel("Login *", { exact: true })).toHaveCount(0);
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByLabel("Xodimlarni qidirish")).toBeVisible();
  const request = s.requests.find(
    (r) => r.method === "POST" && r.path.endsWith("/employees"),
  )!;
  expect(request.body.access).toBe(false);
  expect(request.body.temporary_password).toBeUndefined();
  expect(request.headers["idempotency-key"]).toBeTruthy();
  await page.reload();
  await page.getByLabel("Xodimlarni qidirish").fill("APIProfile");
  await expect(page.locator("tbody")).toContainText("Independent");
  expect(await page.evaluate(() => JSON.stringify(localStorage))).not.toContain(
    "temporary_password",
  );
});
test("employee form validates without posting an invalid account", async ({
  page,
}) => {
  const s = await editorSession(page);
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page
    .getByRole("button", { name: "Xodim qo‘shish", exact: true })
    .click();
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByLabel("Familiya *", { exact: true })).toBeVisible();
  expect(
    s.requests.filter(
      (r) => r.method === "POST" && r.path.endsWith("/employees"),
    ),
  ).toHaveLength(0);
});
test("missing employees permission denies direct hash without fetching profiles", async ({
  page,
}) => {
  const s = await editorSession(page, { "patients.view": "own" });
  await page.evaluate(() => (location.hash = "xodimlar"));
  await expect(
    page.getByRole("heading", { name: "403 — Ruxsat yo‘q" }),
  ).toBeVisible();
  expect(s.requests.filter((r) => r.path.endsWith("/employees"))).toHaveLength(
    0,
  );
});

test("phone edit preserves two access branches without assign_branches authority", async ({
  page,
}) => {
  const { allServerGrants } = await import("./access.fixture");
  const grants = { ...allServerGrants };
  delete grants["employees.assign_branches"];
  const s = await editorSession(page, grants);
  const e = s.employees.find((e) => e.profile.first === "Aziz")!;
  e.branch_ids = ["branch-a", "branch-b"];
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page.getByLabel("Xodimlarni qidirish").fill("Aziz");
  await page
    .getByRole("row", { name: "Aziz Karimov profilini ochish" })
    .click();
  await page.getByRole("button", { name: "Tahrirlash", exact: true }).click();
  await page.getByLabel("Telefon *", { exact: true }).fill("+998 90 999 88 77");
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByLabel("Xodimlarni qidirish")).toBeVisible();
  const patch = s.requests.find(
    (r) => r.method === "PATCH" && r.path.endsWith("/" + e.id),
  )!;
  expect(patch.body).not.toHaveProperty("branch_ids");
  expect(e.branch_ids).toEqual(["branch-a", "branch-b"]);
});

test("explicit access branch selection sends only the chosen authorized assignments", async ({
  page,
}) => {
  const s = await editorSession(page);
  const e = s.employees.find((e) => e.profile.first === "Aziz")!;
  e.branch_ids = ["branch-a", "branch-b"];
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page.getByLabel("Xodimlarni qidirish").fill("Aziz");
  await page
    .getByRole("row", { name: "Aziz Karimov profilini ochish" })
    .click();
  await page.getByRole("button", { name: "Tahrirlash", exact: true }).click();
  await page
    .getByRole("checkbox", {
      name: "Kirish filiali: Qo‘shimcha filial",
      exact: true,
    })
    .uncheck();
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByLabel("Xodimlarni qidirish")).toBeVisible();
  const patch = s.requests.find(
    (r) => r.method === "PATCH" && r.path.endsWith("/" + e.id),
  )!;
  expect(patch.body.branch_ids).toEqual(["branch-a"]);
});

test("employee page combinations persist and cashier PIN stays blank on reopen", async ({
  page,
}) => {
  const s = await editorSession(page);
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page
    .getByRole("button", { name: "Xodim qo‘shish", exact: true })
    .click();
  await page.getByLabel("Familiya *", { exact: true }).fill("MultiPage");
  await page.getByLabel("Ism *", { exact: true }).fill("Worker");
  await page.getByLabel("Telefon *", { exact: true }).fill("+998 90 123 45 67");
  await page
    .getByRole("switch", { name: "Tizimga kirishga ruxsat berish" })
    .click();
  for (const label of [
    "Registratura",
    "Kassa",
    "UZI va Diagnostika",
    "Shifokor kabineti",
    "Navbatlar",
    "Tablo boshqaruvi",
  ])
    await page
      .getByRole("checkbox", { name: "Bo‘lim: " + label, exact: true })
      .check();
  await expect(
    page.getByLabel("Kassa PIN-kodi", { exact: true }),
  ).toHaveAttribute("type", "password");
  await page.getByLabel("Kassa PIN-kodi", { exact: true }).fill("1122");
  await page.getByLabel("PIN-kodni tasdiqlash", { exact: true }).fill("1122");
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByLabel("Xodimlarni qidirish")).toBeVisible();
  const req = s.requests.find(
    (r) => r.method === "POST" && r.path.endsWith("/employees"),
  )!;
  expect(
    req.body.page_grants.map((g: { code: string }) => g.code).sort(),
  ).toEqual(
    [
      "reception.view",
      "cashier.view",
      "diagnostics.view",
      "workspace.view",
      "queues.view",
      "display.view",
    ].sort(),
  );
  expect(req.body.cashier_pin).toBe("1122");
  expect(req.body.access).toBe(false);
  await page.reload();
  await page.getByLabel("Xodimlarni qidirish").fill("MultiPage");
  await page
    .getByRole("row", { name: "Worker MultiPage profilini ochish" })
    .click();
  await page.getByRole("button", { name: "Tahrirlash", exact: true }).click();
  await expect(page.getByLabel("Kassa PIN-kodi", { exact: true })).toHaveValue(
    "",
  );
  await expect(
    page.getByRole("checkbox", { name: "Bo‘lim: Kassa", exact: true }),
  ).toBeChecked();
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByLabel("Xodimlarni qidirish")).toBeVisible();
  expect(s.requests.find((r) => r.method === "PATCH")!.body).not.toHaveProperty(
    "cashier_pin",
  );
  expect(await page.evaluate(() => JSON.stringify(localStorage))).not.toContain(
    "1122",
  );
});
