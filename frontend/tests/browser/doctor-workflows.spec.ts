import { test, expect } from "@playwright/test";
import { allServerGrants } from "./access.fixture";

test.use({ viewport: { width: 1536, height: 1024 } });

test("failed workspace authorization never falls back to demo patient data", async ({ page }) => {
  await page.route("**/api/v1/**", (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === "/api/v1/auth/me") return route.fulfill({ json: {
      id: "doctor-user", login: "doctor",
      is_platform_admin: false, must_change_password: false,
      active_tenant_id: "clinic-a",
    } });
    if (path === "/api/v1/auth/clinics") return route.fulfill({ json: {
      data: [{ id: "clinic-a", name: "Klinika", role: "Shifokor" }],
    } });
    if (path === "/api/v1/clinic/context") return route.fulfill({ json: {
      grants: allServerGrants, branches: [], timezone: "Asia/Tashkent",
    } });
    if (path === "/api/v1/clinic/branches") return route.fulfill({ json: { data: [] } });
    if (path === "/api/v1/clinic/doctor-workspace")
      return route.fulfill({ status: 403, json: { message: "Forbidden" } });
    return route.fulfill({ status: 500, json: { message: "Unexpected call" } });
  });
  await page.goto("/#shifokor-kabineti");
  await expect(page.getByRole("heading", { name: "Shifokor kabineti" })).toBeVisible();
  await expect(page.locator(".dw-ui [role=alert]")).toContainText("ruxsat yo‘q");
  await expect(page.locator(".dw-queue-person")).toHaveCount(0);
  await expect(page.locator(".dw-ui")).not.toContainText("Aliyeva Mohira");
  await expect(page.locator(".dw-ui")).not.toContainText("Aziz Karimov");
});
