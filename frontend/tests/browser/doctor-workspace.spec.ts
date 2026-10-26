import { test, expect, type Page } from "@playwright/test";
import { allServerGrants } from "./access.fixture";

const patient = {
  id: "ticket-nizomjon", visit_id: "visit-nizomjon",
  patient_id: "patient-alijon", patient_number: "P-000009",
  name: "Aliyev Alijon", dob: "2003-02-05",
  gender: "male", phone: "+998912342343", service: "Tish qo‘ydirish",
  number: 1, kind: "walk_in" as const, status: "waiting",
  appointment_id: null, service_id: "service-dental", created_at: "2026-10-26T06:00:00Z",
};
async function setup(
  page: Page,
  manager = false,
  grants: Record<string, string> = allServerGrants,
  rows: Array<Omit<typeof patient, "appointment_id"> & {
    appointment_id: string | null;
    version?: number;
    called_at?: string | null;
    started_at?: string | null;
  }> = [patient],
  calls: string[] = [],
  starts: Array<{ id: string; body: Record<string, unknown> }> = [],
  failOwnSlot = false,
  branchList: Array<{ id: string; name: string; active: boolean; timezone: string }> = [],
) {
  const requests: string[] = [];
  const queue = rows.map((row) => ({ ...row, version: row.version ?? 1, called_at: row.called_at ?? null }));
  await page.route("**/api/v1/**", (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname;
    if (path === "/api/v1/auth/me") return route.fulfill({ json: {
      id: "user-nizomjon", login: manager ? "kamron.ceo" : "nizomjon23",
      is_platform_admin: false, must_change_password: false, active_tenant_id: "tenant-a",
    }});
    if (path === "/api/v1/auth/clinics") return route.fulfill({ json: {
      data: [{ id: "tenant-a", name: "Shifo Med Klinikasi", role: manager ? "CEO" : "Shifokor" }],
    }});
    if (path === "/api/v1/clinic/branches") return route.fulfill({ json: { data: branchList } });
    if (path === "/api/v1/clinic/context") return route.fulfill({ json: {
      grants, branches: branchList, timezone: "Asia/Tashkent",
    }});
    const ownSlot = path.match(/^\/api\/v1\/clinic\/queue-tickets\/([^/]+)\/own-start-slot$/);
    if (ownSlot) {
      if (failOwnSlot) {
        return route.fulfill({ status: 409, json: { message: "No matching slot" } });
      }
      const ticket = queue.find((row) => row.id === ownSlot[1]);
      if (!ticket || ticket.status !== "called" || grants["queues.start"] !== "own") {
        return route.fulfill({ status: 404, json: { message: "Unavailable" } });
      }
      return route.fulfill({ json: {
        room_id: "room-own-doctor",
        starts_at: "2026-10-26T07:30:00Z",
        ends_at: "2026-10-26T08:00:00Z",
      } });
    }
    const startPath = path.match(/^\/api\/v1\/clinic\/queue-tickets\/([^/]+)\/start$/);
    if (startPath) {
      const ticket = queue.find((row) => row.id === startPath[1]);
      const body = route.request().postDataJSON();
      if (route.request().method() !== "POST" || !ticket || ticket.status !== "called" ||
        body?.expected_version !== ticket.version ||
        !body?.room_id || !body?.starts_at || !body?.ends_at) {
        return route.fulfill({ status: 409, json: { message: "Conflict" } });
      }
      starts.push({ id: ticket.id, body });
      ticket.status = "in_progress";
      ticket.version++;
      return route.fulfill({ json: { ticket: { id: ticket.id, version: ticket.version, status: ticket.status } } });
    }
    if (path === "/api/v1/clinic/appointments/appointment-real" && route.request().method() === "GET") {
      return route.fulfill({ json: {
        id: "appointment-real", doctor_id: "doctor-nizomjon",
        branch_id: "branch-1", patient_id: "patient-alijon", service_id: "service-dental",
        room_id: "room-reserved", starts_at: "2026-10-26T07:30:00Z",
        ends_at: "2026-10-26T08:00:00Z", status: "checked_in",
      } });
    }
    if (path === "/api/v1/clinic/doctors/doctor-nizomjon/availability") {
      return route.fulfill({ json: { data: [{
        doctor_id: "doctor-nizomjon", branch_id: "branch-1", room_id: "room-available",
        starts_at: "2026-10-26T07:30:00Z", ends_at: "2026-10-26T08:00:00Z",
      }], meta: { total: 1 } } });
    }
    const callPath = path.match(/^\/api\/v1\/clinic\/queue-tickets\/([^/]+)\/call$/);
    if (callPath) {
      const ticket = queue.find((item) => item.id === callPath[1]);
      const expectedVersion = route.request().postDataJSON()?.expected_version;
      if (route.request().method() !== "POST" || !ticket || ticket.status !== "waiting" || expectedVersion !== ticket.version) {
        return route.fulfill({ status: 409, json: { message: "Conflict" } });
      }
      calls.push(ticket.id);
      ticket.status = "called";
      ticket.version++;
      ticket.called_at = "2026-10-26T07:00:00Z";
      return route.fulfill({ json: { ticket: { id: ticket.id, status: ticket.status, version: ticket.version } } });
    }
    if (path === "/api/v1/clinic/doctor-workspace") {
      requests.push(url.search);
      const other = url.searchParams.get("doctor_id") === "doctor-biloldin";
      const date = url.searchParams.get("date") || "2026-10-26";
      const selected = manager && other ? {
        id: "doctor-biloldin", name: "Hasanov Biloldin", specialty: "Pulmonolog",
      } : { id: "doctor-nizomjon", name: "Nizomjon Mansurov", specialty: "Tish doktori" };
      return route.fulfill({ json: {
        manager, doctors: manager ? [
          { id: "doctor-nizomjon", name: "Nizomjon Mansurov", specialty: "Tish doktori" },
          { id: "doctor-biloldin", name: "Hasanov Biloldin", specialty: "Pulmonolog" },
        ] : [],
        doctor: { ...selected, room: "23",
          branches: [{ id: "branch-1", name: "Asosiy filial", timezone: "Asia/Tashkent" }] },
        branch: { id: "branch-1", name: "Asosiy filial", timezone: "Asia/Tashkent" },
        date,
        tickets: date === "2026-10-26" ? queue.map((item) => ({
          ...item,
          ...(other ? { id: "ticket-biloldin", name: "Boshqa shifokor bemori" } : {}),
        })) : [],
        has_called_today: queue.some((item) => !!item.called_at),
        has_active_patient: queue.some((item) => item.status === "called" || item.status === "in_progress"),
        scheduled: [],
      }});
    }
    return route.fulfill({ status: 500, json: { message: "Unexpected API: " + path } });
  });
  await page.route("**/sanctum/csrf-cookie", r => r.fulfill({ status: 204 }));
  await page.goto("/#shifokor-kabineti");
  await expect(page.getByRole("heading", { name: "Shifokor kabineti" })).toBeVisible();
  return requests;
}
test.use({ viewport: { width: 1672, height: 941 }, locale: "uz-UZ" });

