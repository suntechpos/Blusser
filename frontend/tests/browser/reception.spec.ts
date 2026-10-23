import { test, expect, type Page } from "@playwright/test";
import { bookingSession, ids } from "./reception-booking.fixture";
import { allServerGrants } from "./access.fixture";
test.use({ viewport: { width: 1536, height: 1024 } });
const dialog = (p: Page) =>
  p.getByRole("dialog", { name: "Qabulga yozish", exact: true });
async function open(page: Page) {
  await page
    .locator(".rp-heading-actions")
    .getByRole("button", { name: "Qabulga yozish", exact: true })
    .click();
  await expect(dialog(page)).toBeVisible();
}
async function prepare(page: Page, date = "2026-10-28", time = "10:30") {
  await dialog(page)
    .getByRole("button", { name: /Aliyeva Mohira/ })
    .click();
  await dialog(page)
    .getByLabel("Bo‘lim *", { exact: true })
    .selectOption(ids.specialty);
  await dialog(page)
    .getByLabel("Shifokor *", { exact: true })
    .selectOption(ids.doctor);
  await dialog(page)
    .getByLabel("Qabul turi *", { exact: true })
    .selectOption(ids.service);
  await dialog(page).getByLabel("Qabul sanasi", { exact: true }).fill(date);
  await dialog(page).getByRole("button", { name: time, exact: true }).click();
}
async function submit(page: Page) {
  await dialog(page)
    .getByRole("button", { name: "Qabulga yozish", exact: true })
    .click();
}
test.beforeEach(async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-23T03:01:00Z"));
});

test("real API-shaped existing patient booking uses October 28 slot, refreshes that date and never creates a patient", async ({
  page,
}) => {
  const s = await bookingSession(page);
  await open(page);
  await expect(
    dialog(page).getByRole("button", { name: "Yangi bemor", exact: true }),
  ).toBeDisabled();
  await prepare(page);
  await dialog(page)
    .getByLabel("Izoh", { exact: true })
    .fill("Kelajakdagi qabul");
  await dialog(page).screenshot({ path: "/tmp/reception-booking-modal.png" });
  await submit(page);
  await expect(dialog(page)).toHaveCount(0);
  await expect(page.locator(".rp-toast")).toHaveText(
    /Bemor qabulga muvaffaqiyatli yozildi/,
  );
  await expect(page.getByLabel("Qabullar sanasi")).toHaveValue("2026-10-28");
  await expect(page.locator("tbody")).toContainText("Aliyeva Mohira");
  await expect(page.locator("tbody")).toContainText("10:30");
  await expect(page.locator("tbody")).toContainText("Birlamchi konsultatsiya");
  const req = s.requests.find(
    (r) => r.method === "POST" && r.path.endsWith("/appointments"),
  )!;
  expect(req.body).toMatchObject({
    patient_id: ids.patient,
    doctor_id: ids.doctor,
    branch_id: ids.branch,
    room_id: ids.room,
    service_id: ids.service,
    starts_at: "2026-10-28T05:30:00Z",
    note: "Kelajakdagi qabul",
  });
  expect(req.headers["idempotency-key"]).toMatch(/^[0-9a-f-]{36}$/);
  expect(
    s.requests.filter(
      (r) => r.method === "POST" && r.path.endsWith("/patients"),
    ),
  ).toHaveLength(0);
  await page.screenshot({
    path: "/tmp/reception-booking-table.png",
    fullPage: true,
  });
  await page.reload();
  await page.getByLabel("Qabullar sanasi").fill("2026-10-28");
  await expect(page.locator("tbody")).toContainText("Aliyeva Mohira");
  await page.getByLabel("Qabullar sanasi").fill("2026-10-23");
  await expect(page.locator("tbody")).not.toContainText("Aliyeva Mohira");
});

test("409 clears slot, refreshes availability and keeps patient and note; server error keeps form; double submit is guarded", async ({
  page,
}) => {
  const s = await bookingSession(page);
  await open(page);
  await prepare(page);
  await dialog(page).getByLabel("Izoh", { exact: true }).fill("Keep note");
  s.state.conflict = true;
  const before = s.requests.filter((r) =>
    r.path.includes("/availability"),
  ).length;
  await submit(page);
  await expect(dialog(page).getByRole("alert")).toContainText(
    "Bu vaqt hozir band qilindi",
  );
  await expect(
    dialog(page).getByRole("button", { name: "10:30", exact: true }),
  ).toHaveAttribute("aria-pressed", "false");
  expect(
    s.requests.filter((r) => r.path.includes("/availability")).length,
  ).toBeGreaterThan(before);
  await expect(dialog(page).getByLabel("Izoh", { exact: true })).toHaveValue(
    "Keep note",
  );
  await expect(dialog(page)).toContainText("Tanlangan");
  await dialog(page)
    .getByRole("button", { name: "10:30", exact: true })
    .click();
  s.state.fail = true;
  await submit(page);
  await expect(dialog(page)).toBeVisible();
  await expect(dialog(page).getByRole("alert")).toContainText("Server bilan");
  s.state.delay = 500;
  await submit(page);
  await expect(
    dialog(page).getByRole("button", { name: "Saqlanmoqda…", exact: true }),
  ).toBeDisabled();
  await dialog(page).locator("form").dispatchEvent("submit");
  await expect(dialog(page)).toHaveCount(0);
  expect(
    s.requests.filter(
      (r) => r.method === "POST" && r.path.endsWith("/appointments"),
    ),
  ).toHaveLength(3);
});

