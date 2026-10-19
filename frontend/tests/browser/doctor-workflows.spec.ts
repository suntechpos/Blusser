import { test, expect, type Page } from "@playwright/test";
import { mkdir } from "node:fs/promises";
/** UI-only network fixtures. Production authentication code is never bypassed or changed. */
async function setup(
  page: Page,
  kind: "clinic" | "platform" | "temporary" | "guest" = "clinic",
) {
  let signedIn = kind !== "guest";
  const requests: string[] = [];
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("request", (r) => {
    if (r.url().includes("/api/") || r.url().includes("/sanctum/"))
      requests.push(`${r.method()} ${new URL(r.url()).pathname}`);
  });
  const user = {
    id: "ui-synthetic-user",
    login: "aziz.karimov",
    is_platform_admin: kind === "platform",
    must_change_password: kind === "temporary",
    active_tenant_id: "ui-clinic",
  };
  await page.route("**/api/v1/**", async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === "/api/v1/auth/logout") {
      signedIn = false;
      return route.fulfill({ status: 204 });
    }
    if (path === "/api/v1/auth/login") {
      signedIn = true;
      return route.fulfill({ json: user });
    }
    if (path === "/api/v1/auth/me")
      return route.fulfill(
        signedIn
          ? { json: user }
          : { status: 401, json: { message: "Unauthenticated." } },
      );
    if (path === "/api/v1/auth/clinics")
      return route.fulfill({
        json: {
          data: [{ id: "ui-clinic", name: "Namuna klinika", role: "Shifokor" }],
        },
      });
    if (path === "/api/v1/clinic/context")
      return route.fulfill({
        json: { grants: {}, branches: [], timezone: "Asia/Tashkent" },
      });
    return route.fulfill({
      status: 500,
      json: { message: "Unexpected UI test request" },
    });
  });
  await page.route("**/sanctum/csrf-cookie", (route) =>
    route.fulfill({ status: 204 }),
  );
  await page.goto("/#shifokor-kabineti");
  return { requests, errors };
}

const modal = (p: Page) => p.getByRole("dialog").last();
const tab = (p: Page, n: string) =>
  p.getByRole("tab", { name: n, exact: true });
const out =
  process.env.DOCTOR_WORKFLOW_SCREENSHOTS || "/tmp/blusser-doctor-workflows";
