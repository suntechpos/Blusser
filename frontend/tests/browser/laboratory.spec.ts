import { test, expect, type Page } from "@playwright/test";
import { mkdir } from "node:fs/promises";
import { receptionSession } from "./reception.fixture";
test.use({ viewport: { width: 1536, height: 1024 }, locale: "uz-UZ" });
const dialog = (p: Page) => p.getByRole("dialog").last();
const row = (p: Page, id = "YL-00201") =>
  p.locator(".lb-list tbody tr").filter({ hasText: id });
async function open(p: Page) {
  await p.clock.setFixedTime(new Date("2026-10-20T12:00:00"));
  const requests = await receptionSession(p);
  await p.getByRole("button", { name: "Laboratoriya", exact: true }).click();
  return requests;
}
async function shot(p: Page, name: string) {
  await mkdir("/tmp/blusser-laboratory-screenshots", { recursive: true });
  await p.evaluate(() => document.fonts.ready);
  await p.screenshot({
    path: `/tmp/blusser-laboratory-screenshots/${name}.png`,
  });
}
async function collect(p: Page) {
  await row(p)
    .getByRole("button", { name: "Namuna olish", exact: true })
    .click();
  await dialog(p)
    .getByRole("button", { name: "Namuna olinganini tasdiqlash", exact: true })
    .click();
}
async function start(p: Page) {
  await row(p)
    .getByRole("button", { name: "Tahlilni boshlash", exact: true })
    .click();
  await dialog(p)
    .getByRole("button", { name: "Tahlilni boshlash", exact: true })
    .click();
}
async function cbc(p: Page, first = "128") {
  for (const [name, value] of [
    ["Gemoglobin (HGB)", first],
    ["Eritrotsitlar (RBC)", "4.4"],
    ["Leykotsitlar (WBC)", "11.2"],
    ["Trombotsitlar (PLT)", "250"],
    ["Gematokrit (HCT)", "39"],
    ["MCV", "89"],
    ["ECHT (ESR)", "12"],
  ])
    await p.getByLabel(`${name} natijasi`, { exact: true }).fill(value!);
}
async function submit(p: Page) {
  await p
    .getByRole("button", { name: "Tekshirishga yuborish", exact: true })
    .click();
}
async function confirm(p: Page) {
  await p
    .getByRole("button", { name: "Natijani tasdiqlash", exact: true })
    .click();
  await dialog(p)
    .getByRole("button", { name: "Tasdiqlash", exact: true })
    .click();
}
async function image(p: Page) {
  const data = await p.evaluate(() => {
    const c = document.createElement("canvas");
    c.width = 250;
    c.height = 140;
    const x = c.getContext("2d")!;
    x.fillStyle = "#eff6ff";
    x.fillRect(0, 0, 250, 140);
    x.fillStyle = "#123465";
    x.font = "17px sans-serif";
    x.fillText("LAB SYNTHETIC DEMO", 20, 70);
    return c.toDataURL().split(",")[1]!;
  });
  return {
    name: "lab-demo.png",
    mimeType: "image/png",
    buffer: Buffer.from(data, "base64"),
  };
}
test("queue source/counts/status actions, filters, details read only and pagination", async ({
  page,
}) => {
  const requests = await open(page);
  await expect(page.locator(".lb-list tbody tr")).toHaveCount(8);
  await expect(page.locator(".lb-stats strong")).toHaveText([
    "2",
    "2",
    "2",
    "1",
    "1",
  ]);
  await expect(row(page)).toContainText("Olinmagan");
  await expect(row(page, "YL-00999")).toHaveCount(0);
  await shot(page, "01-list");
  await row(page)
    .getByRole("button", {
      name: "YL-00201 yo‘llanma tafsilotlari",
      exact: true,
    })
    .click();
  await expect(dialog(page)).toContainText("Namuna olinmagan");
  await expect(dialog(page)).toContainText("Hali belgilanmagan");
  await shot(page, "02-referral");
  await dialog(page)
    .getByRole("button", { name: "Yopish", exact: true })
    .last()
    .click();
  await expect(row(page)).toContainText("Namuna olish");
  await page
    .getByRole("button", { name: "Namuna olindi (2)", exact: true })
    .click();
  await expect(page.locator(".lb-list tbody tr")).toHaveCount(2);
  await page.getByRole("button", { name: "Barchasi (8)", exact: true }).click();
  await page.getByLabel("Laboratoriyada qidirish").fill("Usmonov");
  await expect(page.locator(".lb-list tbody tr")).toHaveCount(1);
  await page.getByLabel("Laboratoriyada qidirish").fill("");
  await page.getByLabel("Tahlil filtri").selectOption("cbc");
  await expect(page.locator(".lb-list tbody tr")).toHaveCount(3);
  await page.getByLabel("Laboratoriya filiali").selectOption("yunus");
  await expect(
    page.getByText("Natija topilmadi", { exact: true }),
  ).toBeVisible();
  await page.getByLabel("Tahlil filtri").selectOption("");
  await page.getByLabel("Laboratoriya filiali").selectOption("");
  await page.getByLabel("Yo‘llagan shifokor filtri").selectOption("karimov");
  await expect(page.locator(".lb-list tbody tr")).toHaveCount(3);
  await page.getByLabel("Yo‘llagan shifokor filtri").selectOption("");
  await page.getByLabel("Laboratoriya sahifasidagi yozuvlar").selectOption("5");
  await expect(page.locator(".lb-list tbody tr")).toHaveCount(5);
  await page.getByRole("button", { name: "›", exact: true }).click();
  await expect(page.locator(".lb-list tbody tr")).toHaveCount(3);
  await page.getByLabel("Laboratoriya sanasi").fill("2026-10-21");
  await expect(
    page.getByText("Bu sana va holat uchun yo‘llanma yo‘q", { exact: true }),
  ).toBeVisible();
  expect(
    requests.filter((r) => /laborat|sample|result/.test(r.path)),
  ).toHaveLength(0);
});
test("collect validates date, unique ID, session collector; barcode label prints local specimen only", async ({
  page,
}) => {
  await open(page);
  await row(page)
    .getByRole("button", {
      name: "YL-00201 yo‘llanma tafsilotlari",
      exact: true,
    })
    .click();
  await dialog(page)
    .getByRole("button", { name: "Namuna olish", exact: true })
    .click();
  await expect(dialog(page).getByLabel("Avtomatik namuna ID")).toHaveValue(
    "NM-00109",
  );
  await shot(page, "03-collect");
  await dialog(page).getByLabel("Namuna olingan vaqt").fill("23:59");
  await dialog(page)
    .getByRole("button", { name: "Namuna olinganini tasdiqlash", exact: true })
    .click();
  await expect(dialog(page).getByRole("alert")).toContainText("hozirgacha");
  await dialog(page).getByLabel("Namuna olingan vaqt").fill("11:45");
  await dialog(page)
    .getByRole("button", { name: "Namuna olinganini tasdiqlash", exact: true })
    .click();
  await expect(row(page)).toContainText("NM-00109");
  await expect(row(page)).toContainText("Namuna olindi");
  await page
    .getByRole("button", { name: "Yorliqni chop etish", exact: true })
    .click();
  await expect(dialog(page).getByRole("img")).toHaveAttribute(
    "aria-label",
    "Code 39 shtrix-kod NM-00109",
  );
  await shot(page, "04-label");
  await page.evaluate(() => {
    const open = window.open.bind(window);
    window.open = (...args: Parameters<typeof window.open>) => {
      const w = open(...args);
      if (w)
        w.print = () => {
          w.document.documentElement.dataset.printed = "yes";
        };
      return w;
    };
  });
  const next = page.waitForEvent("popup");
  await dialog(page)
    .getByRole("button", { name: "Yorliqni chop etish", exact: true })
    .click();
  const popup = await next;
  await expect(popup.locator("img")).toHaveCount(1);
  await expect(popup.locator("html")).toHaveAttribute("data-printed", "yes");
  await popup.screenshot({
    path: "/tmp/blusser-laboratory-screenshots/04-label-print.png",
  });
  await popup.close();
  await dialog(page)
    .getByRole("button", { name: "Yopish", exact: true })
    .last()
    .click();
  await row(page)
    .getByRole("button", { name: "Tahlilni boshlash", exact: true })
    .click();
  await expect(dialog(page)).toContainText("visual.ceo");
  await expect(dialog(page)).toContainText("11:45");
  await shot(page, "05-start");
  await dialog(page)
    .getByRole("button", { name: "Tahlilni boshlash", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: /Umumiy qon tahlili/ }),
  ).toBeVisible();
});
test("partial draft, timestamp resume, validation, text and qualitative templates, unknown norm", async ({
  page,
}) => {
  await open(page);
  await row(page, "YL-00205")
    .getByRole("button", { name: "Natija kiritish", exact: true })
    .click();
  const startedAt = await page
    .locator(".lb-sample-info dd")
    .last()
    .textContent();
  await page
    .getByLabel("Gemoglobin (HGB) natijasi", { exact: true })
    .fill("118");
  await page
    .getByRole("button", { name: "Qoralamani saqlash", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Ro‘yxatga qaytish", exact: true })
    .first()
    .click();
  await row(page, "YL-00205")
    .getByRole("button", { name: "Natija kiritish", exact: true })
    .click();
  await expect(
    page.getByLabel("Gemoglobin (HGB) natijasi", { exact: true }),
  ).toHaveValue("118");
  expect(await page.locator(".lb-sample-info dd").last().textContent()).toBe(
    startedAt,
  );
  await submit(page);
  await expect(page.locator(".lb-field-error")).toHaveCount(6);
  await cbc(page, "118");
  await page
    .getByLabel("Eritrotsitlar (RBC) natijasi", { exact: true })
    .fill("bad");
  await submit(page);
  await expect(page.locator(".lb-field-error")).toHaveText(
    "To‘g‘ri raqam kiriting.",
  );
  await page
    .getByLabel("Eritrotsitlar (RBC) natijasi", { exact: true })
    .fill("4,4");
  await expect(page.locator(".lb-flag.low")).toContainText("Past");
  await shot(page, "06-editor");
  await page
    .getByRole("button", { name: "Qoralamani saqlash", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Ro‘yxatga qaytish", exact: true })
    .first()
    .click();
  await row(page, "YL-00206")
    .getByRole("button", { name: "Natija kiritish", exact: true })
    .click();
  await expect(page.locator(".lb-results tbody tr")).toHaveCount(4);
  await page.getByLabel("Rangi natijasi", { exact: true }).fill("Somon sariq");
  await page
    .getByLabel("Nitritlar natijasi", { exact: true })
    .selectOption("Aniqlanmadi");
  await page.getByLabel("pH natijasi", { exact: true }).fill("6");
  await page
    .getByLabel("Nisbiy zichlik natijasi", { exact: true })
    .fill("1.02");
  await expect(page.locator(".lb-flag.unknown")).toContainText("Belgilanmagan");
  await expect(page.locator(".lb-flag.qualitative")).toHaveCount(2);
  await submit(page);
  await expect(
    page.getByRole("heading", { name: /Natijani tekshirish/ }),
  ).toBeVisible();
});
test("submit, return reason/cancel, preserve values, review, confirm immutable result", async ({
  page,
}) => {
  await open(page);
  await collect(page);
  await start(page);
  await cbc(page);
  await page
    .getByLabel("Laborant izohi", { exact: true })
    .fill("Synthetic laboratory note");
  await submit(page);
  await expect(page.locator(".lb-results input")).toHaveCount(0);
  await expect(page.locator(".lb-flag.high")).toHaveText("Yuqori");
  await shot(page, "07-review");
  await page
    .getByRole("button", { name: "Qayta ishlashga qaytarish", exact: true })
    .click();
  await expect(
    dialog(page).getByRole("button", { name: "Qaytarish", exact: true }),
  ).toBeDisabled();
  await dialog(page)
    .getByRole("button", { name: "Bekor qilish", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Qayta ishlashga qaytarish", exact: true })
    .click();
  await dialog(page)
    .getByLabel("Qaytarish sababi", { exact: true })
    .fill("Synthetic rework reason");
  await shot(page, "08-return");
  await dialog(page)
    .getByRole("button", { name: "Qaytarish", exact: true })
    .click();
  await expect(page.locator(".dx-warning").first()).toContainText(
    "Synthetic rework reason",
  );
  await expect(
    page.getByLabel("Gemoglobin (HGB) natijasi", { exact: true }),
  ).toHaveValue("128");
  await submit(page);
  await page
    .getByRole("button", { name: "Natijani tasdiqlash", exact: true })
    .click();
  await expect(dialog(page)).toContainText(
    "Me’yordan tashqari ko‘rsatkich: 1 ta",
  );
  await shot(page, "09-confirm");
  await dialog(page)
    .getByRole("button", { name: "Tasdiqlash", exact: true })
    .click();
  await expect(page.locator(".dx-heading")).toContainText("Yakunlangan");
  await expect(page.locator(".lb-results input")).toHaveCount(0);
  await expect(page.locator(".dx-file-input")).toHaveCount(0);
  await shot(page, "10-completed");
  await page
    .getByRole("button", { name: "Ro‘yxatga qaytish", exact: true })
    .first()
    .click();
  await expect(row(page)).toContainText("Yakunlangan");
  await row(page)
    .getByRole("button", {
      name: "YL-00201 yo‘llanma tafsilotlari",
      exact: true,
    })
    .click();
  await dialog(page)
    .getByRole("button", { name: "Natijani ko‘rish", exact: true })
    .click();
  await expect(page.locator(".lb-results tbody tr")).toHaveCount(7);
});
test("attachments are local and bounded, viewer preserves draft, PDF/file controls differ", async ({
  page,
}) => {
  await open(page);
  await row(page, "YL-00205")
    .getByRole("button", { name: "Natija kiritish", exact: true })
    .click();
  await cbc(page);
  await page.locator(".dx-file-input").setInputFiles([
    await image(page),
    {
      name: "broken.pdf",
      mimeType: "application/pdf",
      buffer: Buffer.from("%PDF-1.4\nbroken"),
    },
  ]);
  await expect(page.locator(".dx-file-card")).toHaveCount(2);
  await shot(page, "11-files");
  await page
    .getByRole("button", { name: "lab-demo.png ko‘rish", exact: true })
    .click();
  await expect(dialog(page).locator(".dw-viewer-stage img")).toBeVisible();
  await dialog(page)
    .getByRole("button", { name: "Masshtabni oshirish", exact: true })
    .click();
  await expect(dialog(page)).toContainText("125%");
  await shot(page, "12-viewer");
  await dialog(page)
    .getByRole("button", { name: "Keyingi fayl", exact: true })
    .click();
  await expect(dialog(page).getByRole("alert")).toContainText("ochib bo‘lmadi");
  await dialog(page)
    .getByRole("button", { name: "Yopish", exact: true })
    .last()
    .click();
  await expect(
    page.getByLabel("Gemoglobin (HGB) natijasi", { exact: true }),
  ).toHaveValue("128");
  await page
    .getByRole("button", { name: "broken.pdfni olib tashlash", exact: true })
    .click();
  await page.locator(".dx-file-input").setInputFiles([
    { name: "bad.txt", mimeType: "text/plain", buffer: Buffer.from("bad") },
    {
      name: "oversized.png",
      mimeType: "image/png",
      buffer: Buffer.alloc(20 * 1024 * 1024 + 1),
    },
  ]);
  await expect(
    page.getByRole("alert").filter({ hasText: "20 MB" }),
  ).toBeVisible();
  await expect(
    page.getByRole("alert").filter({ hasText: "faqat JPG" }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Qoralamani saqlash", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Ro‘yxatga qaytish", exact: true })
    .first()
    .click();
  await row(page, "YL-00205")
    .getByRole("button", { name: "Natija kiritish", exact: true })
    .click();
  await expect(page.locator(".dx-file-card")).toHaveCount(1);
  const extra = await image(page);
  await page.locator(".dx-file-input").setInputFiles(
    Array.from({ length: 10 }, (_, i) => ({
      ...extra,
      name: `extra-${i}.png`,
    })),
  );
  await expect(page.locator(".dx-file-card")).toHaveCount(10);
  await expect(
    page.getByRole("alert").filter({ hasText: "lokal fayl limiti" }),
  ).toBeVisible();
});
test("amendment stays separate, re-review is required, single resumed draft and immutable history", async ({
  page,
}) => {
  await open(page);
  await row(page, "YL-00208")
    .getByRole("button", { name: "Natijani ko‘rish", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Tuzatish kiritish", exact: true })
    .click();
  await expect(
    dialog(page).getByRole("button", {
      name: "Tuzatishni boshlash",
      exact: true,
    }),
  ).toBeDisabled();
  await dialog(page)
    .getByLabel("Tuzatish sababi", { exact: true })
    .fill("Synthetic correction reason");
  await shot(page, "13-amend");
  await dialog(page)
    .getByRole("button", { name: "Tuzatishni boshlash", exact: true })
    .click();
  await cbc(page, "140");
  await page
    .getByRole("button", { name: "Qoralamani saqlash", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Ro‘yxatga qaytish", exact: true })
    .first()
    .click();
  await expect(row(page, "YL-00208")).toContainText("Yakunlangan");
  await row(page, "YL-00208")
    .getByRole("button", { name: "Natijani ko‘rish", exact: true })
    .click();
  await expect(page.locator(".lb-results tbody tr").first()).toContainText(
    "128",
  );
  await page
    .getByRole("button", { name: "Tuzatishni davom ettirish", exact: true })
    .click();
  await expect(
    page.getByLabel("Gemoglobin (HGB) natijasi", { exact: true }),
  ).toHaveValue("140");
  await expect(
    page.getByRole("button", { name: "Natijani tasdiqlash", exact: true }),
  ).toHaveCount(0);
  await submit(page);
  await page
    .getByRole("button", { name: "Ro‘yxatga qaytish", exact: true })
    .first()
    .click();
  await row(page, "YL-00208")
    .getByRole("button", { name: "Natijani ko‘rish", exact: true })
    .click();
  await expect(page.locator(".lb-results tbody tr").first()).toContainText(
    "128",
  );
  await page
    .getByRole("button", { name: "Tuzatishni davom ettirish", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: /Natijani tekshirish/ }),
  ).toBeVisible();
  await confirm(page);
  await expect(page.getByText(/Natija yangilangan/)).toBeVisible();
  await page
    .getByRole("button", { name: "Versiyalar tarixi (2)", exact: true })
    .click();
  await dialog(page)
    .getByRole("button", { name: /1-versiya/ })
    .click();
  await expect(
    dialog(page).locator(".lb-results tbody tr").first(),
  ).toContainText("128");
  await shot(page, "14-history");
  const next = page.waitForEvent("download");
  await dialog(page)
    .getByRole("button", { name: "PDF yuklab olish", exact: true })
    .click();
  const download = await next;
  expect(download.suggestedFilename()).toBe("YL-00208-v1.pdf");
  await download.saveAs(
    "/tmp/blusser-laboratory-screenshots/historical-lab.pdf",
  );
  await dialog(page)
    .getByRole("button", { name: "Yopish", exact: true })
    .last()
    .click();
  await expect(page.locator(".lb-results tbody tr").first()).toContainText(
    "140",
  );
});
test("selected patient PDF/print, actual PDF parsing and page navigation; no application shell", async ({
  page,
}) => {
  await open(page);
  await row(page, "YL-00208")
    .getByRole("button", { name: "Natijani ko‘rish", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Tuzatish kiritish", exact: true })
    .click();
  await dialog(page)
    .getByLabel("Tuzatish sababi", { exact: true })
    .fill("Multi-page export QA");
  await dialog(page)
    .getByRole("button", { name: "Tuzatishni boshlash", exact: true })
    .click();
  await page
    .getByLabel("Laborant izohi", { exact: true })
    .fill("Namuna ".repeat(60));
  await submit(page);
  await confirm(page);
  await page.evaluate(() => {
    const captured: string[] = [];
    (window as unknown as { labExport: string[] }).labExport = captured;
    const old = CanvasRenderingContext2D.prototype.fillText;
    CanvasRenderingContext2D.prototype.fillText = function (text, x, y, max) {
      captured.push(text);
      if (max === undefined) old.call(this, text, x, y);
      else old.call(this, text, x, y, max);
    };
  });
  const next = page.waitForEvent("download");
  await page
    .getByRole("button", { name: "PDF yuklab olish", exact: true })
    .click();
  const downloaded = await next;
  await downloaded.saveAs(
    "/tmp/blusser-laboratory-screenshots/current-lab.pdf",
  );
  const lines = await page.evaluate(() =>
    (window as unknown as { labExport: string[] }).labExport.join(" "),
  );
  expect(lines).toContain("Ahmedova Dilnoza");
  expect(lines).toContain("Me’yor");
  expect(lines).toContain("NM-00108");
  expect(lines).toContain("Tasdiqlagan: visual.ceo");
  expect(lines).not.toContain("Aliyeva Mohira");
  await page.evaluate(() => {
    const open = window.open.bind(window);
    window.open = (...args: Parameters<typeof window.open>) => {
      const w = open(...args);
      if (w)
        w.print = () => {
          w.document.documentElement.dataset.printed = "yes";
        };
      return w;
    };
  });
  const popupNext = page.waitForEvent("popup");
  await page.getByRole("button", { name: "Chop etish", exact: true }).click();
  const popup = await popupNext;
  await expect(popup.locator("html")).toHaveAttribute("data-printed", "yes");
  expect(await popup.locator("img").count()).toBeGreaterThan(0);
  await expect(popup.locator(".clinic-sidebar")).toHaveCount(0);
  await popup.close();
  await page
    .getByRole("button", { name: "Tuzatish kiritish", exact: true })
    .click();
  await dialog(page)
    .getByLabel("Tuzatish sababi", { exact: true })
    .fill("Viewer QA");
  await dialog(page)
    .getByRole("button", { name: "Tuzatishni boshlash", exact: true })
    .click();
  await page
    .locator(".dx-file-input")
    .setInputFiles("/tmp/blusser-laboratory-screenshots/current-lab.pdf");
  await page
    .getByRole("button", { name: "current-lab.pdf ko‘rish", exact: true })
    .click();
  await expect(
    dialog(page).getByRole("button", {
      name: "Keyingi PDF sahifa",
      exact: true,
    }),
  ).toBeEnabled();
  await dialog(page)
    .getByRole("button", { name: "Keyingi PDF sahifa", exact: true })
    .click();
  await expect(dialog(page).locator(".dw-viewer-toolbar")).toContainText("2 /");
  await expect(
    dialog(page).getByRole("button", { name: "Keyingi fayl", exact: true }),
  ).toBeDisabled();
  await expect(dialog(page).locator("canvas")).toBeVisible();
  await shot(page, "15-pdf");
});
test("unsaved warning, keyboard modal focus, small desktop bounds, saved list filters", async ({
  page,
}) => {
  await open(page);
  await page.getByLabel("Laboratoriyada qidirish").fill("Saidova");
  await row(page, "YL-00205")
    .getByRole("button", { name: "Natija kiritish", exact: true })
    .click();
  await page
    .getByLabel("Gemoglobin (HGB) natijasi", { exact: true })
    .fill("123");
  await page
    .getByRole("button", { name: "Ro‘yxatga qaytish", exact: true })
    .first()
    .click();
  await expect(dialog(page)).toContainText("Saqlanmagan");
  await page.keyboard.press("Escape");
  await expect(
    page.getByLabel("Gemoglobin (HGB) natijasi", { exact: true }),
  ).toHaveValue("123");
  await page
    .getByRole("button", { name: "Qoralamani saqlash", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Ro‘yxatga qaytish", exact: true })
    .first()
    .click();
  await expect(page.getByLabel("Laboratoriyada qidirish")).toHaveValue(
    "Saidova",
  );
  await row(page, "YL-00205")
    .getByRole("button", { name: "Natija kiritish", exact: true })
    .click();
  await page.setViewportSize({ width: 1280, height: 900 });
  await shot(page, "16-small-editor");
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBeTruthy();
  await page
    .getByLabel("Gemoglobin (HGB) natijasi", { exact: true })
    .fill("124");
  await page.getByRole("button", { name: "Xizmatlar", exact: true }).click();
  await expect(dialog(page)).toContainText("Saqlanmagan");
  await dialog(page)
    .getByRole("button", { name: "O‘zgarishlarni bekor qilish", exact: true })
    .focus();
  await page.keyboard.press("Tab");
  await expect(
    dialog(page).getByRole("button", { name: "Yopish", exact: true }),
  ).toBeFocused();
  await dialog(page)
    .getByRole("button", { name: "Davom etish", exact: true })
    .click();
  await page.evaluate(() => {
    location.hash = "bemorlar";
  });
  await expect(dialog(page)).toContainText("Saqlanmagan");
  await dialog(page)
    .getByRole("button", { name: "O‘zgarishlarni bekor qilish", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "Bemorlar", exact: true }),
  ).toBeVisible();
});