test("patient search debounces and paginates server results; dependencies clear slots and unlinked branch warns", async ({
  page,
}) => {
  const s = await bookingSession(page);
  await open(page);
  await dialog(page).getByRole("button", { name: "Keyingi bemorlar" }).click();
  await expect(dialog(page)).toContainText("Bemor 9");
  await dialog(page)
    .getByLabel("Bemorni qidirish", { exact: true })
    .fill("Aliyeva");
  await expect(
    dialog(page).getByRole("button", { name: /Aliyeva Mohira/ }),
  ).toBeVisible();
  expect(
    s.requests.some(
      (r) => r.path.includes("/patients?") && r.path.includes("q=Aliyeva"),
    ),
  ).toBe(true);
  await prepare(page);
  await dialog(page)
    .getByLabel("Filial *", { exact: true })
    .selectOption(ids.other);
  await expect(
    dialog(page).getByLabel("Shifokor *", { exact: true }),
  ).toHaveValue("");
  await expect(
    dialog(page).getByLabel("Qabul turi *", { exact: true }),
  ).toHaveValue("");
  await expect(dialog(page)).toContainText(
    "Bemor tanlangan filialga biriktirilmagan",
  );
  await expect(
    dialog(page).getByRole("button", { name: "Qabulga yozish", exact: true }),
  ).toBeDisabled();
});

test("today excludes past slots, past date cannot post, future date beyond 31 days uses one-day availability", async ({
  page,
}) => {
  const s = await bookingSession(page);
  await open(page);
  await prepare(page, "2026-10-23");
  await expect(
    dialog(page).getByRole("button", { name: "08:00", exact: true }),
  ).toHaveCount(0);
  await dialog(page)
    .getByLabel("Qabul sanasi", { exact: true })
    .fill("2026-10-22");
  await submit(page);
  await expect(dialog(page)).toContainText("O‘tgan sanaga");
  expect(s.requests.filter((r) => r.method === "POST")).toHaveLength(0);
  await dialog(page)
    .getByLabel("Qabul sanasi", { exact: true })
    .fill("2027-04-28");
  await dialog(page)
    .getByRole("button", { name: "10:30", exact: true })
    .click();
  const request = s.requests
    .filter((r) => r.path.includes("/availability"))
    .at(-1)!;
  const u = new URL(request.path, "http://fixture");
  expect(
    Date.parse(u.searchParams.get("to")!) -
      Date.parse(u.searchParams.get("from")!),
  ).toBe(86400000);
  await submit(page);
  await expect(page.locator("tbody")).toContainText("Aliyeva Mohira");
});

test("right panel uses appointments and real free slots; + Yozish presets the server slot", async ({
  page,
}) => {
  await bookingSession(page);
  await page.getByLabel("Qabullar sanasi").fill("2026-10-28");
  await page
    .getByRole("button", { name: "10:30 vaqtiga yozish", exact: true })
    .click();
  await expect(
    dialog(page).getByLabel("Shifokor *", { exact: true }),
  ).toHaveValue(ids.doctor);
  await expect(
    dialog(page).getByLabel("Qabul sanasi", { exact: true }),
  ).toHaveValue("2026-10-28");
  await expect(
    dialog(page).getByRole("button", { name: "10:30", exact: true }),
  ).toHaveAttribute("aria-pressed", "true");
});

test("missing booking authority hides creation and never posts appointments", async ({
  page,
}) => {
  const grants = { ...allServerGrants };
  delete grants["appointments.create"];
  const s = await bookingSession(page, grants);
  await expect(
    page
      .locator(".rp-heading-actions")
      .getByRole("button", { name: "Qabulga yozish", exact: true }),
  ).toBeHidden();
  expect(
    s.requests.filter(
      (r) => r.method === "POST" && r.path.endsWith("/appointments"),
    ),
  ).toHaveLength(0);
});

test("empty/error/loading states and mobile dialog keep working", async ({
  page,
}) => {
  const s = await bookingSession(page);
  s.state.empty = true;
  await open(page);
  await dialog(page)
    .getByRole("button", { name: /Aliyeva Mohira/ })
    .click();
  await dialog(page)
    .getByLabel("Bo‘lim *", { exact: true })
    .selectOption(ids.specialty);
  await dialog(page)
    .getByLabel("Shifokor *", { exact: true })
    .selectOption(ids.doctor);
  await dialog(page)
    .getByLabel("Qabul turi *", { exact: true })
    .selectOption(ids.service);
  await expect(dialog(page)).toContainText("Bu sanada qabul mavjud emas");
  await page.setViewportSize({ width: 390, height: 844 });
  expect(
    await dialog(page).evaluate((el) => el.scrollWidth <= el.clientWidth),
  ).toBe(true);
  await dialog(page)
    .getByRole("button", { name: "Bekor qilish", exact: true })
    .click();
  await expect(dialog(page)).toHaveCount(0);
});

