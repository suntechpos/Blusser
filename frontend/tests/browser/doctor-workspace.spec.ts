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
  overdueItems: Array<typeof patient & {
    version: number; status: "called" | "in_progress";
    local_date: string; branch_id: string; branch_name: string;
  }> = [],
  todayItems: Array<typeof patient & { version: number }> = [],
  overdueActions: Array<{ id: string; action: string }> = [],
) {
  const requests: string[] = [];
  const queue = rows.map((row) => ({ ...row, version: row.version ?? 1, called_at: row.called_at ?? null }));
  // Separate mock of the server-authorized overdue projection. Today's rows
  // remain independent so an old patient can block the next day's FIFO call.
  const overdue: Array<Omit<(typeof overdueItems)[number], "status"> & { status: string }> =
    overdueItems.map((row) => ({ ...row }));
  const today = todayItems.map((row) => ({ ...row }));
  const clinicalNotes = new Map<string, {
    id: string; visit_id: string; patient_id: string;
    complaints: string; anamnesis: string; examination: string;
    version: number; updated_at: string;
  }>();
  const diagnoses = new Map<string, {
    id: string; number: string; visit_id: string; patient_id: string;
    diagnosis_text: string; diagnosis_type: string; icd_system: string | null;
    icd_code: string | null; version: number; updated_at: string;
  }>();
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
    const notes = path.match(/^\/api\/v1\/clinic\/visits\/([^/]+)\/clinical-notes$/);
    if (notes) {
      const visitId = notes[1]!;
      const ticket = queue.find((row) => row.visit_id === visitId);
      if (!ticket || !grants["visits.view"] || !grants["workspace.view"]) {
        return route.fulfill({ status: 404, json: { message: "Unavailable" } });
      }
      if (route.request().method() === "GET") {
        return route.fulfill({ json: { data: clinicalNotes.get(visitId) ?? null } });
      }
      if (route.request().method() === "PUT") {
        const payload = route.request().postDataJSON();
        const existing = clinicalNotes.get(visitId);
        if (ticket.status !== "in_progress" || !grants["workspace.consult"]) {
          return route.fulfill({ status: 403, json: { message: "Denied" } });
        }
        if (payload.expected_version !== (existing?.version ?? 0)) {
          return route.fulfill({ status: 409, json: { message: "Stale version" } });
        }
        const next = {
          id: existing?.id ?? "note-created",
          visit_id: visitId, patient_id: ticket.patient_id,
          complaints: payload.complaints,
          anamnesis: payload.anamnesis,
          examination: payload.examination,
          version: (existing?.version ?? 0) + 1,
          updated_at: "2026-10-26T07:00:00Z",
        };
        clinicalNotes.set(visitId, next);
        return route.fulfill({ json: { data: next } });
      }
      return route.fulfill({ status: 405, json: { message: "Method not allowed" } });
    }
    const diagnosis = path.match(/^\/api\/v1\/clinic\/visits\/([^/]+)\/diagnosis$/);
    if (diagnosis) {
      const visitId = diagnosis[1]!;
      const ticket = queue.find((row) => row.visit_id === visitId);
      if (!ticket || !grants["visits.view"] || !grants["workspace.view"]) {
        return route.fulfill({ status: 404, json: { message: "Unavailable" } });
      }
      if (route.request().method() === "GET") {
        return route.fulfill({ json: { data: diagnoses.get(visitId) || null } });
      }
      if (route.request().method() === "PUT") {
        const payload = route.request().postDataJSON();
        const existing = diagnoses.get(visitId);
        if (ticket.status !== "in_progress" || !grants["workspace.consult"]) {
          return route.fulfill({ status: 403, json: { message: "Denied" } });
        }
        if (payload.expected_version !== (existing?.version || 0)) {
          return route.fulfill({ status: 409, json: { message: "Conflict" } });
        }
        const next = {
          id: existing?.id || "diagnosis-created",
          number: existing?.number || "DX-000001",
          visit_id: visitId, patient_id: ticket.patient_id,
          diagnosis_text: payload.diagnosis_text,
          diagnosis_type: payload.diagnosis_type,
          icd_system: payload.icd_code ? "ICD-10" : null,
          icd_code: payload.icd_code,
          version: (existing?.version || 0) + 1,
          updated_at: "2026-10-26T07:00:00Z",
        };
        diagnoses.set(visitId, next);
        return route.fulfill({ json: { data: next } });
      }
      return route.fulfill({ status: 405, json: { message: "Method not allowed" } });
    }
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
    const overdueMutation = path.match(/^\/api\/v1\/clinic\/queue-tickets\/([^/]+)\/(complete|skip|cancel)$/);
    if (overdueMutation) {
      const ticket = overdue.find((row) => row.id === overdueMutation[1]);
      const action = overdueMutation[2]!;
      const version = route.request().postDataJSON()?.expected_version;
      const permission = "queues." + action;
      if (!grants[permission]) return route.fulfill({ status: 403, json: { message: "Denied" } });
      if (!ticket || version !== ticket.version ||
        (ticket.status === "called" && !["skip", "cancel"].includes(action)) ||
        (ticket.status === "in_progress" && action !== "complete")) {
        return route.fulfill({ status: 409, json: { message: "Conflict" } });
      }
      overdueActions.push({ id: ticket.id, action });
      ticket.status = action === "complete" ? "completed" : action === "skip" ? "skipped" : "cancelled";
      ticket.version++;
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
        tickets: (date === "2026-10-26" ? [...queue, ...overdue] :
          date === "2026-10-27" ? today : []).map((item) => ({
          ...item,
          ...(other ? { id: "ticket-biloldin", name: "Boshqa shifokor bemori" } : {}),
        })),
        overdue: other ? [] : overdue.filter((item) => item.status === "called" || item.status === "in_progress"),
        has_called_today: queue.some((item) => !!item.called_at),
        has_active_patient: [...queue, ...overdue, ...today].some(
          (item) => item.status === "called" || item.status === "in_progress"),
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

test("UI: prior-day warning is compact and unfinished tickets live in one queue tab", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-27T07:00:00Z"));
  const actions: Array<{ id: string; action: string }> = [];
  const oldActive = {
    ...patient, id: "ticket-old-active", visit_id: "visit-old-active",
    name: "Aliyev Alijon", version: 3, status: "in_progress" as const,
    local_date: "2026-10-26", branch_id: "branch-1", branch_name: "Asosiy filial",
  };
  const oldCalled = {
    ...patient, id: "ticket-old-called", visit_id: "visit-old-called",
    name: "Jumanov Alijon", version: 2, status: "called" as const,
    local_date: "2026-10-26", branch_id: "branch-1", branch_name: "Asosiy filial",
  };
  const today = { ...patient, id: "ticket-today", visit_id: "visit-today",
    name: "Bugungi bemor", version: 1, created_at: "2026-10-27T06:00:00Z" };
  await setup(page, false, allServerGrants, [], [], [], false, [],
    [oldActive, oldCalled], [today], actions);

  const banner = page.getByRole("region", { name: "Eski kundan qolgan navbatlar" });
  await expect(banner).toContainText("2 ta yakunlanmagan qabul");
  await expect(banner.locator(".dw-overdue-item")).toHaveCount(0);
  const left = page.locator(".dw-queue");
  await expect(left.locator(".dw-queue-tabs button")).toHaveCount(3);
  const waiting = left.getByRole("button", { name: /Kutilmoqda/ });
  const unresolved = left.getByRole("button", { name: /Yakunlanmagan/ });
  const completed = left.getByRole("button", { name: /^Yakunlangan/ });
  await expect(waiting).toContainText("1");
  await expect(unresolved).toContainText("2");
  await waiting.click();
  await expect(left.locator(".dw-queue-person")).toHaveCount(1);
  await expect(left).toContainText("Bugungi bemor");
  await expect(left).not.toContainText("Jumanov Alijon");
  await unresolved.click();
  await expect(left.locator(".dw-queue-person")).toHaveCount(2);
  await expect(left).toContainText("Aliyev Alijon");
  await expect(left).toContainText("Jumanov Alijon");
  await expect(left).toContainText("2026-10-26");
  await expect(page.getByRole("button", { name: "Bemorni chaqirish" })).toBeDisabled();

  // Resume is the existing navigation-only action, not a queue mutation.
  await left.getByRole("button", { name: "Bemor amallari: Aliyev Alijon" }).click();
  await left.getByRole("menuitem", { name: "Qabulni davom ettirish" }).click();
  await expect(page.getByLabel("Qabul sanasi")).toHaveValue("2026-10-26");
  await expect(page.getByRole("region", { name: "Boshlangan qabul interfeysi" })).toBeVisible();
  expect(actions).toEqual([]);
});

test("queue mutation menus respect each action grant without sending unauthorized requests", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-27T07:00:00Z"));
  const actions: Array<{ id: string; action: string }> = [];
  const grants = { ...allServerGrants };
  delete grants["queues.cancel"];
  const old = {
    ...patient, id: "ticket-old-called", status: "called" as const, version: 2,
    local_date: "2026-10-26", branch_id: "branch-1", branch_name: "Asosiy filial",
  };
  await setup(page, false, grants, [], [], [], false, [], [old], [], actions);
  await page.locator(".dw-queue-tabs").getByRole("button", { name: /Yakunlanmagan/ }).click();
  await page.getByRole("button", { name: "Bemor amallari: Aliyev Alijon" }).click();
  await expect(page.getByRole("menuitem", { name: "O‘tkazib yuborish" })).toBeVisible();
  await expect(page.getByRole("menuitem", { name: "Navbatni bekor qilish" })).toBeDisabled();
  await expect(page.getByText("Ba’zi amallar uchun ruxsat yetishmaydi.", { exact: false })).toBeVisible();
  await page.getByRole("menuitem", { name: "O‘tkazib yuborish" }).click();
  const dialog = page.getByRole("dialog", { name: "O‘tkazib yuborish" });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByRole("button", { name: "Tasdiqlash", exact: true })).toBeDisabled();
  await dialog.getByRole("button", { name: "Yopish", exact: true }).click();
  await expect(dialog).toHaveCount(0);
  expect(actions).toEqual([]);
  await expect(page.locator(".dw-queue")).toContainText("Chaqirildi");
});

