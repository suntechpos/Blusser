import { test, expect, type Page } from "@playwright/test";
import { editorSession } from "./access-editor.fixture";
import { allServerGrants, serverPermissions } from "./access.fixture";
test.use({ viewport: { width: 1536, height: 1024 } });
const panel = (page: Page) =>
  page.getByRole("region", {
    name: "Ishlashga ruxsat berilgan bo‘limlar",
    exact: true,
  });
const checkbox = (page: Page, name: string) =>
  page.getByRole("checkbox", { name: "Bo‘lim: " + name, exact: true });
async function newForm(page: Page) {
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page
    .getByRole("button", { name: "Xodim qo‘shish", exact: true })
    .click();
}

test("every catalog page appears once, grouped by code with a fallback for future catalog entries", async ({
  page,
}) => {
  await editorSession(page);
  const extra = {
    code: "future.view",
    label: "Yangi bo‘lim",
    section: "future",
    section_label: "Yangi bo‘lim",
    view: "future.view",
    parent: null,
    ui_only: true,
    allowed_scopes: ["own"],
  };
  await page.route("**/api/v1/clinic/permissions", (route) =>
    route.fulfill({
      json: {
        data: [
          ...serverPermissions.map((p) => ({
            ...p,
            allowed_scopes: ["own", "assigned_branches", "entire_clinic"],
          })),
          extra,
        ],
      },
    }),
  );
  await newForm(page);
  const pages = serverPermissions.filter((p) => p.code === p.view);
  await expect(panel(page).getByRole("checkbox")).toHaveCount(pages.length + 1);
  for (const p of pages)
    await expect(checkbox(page, p.section_label)).toHaveCount(1);
  await expect(
    page
      .getByRole("region", { name: "Boshqa bo‘limlar", exact: true })
      .getByRole("checkbox", { name: "Bo‘lim: Yangi bo‘lim" }),
  ).toBeDisabled();
  await checkbox(page, "Registratura").check();
  await checkbox(page, "Bemorlar").check();
  await expect(panel(page).getByRole("status")).toHaveText(
    "2 ta bo‘lim tanlangan",
  );
  await page.getByLabel("Bo‘limlarni qidirish", { exact: true }).fill("tablo");
  await expect(panel(page).getByRole("checkbox")).toHaveCount(1);
  await expect(checkbox(page, "Tablo boshqaruvi")).toBeVisible();
  await expect(panel(page).getByRole("status")).toHaveText(
    "2 ta bo‘lim tanlangan",
  );
  await page
    .getByLabel("Bo‘limlarni qidirish", { exact: true })
    .fill("no-such-page");
  await expect(panel(page).getByText("Bo‘lim topilmadi.")).toBeVisible();
  await page.getByLabel("Bo‘limlarni qidirish", { exact: true }).fill("");
  await expect(checkbox(page, "Registratura")).toBeChecked();
  const collapse = panel(page).getByRole("button", {
    name: /Bemorlar va qabullar/,
  });
  await collapse.click();
  await expect(checkbox(page, "Registratura")).toBeHidden();
  await collapse.click();
  await expect(checkbox(page, "Registratura")).toBeVisible();
  const groups = panel(page).locator(".employee-permissions-group");
  expect(
    await groups.nth(0).evaluate((el) => el.getBoundingClientRect().top),
  ).toBe(await groups.nth(1).evaluate((el) => el.getBoundingClientRect().top));
  await panel(page).screenshot({
    path: "/tmp/employee-permissions-desktop.png",
  });
  for (const width of [768, 390]) {
    await page.setViewportSize({ width, height: 1024 });
    expect(
      await panel(page).evaluate((el) => el.scrollWidth <= el.clientWidth),
    ).toBe(true);
  }
  await panel(page).screenshot({
    path: "/tmp/employee-permissions-mobile.png",
  });
});

