import { test, expect } from "@playwright/test";
test("employee profile does not fabricate employee working hours from legacy appointment rules", async ({
  page,
}) => {
  await page.goto("/");
  await page.getByLabel("Login", { exact: true }).fill("booking.doctor");
  await page
    .getByLabel("Parol", { exact: true })
    .fill("Synthetic-password-42!");
  await page.getByRole("button", { name: "Kirish", exact: true }).click();
  const writes: string[] = [];
  page.on("request", (r) => {
    if (
      r.url().includes("/clinic/") &&
      ["POST", "PATCH", "PUT", "DELETE"].includes(r.method())
    )
      writes.push(r.url());
  });
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page
    .getByRole("row")
    .filter({ hasText: "Booking test doctor" })
    .click();
  await page.getByRole("tab", { name: "Ish jadvali", exact: true }).click();
  const section = page.getByLabel("Saqlangan ish jadvali", { exact: true });
  const table = section.getByRole("table", {
    name: "Haftalik ish jadvali",
    exact: true,
  });
  await expect(table.locator("tbody tr")).toHaveCount(7);
  await expect(
    section.getByText("Ish jadvali hali yaratilmagan", { exact: true }),
  ).toBeVisible();
  await expect(table.getByRole("row", { name: /Dushanba/ })).toContainText(
    "Dam olish",
  );
  await expect(table).not.toContainText("B101");
  await expect(
    section.getByText("Istisno sanalar yo‘q", { exact: true }),
  ).toBeVisible();
  await expect(section.locator("button, input, select")).toHaveCount(0);
  expect(writes).toEqual([]);
});