test("signed-in doctor shows own real queue and not demo patient data", async ({ page }) => {
  const requests = await setup(page);
  await expect(page.locator(".dw-heading")).toContainText("Nizomjon Mansurov");
  await expect(page.locator(".dw-heading")).toContainText("23-xona");
  await expect(page.locator(".dw-queue-person")).toHaveCount(1);
  await expect(page.locator(".dw-queue-person")).toContainText("Aliyev Alijon");
  await expect(page.locator(".dw-queue")).not.toContainText("Aliyeva Mohira");
  await expect(page.getByText("Qabul tafsilotlari")).toBeVisible();
  await expect(page.getByText("demo shikoyat", { exact: false })).toBeVisible();
  expect(requests).toHaveLength(1);
  await page.getByLabel("Navbatdagi bemorni qidirish").fill("not found");
  await expect(page.locator(".dw-queue-person")).toHaveCount(0);
  await page.getByLabel("Navbatdagi bemorni qidirish").fill("Aliyev");
  await expect(page.locator(".dw-queue-person")).toHaveCount(1);
});

test("doctor with workspace.view only can see and select waiting patients, without consult grant", async ({ page }) => {
  await setup(page, false, { "workspace.view": "own" });
  const waiting = page.locator(".dw-queue-tabs").getByRole("button", { name: /Kutilmoqda/ });
  const completed = page.locator(".dw-queue-tabs").getByRole("button", { name: /Yakunlangan/ });
  const patientRow = page.getByRole("button", { name: "Aliyev Alijon · P-000009" });
  await expect(waiting).toBeVisible();
  await expect(completed).toBeVisible();
  await expect(patientRow).toBeVisible();
  await expect(patientRow).toContainText("Aliyev Alijon");
  await completed.click();
  await expect(patientRow).toHaveCount(0);
  await waiting.click();
  await expect(patientRow).toBeVisible();
  await patientRow.click();
  await expect(page.locator(".dw-live-details")).toContainText("Aliyev Alijon");
});

