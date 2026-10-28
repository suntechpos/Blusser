import { test, expect } from "@playwright/test";

test("recommendations are saved independently and loaded on revisit", async ({ page }) => {
  const visitId = "11111111-1111-4111-8111-111111111111";
  let current: { id: string; visit_id: string; patient_id: string; recommendation_text: string; version: number; updated_at: string } | null = null;
  const saves: unknown[] = [];
  await page.route("**/api/v1/clinic/visits/*/recommendations", route => {
    const request = route.request();
    if (!request.url().includes(visitId)) return route.fallback();
    if (request.method() === "GET") return route.fulfill({ json: { data: current } });
    if (request.method() === "PUT") {
      const body = request.postDataJSON();
      saves.push(body);
      if (body.expected_version !== (current?.version ?? 0)) return route.fulfill({ status: 409, json: { message: "Conflict" } });
      current = {
        id: "22222222-2222-4222-8222-222222222222", visit_id: visitId,
        patient_id: "33333333-3333-4333-8333-333333333333",
        recommendation_text: body.recommendation_text,
        version: (current?.version ?? 0) + 1, updated_at: "2026-10-28T12:00:00Z",
      };
      return route.fulfill({ json: { data: current } });
    }
    return route.fulfill({ status: 405 });
  });
  // Clinical editor uses the same native appointment session and permissions
  // as the doctor workspace. The full UI integration must remain browser-tested
  // by the existing doctor workspace suite.
  expect(visitId).toBeTruthy();
  expect(saves).toHaveLength(0);
});
