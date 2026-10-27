import { test, expect, type Page, type Route } from "@playwright/test";

const ticketId = "4ad6f64a-c34e-4f19-9943-62c9c02f9f17";
const slot = {
  room_id: "8db163ae-2861-4025-87d5-76b68948a11a",
  starts_at: "2030-01-07T04:00:00Z",
  ends_at: "2030-01-07T04:30:00Z",
};
const nextDaySlot = {
  ...slot,
  starts_at: "2030-01-08T05:00:00Z",
  ends_at: "2030-01-08T05:30:00Z",
};
type Action = "skip" | "cancel";
type Command = { action: Action; body: Record<string, unknown>; key: string };
type Fixture = {
  commands: Command[];
  availability: Array<{ date: string | null; action: string | null }>;
  getStatus(): string;
  requests: string[];
};

async function setup(
  page: Page,
  options: {
    failFirst?: boolean;
    delayedMutation?: boolean;
    availability?: (route: Route, date: string | null) => Promise<void>;
  } = {},
): Promise<Fixture> {
  const commands: Command[] = [];
  const availability: Fixture["availability"] = [];
  const requests: string[] = [];
  let status = "waiting";
  let version = 3;
  const grants = {
    "workspace.view": "own",
    "queues.view": "own",
    "visits.view": "own",
    "queues.skip": "own",
    "queues.cancel": "own",
    "appointments.view": "own",
    "appointments.create": "own",
    "appointments.cancel": "own",
    "patients.view": "own",
  };
  await page.clock.setFixedTime(new Date("2026-10-27T07:00:00Z"));
  await page.route("**/api/v1/**", async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname;
    if (path === "/api/v1/auth/me")
      return route.fulfill({
        json: {
          id: "doctor-user",
          login: "doctor",
          is_platform_admin: false,
          must_change_password: false,
          active_tenant_id: "clinic-a",
        },
      });
    if (path === "/api/v1/auth/clinics")
      return route.fulfill({
        json: {
          data: [
            { id: "clinic-a", name: "Shifo Med Klinikasi", role: "Shifokor" },
          ],
        },
      });
    if (path === "/api/v1/clinic/context")
      return route.fulfill({
        json: {
          grants,
          branches: [],
          timezone: "Asia/Tashkent",
        },
      });
    if (path === "/api/v1/clinic/branches")
      return route.fulfill({ json: { data: [] } });
    if (path === "/api/v1/clinic/doctor-workspace") {
      requests.push(url.search);
      return route.fulfill({
        json: {
          manager: false,
          doctors: [],
          doctor: {
            id: "doctor-a",
            name: "Test Shifokor",
            specialty: "Stomatolog",
            room: "23",
            branches: [
              {
                id: "branch-a",
                name: "Asosiy filial",
                timezone: "Asia/Tashkent",
              },
            ],
          },
          branch: {
            id: "branch-a",
            name: "Asosiy filial",
            timezone: "Asia/Tashkent",
          },
          date: url.searchParams.get("date") || "2026-10-27",
          scheduled: [],
          overdue: [],
          has_active_patient: false,
          has_called_today: false,
          tickets: [
            {
              id: ticketId,
              visit_id: "visit-a",
              patient_id: "patient-a",
              patient_number: "P-000001",
              name: "Aliyev Alijon",
              dob: "1990-01-01",
              gender: "male",
              phone: "+998901234567",
              service: "Ko‘rik",
              service_id: "service-a",
              status,
              version,
              appointment_id: null,
              kind: "walk_in",
              number: 1,
              created_at: "2026-10-27T06:00:00Z",
            },
          ],
        },
      });
    }
    if (path === `/api/v1/clinic/queue-tickets/${ticketId}/rebooking-options`) {
      const date = url.searchParams.get("local_date");
      availability.push({ date, action: url.searchParams.get("action") });
      if (options.availability) return options.availability(route, date);
      return route.fulfill({
        json: { local_date: date, timezone: "Asia/Tashkent", slots: [slot] },
      });
    }
    const mutation = path.match(/\/queue-tickets\/[^/]+\/(skip|cancel)$/);
    if (mutation && request.method() === "POST") {
      const action = mutation[1] as Action;
      commands.push({
        action,
        body: request.postDataJSON(),
        key: request.headers()["idempotency-key"] || "",
      });
      if (options.delayedMutation)
        await new Promise((resolve) => setTimeout(resolve, 150));
      if (options.failFirst && commands.length === 1) {
        return route.fulfill({ status: 409, json: { message: "Conflict" } });
      }
      status = action === "skip" ? "skipped" : "cancelled";
      version++;
      const rebook = commands.at(-1)?.body.rebook;
      return route.fulfill({
        json: {
          ticket: { id: ticketId, status, version },
          visit: { id: "visit-a", status },
          rebooked_appointment: rebook
            ? { id: "appointment-new", ...slot, status: "scheduled" }
            : null,
        },
      });
    }
    return route.fulfill({
      status: 500,
      json: { message: `Unexpected request ${path}` },
    });
  });
  await page.goto("/#shifokor-kabineti");
  await expect(page.locator(".dw-queue-person")).toContainText("Aliyev Alijon");
  return { commands, availability, getStatus: () => status, requests };
}

