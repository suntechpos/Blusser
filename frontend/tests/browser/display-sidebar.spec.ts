import { test, expect } from "@playwright/test";
import { editorSession } from "./access-editor.fixture";

test("employee with Dashboard, Cashier and display.view can open Tablo from the sidebar", async ({
  page,
}) => {
  await editorSession(page, {
    "dashboard.view": "entire_clinic",
    "cashier.view": "assigned_branches",
    "display.view": "assigned_branches",
  });
  const sidebar = page.locator(".clinic-sidebar");
  const group = sidebar
    .locator(".clinic-nav-group")
    .filter({ hasText: "BEMORLAR VA QABULLAR" });
  const tablo = group.getByRole("button", {
    name: "Tablo boshqaruvi",
    exact: true,
  });
  await expect(tablo).toBeVisible();
  await expect(
    sidebar.getByRole("button", { name: "Navbatlar", exact: true }),
  ).toHaveCount(0);
  await tablo.click();
  await expect(page).toHaveURL(/#tablo$/);
  await expect(
    page.getByRole("heading", { name: "Tablo boshqaruvi", exact: true }),
  ).toBeVisible();
  await page.reload();
  await expect(
    page.getByRole("heading", { name: "Tablo boshqaruvi", exact: true }),
  ).toBeVisible();
});

test("employee without display.view has no Tablo menu and its direct URL is denied", async ({
  page,
}) => {
  await editorSession(page, {
    "dashboard.view": "entire_clinic",
    "cashier.view": "entire_clinic",
  });
  await expect(
    page
      .locator(".clinic-sidebar")
      .getByRole("button", { name: "Tablo boshqaruvi", exact: true }),
  ).toHaveCount(0);
  await page.evaluate(() => {
    location.hash = "tablo";
  });
  await expect(
    page.getByRole("heading", { name: "403 — Ruxsat yo‘q" }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Tablo boshqaruvi", exact: true }),
  ).toHaveCount(0);
});

test("Tablo follows Navbatlar when both pages are authorized", async ({
  page,
}) => {
  await editorSession(page, { "queues.view": "own", "display.view": "own" });
  const group = page
    .locator(".clinic-sidebar .clinic-nav-group")
    .filter({ hasText: "BEMORLAR VA QABULLAR" });
  await expect(group.getByRole("button")).toHaveText([
    "Navbatlar",
    "Tablo boshqaruvi",
  ]);
});
