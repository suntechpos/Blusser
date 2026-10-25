// Synthetic network fixtures; production auth and Patients code unchanged.
import { expect, type Page } from "@playwright/test";
import {allServerGrants} from "./access.fixture";
export async function receptionSession(page: Page, grants:Record<string,string>=allServerGrants) {
  const requests: {
    method: string;
    path: string;
    body: unknown;
    headers: Record<string, string>;
  }[] = [];
  let signedIn = true;
  let version = 1;
  const rows = Array.from({ length: 23 }, (_, i) => ({
    id: `synthetic-${i + 1}`,
    patient_number: `P-${i + 1}`,
    name: `Synthetic patient ${i + 1}`,
    phone: "+998900000000",
    date_of_birth: "1990-01-01",
    gender: "female",
    branch_ids: ["branch-a"],
    archived_at: null,
  }));
  await page.route("**/api/v1/**", async (route) => {
    const req = route.request(),
      u = new URL(req.url()),
      path = u.pathname;
    requests.push({
      method: req.method(),
      path: u.pathname + u.search,
      body: req.postDataJSON(),
      headers: req.headers(),
    });
    const user = {
      id: "synthetic-user",
      login: "visual.ceo",
      is_platform_admin: false,
      must_change_password: false,
      active_tenant_id: "clinic-a",
    };
    if (path.endsWith('/clinic/cashier/unlock')) {
      if (!grants['cashier.view']) return route.fulfill({status:403});
      return route.fulfill(req.postDataJSON()?.cashier_pin === '1122' ? {status:204} : {status:422,json:{code:'invalid_pin'}});
    }
    if (path.endsWith("/auth/logout")) {
      signedIn = false;
      return route.fulfill({ status: 204 });
    }
    if (path.endsWith("/auth/login")) {
      signedIn = true;
      return route.fulfill({ json: user });
    }
    if (path.endsWith("/auth/me"))
      return route.fulfill(
        signedIn
          ? { json: user }
          : { status: 401, json: { message: "Unauthenticated" } },
      );
    if (path.endsWith("/auth/clinics"))
      return route.fulfill({
        json: {
          data: [{ id: "clinic-a", name: "Synthetic Clinic", role: "CEO" }],
        },
      });
    if(path.endsWith('/clinic/branches')&&req.method()==='GET')return route.fulfill({json:{data:[{id:'branch-a',name:'Synthetic branch',active:true}]}});
    if (path.endsWith("/clinic/context"))
      return route.fulfill({
        json: {
          grants,
          branches: [{ id: "branch-a", name: "Synthetic branch" }],
          timezone: "Asia/Tashkent",
        },
      });
    if (path.endsWith("/clinic/reception/overview")) {
      return route.fulfill({ json: { local_date: u.searchParams.get("local_date"),
        timezone: "Asia/Tashkent", appointments: [], visits: [], tickets: [], counts: {} } });
    }
    if (path.endsWith("/clinic/patients") && req.method() === "GET") {
      const q = u.searchParams.get("q") || "";
      const data = rows.filter((r) =>
        r.name.toLowerCase().includes(q.toLowerCase()),
      );
      const p = Number(u.searchParams.get("page") || 1),
        size = Number(u.searchParams.get("per_page") || 10);
      return route.fulfill({
        json: {
          data: data.slice((p - 1) * size, p * size),
          meta: {
            total: data.length,
            current_page: p,
            last_page: Math.max(1, Math.ceil(data.length / size)),
            per_page: size,
          },
        },
      });
    }
    if (path.endsWith("/clinic/patients/export"))
      return route.fulfill({
        body: "name\nSynthetic",
        contentType: "text/csv",
      });
    if (path.includes("/clinic/patients/") && req.method() === "GET") {
      const r = rows.find((r) => r.id === path.split("/").at(-1));
      return route.fulfill({ json: r, headers: { ETag: `"${version}"` } });
    }
    if (path.endsWith("/clinic/patients") && req.method() === "POST") {
      const body = req.postDataJSON();
      const r = {
        ...rows[0]!,
        ...body,
        id: `synthetic-${rows.length + 1}`,
        patient_number: `P-${rows.length + 1}`,
        archived_at: null,
      };
      rows.unshift(r);
      return route.fulfill({
        status: 201,
        json: r,
        headers: { ETag: `"${++version}"` },
      });
    }
    if (path.includes("/clinic/patients/") && req.method() === "PATCH") {
      if (req.postDataJSON().name === "Conflict")
        return route.fulfill({
          status: 409,
          json: { message: "Synthetic ETag conflict" },
        });
      if (req.postDataJSON().name === "Validation")
        return route.fulfill({
          status: 422,
          json: {
            message: "Synthetic validation",
            errors: { name: ["Synthetic invalid name"] },
          },
        });

      const r = rows.find((r) => r.id === path.split("/").at(-1));
      Object.assign(r!, req.postDataJSON());
      return route.fulfill({ json: r, headers: { ETag: `"${++version}"` } });
    }
    return route.fulfill({
      status: 500,
      json: { message: "Unexpected test request " + path },
    });
  });
  await page.route("**/sanctum/csrf-cookie", (r) => r.fulfill({ status: 204 }));
  await page.goto("/");
  await expect(
    page.locator(".clinic-sidebar"),
  ).toBeVisible();
  return requests;
}