test("waiting ticket keeps action menu visible when both permissions are missing", async ({ page }) => {
  const grants = { ...allServerGrants };
  delete grants["queues.skip"];
  delete grants["queues.cancel"];
  const actions: Array<{ id: string; action: string }> = [];
  await setup(page, false, grants, [patient], [], [], false, [], [], [], actions);
  await page.getByRole("button", { name: "Bemor amallari: Aliyev Alijon" }).click();
  await expect(page.getByRole("menuitem", { name: "O‘tkazib yuborish" })).toBeDisabled();
  await expect(page.getByRole("menuitem", { name: "Navbatni bekor qilish" })).toBeDisabled();
  await expect(page.getByText("Ba’zi amallar uchun ruxsat yetishmaydi.", { exact: false })).toBeVisible();
  expect(actions).toEqual([]);
});

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
  await page.getByRole("button", { name: "Jumanov Alijon · P-000009" }).click();
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

test("Task #02: unchanged identity at 15-second revalidation never reloads patient or resets selected tab", async ({ page }) => {
  await page.clock.install({ time: new Date("2026-10-26T07:00:00Z") });
  const requests = await setup(page, false, allServerGrants, [
    { ...patient, status: "in_progress", version: 3,
      started_at: "2026-10-26T06:42:00Z" },
  ]);
  const clinical = page.getByRole("region", { name: "Boshlangan qabul interfeysi" });
  const history = clinical.getByRole("tab", { name: "Tashriflar tarixi" });
  await history.click();
  await expect(history).toHaveAttribute("aria-selected", "true");
  const initialFeedRequests = requests.length;
  // App.vue revalidates /auth/me every 15 seconds and assigns a FRESH object
  // with the SAME user.id and tenant. The old array-valued watch getter in
  // DoctorWorkspaceLiveView treated that as a change and reset the tab.
  await page.clock.runFor(16_000);
  await expect(history).toHaveAttribute("aria-selected", "true");
  await expect(clinical).toBeVisible();
  await expect(page.locator(".dw-queue-person.selected")).toContainText("Aliyev Alijon");
  expect(requests.length).toBe(initialFeedRequests);
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

test("Tasks #05/#06: complaints anamnesis and examination persist for own ongoing visit", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-26T07:00:00Z"));
  await setup(page, false, allServerGrants, [
    { ...patient, status: "in_progress", version: 3, started_at: "2026-10-26T06:42:00Z" },
  ]);
  const notes = page.getByRole("region", { name: "Shikoyat anamnez va ko‘rik" });
  await notes.getByRole("textbox", { name: "Shikoyatlar" }).fill("Pastki jag‘da og‘riq");
  await notes.getByRole("button", { name: "Ko‘rik yozuvlarini saqlash" }).click();
  await expect(notes).toContainText("serverga saqlandi");
  await expect(notes).toContainText("Serverdagi versiya: 1");
  await notes.getByRole("textbox", { name: "Anamnez" }).fill("Og‘riq 3 kundan beri davom etmoqda");
  await notes.getByRole("textbox", { name: "Ko‘rik natijalari" }).fill("Klinik ko‘rik: K02.1");
  await notes.getByRole("button", { name: "Ko‘rik yozuvlarini yangilash" }).click();
  await expect(notes).toContainText("Serverdagi versiya: 2");
  await page.reload();
  await expect(page.getByRole("textbox", { name: "Shikoyatlar" })).toHaveValue("Pastki jag‘da og‘riq");
  await expect(page.getByRole("textbox", { name: "Anamnez" })).toHaveValue("Og‘riq 3 kundan beri davom etmoqda");
  await expect(page.getByRole("textbox", { name: "Ko‘rik natijalari" })).toHaveValue("Klinik ko‘rik: K02.1");
});

test("Tasks #05/#06: unsaved clinical notes survive tab changes without fake saved status", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-26T07:00:00Z"));
  await setup(page, false, allServerGrants, [
    { ...patient, status: "in_progress", version: 3, started_at: "2026-10-26T06:42:00Z" },
  ]);
  await page.getByRole("textbox", { name: "Anamnez" }).fill("Saqlanmagan anamnez");
  await page.getByRole("tab", { name: "Hujjatlar" }).click();
  await page.getByRole("tab", { name: "Bugungi qabul" }).click();
  await expect(page.getByRole("textbox", { name: "Anamnez" })).toHaveValue("Saqlanmagan anamnez");
  await expect(page.getByText("serverga saqlandi", { exact: false })).toHaveCount(0);
  await page.reload();
  await expect(page.getByRole("textbox", { name: "Anamnez" })).toHaveValue("");
});

