import { test, expect, type Page } from "@playwright/test";
import { mkdir } from "node:fs/promises";
import { receptionSession } from "./reception.fixture";
test.use({ viewport: { width: 1536, height: 1024 }, locale: "uz-UZ" });
const dialog = (p: Page) => p.getByRole("dialog").last();
async function open(p: Page) {
  await p.clock.setFixedTime(new Date("2026-10-20T08:00:00"));
  const requests = await receptionSession(p);
  await p
    .getByRole("button", { name: "UZI va Diagnostika", exact: true })
    .click();
  return requests;
}
const row = (p: Page, id = "YL-00125") =>
  p.locator(".dx-list tbody tr").filter({ hasText: id });
async function start(p: Page) {
  await row(p).getByRole("button", { name: "Boshlash", exact: true }).click();
}
async function fill(
  p: Page,
  description = "Synthetic description",
  conclusion = "Synthetic conclusion",
) {
  await p
    .getByRole("textbox", { name: "Tekshiruv tavsifi", exact: true })
    .fill(description);
  await p
    .getByRole("textbox", { name: "Xulosa", exact: true })
    .fill(conclusion);
}
async function confirm(p: Page, amended = false) {
  await p
    .getByRole("button", {
      name: amended ? "Tuzatilgan natijani tasdiqlash" : "Natijani tasdiqlash",
      exact: true,
    })
    .click();
  await dialog(p)
    .getByRole("button", { name: "Tasdiqlash", exact: true })
    .click();
}
async function shot(p: Page, name: string) {
  await mkdir("/tmp/blusser-diagnostics-screenshots", { recursive: true });
  await p.evaluate(() => document.fonts.ready);
  await p.screenshot({
    path: `/tmp/blusser-diagnostics-screenshots/${name}.png`,
  });
}
async function upload(p: Page) {
  const png = await p.evaluate(() => {
    const c = document.createElement("canvas");
    c.width = 240;
    c.height = 180;
    const x = c.getContext("2d")!;
    x.fillStyle = "#17213c";
    x.fillRect(0, 0, 240, 180);
    x.fillStyle = "white";
    x.font = "16px sans-serif";
    x.fillText("SYNTHETIC DEMO", 20, 90);
    return c.toDataURL().split(",")[1]!;
  });
  await p.locator(".dx-file-input").setInputFiles([
    {
      name: "demo.png",
      mimeType: "image/png",
      buffer: Buffer.from(png, "base64"),
    },
    {
      name: "report.pdf",
      mimeType: "application/pdf",
      buffer: Buffer.from("%PDF-1.4\ncorrupt"),
    },
  ]);
}
test("queue filters, read-only details, date, empty and pagination", async ({
  page,
}) => {
  const requests = await open(page);
  await expect(page.locator(".dx-list tbody tr")).toHaveCount(8);
  await expect(row(page, "YL-00999")).toHaveCount(0);
  await expect(row(page, "YL-00129")).toContainText("Belgilanmagan");
  await shot(page, "01-list");
  await row(page)
    .getByRole("button", { name: "YL-00125 tafsilotlari" })
    .click();
  await expect(dialog(page)).toContainText("09:10");
  await expect(dialog(page)).toContainText("10:30");
  await shot(page, "02-referral");
  await dialog(page)
    .getByRole("button", { name: "Yopish", exact: true })
    .last()
    .click();
  await expect(row(page)).toContainText("Boshlash");
  await page.getByLabel("Bemor yoki yo‘llanma qidirish").fill("Usmonov");
  await expect(page.locator(".dx-list tbody tr")).toHaveCount(1);
  await row(page, "YL-00126")
    .getByRole("button", { name: "Ko‘rish", exact: true })
    .click();
  await expect(
    dialog(page).getByRole("button", {
      name: "Tekshiruvni boshlash",
      exact: true,
    }),
  ).toHaveCount(0);
  await dialog(page)
    .getByRole("button", { name: "Yopish", exact: true })
    .last()
    .click();
  await page.getByLabel("Bemor yoki yo‘llanma qidirish").fill("");
  await page.getByLabel("Tekshiruv", { exact: true }).selectOption("abdomen");
  await expect(page.locator(".dx-list tbody tr")).toHaveCount(2);
  await page
    .getByLabel("Yo‘llagan shifokor", { exact: true })
    .selectOption("alimova");
  await expect(page.locator(".dx-list tbody tr")).toHaveCount(1);
  await page.getByLabel("Filial", { exact: true }).selectOption("yunus");
  await expect(
    page.getByText("Yo‘llanma topilmadi", { exact: true }),
  ).toBeVisible();
  await page.getByLabel("Tekshiruv", { exact: true }).selectOption("");
  await page.getByLabel("Yo‘llagan shifokor", { exact: true }).selectOption("");
  await page.getByLabel("Filial", { exact: true }).selectOption("");
  await page.getByLabel("Sahifadagi qatorlar").selectOption("5");
  await expect(page.locator(".dx-list tbody tr")).toHaveCount(5);
  await page.getByRole("button", { name: "›", exact: true }).click();
  await expect(page.locator(".dx-list tbody tr")).toHaveCount(3);
  await page.getByLabel("Yo‘llanma sanasi").fill("2026-10-21");
  await expect(
    page.getByText("Yo‘llanma topilmadi", { exact: true }),
  ).toBeVisible();
  await page.setViewportSize({ width: 1280, height: 900 });
  await shot(page, "03-small-list");
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBeTruthy();
  expect(
    requests.filter((r) => /diagnos|referral|result/.test(r.path)),
  ).toHaveLength(0);
});
test("partial save, resume, dirty cancel/discard on sidebar", async ({
  page,
}) => {
  await open(page);
  await start(page);
  await shot(page, "04-editor");
  const time = await page.locator("aside dd").last().textContent();
  await page
    .getByRole("textbox", { name: "Tekshiruv tavsifi", exact: true })
    .fill("Partial draft");
  await page
    .getByRole("button", { name: "Qoralamani saqlash", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Ro‘yxatga qaytish", exact: true })
    .first()
    .click();
  await row(page)
    .getByRole("button", { name: "Davom etish", exact: true })
    .click();
  await expect(
    page.getByRole("textbox", { name: "Tekshiruv tavsifi", exact: true }),
  ).toHaveText("Partial draft");
  expect(await page.locator("aside dd").last().textContent()).toBe(time);
  await page
    .getByRole("textbox", { name: "Xulosa", exact: true })
    .fill("Unsaved");
  await page
    .getByRole("button", { name: "Ro‘yxatga qaytish", exact: true })
    .first()
    .click();
  await dialog(page)
    .getByRole("button", { name: "Davom etish", exact: true })
    .click();
  await expect(
    page.getByRole("textbox", { name: "Xulosa", exact: true }),
  ).toHaveText("Unsaved");
  await page.getByRole("button", { name: "Xizmatlar", exact: true }).click();
  await expect(dialog(page)).toContainText("Saqlanmagan");
  await dialog(page)
    .getByRole("button", { name: "O‘zgarishlarni bekor qilish", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "Xizmatlar", exact: true }),
  ).toBeVisible();
});
test("templates, placeholder validation, replacement cancel preserves text and files", async ({
  page,
}) => {
  await open(page);
  await start(page);
  await page
    .getByRole("button", { name: "Natijani tasdiqlash", exact: true })
    .click();
  await expect(page.locator(".dx-field [role=alert]")).toHaveCount(2);
  await page
    .getByRole("button", { name: "Shablondan foydalanish", exact: true })
    .click();
  await shot(page, "05-template");
  await dialog(page)
    .getByRole("button", { name: "Shablonni qo‘llash", exact: true })
    .click();
  await expect(
    page.getByRole("textbox", { name: "Tekshiruv tavsifi", exact: true }),
  ).toContainText("[ma’lumot kiriting]");
  await page
    .getByRole("button", { name: "Natijani tasdiqlash", exact: true })
    .click();
  await expect(page.locator(".dx-field [role=alert]").first()).toContainText(
    "Shablon",
  );
  await fill(page);
  await upload(page);
  await page
    .getByRole("button", { name: "Shablondan foydalanish", exact: true })
    .click();
  await dialog(page).getByLabel("Shablon qidirish").fill("Buyrak");
  await dialog(page)
    .getByRole("button", { name: "Buyrak va siydik pufagi UZI", exact: true })
    .click();
  await dialog(page)
    .getByRole("button", { name: "Shablonni qo‘llash", exact: true })
    .click();
  await expect(dialog(page)).toContainText("almashtiriladi");
  await dialog(page)
    .getByRole("button", { name: "Bekor qilish", exact: true })
    .click();
  await expect(
    page.getByRole("textbox", { name: "Tekshiruv tavsifi", exact: true }),
  ).toHaveText("Synthetic description");
  await expect(page.locator(".dx-file-card")).toHaveCount(2);
  await page
    .getByRole("button", { name: "Shablondan foydalanish", exact: true })
    .click();
  await dialog(page)
    .getByRole("button", { name: "Shablonni qo‘llash", exact: true })
    .click();
  await dialog(page)
    .getByRole("button", { name: "Almashtirishni tasdiqlash", exact: true })
    .click();
  await expect(page.locator(".dx-file-card")).toHaveCount(2);
});
test("file viewer zoom/navigation, corrupt PDF, removal, size/type errors", async ({
  page,
}) => {
  await open(page);
  await start(page);
  await fill(page);
  await upload(page);
  await shot(page, "06-files");
  await page
    .getByRole("button", { name: "demo.png ko‘rish", exact: true })
    .click();
  await expect(dialog(page).locator(".dw-viewer-stage img")).toBeVisible();
  await shot(page, "07-viewer");
  await dialog(page)
    .getByRole("button", { name: "Masshtabni oshirish", exact: true })
    .click();
  await expect(dialog(page)).toContainText("125%");
  await dialog(page)
    .getByRole("button", { name: "Konteynerga moslash", exact: true })
    .click();
  const bounds = await dialog(page)
    .locator(".dw-viewer-stage")
    .evaluate((stage) => ({
      height: stage.clientHeight,
      image: stage.querySelector("img")!.getBoundingClientRect().height,
    }));
  expect(bounds.image).toBeLessThanOrEqual(bounds.height);
  await dialog(page)
    .getByRole("button", { name: "Keyingi fayl", exact: true })
    .click();
  await expect(dialog(page).getByRole("alert")).toContainText("ochib bo‘lmadi");
  await dialog(page)
    .getByRole("button", { name: "Yopish", exact: true })
    .last()
    .click();
  await expect(
    page.getByRole("textbox", { name: "Tekshiruv tavsifi", exact: true }),
  ).toHaveText("Synthetic description");
  await page
    .getByRole("button", { name: "report.pdfni olib tashlash", exact: true })
    .click();
  await expect(page.locator(".dx-file-card")).toHaveCount(1);
  await page.locator(".dx-file-input").setInputFiles([
    { name: "bad.txt", mimeType: "text/plain", buffer: Buffer.from("bad") },
    {
      name: "huge.png",
      mimeType: "image/png",
      buffer: Buffer.alloc(20 * 1024 * 1024 + 1),
    },
  ]);
  await expect(
    page.getByRole("alert").filter({ hasText: "faqat JPG" }),
  ).toBeVisible();
  await expect(
    page.getByRole("alert").filter({ hasText: "20 MB" }),
  ).toBeVisible();
});
test("readonly confirm, single amendment, previous published/history, PDF and print", async ({
  page,
}) => {
  await open(page);
  await start(page);
  await fill(page);
  await upload(page);
  await page
    .getByRole("button", { name: "report.pdfni olib tashlash", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Natijani tasdiqlash", exact: true })
    .click();
  await shot(page, "08-confirm");
  await dialog(page)
    .getByRole("button", { name: "Tasdiqlash", exact: true })
    .click();
  await expect(page.locator(".dx-heading")).toContainText("Yakunlangan");
  await expect(page.locator("[contenteditable]")).toHaveCount(0);
  await expect(page.locator(".dx-file-input")).toHaveCount(0);
  await shot(page, "09-completed");
  await page
    .getByRole("button", { name: "Tuzatish kiritish", exact: true })
    .click();
  await dialog(page)
    .getByLabel("Tuzatish sababi", { exact: true })
    .fill("Synthetic correction");
  await shot(page, "10-amend");
  await dialog(page)
    .getByRole("button", { name: "Tuzatishni boshlash", exact: true })
    .click();
  await expect(
    page.getByRole("textbox", { name: "Tekshiruv tavsifi", exact: true }),
  ).toHaveText("Synthetic description");
  await fill(page, "Corrected synthetic description", "Corrected conclusion");
  await page
    .getByRole("button", { name: "Qoralamani saqlash", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Ro‘yxatga qaytish", exact: true })
    .first()
    .click();
  await expect(row(page)).toContainText("Yakunlangan");
  await row(page).getByRole("button", { name: "Natija", exact: true }).click();
  await expect(page.locator(".dx-rich").first()).toHaveText(
    "Synthetic description",
  );
  await page
    .getByRole("button", { name: "Tuzatishni davom ettirish", exact: true })
    .click();
  await expect(
    page.getByRole("textbox", { name: "Tekshiruv tavsifi", exact: true }),
  ).toHaveText("Corrected synthetic description");
  await confirm(page, true);
  await expect(page.getByText(/Natija yangilangan/)).toBeVisible();
  await page
    .getByRole("button", { name: "Versiyalar tarixi (2)", exact: true })
    .click();
  await dialog(page)
    .getByRole("button", { name: /1-versiya/ })
    .click();
  await expect(dialog(page)).toContainText("Synthetic description");
  await expect(dialog(page)).not.toContainText(
    "Corrected synthetic description",
  );
  await shot(page, "11-history");
  const downloadPromise = page.waitForEvent("download");
  await dialog(page)
    .getByRole("button", { name: "PDF yuklab olish", exact: true })
    .click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toBe("YL-00125-v1.pdf");
  await download.saveAs("/tmp/blusser-diagnostics-screenshots/historical.pdf");
  await dialog(page)
    .getByRole("button", { name: "Yopish", exact: true })
    .last()
    .click();
  await page.evaluate(() => {
    const original = window.open.bind(window);
    window.open = (...args: Parameters<typeof window.open>) => {
      const popup = original(...args);
      if (popup)
        popup.print = () => {
          popup.document.documentElement.dataset.printed = "yes";
        };
      return popup;
    };
  });
  const popupPromise = page.waitForEvent("popup");
  await page.getByRole("button", { name: "Chop etish", exact: true }).click();
  const popup = await popupPromise;
  await expect(popup.locator("img")).toHaveCount(1);
  await expect(popup.locator("html")).toHaveAttribute("data-printed", "yes");
  await expect(popup.locator(".clinic-sidebar")).toHaveCount(0);
  await popup.close();
  await page.setViewportSize({ width: 1280, height: 900 });
  await shot(page, "12-small-completed");
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBeTruthy();
});
test("multi-page report keeps full Uzbek text, patient/version metadata and separate PDF/file navigation", async ({
  page,
}) => {
  await open(page);
  await start(page);
  const payload = await page.evaluate(async () => {
    const modelPath = "/src/mocks/diagnostics.ts",
      reportPath = "/src/components/diagnostics/report.ts";
    const m = await import(modelPath),
      { resultPdf } = await import(reportPath);
    const s = m.createDiagnosticsStore("2026-10-20"),
      r = s.referrals[0],
      e = s.exams[r.examId];
    const text = "O‘zbekcha namunaviy matn ".repeat(400) + " OXIRGI_BELGI";
    const v = m.versionSnapshot(
      {
        number: 1,
        description: text,
        conclusion: "Namuna xulosasi",
        files: [],
        reason: "",
        author: { id: "exporter", name: "Export tester", specialty: "Demo" },
      },
      "2026-10-20T10:52:00",
    );
    const captured: string[] = [];
    const old = CanvasRenderingContext2D.prototype.fillText;
    CanvasRenderingContext2D.prototype.fillText = function (
      text,
      ...args: [number, number, number?]
    ) {
      captured.push(text);
      if (args[2] === undefined) old.call(this, text, args[0], args[1]);
      else old.call(this, text, args[0], args[1], args[2]);
    };
    let bytes: Uint8Array;
    try {
      bytes = new Uint8Array(
        await (await resultPdf(r, e, v, true)).arrayBuffer(),
      );
    } finally {
      CanvasRenderingContext2D.prototype.fillText = old;
    }
    return { data: Array.from(bytes), captured };
  });
  expect(payload.captured.join(" ")).toContain("OXIRGI_BELGI");
  expect(payload.captured.join(" ")).toContain("Aliyeva Mohira");
  expect(payload.captured.join(" ")).toContain("TARIXIY VERSIYA");
  expect(payload.captured.join(" ")).toContain("YL-00125");
  expect(payload.captured.join(" ")).toContain("Export tester");
  expect(payload.captured.join(" ")).not.toContain("Usmonov");
  await page.locator(".dx-file-input").setInputFiles({
    name: "multi-page.pdf",
    mimeType: "application/pdf",
    buffer: Buffer.from(payload.data),
  });
  await page
    .getByRole("button", { name: "multi-page.pdf ko‘rish", exact: true })
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
  await page.waitForFunction(() => {
    const c = document.querySelector<HTMLCanvasElement>(
      ".dw-viewer-stage canvas",
    );
    if (!c || !c.width || !c.height) return false;
    const pixels = c
      .getContext("2d")!
      .getImageData(0, 0, c.width, Math.min(c.height, 200)).data;
    let dark = 0;
    for (let i = 0; i < pixels.length; i += 4)
      if (pixels[i + 3] === 255 && pixels[i]! < 120) dark++;
    return dark > 100;
  });
  await shot(page, "13-multipage-pdf");
  await dialog(page)
    .getByRole("button", { name: "Yopish", exact: true })
    .last()
    .click();
});
test("hash navigation warns and cancel retains the diagnostic draft", async ({
  page,
}) => {
  await open(page);
  await start(page);
  await fill(page);
  await page.evaluate(() => {
    window.location.hash = "bemorlar";
  });
  await expect(dialog(page)).toContainText("Saqlanmagan");
  await dialog(page)
    .getByRole("button", { name: "Davom etish", exact: true })
    .click();
  await expect(
    page.getByRole("textbox", { name: "Tekshiruv tavsifi", exact: true }),
  ).toHaveText("Synthetic description");
  await page.evaluate(() => {
    window.location.hash = "bemorlar";
  });
  await dialog(page)
    .getByRole("button", { name: "O‘zgarishlarni bekor qilish", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "Bemorlar", exact: true }),
  ).toBeVisible();
});