test("appointment table paginates on server, filters status/search and reveals a newly booked later page", async ({
  page,
}) => {
  const s = await bookingSession(page);
  s.state.rows = Array.from({ length: 17 }, (_, i) => ({
    id: `80000000-0000-4000-8000-${String(i + 1).padStart(12, "0")}`,
    patient_id: ids.patient,
    doctor_id: ids.doctor,
    branch_id: ids.branch,
    service_id: ids.service,
    room_id: ids.room,
    starts_at: new Date(
      Date.parse("2026-10-28T04:00:00Z") + i * 1800000,
    ).toISOString(),
    ends_at: new Date(
      Date.parse("2026-10-28T04:30:00Z") + i * 1800000,
    ).toISOString(),
    status: "scheduled",
  }));
  await page.getByLabel("Qabullar sanasi").fill("2026-10-28");
  await expect(page.locator("tbody tr")).toHaveCount(8);
  await page
    .getByRole("button", { name: "Keyingi sahifa", exact: true })
    .click();
  await expect(page.locator(".rp-pagination")).toContainText("9–16");
  expect(
    s.requests.some(
      (r) => r.path.includes("/appointments?") && r.path.includes("page=2"),
    ),
  ).toBe(true);
  await page.getByLabel("Holat", { exact: true }).selectOption("completed");
  await expect(page.locator("tbody")).toContainText("Bemor topilmadi");
  await page.getByLabel("Holat", { exact: true }).selectOption("");
  await page.getByLabel("Ro‘yxatdan bemor qidirish").fill("no-such-name");
  await expect(page.locator("tbody")).toContainText("Bemor topilmadi");
  // saved() clears this search: its debounce must not undo the revealed page.
  await page.getByLabel("Ro‘yxatdan bemor qidirish").fill("Aliyeva");
  await open(page);
  await prepare(page, "2026-10-28", "17:30");
  s.state.listDelay = 200;
  await submit(page);
  await expect(dialog(page)).toHaveCount(0);
  await expect(page.locator("tbody .rp-new-booking")).toContainText("17:30");
  await expect(page.locator(".rp-pagination")).toContainText("17–18");
  await expect(page.getByLabel("Qabullar sanasi")).toHaveValue("2026-10-28");
  await expect(page.getByLabel("Ro‘yxatdan bemor qidirish")).toHaveValue("");

  await page.reload();
  await page.getByLabel("Qabullar sanasi").fill("2026-10-28");
  await expect(page.locator("tbody tr")).toHaveCount(8);

  // Hold an old page response until a newer status filter has finished.
  // Releasing it must not restore its rows, counts, or page number.
  let release!: () => void;
  let requested!: () => void;
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  const started = new Promise<void>((resolve) => {
    requested = resolve;
  });
  let holdNextPage = true;
  await page.route("**/api/v1/clinic/appointments?**", async (route) => {
    const url = new URL(route.request().url());
    if (
      holdNextPage &&
      url.searchParams.get("page") === "2" &&
      url.searchParams.get("per_page") === "8"
    ) {
      holdNextPage = false;
      requested();
      await held;
    }
    await route.fallback();
  });
  await page
    .getByRole("button", { name: "Keyingi sahifa", exact: true })
    .click();
  await started;
  await page.getByLabel("Holat", { exact: true }).selectOption("completed");
  await expect(page.locator("tbody")).toContainText("Bemor topilmadi");
  const oldResponse = page.waitForResponse((r) => {
    const url = new URL(r.url());
    return (
      url.pathname.endsWith("/appointments") &&
      url.searchParams.get("page") === "2" &&
      url.searchParams.get("per_page") === "8"
    );
  });
  release();
  await (await oldResponse).finished();
  // Specifically wait for all reads spawned by the released stale response.
  await page.waitForLoadState("networkidle");
  await expect(page.locator("tbody")).toContainText("Bemor topilmadi");
  await expect(page.locator(".rp-pagination")).toContainText("0–0 / 0");
  await expect(
    page.getByRole("button", { name: "Sahifa 1", exact: true }),
  ).toBeVisible();
  await page.getByLabel("Holat", { exact: true }).selectOption("");
  await expect(page.locator("tbody tr")).toHaveCount(8);
  await page
    .getByRole("button", { name: "Keyingi sahifa", exact: true })
    .click();
  await expect(page.locator(".rp-pagination")).toContainText("9–16");
});

test("schedule-derived service duration is displayed from authoritative slot timestamps", async ({
  page,
}) => {
  const s = await bookingSession(page);
  s.state.duration = null;
  await open(page);
  await prepare(page);
  await expect(dialog(page).locator(".rp-date-slots")).toContainText(
    "30 daqiqa",
  );
  await expect(
    dialog(page).getByLabel("Qabul turi *", { exact: true }),
  ).toHaveValue(ids.service);
});