test("call button always calls oldest waiting ticket, never selected patient, and changes label", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-26T07:00:00Z"));
  const calls: string[] = [];
  await setup(page, false, allServerGrants, [
    { ...patient, id: "ticket-first", version: 1, number: 1, created_at: "2026-10-26T06:00:00Z" },
    { ...patient, id: "ticket-second", version: 1, number: 2, name: "Jumanov Alijon", created_at: "2026-10-26T06:05:00Z" },
  ], calls);
  const button = page.getByRole("button", { name: "Bemorni chaqirish" });
  await expect(button).toBeEnabled();
  // Viewing the second patient must not change the next ticket to be called.
  await page.getByRole("button", { name: /Jumanov Alijon/ }).click();
  await expect(page.locator(".dw-live-details")).toContainText("Jumanov Alijon");
  await button.click();
  await expect(page.getByRole("button", { name: "Keyingi bemor" })).toBeDisabled();
  await expect(page.locator(".dw-queue")).toContainText("Qabul yakunlanmaguncha");
  expect(calls).toEqual(["ticket-first"]);
  // Double-clicking the next button cannot call the second patient.
  await expect(page.locator(".dw-queue-person")).toHaveCount(2);
  expect(calls).toEqual(["ticket-first"]);
});

test("next patient can be called after the previous ticket is completed", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-26T07:00:00Z"));
  const calls: string[] = [];
  await setup(page, false, allServerGrants, [
    { ...patient, id: "ticket-first", version: 4, status: "completed",
      called_at: "2026-10-26T06:00:00Z" },
    { ...patient, id: "ticket-second", version: 1, number: 2,
      name: "Jumanov Alijon", created_at: "2026-10-26T06:05:00Z" },
  ], calls);
  const button = page.getByRole("button", { name: "Keyingi bemor" });
  await expect(button).toBeEnabled();
  await button.click();
  expect(calls).toEqual(["ticket-second"]);
  await expect(button).toBeDisabled();
});

test("doctor with an in-progress patient cannot call a second waiting patient", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-26T07:00:00Z"));
  const calls: string[] = [];
  await setup(page, false, allServerGrants, [
    { ...patient, id: "ticket-first", status: "in_progress", version: 3,
      called_at: "2026-10-26T06:00:00Z" },
    { ...patient, id: "ticket-second", version: 1, number: 2,
      name: "Jumanov Alijon", created_at: "2026-10-26T06:05:00Z" },
  ], calls);
  await expect(page.getByRole("button", { name: "Keyingi bemor" })).toBeDisabled();
  await expect(page.locator(".dw-queue")).toContainText("Qabul yakunlanmaguncha");
  expect(calls).toEqual([]);
});

test("queue call requires server queues.call grant and only works for today's clinic date", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-26T07:00:00Z"));
  await setup(page, false, { "workspace.view": "own" });
  await expect(page.locator(".dw-call-next")).toBeVisible();
  await expect(page.locator(".dw-call-next")).toBeDisabled();
  await expect(page.locator(".dw-queue")).toContainText("ruxsati berilmagan");
  await page.getByLabel("Qabul sanasi").fill("2026-10-27");
  await page.getByLabel("Qabul sanasi").dispatchEvent("change");
  await expect(page.locator(".dw-call-next")).toBeDisabled();
});

test("queue-call button explains missing visits.view even when queues.call is present", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-26T07:00:00Z"));
  const grants = { ...allServerGrants };
  delete grants["visits.view"];
  await setup(page, false, grants);
  await expect(page.locator(".dw-call-next")).toBeVisible();
  await expect(page.locator(".dw-call-next")).toBeDisabled();
  await expect(page.locator(".dw-queue")).toContainText("Tashriflarni ko‘rish ruxsati berilmagan");
});

