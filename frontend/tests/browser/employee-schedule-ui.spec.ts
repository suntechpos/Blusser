import { test, expect } from "@playwright/test";
import { editorSession } from "./access-editor.fixture";

test("schedule day rows preserve times, prevent duplicates, validate and cancel without writes", async ({
  page,
}) => {
  const session = await editorSession(page);
  for (const path of [
    "specialties",
    "appointments/configuration-services",
    "rooms",
  ]) {
    await page.route(`**/api/v1/clinic/${path}**`, (route) =>
      route.fulfill({
        json: {
          data: [
            {
              id: "ui-option",
              name: "UI option",
              number: "UI room",
              availability: "available",
            },
          ],
          meta: { total: 1 },
        },
      }),
    );
  }
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page
    .getByRole("button", { name: "Xodim qo‘shish", exact: true })
    .click();
  const section = page.getByRole("region", {
    name: "Ish jadvali",
    exact: true,
  });
  await expect(
    section.getByText("Umumiy xodim ish jadvali", { exact: false }),
  ).toBeVisible();
  await expect(
    section.getByRole("button", { name: "Jadvalni saqlash", exact: true }),
  ).toBeDisabled();
  await expect(section.getByLabel("Kunlarni alohida sozlash")).toBeChecked();
  await expect(
    section.getByLabel("Ish kuni: Dushanba", { exact: true }),
  ).toHaveCount(0);
  await expect(
    section.getByRole("button", { name: "Yana bir kun qo‘shish", exact: true }),
  ).toBeDisabled();
  await page.setViewportSize({ width: 1536, height: 1000 });
  await expect(
    section.getByLabel("Amal qilish boshlanish sanasi", { exact: true }),
  ).toHaveCount(0);
  await expect(
    section.getByLabel("Amal qilish tugash sanasi", { exact: true }),
  ).toHaveCount(0);
  await section.screenshot({
    path: "/tmp/employee-schedule-default-readonly.png",
  });
  await page.getByLabel("Lavozim *", { exact: true }).selectOption("Shifokor");
  await expect(
    section.getByLabel("Qabul xonasi", { exact: true }).locator("option"),
  ).toHaveCount(2);
  await section.getByLabel("Ish jadvalini saqlash", { exact: true }).check();
  await expect(
    section.getByRole("radio", { name: /Umumiy xona/ }),
  ).toBeChecked();
  await section.getByRole("radio", { name: /Shaxsiy xona/ }).check();
  await expect(
    section.getByText("Bu xona faqat ushbu shifokor", { exact: false }),
  ).toBeVisible();
  await expect(
    section.getByText("Bu tanlov hozircha UI namunasi", { exact: false }),
  ).toBeVisible();
  await section.getByRole("radio", { name: /Umumiy xona/ }).check();
  await expect(
    section.getByText("Bir xil vaqt oralig‘idagi bandlik", { exact: false }),
  ).toBeVisible();

  await expect(section.getByLabel("Kunlarni alohida sozlash")).toBeChecked();
  await expect(
    section.getByLabel("Ish kuni: Dushanba", { exact: true }),
  ).toHaveCount(0);
  const add = section.getByRole("button", {
    name: "Yana bir kun qo‘shish",
    exact: true,
  });
  await add.click();
  await add.click();
  await section
    .getByLabel("Dushanba boshlanish", { exact: true })
    .fill("10:00");
  await section.getByLabel("Dushanba hafta kuni").selectOption("3");
  await expect(
    section.getByLabel("Chorshanba boshlanish", { exact: true }),
  ).toHaveValue("10:00");
  await expect(
    section.getByLabel("Seshanba hafta kuni").locator('option[value="3"]'),
  ).toHaveAttribute("disabled", "");
  await section
    .getByRole("button", { name: "Yana bir kun qo‘shish", exact: true })
    .click();
  await expect(section.locator(".employee-schedule-day")).toHaveCount(3);
  await section
    .getByRole("button", { name: "Dushanba kunini olib tashlash" })
    .click();
  await expect(section.locator(".employee-schedule-day")).toHaveCount(2);
  await page
    .getByPlaceholder("Mutaxassislikni kiriting", { exact: true })
    .fill("UI option");
  await section
    .getByLabel("Qabul xonasi", { exact: true })
    .selectOption("ui-option");
  await section.getByLabel("Shifokor xizmati: UI option").check();
  await section.getByLabel("Chorshanba tugash", { exact: true }).fill("09:00");
  await section
    .getByRole("button", { name: "Jadvalni saqlash", exact: true })
    .click();
  await expect(section.getByRole("alert")).toContainText(
    "tugash vaqti boshlanishdan keyin",
  );
  await section.getByLabel("Chorshanba tugash", { exact: true }).fill("16:00");
  for (const width of [1536, 900, 390]) {
    await page.setViewportSize({ width, height: 1000 });
    expect(
      await section.evaluate((el) => el.scrollWidth <= el.clientWidth),
    ).toBe(true);
    if (width === 390)
      expect(
        await section
          .locator(".employee-schedule-day")
          .first()
          .evaluate(
            (el) => getComputedStyle(el).gridTemplateColumns.split(" ").length,
          ),
      ).toBe(1);
  }
  await section.screenshot({
    path: "/tmp/employee-schedule-default-mobile.png",
  });
  await page.setViewportSize({ width: 1536, height: 1000 });
  await section.screenshot({
    path: "/tmp/employee-schedule-default-desktop.png",
  });
  await section.getByLabel("Kunlarni alohida sozlash").uncheck();
  await expect(
    section.getByLabel("Ish kuni: Chorshanba", { exact: true }),
  ).toBeChecked();
  await section.getByLabel("Ish boshlanishi", { exact: true }).fill("08:00");
  await section.getByLabel("Ish tugashi", { exact: true }).fill("18:00");
  await section.getByLabel("Kunlarni alohida sozlash").check();
  await expect(
    section.getByLabel("Chorshanba boshlanish", { exact: true }),
  ).toHaveValue("10:00");
  await section
    .getByRole("button", { name: "Bekor qilish", exact: true })
    .click();
  await expect(
    section.getByLabel("Ish jadvalini saqlash", { exact: true }),
  ).not.toBeChecked();
  expect(
    session.requests.filter((r) =>
      ["POST", "PATCH", "PUT", "DELETE"].includes(r.method),
    ),
  ).toHaveLength(0);
});

test("empty room API shows disabled design samples without inventing selectable room IDs", async ({
  page,
}) => {
  await editorSession(page);
  for (const path of [
    "specialties",
    "appointments/configuration-services",
    "rooms",
  ])
    await page.route(`**/api/v1/clinic/${path}**`, (route) =>
      route.fulfill({ json: { data: [], meta: { total: 0 } } }),
    );
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page
    .getByRole("button", { name: "Xodim qo‘shish", exact: true })
    .click();
  await page.getByLabel("Lavozim *", { exact: true }).selectOption("Shifokor");
  const section = page.getByRole("region", {
    name: "Ish jadvali",
    exact: true,
  });
  await section.getByLabel("Ish jadvalini saqlash", { exact: true }).check();
  const rooms = section.getByLabel("Qabul xonasi", { exact: true });
  await expect(rooms).toHaveValue("");
  for (const name of ["101-xona", "102-xona", "103-xona"])
    await expect(
      rooms.locator("option").filter({ hasText: name }),
    ).toHaveAttribute("disabled", "");
  await expect(rooms.locator("option:not([disabled])")).toHaveCount(1);
});