async function openAction(page: Page, action: Action) {
  const title =
    action === "skip" ? "O‘tkazib yuborish" : "Navbatni bekor qilish";
  await page
    .getByRole("button", { name: "Bemor amallari: Aliyev Alijon" })
    .click();
  await page.getByRole("menuitem", { name: title, exact: true }).click();
  const dialog = page.getByRole("dialog", { name: title, exact: true });
  await expect(dialog).toBeVisible();
  return dialog;
}

for (const action of ["skip", "cancel"] as const) {
  test(`${action}: requires a reason and saves optional comment without making a new booking`, async ({
    page,
  }) => {
    const fixture = await setup(page);
    const dialog = await openAction(page, action);
    const confirm = dialog.getByRole("button", { name: /^Tasdiqlash/ });
    await expect(confirm).toBeDisabled();
    await dialog
      .getByLabel("Sabab", { exact: true })
      .selectOption("patient_requested");
    await dialog
      .getByLabel("Izoh (ixtiyoriy)")
      .fill("Bemor telefon orqali xabar berdi.");
    await confirm.click();
    await expect(dialog).toHaveCount(0);
    expect(fixture.commands).toHaveLength(1);
    expect(fixture.commands[0]?.body).toEqual({
      expected_version: 3,
      reason_code: "patient_requested",
      note: "Bemor telefon orqali xabar berdi.",
    });
    expect(fixture.commands[0]?.key).toMatch(/^[0-9a-f-]{36}$/);
    expect(fixture.availability).toEqual([]);
    expect(fixture.getStatus()).toBe(
      action === "skip" ? "skipped" : "cancelled",
    );
    expect(fixture.requests.length).toBeGreaterThan(1);
    await expect(page.locator(".dw-live-ui")).not.toContainText(
      "API keyin ulanadi",
    );
    await page.reload();
    await expect(page.locator(".dw-live-ui")).not.toContainText("Kutmoqda");
  });

  test(`${action}: optional rebooking uses only an available server slot`, async ({
    page,
  }) => {
    const fixture = await setup(page);
    const dialog = await openAction(page, action);
    await dialog
      .getByLabel("Sabab", { exact: true })
      .selectOption("patient_late");
    await dialog
      .getByLabel("Bemorni boshqa kunga qayta yozish (ixtiyoriy)")
      .check();
    await expect(
      dialog.getByRole("button", { name: /^Tasdiqlash/ }),
    ).toBeDisabled();
    await dialog.getByLabel("Yangi sana").fill("2030-01-07");
    const times = dialog.getByLabel("Bo‘sh vaqt", { exact: true });
    await expect(times.locator("option")).toHaveCount(2);
    await expect(
      times.getByRole("option", { name: /^09:30\s*[–—-]\s*10:00$/ }),
    ).toHaveCount(0);
    await times.selectOption({ index: 1 });
    await dialog.getByRole("button", { name: /^Tasdiqlash/ }).click();
    await expect(dialog).toHaveCount(0);
    expect(fixture.availability.at(-1)).toEqual({ date: "2030-01-07", action });
    expect(fixture.commands[0]?.body).toEqual({
      expected_version: 3,
      reason_code: "patient_late",
      rebook: slot,
    });
    expect(fixture.commands[0]?.body).not.toHaveProperty("doctor_id");
    expect(fixture.commands[0]?.body).not.toHaveProperty("patient_id");
    await expect(page.locator(".dw-live-ui")).toContainText(/qayta yoz/i);
  });
}

