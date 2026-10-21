import { test, expect, type Page } from "@playwright/test";
import { mkdir, readFile } from "node:fs/promises";
import { receptionSession } from "./reception.fixture";
test.use({ viewport: { width: 1536, height: 1024 }, locale: "uz-UZ" });
const dialog = (p: Page) => p.getByRole("dialog").last();
const pending = (p: Page, name = "Aliyeva Mohira") =>
  p.locator(".cs-invoice").filter({ hasText: name });
async function open(p: Page) {
  await p.clock.setFixedTime(new Date("2026-10-21T18:00:00"));
  const requests = await receptionSession(p);
  await p.getByRole("button", { name: "Kassa", exact: true }).click();
  return requests;
}
async function pin(p: Page) {
  await p.getByRole("button", { name: "Kassaga kirish", exact: true }).click();
  await dialog(p).getByLabel("4 xonali demo PIN").fill("1122");
  await dialog(p).getByRole("button", { name: "Kirish", exact: true }).click();
}
async function start(p: Page, opening = "200000") {
  await pin(p);
  await dialog(p)
    .getByLabel("Boshlang‘ich naqd pul", { exact: true })
    .fill(opening);
  await dialog(p)
    .getByRole("button", { name: "Kassani ochish", exact: true })
    .click();
}
async function payCash(p: Page, value = "350000") {
  await p.getByLabel("Qabul qilingan naqd", { exact: true }).fill(value);
  await p
    .getByRole("button", { name: "To‘lovni qabul qilish", exact: true })
    .click();
  await expect(dialog(p)).toContainText("To‘lov qabul qilindi");
}
async function shot(p: Page, name: string) {
  await mkdir("/tmp/blusser-cashier-screenshots", { recursive: true });
  await p.evaluate(() => document.fonts.ready);
  await p.screenshot({ path: `/tmp/blusser-cashier-screenshots/${name}.png` });
}
async function ledger(p: Page) {
  return p.evaluate(() => {
    const key = Object.keys(localStorage).find((k) =>
      k.startsWith("blusser.cashier.demo.v1."),
    );
    return key ? JSON.parse(localStorage.getItem(key)!) : null;
  });
}
async function refunds(p: Page, query = "CH-00003") {
  await p.getByRole("button", { name: "Qaytarishlar", exact: true }).click();
  await p
    .getByRole("button", { name: "Qaytarish yaratish", exact: true })
    .click();
  await dialog(p)
    .getByLabel("Asl to‘lovni qidirish", { exact: true })
    .fill(query);
  await dialog(p).locator(".cs-refund-search button").first().click();
}
test("landing is private, PIN only on click; keypad/keyboard/backspace, wrong PIN, open/reload resume", async ({
  page,
}) => {
  const requests = await open(page);
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(
    page.getByRole("heading", { name: "Kassaga kirish", exact: true }),
  ).toBeVisible();
  await expect(page.getByText("Aliyeva Mohira", { exact: true })).toHaveCount(
    0,
  );
  await shot(page, "01-locked");
  await page
    .getByRole("button", { name: "Kassaga kirish", exact: true })
    .click();
  await expect(dialog(page).locator(".cs-pin-dots span")).toHaveCount(4);
  await dialog(page).getByLabel("4 xonali demo PIN").fill("0000");
  await page.keyboard.press("Enter");
  await expect(dialog(page).getByRole("alert")).toContainText("PIN noto‘g‘ri");
  await dialog(page).getByRole("button", { name: "1", exact: true }).click();
  await dialog(page).getByRole("button", { name: "1", exact: true }).click();
  await page.keyboard.type("23");
  await page.keyboard.press("Backspace");
  await page.keyboard.type("2");
  await shot(page, "02-pin");
  await page.keyboard.press("Enter");
  await expect(dialog(page)).toContainText("Kassani ochish");
  await dialog(page)
    .getByLabel("Boshlang‘ich naqd pul", { exact: true })
    .fill("-1");
  await dialog(page)
    .getByRole("button", { name: "Kassani ochish", exact: true })
    .click();
  await expect(dialog(page).getByRole("alert")).toContainText("musbat");
  await dialog(page)
    .getByLabel("Boshlang‘ich naqd pul", { exact: true })
    .fill("0");
  await shot(page, "03-open");
  await dialog(page)
    .getByRole("button", { name: "Kassani ochish", exact: true })
    .click();
  await expect(page.locator(".cs-shift")).toContainText("Kassa ochiq");
  const s = await ledger(page),
    id = s.shifts.find((s: { closedAt: string | null }) => !s.closedAt).id;
  await page.reload();
  await page.getByRole("button", { name: "Kassa", exact: true }).click();
  await expect(page.getByText("Aliyeva Mohira", { exact: true })).toHaveCount(
    0,
  );
  await pin(page);
  await expect(page.getByRole("dialog")).toHaveCount(0);
  const after = await ledger(page);
  expect(
    after.shifts.find((s: { closedAt: string | null }) => !s.closedAt).id,
  ).toBe(id);
  expect(
    requests.filter((r) => /cash|payment|refund/.test(r.path)),
  ).toHaveLength(0);
});
test("cash change, immutable receipt, double click, print failure retains payment and next patient", async ({
  page,
}) => {
  await open(page);
  await start(page);
  await shot(page, "04-cash");
  await page.getByLabel("Qabul qilingan naqd", { exact: true }).fill("350000");
  await page.getByLabel("Chekni chop etish", { exact: true }).check();
  await page.evaluate(() => {
    window.open = () => null;
  });
  await page
    .getByRole("button", { name: "To‘lovni qabul qilish", exact: true })
    .dblclick();
  await expect(dialog(page)).toContainText("330 000 so‘m");
  await expect(dialog(page)).toContainText("20 000 so‘m");
  await expect(dialog(page).getByRole("alert")).toContainText(
    "To‘lov saqlangan",
  );
  await shot(page, "05-success");
  const s = await ledger(page);
  expect(s.payments).toHaveLength(8);
  expect(s.payments.at(-1).cash).toBe(330000);
  expect(s.payments.at(-1).tendered).toBe(350000);
  await expect(pending(page)).toHaveCount(0);
  await dialog(page)
    .getByRole("button", { name: "Chekni ko‘rish", exact: true })
    .click();
  await expect(dialog(page).locator(".cs-receipt")).toContainText(
    "Aynan shu to‘lov: 330 000 so‘m",
  );
  await shot(page, "06-receipt");
  const downloadPromise = page.waitForEvent("download");
  await dialog(page)
    .getByRole("button", { name: "PDF yuklab olish", exact: true })
    .click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toBe("CH-00008.pdf");
  await download.saveAs("/tmp/blusser-cashier-screenshots/CH-00008.pdf");
  const bytes = await readFile((await download.path())!);
  expect(bytes.subarray(0, 5).toString()).toBe("%PDF-");
  await dialog(page)
    .getByRole("button", { name: "Yopish", exact: true })
    .click();
  await dialog(page)
    .getByRole("button", { name: "Keyingi bemor", exact: true })
    .click();
  await expect(page.locator(".cs-account")).toContainText("Usmonov Dilshod");
  await page
    .getByRole("button", { name: "Kassani yopish", exact: true })
    .click();
  await expect(dialog(page)).toContainText("530 000 so‘m");
});
test("catalog reuse, duplicate prevention, explicit quantity and discount validation", async ({
  page,
}) => {
  await open(page);
  await start(page);
  await page
    .getByRole("button", { name: "Xizmat qo‘shish", exact: true })
    .click();
  await expect(
    dialog(page).getByRole("checkbox", {
      name: "Qorin bo‘shlig‘i UZI",
      exact: true,
    }),
  ).toBeDisabled();
  await dialog(page).getByLabel("Xizmat qidirish", { exact: true }).fill("EKG");
  await dialog(page)
    .getByRole("checkbox", { name: "EKG tekshiruvi", exact: true })
    .check();
  await shot(page, "07-services");
  await dialog(page)
    .getByRole("button", { name: "Hisobga qo‘shish", exact: true })
    .click();
  await expect(page.locator(".cs-services tbody tr")).toHaveCount(4);
  await page.getByLabel("EKG tekshiruvi miqdori", { exact: true }).fill("2");
  await page.getByLabel("EKG tekshiruvi miqdori", { exact: true }).press("Tab");
  await expect(page.locator(".cs-payment")).toContainText("470 000 so‘m");
  await page.getByLabel("Chegirma qiymati", { exact: true }).fill("471000");
  await expect(
    page.getByRole("button", { name: "To‘lovni qabul qilish", exact: true }),
  ).toBeDisabled();
  await expect(page.getByRole("alert")).toContainText("oshmasin");
  await page.getByLabel("Chegirma qiymati", { exact: true }).fill("10");
  await page.getByRole("button", { name: "%", exact: true }).click();
  await expect(page.locator(".cs-payment")).toContainText("423 000 so‘m");
  await page.getByLabel("Qabul qilingan naqd", { exact: true }).fill("423000");
  await page
    .getByRole("button", { name: "To‘lovni qabul qilish", exact: true })
    .click();
  await dialog(page)
    .getByRole("button", { name: "Yopish", exact: true })
    .click();
  await expect(
    page.getByLabel("Chegirma qiymati", { exact: true }),
  ).toBeDisabled();
  await expect(
    page.getByRole("button", { name: "Xizmat qo‘shish", exact: true }),
  ).toBeDisabled();
});
test("card and mixed require local terminal confirmation, split and cash tender separated", async ({
  page,
}) => {
  await open(page);
  await start(page);
  await page.getByRole("button", { name: "Aralash", exact: true }).click();
  await page.getByLabel("Naqd to‘lov", { exact: true }).fill("100000");
  await page.getByLabel("Karta to‘lovi", { exact: true }).fill("230000");
  await page.getByLabel("Qabul qilingan naqd", { exact: true }).fill("110000");
  await page
    .getByRole("button", { name: "To‘lovni qabul qilish", exact: true })
    .click();
  await expect(page.getByRole("alert")).toContainText("Terminal");
  await page
    .getByLabel("Terminal to‘lovi bajarildi (lokal tasdiq)", { exact: true })
    .check();
  await shot(page, "08-mixed");
  await page
    .getByRole("button", { name: "To‘lovni qabul qilish", exact: true })
    .click();
  let s = await ledger(page);
  expect(s.payments.at(-1)).toMatchObject({
    cash: 100000,
    card: 230000,
    change: 10000,
  });
  await dialog(page)
    .getByRole("button", { name: "Keyingi bemor", exact: true })
    .click();
  await page.getByRole("button", { name: "Karta", exact: true }).click();
  await page
    .getByLabel("Terminal to‘lovi bajarildi (lokal tasdiq)", { exact: true })
    .check();
  await page
    .getByRole("button", { name: "To‘lovni qabul qilish", exact: true })
    .click();
  s = await ledger(page);
  expect(s.payments.at(-1)).toMatchObject({
    amount: 120000,
    cash: 0,
    card: 120000,
    tendered: 0,
  });
});
test("partial remaining queue, next payment and transaction history preserve old receipt facts", async ({
  page,
}) => {
  await open(page);
  await start(page);
  await page.getByLabel("Qisman to‘lov", { exact: true }).check();
  await page
    .getByLabel("Hisobga to‘lanadigan summa", { exact: true })
    .fill("200000");
  await page.getByLabel("Qabul qilingan naqd", { exact: true }).fill("200000");
  await shot(page, "09-partial");
  await page
    .getByRole("button", { name: "200 000 so‘m qabul qilish", exact: true })
    .click();
  await expect(dialog(page)).toContainText("130 000 so‘m");
  await dialog(page)
    .getByRole("button", { name: "Yopish", exact: true })
    .click();
  await expect(pending(page)).toContainText("130 000 so‘m");
  await pending(page).click();
  await payCash(page, "130000");
  await dialog(page)
    .getByRole("button", { name: "Yopish", exact: true })
    .click();
  await page
    .getByRole("button", { name: "To‘lovlar tarixi", exact: true })
    .click();
  await page.getByLabel("To‘lov qidirish", { exact: true }).fill("CH-00008");
  await page.getByRole("button", { name: "Ko‘rish", exact: true }).click();
  await expect(dialog(page)).toContainText("200 000 so‘m");
  await expect(dialog(page)).toContainText("Qolgan qarz (joriy)");
  await shot(page, "10-details");
  await dialog(page)
    .getByRole("button", { name: "Chekni ko‘rish", exact: true })
    .click();
  await expect(dialog(page).locator(".cs-receipt")).toContainText(
    "Qolgan qarz (shu paytda): 130 000 so‘m",
  );
});
test("history/refund filters, aggregate totals, pagination, date range and CSV", async ({
  page,
}) => {
  await open(page);
  await start(page);
  await page
    .getByRole("button", { name: "To‘lovlar tarixi", exact: true })
    .click();
  await expect(page.locator(".cs-stat-grid strong")).toHaveText([
    "1 250 000 so‘m",
    "650 000 so‘m",
    "600 000 so‘m",
  ]);
  await shot(page, "11-history");
  await page.getByLabel("Sahifadagi to‘lovlar").selectOption("5");
  await expect(page.locator(".cs-history-table tbody tr")).toHaveCount(5);
  await page.getByRole("button", { name: "›", exact: true }).click();
  await expect(page.locator(".cs-history-table tbody tr")).toHaveCount(2);
  await page.getByLabel("Usul filtri").selectOption("mixed");
  await expect(page.locator(".cs-history-table tbody tr")).toHaveCount(1);
  await expect(page.locator(".cs-stat-grid strong").first()).toHaveText(
    "330 000 so‘m",
  );
  const d = page.waitForEvent("download");
  await page.getByRole("button", { name: "Eksport", exact: true }).click();
  const download = await d;
  const csv = (await readFile((await download.path())!)).toString();
  expect(csv).toContain("230000");
  expect(csv).not.toContain("CH-00007");
  await page.getByLabel("Usul filtri").selectOption("");
  await page.getByLabel("Kassir filtri").selectOption("demo-dilnoza");
  await expect(page.locator(".cs-history-table tbody tr")).toHaveCount(3);
  await page.getByLabel("Boshlanish sanasi").fill("2026-10-22");
  await expect(
    page.getByText("Yozuv topilmadi", { exact: true }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Qaytarishlar", exact: true }).click();
  await expect(page.locator(".cs-stat-grid strong")).toHaveText([
    "285 000 so‘m",
    "165 000 so‘m",
    "120 000 so‘m",
  ]);
  await shot(page, "12-refunds");
  await page.getByLabel("Sahifadagi qaytarishlar").selectOption("2");
  await expect(page.locator(".cs-history-table tbody tr")).toHaveCount(2);
});
test("mixed refund limits and cancellation ledger update drawer/account without modifying original payment", async ({
  page,
}) => {
  await open(page);
  await start(page);
  const before = await ledger(page);
  await refunds(page);
  await dialog(page)
    .getByRole("checkbox", {
      name: "Qorin bo‘shlig‘i UZIni qaytarish",
      exact: true,
    })
    .check();
  await dialog(page)
    .getByLabel("Qaytarish sababi", { exact: true })
    .selectOption("cancel");
  await dialog(page)
    .getByLabel("Naqd qaytarish", { exact: true })
    .fill("120000");
  await dialog(page).getByLabel("Kartaga qaytarish", { exact: true }).fill("0");
  await dialog(page)
    .getByRole("button", { name: "Qaytarishni tasdiqlash", exact: true })
    .click();
  await expect(dialog(page).getByRole("alert")).toContainText(
    "qismlaridan oshmasin",
  );
  await dialog(page)
    .getByLabel("Naqd qaytarish", { exact: true })
    .fill("100000");
  await dialog(page)
    .getByLabel("Kartaga qaytarish", { exact: true })
    .fill("20000");
  await shot(page, "13-refund-create");
  await dialog(page)
    .getByRole("button", { name: "Qaytarishni tasdiqlash", exact: true })
    .dblclick();
  await expect(dialog(page)).toContainText("Qaytarildi");
  await shot(page, "14-refund-details");
  const after = await ledger(page);
  expect(after.refunds).toHaveLength(before.refunds.length + 1);
  expect(after.payments).toEqual(before.payments);
  expect(after.cancellations).toHaveLength(before.cancellations.length + 1);
  await dialog(page)
    .getByRole("button", { name: "Asl to‘lovni ko‘rish", exact: true })
    .click();
  await expect(dialog(page)).toContainText("Qolgan qarz (joriy)");
  await dialog(page)
    .getByRole("button", { name: "Yopish", exact: true })
    .last()
    .click();
  await page
    .getByRole("button", { name: "Kassani yopish", exact: true })
    .click();
  await expect(dialog(page)).toContainText("100 000 so‘m");
});
test("other reason requires note, partial discounted refund respects paid line ceiling", async ({
  page,
}) => {
  await open(page);
  await start(page);
  await page.getByLabel("Chegirma qiymati", { exact: true }).fill("10");
  await page.getByRole("button", { name: "%", exact: true }).click();
  await page.getByLabel("Qisman to‘lov", { exact: true }).check();
  await page
    .getByLabel("Hisobga to‘lanadigan summa", { exact: true })
    .fill("180000");
  await page.getByLabel("Qabul qilingan naqd", { exact: true }).fill("180000");
  await page
    .getByRole("button", { name: "180 000 so‘m qabul qilish", exact: true })
    .click();
  await dialog(page)
    .getByRole("button", { name: "Yopish", exact: true })
    .click();
  await refunds(page, "CH-00008");
  await dialog(page)
    .getByRole("checkbox", {
      name: "Qorin bo‘shlig‘i UZIni qaytarish",
      exact: true,
    })
    .check();
  await dialog(page)
    .getByLabel("Qaytarish sababi", { exact: true })
    .selectOption("other");
  await dialog(page)
    .getByRole("button", { name: "Qaytarishni tasdiqlash", exact: true })
    .click();
  await expect(dialog(page).getByRole("alert")).toContainText("izoh majburiy");
  await dialog(page)
    .getByLabel("Qaytarish izohi", { exact: true })
    .fill("Test sabab");
  await dialog(page)
    .getByLabel("Qorin bo‘shlig‘i UZI qaytarish summasi", { exact: true })
    .fill("108000");
  await dialog(page)
    .getByRole("button", { name: "Qaytarishni tasdiqlash", exact: true })
    .click();
  await expect(dialog(page).getByRole("alert")).toContainText(
    "to‘langan qismdan oshmasin",
  );
});
test("closing variance required, PIN cleared, archived shift persists; zero and positive variance", async ({
  page,
}) => {
  await open(page);
  await start(page);
  await payCash(page);
  await dialog(page)
    .getByRole("button", { name: "Yopish", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Kassani yopish", exact: true })
    .click();
  await dialog(page)
    .getByLabel("Amaldagi naqd pul", { exact: true })
    .fill("529000");
  await dialog(page)
    .getByRole("button", { name: "Kassani yopish", exact: true })
    .click();
  await expect(dialog(page).getByRole("alert")).toContainText("izoh majburiy");
  await dialog(page)
    .getByLabel("Smena yopish izohi", { exact: true })
    .fill("Kamomad qaydi");
  await shot(page, "15-close-shortage");
  await dialog(page)
    .getByRole("button", { name: "Kassani yopish", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "Kassaga kirish", exact: true }),
  ).toBeVisible();
  await expect(page.getByText("Aliyeva Mohira", { exact: true })).toHaveCount(
    0,
  );
  await start(page, "0");
  await page
    .getByRole("button", { name: "Kassani yopish", exact: true })
    .click();
  await expect(dialog(page)).toContainText("0 so‘m");
  await dialog(page).getByLabel("Amaldagi naqd pul", { exact: true }).fill("0");
  await shot(page, "16-close-balanced");
  await dialog(page)
    .getByRole("button", { name: "Kassani yopish", exact: true })
    .click();
  await start(page, "0");
  await page
    .getByRole("button", { name: "Kassani yopish", exact: true })
    .click();
  await dialog(page)
    .getByLabel("Amaldagi naqd pul", { exact: true })
    .fill("1000");
  await dialog(page)
    .getByLabel("Smena yopish izohi", { exact: true })
    .fill("Ortiqcha pul");
  await expect(dialog(page)).toContainText("Ortiqcha pul");
  await dialog(page)
    .getByRole("button", { name: "Kassani yopish", exact: true })
    .click();
  const s = await ledger(page);
  expect(s.shifts.at(-1).actual).toBe(1000);
  expect(s.shifts.at(-1).closing.expected).toBe(0);
});
test("failed local storage preserves ledger; small desktop/modal focus and shared navigation", async ({
  page,
}) => {
  await open(page);
  await start(page);
  await page.getByLabel("Qabul qilingan naqd", { exact: true }).fill("350000");
  const original = await ledger(page);
  await page.evaluate(() => {
    Storage.prototype.setItem = function () {
      throw new DOMException("Quota exceeded", "QuotaExceededError");
    };
  });
  await page
    .getByRole("button", { name: "To‘lovni qabul qilish", exact: true })
    .click();
  await expect(page.getByRole("alert")).toContainText("Quota");
  expect(await ledger(page)).toEqual(original);
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await page.setViewportSize({ width: 1280, height: 900 });
  await shot(page, "17-small-cash");
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBeTruthy();
  await page
    .getByRole("button", { name: "Kassani yopish", exact: true })
    .click();
  await dialog(page)
    .getByRole("button", { name: "Kassani yopish", exact: true })
    .focus();
  await page.keyboard.press("Tab");
  await expect(
    dialog(page).getByRole("button", { name: "Yopish", exact: true }),
  ).toBeFocused();
  await page.keyboard.press("Escape");
  await page.getByRole("button", { name: "Xizmatlar", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Xizmatlar", exact: true }),
  ).toBeVisible();
});
