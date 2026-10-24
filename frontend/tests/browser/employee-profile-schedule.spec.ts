import { test, expect, type Page } from "@playwright/test";
import { editorSession } from "./access-editor.fixture";
async function profile(page: Page, mode = "filled") {
  const s = await editorSession(page);
  const e = s.employees.find((e) => e.profile.first === "Aziz")!;
  for (const path of ["doctors", "rooms", "schedules", "schedule-exceptions"]) {
    await page.route(`**/api/v1/clinic/${path}**`, async (route) => {
      if (mode === "error" && path === "schedules")
        return route.fulfill({
          status: 503,
          json: { message: "Synthetic schedule read failure" },
        });
      let data: any[] = [];
      if (path === "doctors" && mode !== "empty")
        data = [{ id: "doctor", membership_id: e.membership_id }];
      if (path === "rooms")
        data = [
          { id: "r1", number: "Real room A" },
          { id: "r2", number: "Real room B" },
        ];
      if (path === "schedules") {
        expect(route.request().url()).toContain("doctor_id=doctor");
        const rule = {
          doctor_id: "doctor",
          effective_from: "2026-01-01",
          effective_to: null,
        };
        data = [
          {
            ...rule,
            id: "s1",
            weekday: 1,
            local_start: "09:15",
            local_end: "14:45",
            slot_minutes: 25,
            room_id: "r1",
          },
          {
            ...rule,
            id: "s2",
            weekday: 2,
            local_start: "10:00",
            local_end: "16:00",
            slot_minutes: 40,
            room_id: "r2",
          },
        ];
      }
      if (path === "schedule-exceptions")
        data = [
          {
            id: "ex1",
            local_date: "2026-11-01",
            kind: "closed",
            replacement: null,
          },
          {
            id: "ex2",
            local_date: "2026-11-02",
            kind: "replace",
            replacement: { local_start: "11:00", local_end: "13:00" },
          },
        ];
      await route.fulfill({ json: { data, meta: { total: data.length } } });
    });
  }
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page
    .getByRole("row", { name: "Aziz Karimov profilini ochish" })
    .click();
  await page.getByRole("tab", { name: "Ish jadvali", exact: true }).click();
  return page.getByLabel("Saqlangan ish jadvali", { exact: true });
}
test("profile schedule is read-only, resolves real rooms, shows all days and server exceptions", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1536, height: 1000 });
  const section = await profile(page);
  const table = section.getByRole("table", {
    name: "Haftalik ish jadvali",
    exact: true,
  });
  await expect(table.locator("tbody tr")).toHaveCount(7);
  await expect(table.getByRole("row", { name: /Dushanba/ })).toContainText(
    "09:15–14:45",
  );
  await expect(table.getByRole("row", { name: /Dushanba/ })).toContainText(
    "25 daqiqa",
  );
  await expect(table.getByRole("row", { name: /Dushanba/ })).toContainText(
    "Real room A",
  );
  await expect(table.getByRole("row", { name: /Seshanba/ })).toContainText(
    "Real room B",
  );
  await expect(
    table
      .getByRole("row", { name: /Seshanba/ })
      .locator("td")
      .last(),
  ).toHaveText("—");
  await expect(table.getByRole("row", { name: /Yakshanba/ })).toContainText(
    "Qabul yo‘q",
  );
  await expect(
    section.getByRole("table", { name: "Istisno sanalar", exact: true }),
  ).toContainText("11:00–13:00");
  await expect(section.locator("input, select, button")).toHaveCount(0);
  await expect(
    page.getByRole("button", { name: "Tahrirlash", exact: true }),
  ).toBeVisible();
  await section.screenshot({
    path: "/tmp/employee-profile-schedule-readonly.png",
  });
  await page.setViewportSize({ width: 390, height: 900 });
  expect(await section.evaluate((el) => el.scrollWidth <= el.clientWidth)).toBe(
    true,
  );
});
test("missing doctor profile shows honest empty states without demo schedule", async ({
  page,
}) => {
  const section = await profile(page, "empty");
  await expect(
    section.getByText("Ish jadvali hali yaratilmagan", { exact: true }),
  ).toBeVisible();
  await expect(
    section.getByText("Istisno sanalar yo‘q", { exact: true }),
  ).toBeVisible();
  await expect(
    section
      .getByRole("table", { name: "Haftalik ish jadvali", exact: true })
      .locator("tbody tr"),
  ).toHaveCount(7);
});
test("schedule API errors are not represented as empty schedules", async ({
  page,
}) => {
  const section = await profile(page, "error");
  await expect(section.getByRole("alert")).toContainText("yuklab bo‘lmadi");
  await expect(
    section.getByText("Ish jadvali hali yaratilmagan", { exact: true }),
  ).toHaveCount(0);
});
