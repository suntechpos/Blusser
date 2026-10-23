import type { Page } from "@playwright/test";
import { receptionSession } from "./reception.fixture";
import { allServerGrants } from "./access.fixture";
export const ids = {
  branch: "10000000-0000-4000-8000-000000000001",
  other: "10000000-0000-4000-8000-000000000002",
  doctor: "20000000-0000-4000-8000-000000000001",
  specialty: "30000000-0000-4000-8000-000000000001",
  service: "40000000-0000-4000-8000-000000000001",
  room: "50000000-0000-4000-8000-000000000001",
  patient: "60000000-0000-4000-8000-000000000001",
};
export async function bookingSession(
  page: Page,
  grants: Record<string, string> = allServerGrants,
) {
  const requests = await receptionSession(page, grants);
  const patients = Array.from({ length: 9 }, (_, i) => ({
    id:
      i === 0
        ? ids.patient
        : `60000000-0000-4000-8000-${String(i + 1).padStart(12, "0")}`,
    patient_number: `P-${i + 1}`,
    name: i === 0 ? "Aliyeva Mohira" : `Bemor ${i + 1}`,
    phone: "+998901234567",
    date_of_birth: "1992-03-12",
    gender: "female",
    branch_ids: [ids.branch],
    archived_at: null,
  }));
  const state = {
    conflict: false,
    fail: false,
    delay: 0,
    empty: false,
    patientDelay: 0,
    listDelay: 0,
    duration: 30 as number | null,
    rows: [] as any[],
  };
  await page.route("**/api/v1/clinic/**", async (route) => {
    const request = route.request(),
      url = new URL(request.url()),
      path = url.pathname,
      method = request.method();
    const record = () =>
      requests.push({
        method,
        path: path + url.search,
        body: request.postDataJSON(),
        headers: request.headers(),
      });
    if (path.endsWith("/branches")) {
      record();
      return route.fulfill({
        json: {
          data: [
            {
              id: ids.branch,
              name: "Asosiy filial",
              active: true,
              timezone: "Asia/Tashkent",
            },
            {
              id: ids.other,
              name: "Boshqa filial",
              active: true,
              timezone: "Asia/Tashkent",
            },
          ],
        },
      });
    }
    if (path.endsWith("/context")) {
      record();
      return route.fulfill({
        json: {
          grants,
          branches: [
            {
              id: ids.branch,
              name: "Asosiy filial",
              active: true,
              timezone: "Asia/Tashkent",
            },
            {
              id: ids.other,
              name: "Boshqa filial",
              active: true,
              timezone: "Asia/Tashkent",
            },
          ],
          timezone: "Asia/Tashkent",
        },
      });
    }
    if (path.endsWith("/appointments/booking-options")) {
      record();
      return route.fulfill({
        json: {
          data:
            url.searchParams.get("branch_id") === ids.other
              ? []
              : [
                  {
                    id: ids.doctor,
                    name: "Aziz Karimov",
                    specialty_id: ids.specialty,
                    specialty_name: "Kardiologiya",
                    services: [
                      {
                        id: ids.service,
                        name: "Birlamchi konsultatsiya",
                        duration_minutes: state.duration,
                      },
                    ],
                  },
                ],
          timezone: "Asia/Tashkent",
          meta: { total: 1, page: 1, per_page: 100 },
        },
      });
    }
    if (path.endsWith("/availability")) {
      record();
      const from = url.searchParams.get("from")!,
        date = new Date(Date.parse(from) + 5 * 3600000)
          .toISOString()
          .slice(0, 10);
      const slots = state.empty
        ? []
        : ["03:00", "05:30", "06:00", "12:00", "12:30"]
            .map((time) => ({
              doctor_id: ids.doctor,
              branch_id: ids.branch,
              room_id: ids.room,
              starts_at: `${date}T${time}:00Z`,
              ends_at: new Date(
                Date.parse(`${date}T${time}:00Z`) + 1800000,
              ).toISOString(),
            }))
            .filter(
              (s) => !state.rows.some((a) => a.starts_at === s.starts_at),
            );
      return route.fulfill({
        json: {
          data: slots,
          meta: { total: slots.length, page: 1, per_page: 100 },
        },
      });
    }
    if (path.endsWith("/patients") && method === "POST") {
      record();
      const body = request.postDataJSON();
      const created = {
        ...patients[0]!,
        ...body,
        id: "60000000-0000-4000-8000-000000000099",
        patient_number: "P-99",
        branch_ids: [body.branch_id],
      };
      patients.push(created);
      return route.fulfill({ status: 201, json: created });
    }
    if (path.endsWith("/patients") && method === "GET") {
      record();
      if (state.patientDelay)
        await new Promise((r) => setTimeout(r, state.patientDelay));
      const q = url.searchParams.get("q")?.toLowerCase() ?? "",
        p = Number(url.searchParams.get("page") || 1),
        size = Number(url.searchParams.get("per_page") || 8),
        data = patients.filter((p) =>
          `${p.name} ${p.patient_number} ${p.phone}`.toLowerCase().includes(q),
        );
      return route.fulfill({
        json: {
          data: data.slice((p - 1) * size, p * size),
          meta: { total: data.length, page: p, per_page: size },
        },
      });
    }
    if (/\/patients\/[^/]+$/.test(path) && method === "GET") {
      record();
      return route.fulfill({
        json: {
          ...patients.find((p) => p.id === path.split("/").at(-1)),
        },
      });
    }
    if (path.endsWith("/appointments")) {
      record();
      if (method === "POST") {
        if (state.delay) await new Promise((r) => setTimeout(r, state.delay));
        if (state.conflict) {
          state.conflict = false;
          return route.fulfill({ status: 409, json: { message: "Conflict" } });
        }
        if (state.fail) {
          state.fail = false;
          return route.fulfill({
            status: 503,
            json: { message: "Unavailable" },
          });
        }
        const body = request.postDataJSON(),
          row = {
            ...body,
            id: `70000000-0000-4000-8000-${String(state.rows.length + 1).padStart(12, "0")}`,
            status: "scheduled",
            version: 1,
          };
        state.rows.push(row);
        return route.fulfill({ status: 201, json: row });
      }
      const from = url.searchParams.get("from")!,
        to = url.searchParams.get("to")!,
        status = url.searchParams.get("status"),
        q = url.searchParams.get("q")?.toLowerCase() ?? "";
      const data = state.rows.filter(
        (a) =>
          (!url.searchParams.get("branch_id") ||
            a.branch_id === url.searchParams.get("branch_id")) &&
          (!url.searchParams.get("doctor_id") ||
            a.doctor_id === url.searchParams.get("doctor_id")) &&
          a.starts_at >= from &&
          a.starts_at < to &&
          (!status || a.status === status) &&
          (!q ||
            `${a.id} Aliyeva Mohira P-1 +998901234567`
              .toLowerCase()
              .includes(q)),
      );
      const p = Number(url.searchParams.get("page") || 1),
        size = Number(url.searchParams.get("per_page") || 8);
      // A later navigation may overtake this already running server read.
      if (state.listDelay)
        await new Promise((r) => setTimeout(r, state.listDelay));
      return route.fulfill({
        json: {
          data: data.slice((p - 1) * size, p * size),
          meta: { total: data.length, page: p, per_page: size },
        },
      });
    }
    return route.fallback();
  });
  await page.reload();
  await page.getByRole("button", { name: "Registratura", exact: true }).click();
  return { state, requests, patients };
}
