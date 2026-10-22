import { expect, type Page } from "@playwright/test";
import { allServerGrants, serverPermissions } from "./access.fixture";
import { createEmployees } from "../../src/mocks/employees";
export async function editorSession(page: Page, grants = allServerGrants) {
  const roles = [
    ["administrator", "Administrator"],
    ["registrator", "Registrator"],
    ["doctor", "Shifokor"],
    ["lab", "Laborant"],
    ["uzi", "UZI mutaxassisi"],
    ["cashier", "Kassir"],
  ].map(([id, name]) => ({
    id: id!,
    code: id!,
    name: name!,
    note: "",
    active: true,
    protected: id === "administrator",
    version: 1,
    grants: (id === "administrator"
      ? Object.keys(allServerGrants)
      : id === "cashier"
        ? ["cashier.view", "cashier.shift", "cashier.pay", "cashier.history"]
        : ["patients.view"]
    ).map((code) => ({ code, scope: "entire_clinic" })),
  }));
  const employees = createEmployees().map((e) => {
    const role = roles.find((r) => r.name === e.role);
    return {
      id: e.id,
      membership_id: role ? "m-" + e.id : null,
      user_id: role ? "u-" + e.id : null,
      role_id: role?.id ?? null,
      role_name: role?.name ?? null,
      login: role ? e.login : null,
      name: e.first + " " + e.last,
      active: e.active,
      access: !!role && e.access,
      profile: {
        ...e,
        branch: "branch-a",
        photo: undefined,
        schedule: undefined,
        exceptions: undefined,
      },
      version: 1,
      branch_ids: ["branch-a"],
    };
  });
  const requests: {
    path: string;
    method: string;
    body: any;
    headers: Record<string, string>;
  }[] = [];
  let signed = true;
  await page.route("**/api/v1/**", async (route) => {
    const req = route.request(),
      path = new URL(req.url()).pathname,
      method = req.method(),
      body = req.postDataJSON();
    requests.push({ path, method, body, headers: req.headers() });
    if (path.endsWith("/auth/logout")) {
      signed = false;
      return route.fulfill({ status: 204 });
    }
    if (path.endsWith("/auth/login")) signed = true;
    if (path.endsWith("/auth/me") || path.endsWith("/auth/login"))
      return route.fulfill(
        signed
          ? {
              json: {
                id: "fixture-admin",
                login: "api.admin",
                active_tenant_id: "clinic-a",
                must_change_password: false,
                is_platform_admin: false,
              },
            }
          : { status: 401 },
      );
    if (path.endsWith("/auth/clinics"))
      return route.fulfill({
        json: {
          data: [{ id: "clinic-a", name: "API fixture clinic", role: "CEO" }],
        },
      });
    if (path.endsWith("/clinic/context"))
      return route.fulfill({
        json: {
          grants,
          branches: [{ id: "branch-a", name: "Asosiy filial", active: true }],
          timezone: "Asia/Tashkent",
        },
      });
    if (path.endsWith("/clinic/branches"))
      return route.fulfill({
        json: {
          data: [{ id: "branch-a", name: "Asosiy filial", active: true }],
        },
      });
    if (path.endsWith("/clinic/permissions"))
      return route.fulfill({
        json: {
          data: serverPermissions.map((p) => ({
            ...p,
            allowed_scopes: ["own", "assigned_branches", "entire_clinic"],
          })),
        },
      });
    if (path.endsWith("/clinic/roles") && method === "GET")
      return route.fulfill({
        json: {
          data: roles.map((r) => ({
            ...r,
            employee_count: employees.filter((e) => e.role_id === r.id).length,
          })),
        },
      });
    if (path.includes("/clinic/roles")) {
      const id = path.split("/").at(-1),
        role = roles.find((r) => r.id === id);
      if (role?.protected)
        return route.fulfill({ status: 403, json: { message: "Protected" } });
      if (role && req.headers()["if-match"] !== `"${role.version}"`)
        return route.fulfill({
          status: 409,
          json: { message: "Version conflict" },
        });
      if (method === "DELETE") {
        if (employees.some((e) => e.role_id === id))
          return route.fulfill({ status: 403 });
        roles.splice(roles.indexOf(role!), 1);
        return route.fulfill({ status: 204 });
      }
      if (!body.name?.trim() || !body.grants?.length)
        return route.fulfill({
          status: 422,
          json: { errors: { name: ["Rol nomini kiriting."] } },
        });
      if (
        roles.some(
          (r) =>
            r.id !== id &&
            r.name.toLowerCase() === body.name.trim().toLowerCase(),
        )
      )
        return route.fulfill({ status: 409 });
      if (role) {
        Object.assign(role, body, { version: role.version + 1 });
        return route.fulfill({ json: { id: role.id, version: role.version } });
      }
      const added = {
        ...body,
        id: "custom-" + crypto.randomUUID(),
        version: 1,
        protected: false,
        active: true,
      };
      roles.push(added);
      return route.fulfill({ status: 201, json: { id: added.id, version: 1 } });
    }
    if (path.endsWith("/clinic/employees") && method === "GET")
      return route.fulfill({ json: { data: employees } });
    if (path.includes("/clinic/employees")) {
      if (path.endsWith("/password-reset"))
        return route.fulfill({ status: 204 });
      const id = path.split("/").at(-1),
        old = employees.find((e) => e.id === id);
      if (method === "GET")
        return route.fulfill(old ? { json: old } : { status: 404 });
      if (old && req.headers()["if-match"] !== `"${old.version}"`)
        return route.fulfill({ status: 409 });
      const row = {
        id: old?.id ?? "new-" + crypto.randomUUID(),
        membership_id: body.access
          ? (old?.membership_id ?? "new-membership")
          : (old?.membership_id ?? null),
        user_id: body.access ? "new-user" : null,
        role_id: body.role_id,
        role_name: roles.find((r) => r.id === body.role_id)?.name ?? null,
        login: body.login,
        name: body.profile.first + " " + body.profile.last,
        active: body.active,
        access: body.access,
        profile: { ...body.profile, branch: body.branch_id },
        branch_ids: body.branch_ids,
        version: (old?.version ?? 0) + 1,
      };
      if (old) Object.assign(old, row);
      else employees.push(row);
      return route.fulfill({
        status: old ? 200 : 201,
        json: { id: row.id, version: row.version },
      });
    }
    if (path.endsWith("/clinic/links"))
      return route.fulfill({ status: 201, json: { id: "pending-link" } });
    return route.fulfill({
      status: 500,
      json: { message: "Unexpected fixture API " + path },
    });
  });
  await page.route("**/sanctum/csrf-cookie", (r) => r.fulfill({ status: 204 }));
  await page.goto("/");
  await expect(page.locator(".clinic-sidebar")).toBeVisible();
  return { roles, employees, requests };
}