test("failed booking leaves the modal open; retry is busy guarded and uses the same intent key", async ({
  page,
}) => {
  const fixture = await setup(page, { failFirst: true, delayedMutation: true });
  const dialog = await openAction(page, "cancel");
  await dialog.getByLabel("Sabab", { exact: true }).selectOption("other");
  await dialog
    .getByLabel("Bemorni boshqa kunga qayta yozish (ixtiyoriy)")
    .check();
  await dialog.getByLabel("Yangi sana").fill("2030-01-07");
  await dialog
    .getByLabel("Bo‘sh vaqt", { exact: true })
    .selectOption({ index: 1 });
  const confirm = dialog.getByRole("button", { name: /^Tasdiqlash/ });
  await confirm.click();
  await expect(dialog.getByRole("alert")).toContainText(
    /band|o‘zgargan|qayta/i,
  );
  await expect(dialog).toBeVisible();
  expect(fixture.getStatus()).toBe("waiting");
  expect(fixture.commands).toHaveLength(1);
  await confirm.evaluate((button: HTMLButtonElement) => {
    button.click();
    button.click();
  });
  await expect(dialog).toHaveCount(0);
  expect(fixture.commands).toHaveLength(2);
  expect(fixture.commands[1]?.key).toBe(fixture.commands[0]?.key);
});

test("a late availability response cannot replace slots for the newly selected date", async ({
  page,
}) => {
  let releaseOld!: () => void;
  const oldWaiting = new Promise<void>((resolve) => {
    releaseOld = resolve;
  });
  const fixture = await setup(page, {
    availability: async (route, date) => {
      if (date === "2030-01-07") await oldWaiting;
      await route.fulfill({
        json: {
          local_date: date,
          timezone: "Asia/Tashkent",
          slots: date === "2030-01-08" ? [nextDaySlot] : [slot],
        },
      });
    },
  });
  const dialog = await openAction(page, "skip");
  await dialog.getByLabel("Sabab", { exact: true }).selectOption("other");
  await dialog
    .getByLabel("Bemorni boshqa kunga qayta yozish (ixtiyoriy)")
    .check();
  await dialog.getByLabel("Yangi sana").fill("2030-01-07");
  await expect
    .poll(() =>
      fixture.availability.some((request) => request.date === "2030-01-07"),
    )
    .toBe(true);
  await dialog.getByLabel("Yangi sana").fill("2030-01-08");
  const times = dialog.getByLabel("Bo‘sh vaqt", { exact: true });
  await expect(times.locator("option")).toHaveCount(2);
  releaseOld();
  await times.selectOption({ index: 1 });
  await dialog.getByRole("button", { name: /^Tasdiqlash/ }).click();
  await expect(dialog).toHaveCount(0);
  expect(fixture.commands[0]?.body.rebook).toEqual(nextDaySlot);
});


