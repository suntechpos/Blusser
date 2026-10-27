import { test, expect, type Page } from "@playwright/test";
import { editorSession } from "./access-editor.fixture";
import { allServerGrants } from "./access.fixture";

test.use({ viewport: { width: 1536, height: 1024 } });

async function newAppointmentRole(page: Page, grants = allServerGrants) {
  const state = await editorSession(page, grants);
  await page.getByRole("button", { name: "Sozlamalar", exact: true }).click();
  await expect(page.locator(".sr-role")).toHaveCount(6);
  await page.getByRole("button", { name: "Rol qo‘shish", exact: true }).click();
  await page
    .getByLabel("Rol nomi", { exact: true })
    .fill("Appointment scope regression");
  await page.getByRole("button", { name: /^Qabullar \d/ }).click();
  await page
    .getByRole("checkbox", { name: "Qabullarni ko‘rish", exact: true })
    .check();
  return state;
}

async function enableScope(page: Page, label: string, scope: string) {
  await page.getByRole("checkbox", { name: label, exact: true }).check();
  await page
    .getByLabel(label + " doirasi", { exact: true })
    .selectOption(scope);
}

test("assigned-branch appointment view contains own create, cancel and no-show actions", async ({
  page,
}) => {
  const state = await newAppointmentRole(page);
  await page
    .getByLabel("Qabullarni ko‘rish doirasi", { exact: true })
    .selectOption("assigned_branches");
  await enableScope(page, "Qabul yaratish", "own");
  await enableScope(page, "Qabulni bekor qilish", "own");
  await enableScope(page, "Kelmaganini belgilash", "own");
  await expect(page.getByRole("alert")).toHaveCount(0);
  await page
    .getByRole("button", { name: "Rolni yaratish", exact: true })
    .click();
  await expect(page.getByRole("status")).toContainText("serverda saqlandi");
  expect(
    state.roles.find((role) => role.name === "Appointment scope regression")
      ?.grants,
  ).toEqual([
    { code: "appointments.view", scope: "assigned_branches" },
    { code: "appointments.create", scope: "own" },
    { code: "appointments.cancel", scope: "own" },
    { code: "appointments.no_show", scope: "own" },
  ]);
});

test("own appointment view cannot contain an action covering assigned branches", async ({
  page,
}) => {
  const state = await newAppointmentRole(page);
  await page
    .getByLabel("Qabullarni ko‘rish doirasi", { exact: true })
    .selectOption("own");
  await enableScope(page, "Qabulni bekor qilish", "assigned_branches");
  await expect(page.getByRole("alert")).toContainText("Qabulni bekor qilish");
  await expect(page.getByRole("alert")).toContainText(
    "Ichki amal doirasi ko‘rish doirasidan keng bo‘lmasin",
  );
  await page
    .getByRole("button", { name: "Rolni yaratish", exact: true })
    .click();
  expect(
    state.requests.filter(
      (request) => request.method === "POST" && request.path.endsWith("/roles"),
    ),
  ).toHaveLength(0);
  await expect(page.getByLabel("Rol nomi", { exact: true })).toHaveValue(
    "Appointment scope regression",
  );
});

test("an own-scoped delegator may grant only own appointment creation", async ({
  page,
}) => {
  const state = await newAppointmentRole(page, {
    ...allServerGrants,
    "appointments.create": "own",
  });
  await page
    .getByLabel("Qabullarni ko‘rish doirasi", { exact: true })
    .selectOption("assigned_branches");
  await enableScope(page, "Qabul yaratish", "own");
  const scope = page.getByLabel("Qabul yaratish doirasi", { exact: true });
  await expect(scope.locator("option")).toHaveCount(1);
  await expect(scope.locator("option")).toHaveAttribute("value", "own");
  await page
    .getByRole("button", { name: "Rolni yaratish", exact: true })
    .click();
  await expect(page.getByRole("status")).toContainText("serverda saqlandi");
  expect(
    state.roles.find((role) => role.name === "Appointment scope regression")
      ?.grants,
  ).toContainEqual({
    code: "appointments.create",
    scope: "own",
  });
});