test("Tasks #05/#06: denied consult permission keeps notes read-only", async ({ page }) => {
  const grants = { ...allServerGrants };
  delete grants["workspace.consult"];
  await setup(page, false, grants, [
    { ...patient, status: "in_progress", version: 3, started_at: "2026-10-26T06:42:00Z" },
  ]);
  for (const name of ["Shikoyatlar", "Anamnez", "Ko‘rik natijalari"]) {
    await expect(page.getByRole("textbox", { name })).toBeDisabled();
  }
  await expect(page.getByRole("button", { name: "Ko‘rik yozuvlarini saqlash" })).toBeDisabled();
});

test("Task #06: real diagnosis can be written, saved and reopened without demo data", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-26T07:00:00Z"));
  await setup(page, false, allServerGrants, [
    { ...patient, status: "in_progress", version: 3,
      started_at: "2026-10-26T06:42:00Z" },
  ]);
  const clinical = page.getByRole("region", { name: "Boshlangan qabul interfeysi" });
  await expect(clinical.getByText("Yangi tashxis")).toBeVisible();
  await clinical.getByRole("textbox", { name: "Shifokor tashxisi" })
    .fill("Tishning dentin kariyesi");
  await clinical.getByRole("combobox", { name: "Tashxis turi" }).selectOption("final");
  await clinical.getByRole("textbox", { name: "MKB-10 kodi" }).fill("k02.1");
  await clinical.getByRole("button", { name: "Tashxisni saqlash" }).click();
  await expect(clinical).toContainText("Tashxis serverga saqlandi.");
  await expect(clinical).toContainText("DX-000001");
  await expect(clinical.getByRole("button", { name: "Tashxisni yangilash" })).toBeEnabled();
  await page.getByRole("tab", { name: "Tashriflar tarixi" }).click();
  await page.getByRole("tab", { name: "Bugungi qabul" }).click();
  await expect(clinical.getByRole("textbox", { name: "Shifokor tashxisi" })).toHaveValue("Tishning dentin kariyesi");
  await expect(clinical.getByRole("textbox", { name: "MKB-10 kodi" })).toHaveValue("K02.1");
  await page.reload();
  await expect(page.getByRole("textbox", { name: "Shifokor tashxisi" })).toHaveValue("Tishning dentin kariyesi");
  await expect(page.getByRole("combobox", { name: "Tashxis turi" })).toHaveValue("final");
});

