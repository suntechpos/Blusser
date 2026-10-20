import { test, expect, type Page } from "@playwright/test";
import { mkdir } from "node:fs/promises";
import { receptionSession } from "./reception.fixture";
test.use({ viewport: { width: 1536, height: 1024 }, locale: "uz-UZ" });
async function open(page: Page) {
  await page.clock.setFixedTime(new Date("2026-10-20T08:00:00"));
  const requests = await receptionSession(page);
  await page.getByRole("button", { name: "Registratura", exact: true }).click();
  return requests;
}
const dialog = (p: Page) => p.getByRole("dialog");
async function book(page: Page) {
  await page
    .getByRole("button", { name: "Qabulga yozish", exact: true })
    .first()
    .click();
}
async function existing(page: Page, name = "Aliyeva") {
  await dialog(page)
    .getByRole("button", { name: "Oldin kelgan bemor", exact: true })
    .click();
  await dialog(page)
    .getByRole("textbox", { name: "Bemorni qidirish", exact: true })
    .fill(name);
  await dialog(page)
    .getByRole("button")
    .filter({ hasText: name })
    .first()
    .click();
}
async function future(page: Page, date = "2026-11-04") {
  await dialog(page).getByLabel("Qabul sanasi", { exact: true }).fill(date);
}
async function shot(page: Page, name: string) {
  await mkdir("/tmp/blusser-reception-screenshots", { recursive: true });
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({
    path: `/tmp/blusser-reception-screenshots/${name}.png`,
  });
}
test("reference desktop and modal screenshots, mobile bounds, no reception API", async ({
  page,
}) => {
  const requests = await open(page);
  await expect(page.locator(".rp-stats article")).toHaveCount(6);
  expect(
    await page
      .locator(".rp-stats article")
      .evaluateAll(
        (xs) =>
          new Set(xs.map((x) => Math.round(x.getBoundingClientRect().top)))
            .size,
      ),
  ).toBe(1);
  await expect(page.locator(".rp-heading-actions button")).toHaveCount(2);
  await expect(page.locator(".clinic-demo-note")).toHaveCount(0);
  await shot(page, "01-reception");
  await book(page);
  const close = dialog(page).getByRole("button", {
    name: "Yopish",
    exact: true,
  });
  await close.focus();
  await page.keyboard.press("Shift+Tab");
  await expect(
    dialog(page).getByRole("button", { name: "Qabulga yozish", exact: true }),
  ).toBeFocused();
  await page.keyboard.press("Tab");
  await expect(close).toBeFocused();
  await future(page);
  await dialog(page)
    .getByRole("button", { name: "10:00", exact: true })
    .click();
  await shot(page, "02-book-new");
  await existing(page);
  await shot(page, "03-book-existing");
  await page.keyboard.press("Escape");
  await page
    .getByRole("button", { name: "Bemorni ro‘yxatga olish", exact: true })
    .click();
  await shot(page, "04-arrival");
  await page.keyboard.press("Escape");
  await page.setViewportSize({ width: 390, height: 844 });
  await book(page);
  await shot(page, "05-mobile");
  const b = await dialog(page).boundingBox();
  expect(b!.x).toBeGreaterThanOrEqual(0);
  expect(b!.y + b!.height).toBeLessThanOrEqual(845);
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  await page.keyboard.press("Escape");
  expect(
    requests.filter((r) => r.path.includes("/patients") || r.method !== "GET"),
  ).toEqual([]);
});
test("new patient validation and explicit phone matches; future and today bookings, pagination, local reset", async ({
  page,
}) => {
  const requests = await open(page);
  await book(page);
  await dialog(page)
    .getByRole("button", { name: "Qabulga yozish", exact: true })
    .click();
  await expect(
    dialog(page).getByText("Familiyani kiriting.", { exact: true }),
  ).toBeVisible();
  await dialog(page).getByLabel("Familiya *", { exact: true }).fill("Testova");
  await dialog(page).getByLabel("Ism *", { exact: true }).fill("Namuna");
  await dialog(page)
    .getByLabel("Tug‘ilgan sana *", { exact: true })
    .fill("1998-01-12");
  await dialog(page)
    .getByLabel("Telefon raqami", { exact: true })
    .fill("+998 90 123 45 67");
  await expect(dialog(page).locator(".rp-match")).toContainText("Aliyeva");
  await future(page);
  await dialog(page)
    .getByRole("button", { name: "09:00", exact: true })
    .click();
  await dialog(page)
    .getByRole("button", { name: "Qabulga yozish", exact: true })
    .click();
  await expect(dialog(page)).toHaveCount(0);
  await expect(page.getByRole("status")).toContainText("lokal");
  await expect(page.locator(".rp-page tbody")).toContainText("Testova Namuna");
  await expect(page.locator(".rp-page tbody")).toContainText("Yozilgan");
  await expect(page.locator(".rp-date-context")).toContainText("4-noyabr");
  await expect(
    page
      .locator(".rp-page tbody")
      .getByRole("button", { name: "Keldi", exact: true }),
  ).toHaveCount(0);
  await book(page);
  await existing(page, "Testova");
  await future(page, "2026-10-20");
  await dialog(page)
    .getByRole("button", { name: "10:00", exact: true })
    .click();
  await dialog(page)
    .getByRole("button", { name: "Qabulga yozish", exact: true })
    .click();
  await expect(page.locator(".rp-tabs")).toContainText("9");
  await page
    .getByRole("button", { name: "Keyingi sahifa", exact: true })
    .click();
  await expect(page.locator(".rp-pagination")).toContainText("9–9 / 9");
  await page
    .getByRole("textbox", { name: "Ro‘yxatdan bemor qidirish" })
    .fill("Testova");
  await expect(page.locator(".rp-page tbody tr")).toHaveCount(1);
  await expect(page.locator(".rp-page tbody")).toContainText("Yozilgan");
  expect(requests.filter((r) => r.path.includes("/patients"))).toEqual([]);
  await page.reload();
  await page.getByRole("button", { name: "Registratura", exact: true }).click();
  await expect(page.locator(".rp-page tbody")).not.toContainText("Testova");
});
test("existing selection, mode persistence, dependent fields, unavailable schedule and duration conflicts", async ({
  page,
}) => {
  await open(page);
  await book(page);
  await existing(page);
  await expect(dialog(page).locator(".rp-selected-patient")).toContainText(
    "P-00124",
  );
  await expect(
    dialog(page).getByRole("button", { name: "09:00 — Band", exact: true }),
  ).toBeDisabled();
  await future(page);
  await dialog(page)
    .getByRole("button", { name: "10:00", exact: true })
    .click();
  await dialog(page)
    .getByRole("button", { name: "Yangi bemor", exact: true })
    .click();
  await dialog(page)
    .getByRole("button", { name: "Oldin kelgan bemor", exact: true })
    .click();
  await expect(dialog(page).locator(".rp-selected-patient")).toContainText(
    "Aliyeva",
  );
  await expect(
    dialog(page).getByRole("button", { name: "10:00", exact: true }),
  ).toHaveAttribute("aria-pressed", "true");
  await dialog(page)
    .getByRole("button", { name: "Boshqa bemorni tanlash", exact: true })
    .click();
  await dialog(page)
    .getByRole("textbox", { name: "Bemorni qidirish", exact: true })
    .fill("no-match");
  await expect(
    dialog(page).getByText("Bemor topilmadi", { exact: true }),
  ).toBeVisible();
  await future(page, "2026-11-08");
  await expect(dialog(page)).toContainText("jadvali belgilanmagan");
  await expect(dialog(page).locator(".rp-summary")).toHaveCount(0);
  await future(page);
  await dialog(page)
    .getByRole("button", { name: "11:30", exact: true })
    .click();
  await dialog(page)
    .getByLabel("Qabul turi *", { exact: true })
    .selectOption("extended");
  await expect(dialog(page).locator(".rp-summary")).toHaveCount(0);
  await dialog(page)
    .getByLabel("Filial *", { exact: true })
    .selectOption("yunus");
  await dialog(page)
    .getByLabel("Bo‘lim *", { exact: true })
    .selectOption("therapy");
  await expect(
    dialog(page).getByLabel("Shifokor *", { exact: true }),
  ).toHaveValue("");
});
test("Keldi preselects encounter, duplicate prevention and new local patient walk-in", async ({
  page,
}) => {
  await open(page);
  const row = page
    .locator(".rp-page tbody tr")
    .filter({ hasText: "Karimova Nigora" });
  await row.getByRole("button", { name: "Keldi", exact: true }).click();
  await expect(dialog(page).locator(".rp-selected-patient")).toContainText(
    "Karimova Nigora",
  );
  await expect(dialog(page).getByRole("radio")).toBeChecked();
  await dialog(page)
    .getByRole("button", { name: "Keldi — navbatga qo‘shish", exact: true })
    .click();
  await expect(row).toContainText("Navbatda");
  await expect(
    row.getByRole("button", { name: "Keldi", exact: true }),
  ).toHaveCount(0);
  await page
    .getByRole("button", { name: "Bemorni ro‘yxatga olish", exact: true })
    .click();
  await dialog(page)
    .getByRole("textbox", { name: "Bemorni qidirish", exact: true })
    .fill("Karimova");
  await dialog(page)
    .getByRole("button")
    .filter({ hasText: "Karimova Nigora" })
    .click();
  await expect(dialog(page).getByRole("radio")).toBeDisabled();
  await dialog(page)
    .getByRole("button", { name: "Keldi — navbatga qo‘shish", exact: true })
    .click();
  await expect(dialog(page).getByRole("alert")).toContainText(
    "qayta qo‘shilmaydi",
  );
  await page.keyboard.press("Escape");
  await page
    .getByRole("button", { name: "Bemorni ro‘yxatga olish", exact: true })
    .click();
  await dialog(page)
    .getByRole("textbox", { name: "Bemorni qidirish", exact: true })
    .fill("Unknown");
  await expect(
    dialog(page).getByText("Bemor topilmadi", { exact: true }),
  ).toBeVisible();
  await dialog(page)
    .getByRole("button", { name: "+ Yangi bemor qo‘shish", exact: true })
    .click();
  await dialog(page).getByLabel("Familiya *", { exact: true }).fill("Walkin");
  await dialog(page).getByLabel("Ism *", { exact: true }).fill("Example");
  await dialog(page)
    .getByLabel("Tug‘ilgan sana *", { exact: true })
    .fill("2000-01-01");
  await dialog(page)
    .getByRole("button", { name: "Bemorni qo‘shish (lokal)", exact: true })
    .click();
  await expect(dialog(page)).toContainText("Bugungi qabul yozuvi yo‘q");
  await dialog(page)
    .getByRole("button", { name: "Keldi — navbatga qo‘shish", exact: true })
    .click();
  await page
    .getByRole("textbox", { name: "Ro‘yxatdan bemor qidirish" })
    .fill("Walkin");
  await expect(page.locator(".rp-page tbody")).toContainText("Navbatda");
});
test("shared sidebar, modal cancel and login/logout regressions", async ({
  page,
}) => {
  const requests = await open(page);
  for (const label of [
    "Bemorlar",
    "Shifokor kabineti",
    "Qabullar",
    "Navbatlar",
    "Registratura",
  ]) {
    await page.getByRole("button", { name: label, exact: true }).click();
    expect(
      await page
        .locator(".clinic-nav-item")
        .first()
        .evaluate((el) => parseFloat(getComputedStyle(el).fontSize)),
    ).toBeGreaterThanOrEqual(14);
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    ).toBe(true);
  }
  await book(page);
  await page.keyboard.press("Escape");
  await expect(
    page.getByRole("button", { name: "Qabulga yozish", exact: true }).first(),
  ).toBeFocused();
  await page.getByRole("button", { name: "Profil menyusi" }).click();
  await page
    .getByRole("button", { name: "Tizimdan chiqish", exact: true })
    .click();
  await page.getByLabel("Login", { exact: true }).fill("synthetic");
  await page
    .getByLabel("Parol", { exact: true })
    .fill("Synthetic-password-42!");
  await page.getByRole("button", { name: "Kirish", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Bosh sahifa", exact: true }),
  ).toBeVisible();
  expect(requests.some((r) => r.path === "/api/v1/auth/logout")).toBe(true);
  expect(requests.some((r) => r.path === "/api/v1/auth/login")).toBe(true);
});