test("bulk selection respects actor scopes, parent authority, confirmation and existing PIN card", async ({
  page,
}) => {
  const grants = { ...allServerGrants, "patients.view": "own" };
  delete grants["finance.view"];
  delete grants["services.view"]; // A child cannot be granted without its required parent.
  await editorSession(page, grants);
  await newForm(page);
  await expect(checkbox(page, "Moliya")).toBeDisabled();
  await expect(checkbox(page, "Xizmat kategoriyalari")).toBeDisabled();
  await panel(page)
    .getByRole("button", { name: "Barchasini belgilash", exact: true })
    .click();
  const available = serverPermissions.filter(
    (p) =>
      p.code === p.view && grants[p.code] && (!p.parent || grants[p.parent]),
  );
  await expect(
    panel(page).getByRole("checkbox", { checked: true }),
  ).toHaveCount(available.length);
  await expect(page.getByLabel("Bemorlar kirish doirasi")).toHaveValue("own");
  await expect(
    page.getByLabel("Bemorlar kirish doirasi").locator("option"),
  ).toHaveCount(1);
  await expect(
    page.getByLabel("Kassa PIN-kodi", { exact: true }),
  ).toHaveAttribute("type", "password");
  await panel(page)
    .getByRole("button", { name: "Tozalash", exact: true })
    .click();
  await expect(
    panel(page).getByRole("checkbox", { checked: true }),
  ).toHaveCount(available.length);
  await panel(page)
    .getByRole("button", { name: "Bekor qilish", exact: true })
    .click();
  await expect(
    panel(page).getByRole("checkbox", { checked: true }),
  ).toHaveCount(available.length);
  await panel(page)
    .getByRole("button", { name: "Tozalash", exact: true })
    .click();
  await panel(page)
    .getByRole("button", { name: "Ha, tozalash", exact: true })
    .click();
  await expect(
    panel(page).getByRole("checkbox", { checked: true }),
  ).toHaveCount(0);
  await expect(page.getByLabel("Kassa PIN-kodi", { exact: true })).toHaveCount(
    0,
  );
});

test("multiple selections and scopes save via unchanged payload and reopen for existing employees", async ({
  page,
}) => {
  const s = await editorSession(page);
  await newForm(page);
  await page.getByLabel("Familiya *", { exact: true }).fill("GroupedUI");
  await page.getByLabel("Ism *", { exact: true }).fill("Worker");
  await page.getByLabel("Telefon *", { exact: true }).fill("+998 90 123 45 67");
  await page
    .getByRole("switch", { name: "Tizimga kirishga ruxsat berish" })
    .click();
  await checkbox(page, "Registratura").check();
  await checkbox(page, "Xizmat kategoriyalari").check();
  await expect(checkbox(page, "Xizmatlar")).toBeChecked();
  await page.getByLabel("Registratura kirish doirasi").selectOption("own");
  await expect(panel(page).getByRole("status")).toHaveText(
    "3 ta bo‘lim tanlangan",
  );
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByLabel("Xodimlarni qidirish")).toBeVisible();
  const req = s.requests.find(
    (r) => r.method === "POST" && r.path.endsWith("/employees"),
  )!;
  expect(req.body.page_grants).toEqual([
    { code: "reception.view", scope: "own" },
    { code: "services.view", scope: "assigned_branches" },
    { code: "categories.view", scope: "assigned_branches" },
  ]);
  expect(req.body).not.toHaveProperty("cashier_pin");
  await page.reload();
  await page.getByLabel("Xodimlarni qidirish").fill("GroupedUI");
  await page
    .getByRole("row", { name: "Worker GroupedUI profilini ochish" })
    .click();
  await page.getByRole("button", { name: "Tahrirlash", exact: true }).click();
  await expect(checkbox(page, "Registratura")).toBeChecked();
  await expect(checkbox(page, "Xizmat kategoriyalari")).toBeChecked();
  await expect(page.getByLabel("Registratura kirish doirasi")).toHaveValue(
    "own",
  );
  await panel(page)
    .getByRole("button", { name: "Tozalash", exact: true })
    .click();
  await panel(page)
    .getByRole("button", { name: "Ha, tozalash", exact: true })
    .click();
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByLabel("Xodimlarni qidirish")).toBeVisible();
  expect(
    s.requests.find((r) => r.method === "PATCH")!.body.page_grants,
  ).toEqual([]);
});

test("search and collapse preserve inherited null grants and actors without assignment cannot change grants", async ({
  page,
}) => {
  const grants = { ...allServerGrants };
  delete grants["employees.assign_role"];
  const s = await editorSession(page, grants);
  const e = s.employees.find((e) => e.profile.first === "Aziz")!;
  expect(e.page_grants).toBeNull();
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page.getByLabel("Xodimlarni qidirish").fill("Aziz");
  await page
    .getByRole("row", { name: "Aziz Karimov profilini ochish" })
    .click();
  await page.getByRole("button", { name: "Tahrirlash", exact: true }).click();
  await expect(
    panel(page).getByRole("button", {
      name: "Barchasini belgilash",
      exact: true,
    }),
  ).toBeDisabled();
  await expect(
    panel(page).getByRole("button", { name: "Tozalash", exact: true }),
  ).toBeDisabled();
  for (const box of await panel(page).getByRole("checkbox").all())
    await expect(box).toBeDisabled();
  await page.getByLabel("Bo‘limlarni qidirish", { exact: true }).fill("bemor");
  await page.getByLabel("Bo‘limlarni qidirish", { exact: true }).fill("");
  await page.getByLabel("Telefon *", { exact: true }).fill("+998 90 111 22 33");
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByLabel("Xodimlarni qidirish")).toBeVisible();
  expect(s.requests.find((r) => r.method === "PATCH")!.body).not.toHaveProperty(
    "page_grants",
  );
});