test("Task #06: unsaved diagnosis stays in memory while switching old tabs, but is never claimed as saved", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-26T07:00:00Z"));
  await setup(page, false, allServerGrants, [
    { ...patient, status: "in_progress", version: 3, started_at: "2026-10-26T06:42:00Z" },
  ]);
  await page.getByRole("textbox", { name: "Shifokor tashxisi" }).fill("Tekshiruvdan oldin dastlabki qayd");
  await page.getByRole("tab", { name: "Tashriflar tarixi" }).click();
  await page.getByRole("tab", { name: "Bugungi qabul" }).click();
  await expect(page.getByRole("textbox", { name: "Shifokor tashxisi" }))
    .toHaveValue("Tekshiruvdan oldin dastlabki qayd");
  await expect(page.getByText("Yangi tashxis")).toBeVisible();
  await expect(page.getByText("Tashxis serverga saqlandi.")).toHaveCount(0);
});

test("Task #06: diagnosis cannot be edited when consult grant is missing", async ({ page }) => {
  const grants = { ...allServerGrants };
  delete grants["workspace.consult"];
  await setup(page, false, grants, [
    { ...patient, status: "in_progress", version: 3, started_at: "2026-10-26T06:42:00Z" },
  ]);
  await expect(page.getByRole("textbox", { name: "Shifokor tashxisi" })).toBeDisabled();
  await expect(page.getByText("Ko‘rik ma’lumotlarini saqlash", { exact: false })).toBeVisible();
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
  for (const name of ["Shikoyatlar", "Anamnez", "Ko‘rik natijalari"]) {
    await expect(clinical.getByRole("textbox", { name })).toBeEnabled();
  }
  await expect(clinical.getByRole("textbox", { name: "Tavsiyalar" })).toBeDisabled();
  await expect(clinical.getByRole("button", { name: "Ko‘rik yozuvlarini saqlash" })).toBeDisabled();
  await expect(clinical.getByRole("combobox", { name: "Tashxis turi" })).toBeEnabled();
  await expect(clinical.getByRole("textbox", { name: "Shifokor tashxisi" })).toBeEnabled();
  await expect(clinical.getByRole("button", { name: "Tashxisni saqlash" })).toBeDisabled();
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

test("keeps a booked patient when visit is on another day, without duplicating same-day ticket", async ({ page }) => {
  await page.clock.setFixedTime(new Date("2026-10-26T07:00:00Z"));
  await setup(page, false, allServerGrants, []);
  const booked = (id: string, name: string) => ({
    id, appointment_id: id, patient_id: id + "-patient",
    patient_number: "P-" + id, name, dob: "1990-01-01", gender: "male",
    phone: null, service: "Tish qo‘ydirish", starts_at: "2026-10-26T06:30:00Z",
    status: "scheduled", version: 1,
  });
  const ticket = {
    ...patient, id: "ticket-linked", name: "Booked Same-day",
    appointment_id: "appointment-linked", status: "waiting", version: 1,
  };
  await page.route("**/api/v1/clinic/doctor-workspace*", async (route) => {
    await route.fulfill({ json: {
      manager: false, doctors: [], doctor: {
        id: "doctor-nizomjon", name: "Nizomjon Mansurov", specialty: "Tish doktori",
      }, branch: { id: "branch-1", name: "Asosiy filial", timezone: "Asia/Tashkent" },
      date: "2026-10-26", has_active_patient: false, has_called_today: false,
      overdue: [], tickets: [ticket],
      scheduled: [
        booked("appointment-across-day", "Booked Different-day"),
        booked("appointment-linked", "Booked Same-day"),
      ],
    } });
  });
  await page.reload();
  const list = page.locator(".dw-queue-list");
  await expect(list.getByText("Booked Different-day")).toHaveCount(1);
  await expect(list.getByText("Booked Same-day")).toHaveCount(1);
  await expect(page.getByRole("button", { name: /Kutilmoqda 2/ })).toBeVisible();
});