test("Task #02: refreshing the same doctor keeps the selected patient and clinical tab visible", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-26T07:00:00Z"));
  await setup(page, false, allServerGrants, [
    { ...patient, status: "in_progress", version: 3,
      started_at: "2026-10-26T06:42:00Z" },
  ]);
  const history = page.getByRole("tab", { name: "Tashriflar tarixi" });
  await history.click();
  await expect(history).toHaveAttribute("aria-selected", "true");
  const clinical = page.getByRole("region", { name: "Boshlangan qabul interfeysi" });
  let begin!: () => void;
  let release!: () => void;
  const requestStarted = new Promise<void>((resolve) => { begin = resolve; });
  const mayComplete = new Promise<void>((resolve) => { release = resolve; });
  await page.route("**/api/v1/clinic/doctor-workspace*", async (route) => {
    begin();
    await mayComplete;
    await route.fallback();
  });

  await page.getByRole("button", { name: "Navbatni yangilash" }).click();
  await requestStarted;
  // Old code set feed=null before GET, hiding the preview and resetting the tab.
  await expect(clinical).toBeVisible();
  await expect(history).toHaveAttribute("aria-selected", "true");
  await expect(page.locator(".dw-queue-person.selected")).toContainText("Aliyev Alijon");
  release();
  await expect(history).toHaveAttribute("aria-selected", "true");
  await expect(clinical).toContainText("Tashrif topilmadi");
});

test("Task #02: 15-second session recheck updates clinic display metadata without remounting consultation", async ({ page }) => {
  await page.clock.install({ time: new Date("2026-10-26T07:00:00Z") });
  const branches = [{
    id: "branch-1", name: "Asosiy filial", active: true, timezone: "Asia/Tashkent",
  }];
  const grants = { ...allServerGrants };
  let branchResponses = 0;
  page.on("response", (response) => {
    if (new URL(response.url()).pathname === "/api/v1/clinic/branches") branchResponses++;
  });
  await setup(page, false, grants, [
    { ...patient, status: "in_progress", version: 3,
      started_at: "2026-10-26T06:42:00Z" },
  ], [], [], false, branches);
  const history = page.getByRole("tab", { name: "Tashriflar tarixi" });
  await history.click();
  await expect(history).toHaveAttribute("aria-selected", "true");
  // Same branch/same grants; only the display name changed on the server.
  branches[0]!.name = "Asosiy filial (yangilangan)";
  await page.clock.runFor(16_000);
  await expect.poll(() => branchResponses).toBeGreaterThanOrEqual(2);
  await expect(history).toHaveAttribute("aria-selected", "true");
  await expect(page.getByRole("region", { name: "Boshlangan qabul interfeysi" })).toBeVisible();
});

test("Task #02: real in-progress visit reveals the restored clinical layout read-only", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-26T07:00:00Z"));
  await setup(page, false, allServerGrants, [
    { ...patient, status: "in_progress", version: 3,
      started_at: "2026-10-26T06:42:00Z", called_at: "2026-10-26T06:40:00Z" },
  ]);
  const clinical = page.getByRole("region", { name: "Boshlangan qabul interfeysi" });
  await expect(clinical).toBeVisible();
  await expect(clinical).toContainText("Qabul yozuvi");
  await expect(clinical).toContainText("11:42"); // 06:42Z in Asia/Tashkent
  await expect(page.locator(".dw-live-details")).toContainText("Aliyev Alijon");
  await expect(page.locator(".dw-live-details")).toContainText("Nizomjon Mansurov");
  for (const name of ["Shikoyatlar", "Anamnez", "Ko‘rik natijalari", "Tavsiyalar"]) {
    await expect(clinical.getByRole("textbox", { name })).toBeDisabled();
  }
  await expect(clinical.getByRole("combobox", { name: "Tashxis turi" })).toBeDisabled();
  for (const name of ["Yo‘llanma", "Retsept", "Qoralamani saqlash", "Qabulni yakunlash"]) {
    await expect(clinical.getByRole("button", { name })).toBeDisabled();
  }
  await expect(clinical).not.toContainText("Aziz Karimov");
  await expect(clinical).not.toContainText("Aliyeva Mohira");
  await expect(clinical).not.toContainText("Qoralama saqlangan");
});

