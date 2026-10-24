import { test, expect } from "@playwright/test";
test.use({ viewport: { width: 1536, height: 1024 } });
test("real room and temporary-password doctor save three days and private usage before first login", async ({
  page,
}) => {
  await page.clock.setFixedTime(new Date("2030-01-08T21:30:00Z"));
  await page.goto("/");
  await page.getByLabel("Login", { exact: true }).fill("booking.doctor");
  await page
    .getByLabel("Parol", { exact: true })
    .fill("Synthetic-password-42!");
  await page.getByRole("button", { name: "Kirish", exact: true }).click();
  await page
    .getByRole("button", { name: "Bo‘limlar va Xonalar", exact: true })
    .click();
  await page.getByRole("tab", { name: "Xonalar", exact: true }).click();
  await page
    .getByRole("button", { name: "Xona qo‘shish", exact: true })
    .click();
  const dialog = page.getByRole("dialog");
  await dialog
    .getByLabel("Xona nomi yoki raqami", { exact: true })
    .fill("Synthetic private room");
  await dialog
    .getByLabel("Xona turi", { exact: true })
    .selectOption("Qabul kabineti");
  await dialog.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(dialog).toHaveCount(0);
  await page.reload();
  await page.getByRole("tab", { name: "Xonalar", exact: true }).click();
  await expect(
    page.getByRole("row", { name: /Synthetic private room/ }),
  ).toBeVisible();
  await page
    .getByRole("button", {
      name: "Synthetic private room xonasini tahrirlash",
      exact: true,
    })
    .click();
  await dialog
    .getByLabel("Xona nomi yoki raqami", { exact: true })
    .fill("Synthetic private room updated");
  await dialog.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(dialog).toHaveCount(0);
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page
    .getByRole("button", { name: "Xodim qo‘shish", exact: true })
    .click();
  await page.getByLabel("Familiya *", { exact: true }).fill("RoomIntegration");
  await page.getByLabel("Ism *", { exact: true }).fill("Synthetic");
  await page.getByLabel("Telefon *", { exact: true }).fill("+998 90 765 43 29");
  await page.getByLabel("Lavozim *", { exact: true }).selectOption("Shifokor");
  await page
    .getByPlaceholder("Mutaxassislikni kiriting", { exact: true })
    .fill("Booking specialty");
  await page
    .getByLabel("Login *", { exact: true })
    .fill("synthetic.room-integration");
  await page
    .getByLabel("Ruxsat roli *", { exact: true })
    .selectOption({ label: "Synthetic booking setup" });
  await page
    .getByLabel("Yangi hisob paroli", { exact: true })
    .fill("Synthetic-new-password-42!");
  // Explicit page grants avoid inheriting cashier access; this task does not change PIN behavior.
  const cashier = page.getByLabel("Bo‘lim: Kassa", { exact: true });
  if (await cashier.isChecked()) await cashier.uncheck();
  const section = page.getByRole("region", {
    name: "Ish jadvali",
    exact: true,
  });
  await expect(
    section
      .getByLabel("Qabul xonasi", { exact: true })
      .locator("option")
      .filter({ hasText: "Synthetic private room updated" }),
  ).toHaveCount(1);
  await section.getByLabel("Ish jadvalini saqlash", { exact: true }).check();
  await section
    .getByLabel("Qabul xonasi", { exact: true })
    .selectOption({ label: "Synthetic private room updated" });
  await section.getByRole("radio", { name: /Shaxsiy xona/ }).check();
  await section
    .getByLabel("Shifokor xizmati: Booking consultation", { exact: true })
    .check();
  for (let i = 0; i < 3; i++)
    await section
      .getByRole("button", { name: "Yana bir kun qo‘shish", exact: true })
      .click();
  let employeePosts = 0;
  page.on("request", (r) => {
    if (r.method() === "POST" && r.url().endsWith("/clinic/employees"))
      employeePosts++;
  });
  await section
    .getByRole("button", { name: "Jadvalni saqlash", exact: true })
    .click();
  await expect(page.getByLabel("Xodimlarni qidirish")).toBeVisible();
  expect(employeePosts).toBe(1);
  await page.reload();
  await page.getByLabel("Xodimlarni qidirish").fill("RoomIntegration");
  await page.getByRole("row").filter({ hasText: "RoomIntegration" }).click();
  await page.getByRole("tab", { name: "Ish jadvali", exact: true }).click();
  const table = page.getByRole("table", {
    name: "Haftalik ish jadvali",
    exact: true,
  });
  await expect(table.getByRole("row", { name: /Dushanba/ })).toContainText(
    "Synthetic private room updated",
  );
  await expect(table.getByRole("row", { name: /Dushanba/ })).toContainText(
    "Shaxsiy xona",
  );
  await expect(table.getByText("Shaxsiy xona", { exact: true })).toHaveCount(3);
  await page.getByRole("button", { name: "Tahrirlash", exact: true }).click();
  await expect(
    section.getByRole("radio", { name: /Shaxsiy xona/ }),
  ).toBeChecked();
  await expect(section.locator(".employee-schedule-day")).toHaveCount(3);
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByLabel("Xodimlarni qidirish")).toBeVisible();
  expect(employeePosts).toBe(1);
});
