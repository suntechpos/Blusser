import { test, expect } from "@playwright/test";
import { mkdir } from "node:fs/promises";
import { legacySession, openLegacy } from "./legacy.fixture";
test("six existing pages desktop and narrow screenshots", async ({ page }) => {
  await legacySession(page);
  const out = process.env.LEGACY_SCREENSHOTS || "/tmp/blusser-legacy-after";
  await mkdir(out, { recursive: true });
  for (const width of [1672, 1000]) {
    await page.setViewportSize({ width, height: 1000 });
    for (const [i, label] of [
      "Bosh sahifa",
      "Registratura",
      "Bemorlar",
      "Qabullar",
      "Navbatlar",
      "Tablo boshqaruvi",
    ].entries()) {
      await openLegacy(page, label);
      if (label === "Bemorlar")
        await expect(page.locator("tbody")).toContainText("Synthetic patient");
      await page.screenshot({
        path: `${out}/${i}-${width}.png`,
        fullPage: true,
      });
    }
  }
  await page.setViewportSize({ width: 1672, height: 1000 });
  await openLegacy(page, "Bemorlar");
  await page
    .getByRole("button", { name: "Ko‘rish", exact: true })
    .first()
    .click();
  await expect(page.getByRole("dialog")).toBeVisible();
  await page.screenshot({ path: `${out}/patients-modal.png` });
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "Yopish", exact: true })
    .click();
  await openLegacy(page, "Qabullar");
  await page
    .getByRole("button", { name: "Ko‘rish", exact: true })
    .first()
    .click();
  await expect(page.getByRole("dialog")).toBeVisible();
  await page.screenshot({ path: `${out}/appointments-modal.png` });
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "Yopish", exact: true })
    .click();
  await openLegacy(page, "Tablo boshqaruvi");
  await page
    .getByRole("button", { name: "Oldindan ko‘rish", exact: true })
    .click();
  await page.screenshot({ path: `${out}/tablo-preview.png` });
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "Yopish", exact: true })
    .click();
  await page.getByTitle("Sozlash", { exact: true }).first().click();
  await page.screenshot({ path: `${out}/tablo-settings.png` });
});