async function shot(p: Page, n: string) {
  await mkdir(out, { recursive: true });
  await p.evaluate(() => document.fonts.ready);
  await p.screenshot({ path: `${out}/${n}.png` });
}
async function dismiss(p: Page) {
  await p.keyboard.press("Escape");
  const b = p.getByRole("button", {
    name: "O‘zgarishlarni bekor qilish",
    exact: true,
  });
  if (await b.isVisible()) await b.click();
}
async function rx(p: Page) {
  await p.getByRole("button", { name: "Retsept", exact: true }).click();
}
async function fillDrug(p: Page, i: number) {
  await modal(p)
    .getByLabel(`${i}-dori nomi`, { exact: true })
    .fill("Sintetik preparat");
  await modal(p)
    .getByLabel(`${i}-dori Bir martalik doza`, { exact: true })
    .fill("1");
  await modal(p)
    .getByLabel(`${i}-dori Doza birligi`, { exact: true })
    .selectOption("tabletka");
  await modal(p)
    .getByLabel(`${i}-dori Qabul tartibi`, { exact: true })
    .fill("Faqat namuna");
  await modal(p)
    .getByLabel(`${i}-dori Davomiyligi`, { exact: true })
    .fill("Demo");
}
test.use({ viewport: { width: 1672, height: 941 } });
test("prescriptions validate, add/remove/focus, save/edit, discard and isolate encounters", async ({
  page,
}) => {
  const { requests, errors } = await setup(page);
  await expect(page.locator(".dw-current")).toBeVisible();
  const initial = [...requests];
  await rx(page);
  await shot(page, "12-prescription-single");
  await modal(page).getByRole("button", { name: "Retseptni saqlash" }).click();
  await expect(modal(page).locator(".dw-error")).toHaveCount(5);
  await fillDrug(page, 1);
  await modal(page)
    .getByRole("button", { name: "Dori qo‘shish", exact: true })
    .click();
  await expect(
    modal(page).getByLabel("2-dori nomi", { exact: true }),
  ).toBeFocused();
  await shot(page, "13-prescription-multiple");
  await modal(page)
    .getByRole("button", { name: "1-dorini olib tashlash" })
    .click();
  await fillDrug(page, 1);
  await modal(page)
    .getByRole("button", { name: "Retseptni saqlash" })
    .dblclick();
  await expect(page.locator(".dw-prescriptions tbody tr")).toHaveCount(1);
  await page
    .locator(".dw-prescriptions")
    .getByRole("button", { name: "Tahrirlash" })
    .click();
  await expect(
    modal(page).getByLabel("1-dori nomi", { exact: true }),
  ).toHaveValue("Sintetik preparat");
  await modal(page).getByLabel("1-dori nomi", { exact: true }).fill("Bekor");
  await page.keyboard.press("Escape");
  await expect(modal(page).getByRole("alert")).toBeVisible();
  await modal(page).getByRole("button", { name: "Davom etish" }).click();
  await expect(
    modal(page).getByLabel("1-dori nomi", { exact: true }),
  ).toHaveValue("Bekor");
  await dismiss(page);
  await page
    .locator(".dw-prescriptions")
    .getByRole("button", { name: "Tahrirlash" })
    .click();
  await expect(
    modal(page).getByLabel("1-dori nomi", { exact: true }),
  ).toHaveValue("Sintetik preparat");
  await dismiss(page);
  await page
    .getByRole("button", { name: "Qodirov Aziz · Q-2", exact: true })
    .click();
  await expect(page.locator(".dw-prescriptions")).toHaveCount(0);
  expect(requests).toEqual(initial);
  expect(errors).toEqual([]);
});
test("laboratory own ranges, unknown range, UZI distinction and nested actual PDF viewer preserve filters", async ({
  page,
}) => {
  const { errors } = await setup(page);
  await tab(page, "Tekshiruv natijalari").click();
  await page.getByLabel("Tur filtri").selectOption("Laboratoriya");
  await page
    .getByRole("row")
    .filter({ hasText: "Umumiy qon tahlili" })
    .getByRole("button", { name: "Natijani ko‘rish" })
    .click();
  await expect(modal(page)).toContainText("Gemoglobin");
  await expect(modal(page)).toContainText("120 – 150");
  await shot(page, "14-laboratory");
  await page.setViewportSize({ width: 1536, height: 1024 });
  await shot(page, "15-laboratory-source-viewport");
  await page.setViewportSize({ width: 1672, height: 941 });
  await modal(page)
    .getByRole("button", { name: "Laboratoriya faylini ko‘rish", exact: true })
    .click();
  await expect(modal(page).locator("canvas")).toHaveAttribute("width", /\d+/);
  await expect(modal(page)).toContainText("1 / 1");
  await shot(page, "17-document-viewer");
  await page.keyboard.press("Escape");
  await expect(
    modal(page).getByRole("heading", {
      name: "Laboratoriya natijasi",
      exact: true,
    }),
  ).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(page.getByLabel("Tur filtri")).toHaveValue("Laboratoriya");
  await page
    .getByRole("row")
    .filter({ hasText: "Siydik tahlili" })
    .getByRole("button", { name: "Natijani ko‘rish" })
    .click();
  await expect(modal(page)).toContainText("Baholanmagan");
  await expect(modal(page)).not.toContainText("Gemoglobin");
  await page.keyboard.press("Escape");
  await page.getByLabel("Tur filtri").selectOption("Diagnostika");
  await page
    .getByRole("row")
    .filter({ hasText: "Qorin bo‘shlig‘i UZI" })
    .getByRole("button", { name: "Natijani ko‘rish" })
    .click();
  await expect(modal(page)).toContainText("Tekshiruv tavsifi");
  expect(errors).toEqual([]);
});
test("actual local file uploads validate, view image without PDF pagination, external results share file and reset on reload", async ({
  page,
}) => {
  const { requests, errors } = await setup(page);
  await tab(page, "Hujjatlar").click();
  const initial = [...requests];
  await page
    .getByRole("button", { name: "Hujjat yuklash", exact: true })
    .click();
  await modal(page)
    .getByRole("button", { name: "Yuklash", exact: true })
    .click();
  await expect(modal(page)).toContainText("Faylni tanlang");
  await modal(page)
    .getByLabel("Fayl tanlash", { exact: true })
    .setInputFiles({
      name: "bad.txt",
      mimeType: "text/plain",
      buffer: Buffer.from("bad"),
    });
  await expect(modal(page)).toContainText("Faqat PDF, JPG yoki PNG");
  const png = Buffer.from(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==",
    "base64",
  );
  await modal(page).getByLabel("Fayl tanlash", { exact: true }).setInputFiles({
    name: "synthetic.png",
    mimeType: "image/png",
    buffer: png,
  });
  await modal(page)
    .getByLabel("Hujjat turi", { exact: true })
    .selectOption("Tashqi natija");
  await shot(page, "16-upload");
  await modal(page)
    .getByRole("button", { name: "Yuklash", exact: true })
    .click();
  await page.getByLabel("Hujjatni qidirish").fill("synthetic");
  await page
    .getByRole("button", { name: "synthetic ko‘rish", exact: true })
    .click();
  await expect(modal(page).locator("img")).toBeVisible();
  await expect(
    modal(page).getByRole("button", { name: "Keyingi PDF sahifa" }),
  ).toHaveCount(0);
  await page.keyboard.press("Escape");
  await tab(page, "Tekshiruv natijalari").click();
  await page
    .getByRole("row")
    .filter({ hasText: "synthetic" })
    .getByRole("button", { name: "Hujjatni ochish" })
    .click();
  await expect(modal(page).locator("img")).toBeVisible();
  await page.keyboard.press("Escape");
  expect(requests).toEqual(initial);
  expect(errors).toEqual([]);
  await page.reload();
  await tab(page, "Hujjatlar").click();
  await page.getByLabel("Hujjatni qidirish").fill("synthetic");
  await expect(page.locator("tbody")).toContainText("Hujjat topilmadi");
});
test("external referral detail success only once, PDF bytes and encounter isolation", async ({
  page,
}) => {
  const { errors } = await setup(page);
  await page.getByRole("button", { name: "Yo‘llanma", exact: true }).click();
  await modal(page).getByLabel("Boshqa klinikada", { exact: false }).check();
  await modal(page).getByLabel("Laboratoriya", { exact: false }).check();
  await modal(page)
    .getByLabel("Tekshiruv / xizmat", { exact: true })
    .selectOption("cbc");
  await modal(page)
    .getByRole("button", { name: "Yo‘llanma yaratish", exact: true })
    .click();
  await expect(modal(page).locator(".dw-created")).toBeVisible();
  await shot(page, "20-referral-created");
  const dl = page.waitForEvent("download");
  await modal(page)
    .getByRole("button", { name: "PDF olish", exact: true })
    .click();
  const download = await dl;
  const stream = await download.createReadStream();
  const chunks = [];
  for await (const c of stream!) chunks.push(c);
  expect(Buffer.concat(chunks).subarray(0, 5).toString()).toBe("%PDF-");
  await page.keyboard.press("Escape");
  await shot(page, "19-referrals-list");
  await page
    .locator(".dw-referrals")
    .getByRole("button", { name: "Ko‘rish", exact: true })
    .click();
  await expect(modal(page).locator(".dw-created")).toHaveCount(0);
  await expect(modal(page)).toContainText("Belgilanmagan");
  await shot(page, "21-referral-detail");
  await page.keyboard.press("Escape");
  await page
    .getByRole("button", { name: "Qodirov Aziz · Q-2", exact: true })
    .click();
  await expect(page.locator(".dw-referrals")).toHaveCount(0);
  expect(errors).toEqual([]);
});
test("completion requires fields, wait preserves draft and queue, finalized encounter read only", async ({
  page,
}) => {
  await setup(page);
  await page
    .getByRole("button", { name: "Qabulni yakunlash", exact: true })
    .click();
  await expect(
    modal(page).getByRole("button", { name: "Qabulni yakunlash", exact: true }),
  ).toBeDisabled();
  await expect(modal(page)).toContainText("Kiritish kerak");
  await shot(page, "18-finish");
  await modal(page)
    .getByRole("button", { name: "Natija kutishga qoldirish", exact: true })
    .click();
  await expect(page.locator(".dw-patient-actions")).toContainText(
    "Natija kutilmoqda",
  );
  for (const label of [
    "Shikoyatlar",
    "Ko‘rik natijalari",
    "Tashxis nomi yoki kodi",
    "Tavsiyalar",
  ])
    await page.getByLabel(label, { exact: true }).fill("Faqat sintetik namuna");
  await page
    .getByRole("button", { name: "Qodirov Aziz · Q-2", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Aliyeva Mohira · Q-1", exact: true })
    .click();
  await expect(page.getByLabel("Shikoyatlar", { exact: true })).toHaveValue(
    "Faqat sintetik namuna",
  );
  await page
    .getByRole("button", { name: "Qabulni yakunlash", exact: true })
    .click();
  await modal(page)
    .getByRole("button", { name: "Qabulni yakunlash", exact: true })
    .click();
  await expect(page.locator(".dw-patient-actions")).toContainText(
    "Yakunlangan",
  );
  await expect(page.getByLabel("Shikoyatlar", { exact: true })).toBeDisabled();
  await expect(page.locator(".dw-queue-tabs .selected")).toContainText(
    "Yakunlangan",
  );
});
test("new modal mobile bounds, trapped focus and discarded form returns focus", async ({
  page,
}) => {
  await setup(page);
  await page.setViewportSize({ width: 390, height: 844 });
  const trigger = page.getByRole("button", { name: "Retsept", exact: true });
  await trigger.click();
  for (let i = 0; i < 18; i++) {
    await page.keyboard.press("Shift+Tab");
    expect(
      await modal(page).evaluate((el) => el.contains(document.activeElement)),
    ).toBe(true);
  }
  expect(
    await modal(page).evaluate(
      (el) =>
        el.getBoundingClientRect().top >= 0 &&
        el.getBoundingClientRect().bottom <= innerHeight,
    ),
  ).toBe(true);
  await dismiss(page);
  await expect(trigger).toBeFocused();
  await tab(page, "Hujjatlar").click();
  await page
    .getByRole("button", { name: "Hujjat yuklash", exact: true })
    .click();
  expect(
    await modal(page).evaluate(
      (el) => el.getBoundingClientRect().bottom <= innerHeight,
    ),
  ).toBe(true);
  await dismiss(page);
});

function twoPagePdf(): Buffer {
  const streams = ["First synthetic page", "Second synthetic page"].map(
    (t) => `BT /F1 16 Tf 50 750 Td (${t}) Tj ET`,
  );
  const objects = [
    "<< /Type /Catalog /Pages 2 0 R >>",
    "<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>",
    ...["6", "7"].map(
      (n) =>
        `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents ${n} 0 R >>`,
    ),
    "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    ...streams.map((s) => `<< /Length ${s.length} >>\nstream\n${s}\nendstream`),
  ];
  let text = "%PDF-1.4\n";
  const offsets = [0];
  objects.forEach((o, i) => {
    offsets.push(text.length);
    text += `${i + 1} 0 obj\n${o}\nendobj\n`;
  });
  const start = text.length;
  text +=
    `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n` +
    offsets
      .slice(1)
      .map((n) => `${String(n).padStart(10, "0")} 00000 n \n`)
      .join("") +
    `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${start}\n%%EOF`;
  return Buffer.from(text);
}
test("uploaded PDF derives real page count, navigates/zooms/fits, downloads original bytes and prints rendered pages", async ({
  page,
}) => {
  await page.context().addInitScript(() => {
    window.print = () => {
      document.documentElement.dataset.printInvoked = "true";
    };
  });
  await setup(page);
  await tab(page, "Hujjatlar").click();
  await page
    .getByRole("button", { name: "Hujjat yuklash", exact: true })
    .click();
  await modal(page)
    .getByLabel("Fayl tanlash", { exact: true })
    .setInputFiles({
      name: "too-large.pdf",
      mimeType: "application/pdf",
      buffer: Buffer.alloc(20 * 1024 * 1024 + 1),
    });
  await expect(modal(page)).toContainText("20 MB dan oshmasin");
  const bytes = twoPagePdf();
  await modal(page).getByLabel("Fayl tanlash", { exact: true }).setInputFiles({
    name: "two-pages.pdf",
    mimeType: "application/pdf",
    buffer: bytes,
  });
  await modal(page)
    .getByLabel("Hujjat turi", { exact: true })
    .selectOption("Tashqi xulosa");
  await modal(page)
    .getByRole("button", { name: "Yuklash", exact: true })
    .click();
  await page.getByLabel("Hujjatni qidirish").fill("two-pages");
  await page
    .getByRole("button", { name: "two-pages ko‘rish", exact: true })
    .click();
  await expect(modal(page)).toContainText("1 / 2");
  await modal(page)
    .getByRole("button", { name: "Keyingi PDF sahifa", exact: true })
    .click();
  await expect(modal(page)).toContainText("2 / 2");
  await modal(page)
    .getByRole("button", { name: "Masshtabni oshirish", exact: true })
    .click();
  await expect(modal(page)).toContainText("125%");
  await modal(page)
    .getByRole("button", { name: "Konteynerga moslash", exact: true })
    .click();
  const event = page.waitForEvent("download");
  await modal(page)
    .getByRole("button", { name: "Yuklab olish", exact: true })
    .click();
  const dl = await event;
  const stream = await dl.createReadStream();
  const chunks = [];
  for await (const c of stream!) chunks.push(c);
  expect(Buffer.concat(chunks)).toEqual(bytes);
  const pop = page.waitForEvent("popup");
  await modal(page)
    .getByRole("button", { name: "Chop etish", exact: true })
    .click();
  const popup = await pop;
  await expect(popup.locator("html")).toHaveAttribute(
    "data-print-invoked",
    "true",
  );
  await expect(popup.locator("img")).toHaveCount(2);
  expect(await popup.evaluate(() => window.opener)).toBeNull();
  await popup.close();
  await shot(page, "22-uploaded-two-page-pdf");
});
test("corrupt PDF gives explicit error and cancel never inserts uploaded metadata", async ({
  page,
}) => {
  await setup(page);
  await tab(page, "Hujjatlar").click();
  await page
    .getByRole("button", { name: "Hujjat yuklash", exact: true })
    .click();
  await modal(page)
    .getByLabel("Fayl tanlash", { exact: true })
    .setInputFiles({
      name: "discard.pdf",
      mimeType: "application/pdf",
      buffer: Buffer.from("not a pdf"),
    });
  await dismiss(page);
  await page.getByLabel("Hujjatni qidirish").fill("discard");
  await expect(page.locator("tbody")).toContainText("Hujjat topilmadi");
  await page.getByLabel("Hujjatni qidirish").fill("");
  await page
    .getByRole("button", { name: "Hujjat yuklash", exact: true })
    .click();
  await modal(page)
    .getByLabel("Fayl tanlash", { exact: true })
    .setInputFiles({
      name: "invalid.pdf",
      mimeType: "application/pdf",
      buffer: Buffer.from("invalid synthetic bytes"),
    });
  await modal(page)
    .getByLabel("Hujjat turi", { exact: true })
    .selectOption("Tashqi xulosa");
  await modal(page)
    .getByRole("button", { name: "Yuklash", exact: true })
    .click();
  await page.getByLabel("Hujjatni qidirish").fill("invalid");
  await page
    .getByRole("button", { name: "invalid ko‘rish", exact: true })
    .click();
  await expect(modal(page).getByRole("alert")).toContainText(
    "uni ochib bo‘lmadi",
  );
  await expect(modal(page)).not.toContainText("1 / 2");
});
