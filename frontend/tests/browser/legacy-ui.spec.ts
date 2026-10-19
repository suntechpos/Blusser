import { test, expect } from "@playwright/test";
import { legacySession, openLegacy } from "./legacy.fixture";
test.use({ viewport: { width: 1672, height: 1000 } });
test("dashboard has current login, shared filters, accessible chart tooltip/legend/drilldown/donut and empty state", async ({
  page,
}) => {
  const requests = await legacySession(page);
  await expect(
    page.getByRole("heading", { name: "Xush kelibsiz, visual.ceo!" }),
  ).toBeVisible();
  await expect(page.locator(".overview")).not.toContainText("Gulnoza");
  await page
    .getByLabel("Boshlanish sanasi", { exact: true })
    .fill("2026-10-15");
  await expect(page.locator(".overview-metrics article").first()).toContainText(
    "20",
  );
  const point = page.getByRole("button", {
    name: "2026-10-15: Bemorlar soni 20 bemor",
    exact: true,
  });
  await point.locator("circle").last().hover();
  await expect(page.locator(".overview-tooltip")).toContainText("20 bemor");
  await point.focus();
  await expect(page.locator(".overview-tooltip")).toContainText("20 bemor");
  await point.press("Enter");
  await expect(page.locator(".overview-detail-head").first()).toContainText(
    "2026-10-15",
  );
  await page
    .getByRole("button", { name: "Tushum (so‘m)", exact: true })
    .click();
  await expect(
    page.getByRole("group", { name: "Tushum", exact: true }),
  ).toHaveCount(0);
  const segment = page.getByRole("button", {
    name: "Laboratoriya: 4 bemor",
    exact: true,
  });
  await segment.focus();
  await segment.press("Enter");
  await expect(page.locator(".overview-table tbody tr")).toHaveCount(4);
  await page
    .getByLabel("Dashboard filiali", { exact: true })
    .selectOption("Yunusobod");
  await expect(page.locator(".overview-metrics article").first()).toContainText(
    "10",
  );
  await page
    .getByLabel("Boshlanish sanasi", { exact: true })
    .fill("2027-01-01");
  await page.getByLabel("Tugash sanasi", { exact: true }).fill("2027-01-02");
  await expect(page.locator(".overview-table")).toContainText(
    "Ma’lumot mavjud emas",
  );
  expect(
    requests.filter((r) => r.path.includes("/clinic/patients")),
  ).toHaveLength(0);
  await page
    .locator(".overview-detail-head")
    .first()
    .getByRole("button", { name: "Barchasini ko‘rish" })
    .click();
  await expect(
    page.getByRole("heading", { name: "Qabullar", exact: true }),
  ).toBeVisible();
});
test("Reception filters, sort, schedule and local call remain functional with readable typography", async ({
  page,
}) => {
  await legacySession(page);
  await openLegacy(page, "Registratura");
  const first = page
    .locator(".reg-table-scroll tbody tr")
    .filter({ hasText: "Qodirov Aziz" });
  await first.getByRole("button", { name: "Chaqirish" }).click();
  await expect(first).toContainText("Qabulda");
  await expect(
    page.locator(".reg-stats").getByText("Qabulda", { exact: true }),
  ).toBeVisible();
  await page
    .getByPlaceholder("Bemor qidirish...", { exact: true })
    .fill("NO MATCH");
  await expect(page.locator(".reg-table-scroll")).toContainText(
    "Bemor topilmadi",
  );
  await page.getByPlaceholder("Bemor qidirish...", { exact: true }).fill("");
  await page.getByLabel("Saralash", { exact: true }).selectOption("name");
  await expect(
    page.locator(".reg-table-scroll tbody tr").first(),
  ).toContainText("Aliyeva");
  await page.getByLabel("Qabul jadvali shifokori").selectOption("Saidov B.");
  await expect(page.locator(".reg-slots")).not.toContainText("Aliyeva");
  expect(
    await page
      .locator(".reg-filters select")
      .first()
      .evaluate((el) => parseFloat(getComputedStyle(el).fontSize)),
  ).toBeGreaterThanOrEqual(14);
  expect(
    await page
      .locator(".reg-table-scroll tbody td")
      .first()
      .evaluate((el) => parseFloat(getComputedStyle(el).fontSize)),
  ).toBeGreaterThanOrEqual(14);
});
test("Patients preserves server total/page, search, branch, details, ETag update, create and CSV contracts with fixtures", async ({
  page,
}) => {
  const requests = await legacySession(page);
  await openLegacy(page, "Bemorlar");
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
test("Appointments existing search, menu, edit, calendar filter and CSV still work", async ({
  page,
}) => {
  await legacySession(page);
  await openLegacy(page, "Qabullar");
  await page
    .getByPlaceholder("Bemor, telefon yoki ID bo‘yicha qidiring...")
    .fill("Mohira");
  await expect(page.locator(".ap-table-wrap tbody tr")).toHaveCount(1);
  await page.getByRole("button", { name: "Ko‘rish", exact: true }).click();
  await expect(page.getByRole("dialog")).toContainText("Aliyeva Mohira");
  await page
    .getByRole("button", { name: "Yopish", exact: true })
    .first()
    .click();
  await page.getByRole("button", { name: "Qo‘shimcha amallar" }).click();
  await page
    .locator(".ap-menu")
    .getByRole("button", { name: "Kutilmoqda", exact: true })
    .click();
  await expect(page.locator(".ap-status")).toContainText("Kutilmoqda");
  const dl = page.waitForEvent("download");
  await page.getByRole("button", { name: "Eksport", exact: true }).click();
  expect((await dl).suggestedFilename()).toContain("qabullar");
});
test("Queue calls update cards, tabs and called panel from same local source", async ({
  page,
}) => {
  const requests = await legacySession(page);
  await openLegacy(page, "Navbatlar");
  await expect(page.locator(".queue-call")).toHaveCount(2);
  const row = page.locator("tbody tr").filter({ hasText: "Matchonov Siroj" });
  await row.getByRole("button", { name: "Chaqirish", exact: true }).click();
  await expect(page.locator(".queue-call")).toHaveCount(3);
  await expect(page.locator(".queue-side")).toContainText("Matchonov Siroj");
  await row
    .getByRole("button", { name: "Qabulga o'tkazish", exact: true })
    .click();
  await expect(page.locator(".queue-call")).toHaveCount(2);
  await expect(row).toContainText("Qabulda");
  await page
    .locator(".queue-tabs")
    .getByRole("button", { name: /Qabulda/ })
    .click();
  await expect(page.locator("tbody tr")).toHaveCount(2);
  expect(requests.some((r) => r.path.includes("/clinic/queues"))).toBe(false);
});
test("Tablo selected preview equals modal; sound, settings, copy, TV and confirmed delete remain local", async ({
  page,
  context,
}) => {
  await context.grantPermissions(["clipboard-read", "clipboard-write"]);
  await legacySession(page);
  await openLegacy(page, "Tablo boshqaruvi");
  await page.getByLabel("Preview ekrani").selectOption("2");
  await expect(page.locator(".tb-mini-main")).toContainText("201");
  const ticket = await page.locator(".tb-mini-main strong").first().innerText();
  await page
    .getByRole("button", { name: "Oldindan ko‘rish", exact: true })
    .click();
  await expect(page.locator(".tb-big-tv-codes")).toContainText(ticket);
  await expect(page.locator(".tb-big-tv-codes")).toContainText("201");
  await test.step("copy code and read system clipboard", async () => {
    await page.getByRole("button", { name: "Kodni nusxalash" }).click();
    await expect
      .poll(() => page.evaluate(() => navigator.clipboard.readText()))
      .toBe("619284");
  });
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "Yopish", exact: true })
    .click();
  await page
    .getByRole("button", { name: "UZI kutish zali ovoz", exact: true })
    .click();
  await expect(
    page
      .locator("tr")
      .filter({ hasText: "UZI kutish zali" })
      .locator(".tb-sound"),
  ).toContainText("Yoqilgan");

  await page
    .locator("tr")
    .filter({ hasText: "UZI kutish zali" })
    .getByTitle("Sozlash")
    .click();
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "201", exact: true })
    .click();
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.locator(".tb-mini-main")).toContainText("202");
  await test.step("open TV preview", async () => {
    await page
      .getByRole("button", { name: "TV ko‘rinishini ochish", exact: true })
      .click();
    await expect(page.locator(".tb-big-tv-codes")).toContainText("202");
    // Vue renders the preview before requestFullscreen() finishes. The header's
    // close button is outside the fullscreen TV, so wait for entry before exit.
    await expect
      .poll(() =>
        page.evaluate(
          () =>
            document.fullscreenElement?.classList.contains("tb-big-tv") ??
            false,
        ),
      )
      .toBe(true);
  });
  await test.step("exit fullscreen and close preview", async () => {
    await page.evaluate(async () => {
      await document.exitFullscreen();
    });
    await expect
      .poll(() => page.evaluate(() => document.fullscreenElement === null))
      .toBe(true);
    await page
      .getByRole("dialog")
      .getByRole("button", { name: "Yopish", exact: true })
      .click();
  });
  page.once("dialog", (d) => d.dismiss());
  await page
    .locator("tr")
    .filter({ hasText: "UZI kutish zali" })
    .getByTitle("O‘chirish")
    .click();
  await expect(page.locator("tbody tr")).toHaveCount(3);
  page.once("dialog", (d) => d.accept());
  await page
    .locator("tr")
    .filter({ hasText: "UZI kutish zali" })
    .getByTitle("O‘chirish")
    .click();
  await expect(page.locator("tbody tr")).toHaveCount(2);
  await expect(page.locator(".tb-mini-main")).toContainText("101");
});
test("narrow pages scroll tables locally, shell stays in viewport and logout/login fixtures survive", async ({
  page,
}) => {
  await legacySession(page);
  await page.setViewportSize({ width: 1000, height: 900 });
  for (const label of [
    "Bosh sahifa",
    "Registratura",
    "Bemorlar",
    "Qabullar",
    "Navbatlar",
    "Tablo boshqaruvi",
  ]) {
    await openLegacy(page, label);
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    ).toBe(true);
  }
  await page.getByRole("button", { name: "Profil menyusi" }).click();
  await page
    .getByRole("button", { name: "Tizimdan chiqish", exact: true })
    .click();
  await expect(page.getByLabel("Login", { exact: true })).toBeVisible();
  await page.getByLabel("Login", { exact: true }).fill("visual.ceo");
  await page.getByLabel("Parol", { exact: true }).fill("Synthetic-only");
  await page.getByRole("button", { name: "Kirish", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Bosh sahifa", exact: true }),
  ).toBeVisible();
});

test("Tablo waits for delayed native fullscreen entry before closing preview", async ({
  page,
}) => {
  await page.addInitScript(() => {
    const requestFullscreen = Element.prototype.requestFullscreen;
    Element.prototype.requestFullscreen = async function (options) {
      // Render the modal first, as on a busy CI runner, then use the real API.
      await new Promise((resolve) => setTimeout(resolve, 250));
      return requestFullscreen.call(this, options);
    };
  });
  await legacySession(page);
  await openLegacy(page, "Tablo boshqaruvi");
  await page
    .getByRole("button", { name: "TV ko‘rinishini ochish", exact: true })
    .click();
  await expect(page.locator(".tb-big-tv-codes")).toContainText("101");
  await expect
    .poll(() =>
      page.evaluate(
        () =>
          document.fullscreenElement?.classList.contains("tb-big-tv") ?? false,
      ),
    )
    .toBe(true);
  await page.evaluate(() => document.exitFullscreen());
  await expect
    .poll(() => page.evaluate(() => document.fullscreenElement === null))
    .toBe(true);
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "Yopish", exact: true })
    .click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(page.locator("tbody tr")).toHaveCount(3);
});