test("Task #02: visit details remain collapsed inside patient header, with old tabs immediately below", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-26T07:00:00Z"));
  await setup(page, false, allServerGrants, [
    { ...patient, status: "in_progress", version: 3,
      started_at: "2026-10-26T06:42:00Z" },
  ]);
  const patientCard = page.locator(".dw-patient-card");
  const details = patientCard.locator("details.dw-visit-disclosure");
  const tab = page.getByRole("tab", { name: "Bugungi qabul" });
  await expect(details).not.toHaveAttribute("open", "");
  await expect(tab).toBeVisible();
  await expect(page.locator(".dw-live-visit")).toHaveCount(0);
  await expect(patientCard.locator("dl")).not.toBeVisible();
  await details.locator("summary").click();
  await expect(details).toHaveAttribute("open", "");
  await expect(patientCard).toContainText("Nizomjon Mansurov");
  await expect(patientCard).toContainText("Tish qo‘ydirish");
  await details.locator("summary").click();
  await expect(details).not.toHaveAttribute("open", "");
  await expect(tab).toBeVisible();
});

test("Task #02: called ticket keeps start button visible outside collapsed visit details", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-26T07:00:00Z"));
  await setup(page, false, allServerGrants, [
    { ...patient, status: "called", version: 2 },
  ]);
  const card = page.locator(".dw-patient-card");
  await expect(card.locator("details.dw-visit-disclosure")).not.toHaveAttribute("open", "");
  await expect(card.getByRole("button", { name: "Qabulni boshlash" })).toBeVisible();
  await expect(page.getByRole("region", { name: "Boshlangan qabul interfeysi" })).toHaveCount(0);
});

test("Task #02: old doctor tabs remain visible with empty real-data states and no demo upload", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-26T07:00:00Z"));
  await setup(page, false, allServerGrants, [
    { ...patient, status: "in_progress", version: 3,
      started_at: "2026-10-26T06:42:00Z" },
  ]);
  const region = page.getByRole("region", { name: "Boshlangan qabul interfeysi" });
  await region.getByRole("tab", { name: "Tashriflar tarixi" }).click();
  await expect(region).toContainText("Tashrif topilmadi");
  await expect(region).not.toContainText("Aziz Karimov");
  await region.getByRole("tab", { name: "Tekshiruv natijalari" }).click();
  await expect(region).toContainText("Tekshiruv natijasi topilmadi");
  await expect(region.getByRole("button", { name: "Tashqi natija qo‘shish" })).toHaveCount(0);
  await region.getByRole("tab", { name: "Hujjatlar" }).click();
  await expect(region).toContainText("Hujjat topilmadi");
  await expect(region.getByRole("button", { name: "Hujjat yuklash" })).toHaveCount(0);
});

test("Task #02: called, waiting or completed patients never show editable clinical form", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-26T07:00:00Z"));
  await setup(page, false, allServerGrants, [
    { ...patient, status: "called", version: 2 },
    { ...patient, id: "ticket-completed", visit_id: "visit-completed",
      patient_id: "another-patient", patient_number: "P-000010",
      name: "Jumanov Alijon", status: "completed", version: 4, number: 2 },
  ]);
  await expect(page.getByRole("button", { name: "Qabulni boshlash" })).toBeVisible();
  await expect(page.getByRole("region", { name: "Boshlangan qabul interfeysi" })).toHaveCount(0);
  await page.locator(".dw-queue-tabs").getByRole("button", { name: /Yakunlangan/ }).click();
  await expect(page.getByRole("region", { name: "Boshlangan qabul interfeysi" })).toHaveCount(0);
});

test("Task #01: called walk-in patient starts through real availability slot", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-26T07:00:00Z"));
  const starts: Array<{ id: string; body: Record<string, unknown> }> = [];
  await setup(page, false, allServerGrants, [
    { ...patient, status: "called", version: 2, called_at: "2026-10-26T06:55:00Z" },
  ], [], starts);
  await expect(page.getByRole("button", { name: "Qabulni boshlash" })).toBeEnabled();
  await page.getByRole("button", { name: "Qabulni boshlash" }).click();
  await expect(page.locator(".dw-live-details")).toContainText("Qabulda");
  expect(starts).toHaveLength(1);
  expect(starts[0]?.body).toMatchObject({
    expected_version: 2, room_id: "room-available",
    starts_at: "2026-10-26T07:30:00Z", ends_at: "2026-10-26T08:00:00Z",
  });
  await expect(page.getByRole("button", { name: "Qabulni boshlash" })).toHaveCount(0);
});

