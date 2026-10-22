import { test, expect } from "@playwright/test";
import { receptionSession } from "./reception.fixture";
import { allServerGrants } from "./access.fixture";

test("reception view alone does not authorize booking, registering or check-in", async ({
  page,
}) => {
  await receptionSession(page, { "reception.view": "entire_clinic" });
  await page.goto("/#registratura");
  await expect(
    page.getByRole("heading", { name: /Registratura/ }),
  ).toBeVisible();
  await expect(
    page
      .locator(".rp-heading-actions")
      .getByRole("button", { name: "Qabulga yozish", exact: true }),
  ).toBeHidden();
  await expect(
    page.getByRole("button", { name: "Bemorni ro‘yxatga olish", exact: true }),
  ).toBeDisabled();
  await expect(
    page.getByRole("button", { name: "Keldi", exact: true }),
  ).toHaveCount(0);
  await expect(
    page.getByRole("button", { name: "Keyingi sahifa", exact: true }),
  ).toBeVisible();
});

test("booking an existing patient does not grant new-patient creation", async ({
  page,
}) => {
  const grants = { ...allServerGrants };
  delete grants["patients.create"];
  await receptionSession(page, grants);
  await page.goto("/#registratura");
  await page
    .locator(".rp-heading-actions")
    .getByRole("button", { name: "Qabulga yozish", exact: true })
    .click();
  await expect(
    page.getByRole("button", { name: "Yangi bemor", exact: true }),
  ).toBeHidden();
  await expect(
    page.getByRole("button", { name: "Oldin kelgan bemor", exact: false }),
  ).toBeVisible();
  await expect(page.getByRole("dialog")).toBeVisible();
});

test("check-in also requires visit and queue creation; UI cannot rely on label matching", async ({
  page,
}) => {
  const grants = { ...allServerGrants };
  delete grants["queues.create"];
  await receptionSession(page, grants);
  await page.goto("/#registratura");
  await expect(
    page.getByRole("button", { name: "Keldi", exact: true }),
  ).toHaveCount(0);
  await expect(
    page.getByRole("button", { name: "Bemorni ro‘yxatga olish", exact: true }),
  ).toBeDisabled();
  await expect(
    page
      .locator(".rp-heading-actions")
      .getByRole("button", { name: "Qabulga yozish", exact: true }),
  ).toBeEnabled();
});
