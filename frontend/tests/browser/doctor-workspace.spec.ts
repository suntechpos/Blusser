import {allServerGrants} from "./access.fixture";
import { test, expect, type Page } from "@playwright/test";
import { mkdir } from "node:fs/promises";
const output =
  process.env.DOCTOR_SCREENSHOTS ?? "/tmp/blusser-doctor-screenshots";
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
    // Access-control fixture: the shell now reads authorized branches.
    if(path === '/api/v1/clinic/branches')return route.fulfill({json:{data:[]}});
    if (path === "/api/v1/clinic/context")
      return route.fulfill({
        json: { grants: allServerGrants, branches: [], timezone: "Asia/Tashkent" },
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
async function shot(page: Page, name: string) {
  await mkdir(output, { recursive: true });
  await page.evaluate(() => document.fonts.ready);
  const modal = page.getByRole("dialog");
  if (await modal.isVisible()) await modal.getByRole("heading").first().click();
  await page.screenshot({ path: `${output}/${name}.png`, fullPage: false });
}
const tab = (page: Page, name: string) =>
  page.getByRole("tab", { name, exact: true });
const dialog = (page: Page) => page.getByRole("dialog").last();
async function dismiss(page: Page) {
  await page.keyboard.press("Escape");
  const discard = page.getByRole("button", {
    name: "O‘zgarishlarni bekor qilish",
    exact: true,
  });
  if (await discard.isVisible()) await discard.click();
}
test.use({ viewport: { width: 1672, height: 941 }, locale: "uz-UZ" });
test("four tabs and approved modals share identical queue/card/shell, no new API calls", async ({
  page,
}) => {
  const { requests, errors } = await setup(page);
  await expect(
    page.getByRole("heading", { name: "Shifokor kabineti", exact: true }),
  ).toBeVisible();
  const initial = [...requests];
  await expect(page.locator(".dw-queue-person")).toHaveCount(5);
  await expect(page.locator(".dw-allergy")).toContainText(
    "Ma’lumot kiritilmagan",
  );
  await shot(page, "01-current-visit");
  const card = await page
    .locator(".dw-patient-card")
    .evaluate((el) => el.outerHTML);
  const cardBox = await page.locator(".dw-patient-card").boundingBox();
  const queue = await page.locator(".dw-queue").evaluate((el) => el.outerHTML);
  const queueBox = await page.locator(".dw-queue").boundingBox();
  for (const [t, file] of [
    ["Tashriflar tarixi", "02-history"],
    ["Tekshiruv natijalari", "04-results"],
    ["Hujjatlar", "06-documents"],
  ]) {
    await tab(page, t!).click();
    await shot(page, file!);
    expect(
      await page.locator(".dw-patient-card").evaluate((el) => el.outerHTML),
    ).toBe(card);
    expect(await page.locator(".dw-patient-card").boundingBox()).toEqual(
      cardBox,
    );
    expect(await page.locator(".dw-queue").evaluate((el) => el.outerHTML)).toBe(
      queue,
    );
    expect(await page.locator(".dw-queue").boundingBox()).toEqual(queueBox);
  }
  await tab(page, "Tashriflar tarixi").click();
  await page
    .getByRole("button", { name: "05.10.2026 tashrifini ko‘rish" })
    .click();
  await expect(dialog(page).getByRole("textbox")).toHaveCount(0);
  await shot(page, "03-history-detail");
  await dismiss(page);
  await tab(page, "Tekshiruv natijalari").click();
  await page
    .getByRole("row")
    .filter({ hasText: "Qorin bo‘shlig‘i UZI" })
    .getByRole("button", { name: "Natijani ko‘rish", exact: true })
    .click();
  await expect(dialog(page).getByRole("textbox")).toHaveCount(0);
  await shot(page, "05-uzi-result");
  await dialog(page)
    .locator("footer")
    .getByRole("button", { name: "Yopish", exact: true })
    .click();
  await tab(page, "Bugungi qabul").click();
  await page.getByRole("button", { name: "Yo‘llanma", exact: true }).click();
  await dialog(page)
    .getByLabel("Tekshiruv / xizmat", { exact: true })
    .selectOption("uzi");
  await dialog(page)
    .getByLabel("Filial", { exact: true })
    .selectOption("Asosiy filial");
  await shot(page, "07-internal-referral");
  await dialog(page).getByLabel("Boshqa klinikada", { exact: false }).check();
  await shot(page, "08-external-referral");
  await dismiss(page);
  await page.setViewportSize({ width: 1536, height: 1024 });
  await tab(page, "Tekshiruv natijalari").click();
  await shot(page, "10-results-source-viewport");
  await tab(page, "Hujjatlar").click();
  await shot(page, "11-documents-source-viewport");
  expect(requests).toEqual(initial);
  expect(errors).toEqual([]);
});
test("drafts isolate patient AND encounter, persist tab changes and reset on reload", async ({
  page,
}) => {
  await setup(page);
  await page
    .getByLabel("Shikoyatlar", { exact: true })
    .fill("Faqat Mohira uchun demo");
  await page
    .getByRole("button", { name: "Qoralamani saqlash", exact: true })
    .click();
  await expect(page.getByRole("status")).toContainText("frontend xotirasida");
  await tab(page, "Hujjatlar").click();
  await tab(page, "Bugungi qabul").click();
  await expect(page.getByLabel("Shikoyatlar", { exact: true })).toHaveValue(
    "Faqat Mohira uchun demo",
  );
  await page
    .getByRole("button", { name: "Qodirov Aziz · Q-2", exact: true })
    .click();
  await expect(page.getByLabel("Shikoyatlar", { exact: true })).toHaveValue("");
  await page
    .getByLabel("Shikoyatlar", { exact: true })
    .fill("Aziz uchun alohida demo");
  await tab(page, "Tashriflar tarixi").click();
  await expect(page.locator(".dw-tab-panel")).toContainText(
    "Tashrif topilmadi",
  );
  await tab(page, "Bugungi qabul").click();
  await page
    .getByRole("button", { name: "Aliyeva Mohira · Q-1", exact: true })
    .click();
  await expect(page.getByLabel("Shikoyatlar", { exact: true })).toHaveValue(
    "Faqat Mohira uchun demo",
  );
  await page
    .getByRole("button", { name: "Yakunlangan 2", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Aliyeva Mohira · Q-7", exact: true })
    .click();
  await expect(page.getByLabel("Shikoyatlar", { exact: true })).toHaveValue("");
  await expect(page.getByLabel("Shikoyatlar", { exact: true })).toBeDisabled();
  await page.getByRole("button", { name: "Kutilmoqda 4", exact: true }).click();
  await expect(page.getByLabel("Shikoyatlar", { exact: true })).toHaveValue(
    "Faqat Mohira uchun demo",
  );
  await page.reload();
  await expect(page.getByLabel("Shikoyatlar", { exact: true })).toHaveValue("");
});
test("queue search/date/next work locally and canceled modal actions never complete visit", async ({
  page,
}) => {
  await setup(page);
  await page.getByRole("button", { name: "Retsept", exact: true }).click();
  await expect(dialog(page)).toContainText("Retsept yozish");
  await dismiss(page);
  await page
    .getByRole("button", { name: "Qabulni yakunlash", exact: true })
    .click();
  await expect(
    dialog(page).getByRole("button", {
      name: "Qabulni yakunlash",
      exact: true,
    }),
  ).toBeDisabled();
  await dismiss(page);
  await expect(page.locator(".dw-patient-actions")).toContainText("Qabulda");
  await page.getByLabel("Navbatdagi bemorni qidirish").fill("NO MATCH");
  await expect(page.locator(".dw-queue")).toContainText("bemor topilmadi");
  await page.getByLabel("Navbatdagi bemorni qidirish").fill("");
  await page
    .getByRole("button", { name: "Keyingi bemorni chaqirish", exact: true })
    .click();
  await expect(page.locator(".dw-patient-card")).toContainText("Qodirov Aziz");
  await expect(page.locator(".dw-patient-actions")).toContainText("Chaqirildi");
  await page.getByLabel("Qabul sanasi", { exact: true }).fill("2026-10-20");
  await expect(
    page.getByText("Bemorni tanlang yoki boshqa sanani belgilang."),
  ).toBeVisible();
  await page.getByRole("button", { name: "Bugun", exact: true }).click();
  await expect(page.getByLabel("Qabul sanasi", { exact: true })).toHaveValue(
    new Intl.DateTimeFormat("en-CA", {
      timeZone: "Europe/Moscow",
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
    }).format(new Date()),
  );
});
test("referrals validate, sanitize hidden values, remain local, do not finalize and generate results", async ({
  page,
}) => {
  await setup(page);
  await page.getByRole("button", { name: "Yo‘llanma", exact: true }).click();
  let d = dialog(page);
  await d
    .getByRole("button", { name: "Yo‘llanma yaratish", exact: true })
    .click();
  await expect(d.locator(".dw-error")).toHaveCount(2);
  await d.getByLabel("Tekshiruv / xizmat", { exact: true }).selectOption("uzi");
  await d.getByLabel("Filial", { exact: true }).selectOption("Asosiy filial");
  await d
    .getByLabel("Mutaxassis", { exact: true })
    .selectOption("Demo UZI mutaxassisi");
  await d.getByLabel("Filial", { exact: true }).selectOption("Yunusobod");
  await expect(d.getByLabel("Mutaxassis", { exact: true })).toHaveValue("");
  await d
    .getByRole("button", { name: "Yo‘llanma yaratish", exact: true })
    .click();
  await expect(d).toBeHidden();
  await expect(page.locator(".dw-referrals")).toContainText("Buyurilgan");
  await expect(page.locator(".dw-patient-actions")).toContainText("Qabulda");
  await tab(page, "Tekshiruv natijalari").click();
  await page
    .getByLabel("Natija holati", { exact: true })
    .selectOption("Buyurilgan");
  await expect(page.locator("tbody")).toContainText("Qorin bo‘shlig‘i UZI");
  await tab(page, "Bugungi qabul").click();
  await page.getByRole("button", { name: "Yo‘llanma", exact: true }).click();
  d = dialog(page);
  await d.getByLabel("Boshqa klinikada", { exact: false }).check();
  await expect(d.getByLabel("Filial", { exact: true })).toHaveCount(0);
  await d.getByLabel("Tekshiruv / xizmat", { exact: true }).selectOption("uzi");
  await d
    .getByRole("button", { name: "Yo‘llanma yaratish", exact: true })
    .click();
  await expect(page.locator(".dw-referrals tbody tr")).toHaveCount(2);
  await expect(page.locator(".dw-referrals")).toContainText(
    "Natija kutilmoqda",
  );
  await expect(dialog(page).locator(".dw-created")).toBeVisible();
  await dismiss(page);
  await expect(page.locator(".dw-referrals")).toContainText("Boshqa klinikada");
  await shot(page, "09-referrals-list");
  await page.getByRole("button", { name: "Yo‘llanma", exact: true }).click();
  d = dialog(page);
  await d.getByLabel("Laboratoriya", { exact: false }).check();
  await expect(
    d.getByLabel("Tekshiruv / xizmat").locator("option"),
  ).not.toContainText(["Qorin bo‘shlig‘i UZI"]);
  await d.getByRole("button", { name: "Bekor qilish", exact: true }).click();
  await expect(page.locator(".dw-referrals tbody tr")).toHaveCount(2);
});
test("history and result/document filters, read-only UZI, valid mock files and unsupported screens", async ({
  page,
}) => {
  await setup(page);
  await tab(page, "Tashriflar tarixi").click();
  await page.getByLabel("Tashrif shifokori").selectOption("Dilnoza Rasulova");
  await expect(page.locator("tbody tr")).toHaveCount(2);
  await page.getByLabel("Tashrif boshlanish sanasi").fill("2026-09-01");
  await expect(page.locator("tbody tr")).toHaveCount(1);
  await tab(page, "Tekshiruv natijalari").click();
  await expect(page.locator(".dw-pages button")).toHaveCount(3);
  await page.getByLabel("Tur filtri").selectOption("Laboratoriya");
  await expect(page.locator("tbody tr")).toHaveCount(3);
  await page
    .getByRole("row")
    .filter({ hasText: "Umumiy qon tahlili" })
    .getByRole("button", { name: "Natijani ko‘rish" })
    .click();
  await expect(dialog(page)).toContainText("Laboratoriya natijasi");
  await expect(dialog(page)).toContainText("Gemoglobin");
  await dismiss(page);
  await page.getByLabel("Tur filtri").selectOption("Diagnostika");
  await page.getByLabel("Natija holati").selectOption("Jarayonda");
  await expect(page.locator("tbody")).toContainText("topilmadi");
  await page.getByLabel("Natija holati").selectOption("Tayyor");
  await page
    .getByRole("row")
    .filter({ hasText: "Qorin bo‘shlig‘i UZI" })
    .getByRole("button", { name: "Natijani ko‘rish" })
    .click();
  let d = dialog(page);
  await expect(d).toContainText("Dilshod Rahimov");
  await expect(d.getByRole("textbox")).toHaveCount(0);
  const download = page.waitForEvent("download");
  await d
    .getByRole("button", { name: "UZI xulosasi.pdf yuklab olish", exact: true })
    .click();
  const file = await download;
  const fs = await import("node:fs/promises");
  const bytes = await fs.readFile((await file.path())!);
  expect(bytes.subarray(0, 8).toString()).toBe("%PDF-1.4");
  await d
    .locator("footer")
    .getByRole("button", { name: "Yopish", exact: true })
    .click();
  await tab(page, "Hujjatlar").click();
  await page
    .getByRole("button", { name: "Hujjat yuklash", exact: true })
    .click();
  await expect(dialog(page)).toContainText("Hujjat yuklash");
  await dismiss(page);
  await page.getByLabel("Hujjatni qidirish").fill("NO MATCH");
  await expect(page.locator("tbody")).toContainText("Hujjat topilmadi");
  await page.getByLabel("Hujjatni qidirish").fill("");
  await page.getByLabel("Tur filtri").selectOption("Tashqi hujjat");
  await expect(page.locator("tbody tr")).toHaveCount(1);
  const jpg = page.waitForEvent("download");
  await page
    .getByRole("button", {
      name: "Boshqa klinika xulosasi yuklab olish",
      exact: true,
    })
    .click();
  const jpgBytes = await fs.readFile((await (await jpg).path())!);
  expect(jpgBytes[0]).toBe(255);
  expect(jpgBytes[1]).toBe(216);
});
test("modal focus, escape/cancel and mobile horizontal scrolling", async ({
  page,
}) => {
  await setup(page);
  const trigger = page.getByRole("button", { name: "Yo‘llanma", exact: true });
  await trigger.click();
  let d = dialog(page);
  for (let i = 0; i < 12; i++) {
    await page.keyboard.press("Shift+Tab");
    expect(await d.evaluate((el) => el.contains(document.activeElement))).toBe(
      true,
    );
  }
  await dismiss(page);
  await expect(trigger).toBeFocused();
  await page.setViewportSize({ width: 390, height: 844 });
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  await trigger.click();
  d = dialog(page);
  expect(
    await d.evaluate(
      (el) =>
        el.getBoundingClientRect().top >= 0 &&
        el.getBoundingClientRect().bottom <= innerHeight,
    ),
  ).toBe(true);
  await dismiss(page);
  await tab(page, "Hujjatlar").click();
  expect(
    await page
      .locator(".dw-scroll")
      .evaluate((el) => el.scrollWidth > el.clientWidth),
  ).toBe(true);
});
test("referral sanitization and synthetic PDF xref are internally consistent", async () => {
  const { sanitizeReferral, referralErrors } = await import(
    "../../src/mocks/doctorWorkspace"
  );
  const raw = {
    id: "demo",
    encounterKey: "P1:Q1",
    location: "external" as const,
    type: "Diagnostika",
    service: "uzi",
    branch: "Asosiy filial",
    specialist: "Demo UZI mutaxassisi",
    clinic: "",
    note: "",
  };
  expect(referralErrors(raw)).toEqual({});
  const clean = sanitizeReferral(raw);
  expect(clean.branch).toBe("");
  expect(clean.specialist).toBe("");
  expect(
    sanitizeReferral({ ...raw, location: "internal", clinic: "external-name" })
      .clinic,
  ).toBe("");
  const { demoPdf } = await import("../../src/components/doctor/mockFiles");
  const text = await demoPdf("Synthetic").text();
  const start = Number(text.match(/startxref\n(\d+)/)![1]);
  expect(text.slice(start, start + 4)).toBe("xref");
});

test("history print creates a read-only synthetic print window and results paginate after a local referral", async ({
  page,
}) => {
  await page.context().addInitScript(() => {
    window.print = () => {
      document.documentElement.dataset.printInvoked = "true";
    };
  });
  await setup(page);
  await tab(page, "Tashriflar tarixi").click();
  await page
    .getByRole("button", { name: "05.10.2026 tashrifini ko‘rish" })
    .click();
  const popupEvent = page.waitForEvent("popup");
  await dialog(page)
    .locator("footer")
    .getByRole("button", { name: "Chop etish", exact: true })
    .click();
  const popup = await popupEvent;
  await expect(popup.locator("html")).toHaveAttribute(
    "data-print-invoked",
    "true",
  );
  await expect(popup.locator("body")).toContainText(
    "Haqiqiy tibbiy hujjat emas",
  );
  expect(await popup.evaluate(() => window.opener)).toBeNull();
  await popup.close();
  await dismiss(page);
  await tab(page, "Bugungi qabul").click();
  await page.getByRole("button", { name: "Yo‘llanma", exact: true }).click();
  await dialog(page)
    .getByLabel("Tekshiruv / xizmat", { exact: true })
    .selectOption("uzi");
  await dialog(page)
    .getByLabel("Filial", { exact: true })
    .selectOption("Asosiy filial");
  await dialog(page)
    .getByRole("button", { name: "Yo‘llanma yaratish", exact: true })
    .click();
  await tab(page, "Tekshiruv natijalari").click();
  await expect(page.locator(".dw-pages button")).toHaveCount(4);
  await page
    .getByRole("button", { name: "Keyingi sahifa", exact: true })
    .click();
  await expect(page.locator("tbody tr")).toHaveCount(1);
});