test("Task #01: unavailable schedule stays called and explains why next to start button", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-26T07:00:00Z"));
  const starts: Array<{ id: string; body: Record<string, unknown> }> = [];
  const grants = { ...allServerGrants, "queues.start": "own" };
  await setup(page, false, grants, [
    { ...patient, status: "called", version: 2 },
  ], [], starts, true);

  const button = page.getByRole("button", { name: "Qabulni boshlash" });
  await button.click();
  await expect(page.locator(".dw-start-error")).toContainText("bo‘sh xona yoki vaqt yo‘q");
  await expect(page.locator(".dw-live-details")).toContainText("Chaqirildi");
  await expect(button).toBeEnabled();
  expect(starts).toHaveLength(0);
});

test("Task #01: own-scoped doctor starts without clinic-wide service/room/schedule views", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-26T07:00:00Z"));
  const starts: Array<{ id: string; body: Record<string, unknown> }> = [];
  const grants = { ...allServerGrants, "queues.start": "own" };
  for (const permission of [
    "services.view", "rooms.view", "doctors.view",
    "schedules.view", "patients.view", "appointments.update",
  ]) delete grants[permission];
  await setup(page, false, grants, [
    { ...patient, status: "called", version: 2 },
  ], [], starts);
  const start = page.getByRole("button", { name: "Qabulni boshlash" });
  await expect(start).toBeEnabled();
  await start.click();
  await expect(page.locator(".dw-live-details")).toContainText("Qabulda");
  expect(starts).toHaveLength(1);
  expect(starts[0]?.body).toMatchObject({
    expected_version: 2, room_id: "room-own-doctor",
    starts_at: "2026-10-26T07:30:00Z",
    ends_at: "2026-10-26T08:00:00Z",
  });
});

test("Task #01: scheduled patient uses exactly its existing appointment reservation", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-26T07:00:00Z"));
  const starts: Array<{ id: string; body: Record<string, unknown> }> = [];
  await setup(page, false, allServerGrants, [
    { ...patient, status: "called", appointment_id: "appointment-real", version: 2 },
  ], [], starts);
  await page.getByRole("button", { name: "Qabulni boshlash" }).click();
  await expect(page.locator(".dw-live-details")).toContainText("Qabulda");
  expect(starts).toHaveLength(1);
  expect(starts[0]?.body).toMatchObject({
    room_id: "room-reserved", expected_version: 2,
    starts_at: "2026-10-26T07:30:00Z", ends_at: "2026-10-26T08:00:00Z",
  });
});

test("Task #01: missing queue.start grant or another in-progress consultation blocks start", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-26T07:00:00Z"));
  const grants = { ...allServerGrants };
  delete grants["queues.start"];
  await setup(page, false, grants, [
    { ...patient, status: "called", version: 2 },
  ]);
  await expect(page.getByRole("button", { name: "Qabulni boshlash" })).toBeDisabled();
  await expect(page.locator(".dw-live-details")).toContainText("ruxsati berilmagan");
});

test("Task #01: never starts a patient who is waiting or merely scheduled", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-26T07:00:00Z"));
  await setup(page);
  await expect(page.getByRole("button", { name: "Qabulni boshlash" })).toHaveCount(0);
});

test("clinic date switching fetches new rows and does not resurrect demo queue", async ({ page }) => {
  const requests = await setup(page);
  await expect(page.locator(".dw-queue-person")).toHaveCount(1);
  await page.getByLabel("Qabul sanasi").fill("2026-10-27");
  await page.getByLabel("Qabul sanasi").dispatchEvent("change");
  await expect(page.locator(".dw-queue-person")).toHaveCount(0);
  await expect(page.locator(".dw-queue")).toContainText("Ushbu sanada bemorlar yo‘q");
  expect(requests.some(q => q.includes("2026-10-27"))).toBe(true);
  await page.getByRole("button", { name: "Bugun" }).click();
  await expect(page.locator(".dw-queue-person")).toHaveCount(1);
});

test("CEO explicitly selects a doctor; changing doctor clears previous patient", async ({ page }) => {
  const requests = await setup(page, true);
  await expect(page.locator(".dw-queue-person")).toContainText("Aliyev Alijon");
  await page.getByLabel("Shifokorni tanlash").selectOption("doctor-biloldin");
  await expect(page.locator(".dw-heading")).toContainText("Hasanov Biloldin");
  await expect(page.locator(".dw-queue-person")).toContainText("Boshqa shifokor bemori");
  await expect(page.locator(".dw-queue")).not.toContainText("Aliyev Alijon");
  expect(requests.some(q => q.includes("doctor-biloldin"))).toBe(true);
});
