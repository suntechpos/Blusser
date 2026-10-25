/** Synthetic server contract; production catalog never imports this fixture. */
import type { Page, Route } from "@playwright/test";
import { receptionSession } from "./reception.fixture";
import { allServerGrants } from "./access.fixture";
export const uuid = (n: number) =>
  `60000000-0000-4000-8000-${String(n).padStart(12, "0")}`;
export type ApiCategory = {
  id: string;
  name: string;
  description: string;
  color: string;
  active: boolean;
  version: number;
};
export type ApiService = {
  id: string;
  code: string;
  name: string;
  category_id: string | null;
  service_type: string;
  branch_ids: string[];
  price: string | null;
  timed: boolean;
  duration_minutes: number | null;
  turnaround: string | null;
  turnaround_unit: string;
  description: string;
  active: boolean;
  version: number;
};
export async function serviceSession(
  page: Page,
  grants: Record<string, string> = allServerGrants,
) {
  await receptionSession(page, grants);
  const categories: ApiCategory[] = [
    {
      id: uuid(1),
      name: "API qabul",
      description: "Haqiqiy API shaklidagi test",
      color: "blue",
      active: true,
      version: 1,
    },
    {
      id: uuid(2),
      name: "API laboratoriya",
      description: "",
      color: "green",
      active: true,
      version: 1,
    },
  ];
  const branches = [
    { id: uuid(3), name: "Asosiy filial", active: true },
    { id: uuid(4), name: "Ikkinchi filial", active: true },
  ];
  const services: ApiService[] = Array.from({ length: 12 }, (_, i) => ({
    id: uuid(100 + i),
    code: `XZ-${String(i + 1).padStart(3, "0")}`,
    name: `API xizmat ${i + 1}`,
    category_id: categories[i % 2]!.id,
    service_type: "consultation",
    branch_ids: [branches[i % 2]!.id],
    price: i === 0 ? "120000.25" : "50000.00",
    timed: true,
    duration_minutes: 30,
    turnaround: null,
    turnaround_unit: "hour",
    description: "",
    active: i !== 11,
    version: 1,
  }));
  const requests: {
    method: string;
    path: string;
    body: any;
    headers: Record<string, string>;
  }[] = [];
  const state = {
    failLoad: false,
    failNextWrite: false,
    conflictNextWrite: false,
    empty: false,
    delays: new Map<string, Promise<void>>(),
    completedQueries: [] as string[],
  };
  const categoryList = () =>
    categories.map((c) => ({
      ...c,
      service_count: services.filter((s) => s.category_id === c.id).length,
    }));
  const filtered = (url: URL) =>
    services.filter(
      (s) =>
        (!url.searchParams.get("q") ||
          `${s.code} ${s.name}`
            .toLowerCase()
            .includes(url.searchParams.get("q")!.toLowerCase())) &&
        (!url.searchParams.get("branch_id") ||
          s.branch_ids.includes(url.searchParams.get("branch_id")!)) &&
        (!url.searchParams.get("category_id") ||
          s.category_id === url.searchParams.get("category_id")) &&
        (!url.searchParams.get("status") ||
          url.searchParams.get("status") === "all" ||
          (url.searchParams.get("status") === "active") === s.active),
    );
  await page.route("**/api/v1/clinic/**", async (route: Route) => {
    const req = route.request(),
      url = new URL(req.url()),
      path = url.pathname.replace("/api/v1/clinic/", ""),
      method = req.method();
    if (
      !path.startsWith("appointments/configuration-services") &&
      !path.startsWith("service-categories")
    )
      return route.fallback();
    const body = req.postDataJSON();
    requests.push({
      method,
      path: path + url.search,
      body,
      headers: req.headers(),
    });
    const servicePath = path.startsWith("appointments/configuration-services");
    const id = path.split("/")[servicePath ? 2 : 1];
    const row = servicePath
      ? services.find((s) => s.id === id)
      : categories.find((c) => c.id === id);
    if (method !== "GET") {
      if (state.failNextWrite) {
        state.failNextWrite = false;
        return route.fulfill({
          status: 503,
          json: { message: "Synthetic catalog unavailable" },
        });
      }
      if (state.conflictNextWrite) {
        state.conflictNextWrite = false;
        return route.fulfill({
          status: 409,
          json: { message: "Synthetic stale version" },
        });
      }
      if (id && row && req.headers()["if-match"] !== `"${row.version}"`)
        return route.fulfill({
          status: 409,
          json: { message: "Stale version" },
        });
      if (path.endsWith("/status")) {
        if (!row) return route.fulfill({ status: 404 });
        row.active = body.active;
        row.version++;
        return route.fulfill({ json: row });
      }
      if (servicePath) {
        if (method === "POST") {
          const created = {
            ...body,
            id: uuid(200 + services.length),
            code: `XZ-${String(services.length + 1).padStart(3, "0")}`,
            version: 1,
          } as ApiService;
          services.unshift(created);
          return route.fulfill({ status: 201, json: created });
        }
        if (!row) return route.fulfill({ status: 404 });
        Object.assign(row, body, { version: row.version + 1 });
        return route.fulfill({ json: row });
      }
      if (method === "POST") {
        const created = {
          ...body,
          id: uuid(20 + categories.length),
          version: 1,
        } as ApiCategory;
        categories.push(created);
        return route.fulfill({
          status: 201,
          json: { ...created, service_count: 0 },
        });
      }
      if (!row) return route.fulfill({ status: 404 });
      Object.assign(row, body, { version: row.version + 1 });
      return route.fulfill({
        json: {
          ...row,
          service_count: services.filter((s) => s.category_id === row.id)
            .length,
        },
      });
    }
    if (path.endsWith("/export")) {
      const rows = filtered(url);
      return route.fulfill({
        contentType: "text/csv;charset=utf-8",
        headers: {
          "Content-Disposition": 'attachment; filename="xizmatlar.csv"',
        },
        body:
          "\uFEFFKod,Xizmat nomi,Narx\r\n" +
          rows.map((s) => `${s.code},${s.name},${s.price}`).join("\r\n"),
      });
    }
    if (id) return route.fulfill(row ? { json: row } : { status: 404 });
    if (!servicePath)
      return route.fulfill({
        json: {
          data: categoryList(),
          meta: { total: categories.length, page: 1, per_page: 100 },
        },
      });
    if (state.failLoad)
      return route.fulfill({
        status: 503,
        json: { message: "Synthetic catalog unavailable" },
      });
    const rows = state.empty ? [] : filtered(url),
      p = Number(url.searchParams.get("page") || 1),
      size = Number(url.searchParams.get("per_page") || 10);
    // Capture before delaying to exercise a stale response, not fresh server state.
    const result = {
      data: rows.slice((p - 1) * size, p * size),
      meta: {
        total: rows.length,
        page: p,
        current_page: p,
        last_page: Math.max(1, Math.ceil(rows.length / size)),
        per_page: size,
      },
      stats: {
        total: state.empty ? 0 : services.length,
        active: state.empty ? 0 : services.filter((s) => s.active).length,
        inactive: state.empty ? 0 : services.filter((s) => !s.active).length,
        categories: categories.length,
      },
      categories: categoryList(),
      branches,
    };
    const hold = state.delays.get(url.searchParams.get("q") || "");
    if (hold) await hold;
    try {
      return await route.fulfill({ json: result });
    } catch {
      /* Request may have been cancelled by the newer query. */
    } finally {
      state.completedQueries.push(url.searchParams.get("q") || "");
    }
  });
  await page.getByRole("button", { name: "Xizmatlar", exact: true }).click();
  return { services, categories, branches, requests, state };
}