test("multiple same-day appointments require explicit encounter selection; counts follow mutations", async ({
  page,
}) => {
  await open(page);
  await book(page);
  await existing(page, "Karimova");
  await dialog(page)
    .getByRole("button", { name: "10:00", exact: true })
    .click();
  await dialog(page)
    .getByRole("button", { name: "Qabulga yozish", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Bemorni ro‘yxatga olish", exact: true })
    .click();
  await dialog(page)
    .getByRole("textbox", { name: "Bemorni qidirish", exact: true })
    .fill("Karimova");
  await dialog(page)
    .getByRole("button")
    .filter({ hasText: "Karimova Nigora" })
    .click();
  await expect(dialog(page).getByRole("radio")).toHaveCount(2);
  await dialog(page)
    .getByRole("button", { name: "Keldi — navbatga qo‘shish", exact: true })
    .click();
  await expect(dialog(page).getByRole("alert")).toContainText(
    "Bugungi yozilgan qabulni tanlang",
  );
  await dialog(page).getByRole("radio").last().check();
  await dialog(page)
    .getByRole("button", { name: "Keldi — navbatga qo‘shish", exact: true })
    .click();
  const rows = page
    .locator(".rp-page tbody tr")
    .filter({ hasText: "Karimova Nigora" });
  await expect(rows).toHaveCount(2);
  await expect(rows.filter({ hasText: "Navbatda" })).toHaveCount(1);
  await expect(rows.filter({ hasText: "Yozilgan" })).toHaveCount(1);
});

test("local search shows loading/error/retry and ignores obsolete responses", async ({
  page,
}) => {
  await open(page);
  await page.evaluate(async () => {
    const { createApp, ref, h } = await import(
      "/node_modules/.vite/deps/vue.js"
    );
    const Search = (
      await import("/src/components/reception/ReceptionPatientSearch.vue")
    ).default;
    const { createReceptionState, searchPatients } = await import(
      "/src/mocks/reception.ts"
    );
    const patients = createReceptionState().patients;
    let attempts = 0;
    const host = document.createElement("div");
    host.id = "search-harness";
    document.body.append(host);
    createApp({
      setup() {
        const selected = ref(null);
        const lookup = async (q: string) => {
          if (attempts++ === 0)
            return new Promise((_, reject) =>
              Object.assign(window, {
                failSearch: () => reject(Error("test lookup failure")),
              }),
            );
          await new Promise((r) => setTimeout(r, q === "slow" ? 350 : 50));
          if (q === "slow") {
            Object.assign(window, { oldSearchSettled: true });
            return [];
          }
          return searchPatients(patients, q);
        };
        return () =>
          h(Search, {
            modelValue: selected.value,
            "onUpdate:modelValue": (p: never) => (selected.value = p),
            lookup,
          });
      },
    }).mount(host);
  });
  const host = page.locator("#search-harness");
  await expect(host.getByRole("status")).toContainText("Qidirilmoqda");
  await page.evaluate(() =>
    (window as unknown as { failSearch: () => void }).failSearch(),
  );
  await expect(host.getByRole("alert")).toContainText("Qidiruv amalga oshmadi");
  await host.getByRole("button", { name: "Qayta urinish" }).click();
  await expect(
    host.getByRole("button").filter({ hasText: "Aliyeva" }),
  ).toBeVisible();
  await host.getByRole("textbox").fill("slow");
  await host.getByRole("textbox").fill("Usmonov");
  await expect(
    host.getByRole("button").filter({ hasText: "Usmonov" }),
  ).toBeVisible();
  await expect
    .poll(() =>
      page.evaluate(
        () =>
          (window as unknown as { oldSearchSettled: boolean }).oldSearchSettled,
      ),
    )
    .toBe(true);
  await expect(
    host.getByRole("button").filter({ hasText: "Usmonov" }),
  ).toBeVisible();
  await expect(
    host.getByRole("button").filter({ hasText: "Aliyeva" }),
  ).toHaveCount(0);
});

test("afternoon booking appears in the same day timeline, beyond the initial visible slots", async ({
  page,
}) => {
  await open(page);
  await book(page);
  await existing(page);
  await dialog(page)
    .getByRole("button", { name: "14:00", exact: true })
    .click();
  await dialog(page)
    .getByRole("button", { name: "Qabulga yozish", exact: true })
    .click();
  const afternoon = page
    .locator(".rp-timeline-row")
    .filter({ hasText: "14:00" });
  await expect(afternoon).toContainText("Aliyeva");
  await afternoon.scrollIntoViewIfNeeded();
  await expect(afternoon).toBeVisible();
});

test("fully occupied capacity and missing doctor schedule are distinct UI states", async ({
  page,
}) => {
  await open(page);
  await page.evaluate(async () => {
    const { createApp, h } = await import("/node_modules/.vite/deps/vue.js");
    const Booking = (
      await import("/src/components/reception/BookingDialog.vue")
    ).default;
    const { createReceptionState, blankBooking, availability } = await import(
      "/src/mocks/reception.ts"
    );
    const state = createReceptionState(),
      draft = blankBooking();
    const full = availability(draft, []).slots.map(
      (s: { time: string }, i: number) => ({
        ...draft,
        id: `test-${i}`,
        patientId: state.patients[0].id,
        time: s.time,
        status: "booked",
        kind: "scheduled",
      }),
    );
    const host = document.createElement("div");
    document.body.append(host);
    createApp({
      render: () =>
        h(Booking, { patients: state.patients, appointments: full }),
    }).mount(host);
  });
  await expect(dialog(page)).toContainText("Bo‘sh joy qolmagan");
  expect(
    await dialog(page).locator(".rp-slots button:not(:disabled)").count(),
  ).toBe(0);
  await future(page, "2026-11-08");
  await expect(dialog(page)).toContainText("jadvali belgilanmagan");
  await expect(dialog(page)).not.toContainText("Bo‘sh joy qolmagan");
});