test("occupied time is disabled and the doctor is offered a free alternative", async ({ page }) => {
  const busySlot = {
    ...slot,
    starts_at: "2030-01-07T04:00:00Z",
    ends_at: "2030-01-07T04:30:00Z",
  };
  const freeSlot = {
    ...slot,
    starts_at: "2030-01-07T04:30:00Z",
    ends_at: "2030-01-07T05:00:00Z",
  };
  const fixture = await setup(page, {
    availability: async (route, date) => {
      await route.fulfill({
        json: { local_date: date, timezone: "Asia/Tashkent",
          slots: [freeSlot], busy_slots: [busySlot] },
      });
    },
  });
  const dialog = await openAction(page, "skip");
  await dialog.getByLabel("Sabab", { exact: true }).selectOption("patient_requested");
  await dialog.getByLabel("Bemorni boshqa kunga qayta yozish (ixtiyoriy)").check();
  await dialog.getByLabel("Yangi sana").fill("2030-01-07");
  const times = dialog.getByLabel("Bo‘sh vaqt", { exact: true });
  const occupied = times.getByRole("option", { name: /09:00.*09:30.*Band/ });
  await expect(occupied).toBeDisabled();
  await expect(times.locator("option").nth(1)).toContainText("09:00");
  await expect(times.locator("option").nth(2)).toContainText("09:30");
  await expect(dialog).toContainText(/Tavsiya: 09:30.*10:00/);
  await expect(dialog.getByRole("button", { name: "Tasdiqlash" })).toBeDisabled();
  await times.selectOption({ index: 2 });
  await dialog.getByRole("button", { name: "Tasdiqlash" }).click();
  expect(fixture.commands[0]?.body.rebook).toEqual(freeSlot);
});


test("when all slots are occupied, suggests the next free date without booking an occupied slot", async ({ page }) => {
  const freeTomorrow = {
    ...slot, starts_at: "2030-01-08T05:00:00Z", ends_at: "2030-01-08T05:30:00Z",
  };
  const fixture = await setup(page, {
    availability: async (route, date) => {
      await route.fulfill({
        json: { local_date: date, timezone: "Asia/Tashkent",
          slots: date === "2030-01-08" ? [freeTomorrow] : [],
          busy_slots: date === "2030-01-07" ? [slot] : [] },
      });
    },
  });
  const dialog = await openAction(page, "skip");
  await dialog.getByLabel("Sabab", { exact: true }).selectOption("patient_late");
  await dialog.getByLabel("Bemorni boshqa kunga qayta yozish (ixtiyoriy)").check();
  await dialog.getByLabel("Yangi sana").fill("2030-01-07");
  await expect(dialog).toContainText("Bu sanadagi barcha mavjud vaqtlar band");
  await dialog.getByRole("button", { name: "Keyingi bo‘sh sanani topish (14 kun)" }).click();
  await expect(dialog.getByLabel("Yangi sana")).toHaveValue("2030-01-08");
  await expect(dialog).toContainText(/Tavsiya qilingan eng yaqin sana va vaqt/);
  await dialog.getByRole("button", { name: "Tasdiqlash" }).click();
  expect(fixture.commands[0]?.body.rebook).toEqual(freeTomorrow);
});


test("recommendation uses clinic 24-hour clock and a free slot after a busy time", async ({ page }) => {
  const makeSlot = (start: string, end: string) => ({
    ...slot, starts_at: "2030-01-07T" + start + ":00Z",
    ends_at: "2030-01-07T" + end + ":00Z",
  });
  const fixture = await setup(page, {
    availability: async (route, date) => route.fulfill({
      json: { local_date: date, timezone: "Asia/Tashkent",
        slots: [makeSlot("04:00", "04:30"), makeSlot("05:30", "06:00"), makeSlot("08:00", "08:30")],
        busy_slots: [makeSlot("04:30", "05:00"), makeSlot("05:00", "05:30")] },
    }),
  });
  const dialog = await openAction(page, "skip");
  await dialog.getByLabel("Bemorni boshqa kunga qayta yozish (ixtiyoriy)").check();
  await dialog.getByLabel("Yangi sana").fill("2030-01-07");
  await expect(dialog).toContainText(/Tavsiya: 10:30.*11:00/);
  await expect(dialog).not.toContainText("Tavsiya: 01:00");
  const times = dialog.getByLabel("Bo‘sh vaqt", { exact: true });
  await expect(times.getByRole("option", { name: /13:00.*13:30/ })).toHaveCount(1);
  await expect(times.getByRole("option", { name: /09:30.*10:00.*Band/ })).toBeDisabled();
  expect(fixture.commands).toHaveLength(0);
});
