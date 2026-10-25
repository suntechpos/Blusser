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
  await section.getByLabel("Ish jadvalini saqlash", { exact: true }).check();
  await expect(
    section.getByLabel("Bemor qabul qiladi", { exact: true }),
  ).not.toBeChecked();
  await section.getByLabel("Bemor qabul qiladi", { exact: true }).check();
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
  await section.getByRole("button", { name: "Qabul xizmatlarini tanlash" }).click();
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

test("empty room API shows honest empty state without demo IDs", async ({
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
  await section.getByLabel("Bemor qabul qiladi", { exact: true }).check();
  const rooms = section.getByLabel("Qabul xonasi", { exact: true });
  await expect(rooms).toHaveValue("");
  await expect(
    section.getByText("Bu filialda qabul xonasi mavjud emas", { exact: true }),
  ).toBeVisible();
  await expect(rooms.locator("option")).toHaveCount(1);
  await expect(rooms.locator("option:not([disabled])")).toHaveCount(1);
});

test("doctor can search, select and remove multiple real API catalog services", async ({
  page,
}) => {
  const session = await editorSession(page);
  for (const path of ["specialties", "rooms"]) {
    await page.route(`**/api/v1/clinic/${path}**`, (route) =>
      route.fulfill({ json: { data: [], meta: { total: 0 } } }),
    );
  }
  await page.route("**/api/v1/clinic/appointments/configuration-services**", (route) => {
    if (route.request().method() !== "GET")
      return route.fulfill({ status: 405 });
    return route.fulfill({
      json: {
        data: [
          { id: "service-1", name: "Birlamchi qabul", active: true },
          { id: "service-2", name: "Takroriy qabul", active: true },
        ],
        meta: { total: 2 },
      },
    });
  });

  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page.getByRole("button", { name: "Xodim qo‘shish", exact: true }).click();
  await page.getByLabel("Lavozim *", { exact: true }).selectOption("Shifokor");
  const section = page.getByRole("region", { name: "Ish jadvali", exact: true });
  await section.getByLabel("Ish jadvalini saqlash", { exact: true }).check();
  await section.getByLabel("Bemor qabul qiladi", { exact: true }).check();
  await expect(section.getByLabel("Yangi qabul xizmati nomi")).toHaveCount(0);
  await section.getByRole("button", { name: "Qabul xizmatlarini tanlash" }).click();
  const search = section.getByRole("searchbox", { name: "Qabul xizmatini qidirish" });
  await search.fill("Birlamchi");
  await expect(section.getByLabel("Shifokor xizmati: Birlamchi qabul")).toBeVisible();
  await expect(section.getByLabel("Shifokor xizmati: Takroriy qabul")).toHaveCount(0);
  await section.getByLabel("Shifokor xizmati: Birlamchi qabul").check();
  await search.fill("");
  await section.getByLabel("Shifokor xizmati: Takroriy qabul").check();
  await expect(section.getByRole("button", { name: "Qabul xizmatlarini tanlash" }))
    .toContainText("2 ta xizmat tanlangan");
  await expect(section.getByRole("button", { name: "Xizmatni olib tashlash: Birlamchi qabul" }))
    .toBeVisible();
  await section.getByRole("button", { name: "Xizmatni olib tashlash: Birlamchi qabul" }).click();
  await expect(section.getByLabel("Shifokor xizmati: Birlamchi qabul")).not.toBeChecked();
  await expect(section.getByLabel("Shifokor xizmati: Takroriy qabul")).toBeChecked();
  await expect(section.getByRole("button", { name: "Qabul xizmatlarini tanlash" }))
    .toContainText("1 ta xizmat tanlangan");
  expect(session.requests.filter((r) =>
    r.path.includes("configuration-services") && r.method !== "GET",
  )).toHaveLength(0);
});
