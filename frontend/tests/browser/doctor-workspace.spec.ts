import { test, expect, type Page } from "@playwright/test";
import { allServerGrants } from "./access.fixture";

const patient = {
  id: "ticket-nizomjon", visit_id: "visit-nizomjon",
  patient_id: "patient-alijon", patient_number: "P-000009",
  name: "Aliyev Alijon", dob: "2003-02-05",
  gender: "male", phone: "+998912342343", service: "Tish qo‘ydirish",
  number: 1, kind: "walk_in" as const, status: "waiting",
  appointment_id: null, created_at: "2026-10-26T06:00:00Z",
};
async function setup(page: Page, manager = false, grants: Record<string, string> = allServerGrants) {
  const requests: string[] = [];
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
    if (path === "/api/v1/clinic/branches") return route.fulfill({ json: { data: [] } });
    if (path === "/api/v1/clinic/context") return route.fulfill({ json: {
      grants, branches: [], timezone: "Asia/Tashkent",
    }});
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
        tickets: date === "2026-10-26" ? [{
          ...patient,
          ...(other ? { id: "ticket-biloldin", name: "Boshqa shifokor bemori" } : {}),
        }] : [],
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
