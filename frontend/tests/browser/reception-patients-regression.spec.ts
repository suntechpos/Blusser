import { test, expect } from "@playwright/test";
import { receptionSession } from "./reception.fixture";
test("Patients preserves server total/page, search, branch, details, ETag update, create and CSV contracts with fixtures", async ({
  page,
}) => {
  const requests = await receptionSession(page);
  await page.getByRole("button", { name: "Bemorlar", exact: true }).click();
  await expect(page.locator(".patients-total")).toContainText("23");
  await expect(page.locator("tbody tr")).toHaveCount(10);
  await page.getByRole("button", { name: "2", exact: true }).click();
  await expect(page.locator("tbody")).toContainText("Synthetic patient 11");
  expect(requests.some((r) => r.path.includes("page=2"))).toBe(true);
  await page
    .getByPlaceholder("F.I.Sh., bemor raqami yoki telefon...")
    .fill("Synthetic patient 23");
  await expect(page.locator("tbody tr")).toHaveCount(1);
  await page.getByRole("button", { name: "Ko‘rish", exact: true }).click();
  await expect(page.getByRole("dialog")).toContainText("Synthetic patient 23");
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "Yopish", exact: true })
    .click();
  await page.getByRole("button", { name: "Tahrirlash", exact: true }).click();
  await page
    .getByRole("dialog")
    .getByLabel("F.I.Sh. *", { exact: true })
    .fill("Conflict");
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "Saqlash", exact: true })
    .click();
  await expect(page.getByRole("dialog").getByRole("alert")).toContainText(
    "Ma’lumot o‘zgargan",
  );
  await expect(page.getByRole("dialog")).toBeVisible();
  await page
    .getByRole("dialog")
    .getByLabel("F.I.Sh. *", { exact: true })
    .fill("Validation");
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "Saqlash", exact: true })
    .click();
  await expect(page.getByRole("dialog").getByRole("alert")).toContainText(
    "F.I.Sh.: kiritilgan qiymatni tekshiring.",
  );
  await expect(page.getByRole("dialog")).toBeVisible();

  await page
    .getByRole("dialog")
    .getByLabel("F.I.Sh. *", { exact: true })
    .fill("Synthetic updated");
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "Saqlash", exact: true })
    .click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  const patch = requests.find((r) => r.method === "PATCH");
  expect(patch?.headers["if-match"]).toBe('"1"');
  expect(
    requests.filter((r) => r.method === "PATCH").at(-1)?.body,
  ).toMatchObject({
    name: "Synthetic updated",
    date_of_birth: "1990-01-01",
    gender: "female",
  });
  await page.getByPlaceholder("F.I.Sh., bemor raqami yoki telefon...").fill("");
  await page.getByLabel("Filial filtri").selectOption("branch-a");
  await expect(page.locator("tbody tr")).toHaveCount(10);
  expect(requests.some((r) => r.path.includes("branch_id=branch-a"))).toBe(
    true,
  );
  const dl = page.waitForEvent("download");
  await page.getByRole("button", { name: "Eksport", exact: true }).click();
  expect((await dl).suggestedFilename()).toBe("bemorlar.csv");
  await page.getByRole("button", { name: "Yangi bemor", exact: true }).click();
  await page
    .getByRole("dialog")
    .getByLabel("F.I.Sh. *", { exact: true })
    .fill("Synthetic created");
  await page
    .getByRole("dialog")
    .getByLabel("Tug‘ilgan sana *", { exact: true })
    .fill("1992-02-02");
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "Saqlash", exact: true })
    .click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  expect(
    requests.find((r) => r.method === "POST" && r.path.endsWith("/patients"))
      ?.body,
  ).toMatchObject({ name: "Synthetic created", branch_id: "branch-a" });
  await expect(page.locator(".patients-total")).toContainText("24");
});
