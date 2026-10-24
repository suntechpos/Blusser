import { test, expect } from "@playwright/test";
test.use({ viewport: { width: 1536, height: 1024 } });
test("inline doctor schedule saves multiple days, retries partial writes and reloads real rules without another employee or doctor", async ({
  page,
}) => {
  await page.goto("/");
  await page.getByLabel("Login", { exact: true }).fill("booking.doctor");
  await page
    .getByLabel("Parol", { exact: true })
    .fill("Synthetic-password-42!");
  await page.getByRole("button", { name: "Kirish", exact: true }).click();
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page.getByRole("row").filter({ hasText: "inline clinician" }).click();
  await page.getByRole("button", { name: "Tahrirlash", exact: true }).click();
  await page.getByLabel("Telefon *", { exact: true }).fill("+998 90 765 43 21");
  await page.getByLabel("Lavozim *", { exact: true }).selectOption("Shifokor");
  await page
    .getByPlaceholder("Mutaxassislikni kiriting", { exact: true })
    .fill("Booking specialty");
  const section = page.getByRole("region", {
    name: "Ish jadvali",
    exact: true,
  });
  await expect(
    section
      .getByLabel("Jadval mutaxassisligi")
      .locator("option")
      .filter({ hasText: "Booking specialty" }),
  ).toHaveCount(1);
  await section.getByLabel("Ish jadvalini saqlash").check();
  await section
    .getByLabel("Jadval mutaxassisligi")
    .selectOption({ label: "Booking specialty" });
  await section
    .getByLabel("Shifokor xizmati: Booking consultation", { exact: true })
    .check();
  await section
    .getByLabel("Shifokor xizmati: Booking inline additional service", {
      exact: true,
    })
    .check();
  await section
    .getByLabel("Qabul xonasi", { exact: true })
    .selectOption({ label: "B101" });
  await section.getByLabel("Ish kuni: Chorshanba", { exact: true }).check();
  await section.getByLabel("Ish kuni: Juma", { exact: true }).check();
  await section.getByLabel("Ish boshlanishi", { exact: true }).fill("18:00");
  await section.getByLabel("Ish tugashi", { exact: true }).fill("19:00");
  await section
    .getByLabel("Amal qilish boshlanish sanasi", { exact: true })
    .fill("2030-01-09");
  await section
    .getByLabel("Amal qilish tugash sanasi", { exact: true })
    .fill("2030-01-11");
  await section.getByLabel("Kunlarni alohida sozlash").check();
  await section.getByLabel("Juma boshlanish", { exact: true }).fill("18:15");
  await section.getByLabel("Juma tugash", { exact: true }).fill("19:15");
  // This synthetic account inherits cashier.view; preserve the existing required PIN flow.
  await page.getByLabel("Kassa PIN-kodi", { exact: true }).fill("7462");
  await page.getByLabel("PIN-kodni tasdiqlash", { exact: true }).fill("7462");
  let writeAttempts = 0,
    scheduleUpdates = 0;
  let schedulePosts = 0,
    doctorPosts = 0,
    employeePosts = 0;
  const bodies: any[] = [];
  page.on("request", (r) => {
    if (r.method() === "POST" && r.url().endsWith("/clinic/doctors"))
      doctorPosts++;
    if (r.method() === "POST" && r.url().endsWith("/clinic/employees"))
      employeePosts++;
  });
  await page.route("**/api/v1/clinic/schedules**", async (route) => {
    const method = route.request().method();
    if (!["POST", "PUT"].includes(method)) return route.continue();
    writeAttempts++;
    if (method === "POST") schedulePosts++;
    else scheduleUpdates++;
    bodies.push(route.request().postDataJSON());
    if ([2, 4].includes(writeAttempts))
      return route.fulfill({
        status: 503,
        json: { message: "Synthetic transient failure" },
      });
    return route.continue();
  });
  await section
    .getByRole("button", { name: "Jadvalni saqlash", exact: true })
    .click();
  await expect(section.getByRole("alert")).toContainText(
    "Jadval to‘liq saqlanmadi",
  );
  expect(schedulePosts).toBe(2);
  expect(doctorPosts).toBe(1);
  await section.getByLabel("Chorshanba tugash", { exact: true }).fill("19:15");
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(section.getByRole("alert")).toContainText(
    "Jadval to‘liq saqlanmadi",
  );
  expect(writeAttempts).toBe(4);
  await section.getByLabel("Chorshanba tugash", { exact: true }).fill("19:00");
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByLabel("Xodimlarni qidirish")).toBeVisible();
  expect(schedulePosts).toBe(4);
  expect(scheduleUpdates).toBe(2);
  expect(doctorPosts).toBe(1);
  expect(employeePosts).toBe(0);
  expect(bodies[0].weekday).toBe(3);
  expect(bodies[1].weekday).toBe(5);
  expect(bodies[2].weekday).toBe(3);
  expect(bodies[2].local_end).toBe("19:15");
  expect(bodies[4].weekday).toBe(3);
  expect(bodies[4].local_end).toBe("19:00");
  expect(bodies[5].weekday).toBe(5);
  expect(bodies[5].local_start).toBe("18:15");
  await page.reload();
  await page.getByRole("row").filter({ hasText: "inline clinician" }).click();
  await page.getByRole("button", { name: "Tahrirlash", exact: true }).click();
  await expect(section.locator(".employee-existing-rules")).toContainText(
    "Chorshanba",
  );
  await expect(section.locator(".employee-existing-rules")).toContainText(
    "Juma",
  );
  await expect(section.getByLabel("Ish jadvalini saqlash")).not.toBeChecked();
  await section.getByLabel("Ish jadvalini saqlash").check();
  await section
    .getByLabel("Shifokor xizmati: Booking inline additional service", {
      exact: true,
    })
    .uncheck();
  const doctorUpdate = page.waitForResponse(
    (r) =>
      r.request().method() === "PUT" && r.url().includes("/clinic/doctors/"),
  );

  await section
    .getByRole("button", {
      name: "Chorshanba kunini olib tashlash",
      exact: true,
    })
    .click();
  await section.getByLabel("Juma tugash", { exact: true }).fill("19:30");
  let updates = 0;
  page.on("request", (r) => {
    if (r.method() === "PUT" && r.url().includes("/clinic/schedules/"))
      updates++;
  });
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByLabel("Xodimlarni qidirish")).toBeVisible();
  expect(updates).toBe(1);
  const changedDoctor = await doctorUpdate;
  expect(changedDoctor.status()).toBe(200);
  expect((await changedDoctor.json()).service_ids).toHaveLength(1);
  expect(doctorPosts).toBe(1);
  await page.getByRole("row").filter({ hasText: "inline clinician" }).click();
  await page.getByRole("button", { name: "Tahrirlash", exact: true }).click();
  await expect(section.locator(".employee-existing-rules p")).toHaveCount(2);
  await section.screenshot({
    path: "/tmp/employee-inline-doctor-schedule.png",
  });
  await section
    .getByRole("button", { name: "Yangi jadval", exact: true })
    .click();
  await expect(
    section.getByLabel("Ish kuni: Chorshanba", { exact: true }),
  ).not.toBeChecked();
  await expect(section.getByLabel("Qabul xonasi", { exact: true })).toHaveValue(
    "",
  );
  await expect(section.locator(".employee-existing-rules p")).toHaveCount(2);
});

test("a new loginless doctor profile is retained when scheduling needs an account, then retry patches it", async ({
  page,
}) => {
  await page.goto("/");
  await page.getByLabel("Login", { exact: true }).fill("booking.doctor");
  await page
    .getByLabel("Parol", { exact: true })
    .fill("Synthetic-password-42!");
  await page.getByRole("button", { name: "Kirish", exact: true }).click();
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page
    .getByRole("button", { name: "Xodim qo‘shish", exact: true })
    .click();
  await page.getByLabel("Familiya *", { exact: true }).fill("InlineNoAccount");
  await page.getByLabel("Ism *", { exact: true }).fill("Synthetic");
  await page.getByLabel("Telefon *", { exact: true }).fill("+998 90 765 43 22");
  await page
    .getByRole("switch", { name: "Tizimga kirishga ruxsat berish" })
    .click();
  await page.getByLabel("Lavozim *", { exact: true }).selectOption("Shifokor");
  await page
    .getByPlaceholder("Mutaxassislikni kiriting", { exact: true })
    .fill("Booking specialty");
  const section = page.getByRole("region", {
    name: "Ish jadvali",
    exact: true,
  });
  await expect(
    section
      .getByLabel("Jadval mutaxassisligi")
      .locator("option")
      .filter({ hasText: "Booking specialty" }),
  ).toHaveCount(1);
  await section.getByLabel("Ish jadvalini saqlash").check();
  await section
    .getByLabel("Jadval mutaxassisligi")
    .selectOption({ label: "Booking specialty" });
  await section
    .getByLabel("Shifokor xizmati: Booking consultation", { exact: true })
    .check();
  await section
    .getByLabel("Qabul xonasi", { exact: true })
    .selectOption({ label: "B101" });
  await section.getByLabel("Ish kuni: Dushanba", { exact: true }).check();
  await section
    .getByLabel("Amal qilish boshlanish sanasi", { exact: true })
    .fill("2030-01-07");
  await section
    .getByLabel("Amal qilish tugash sanasi", { exact: true })
    .fill("2030-01-07");
  let posts = 0,
    patches = 0,
    doctorWrites = 0;
  page.on("request", (r) => {
    if (r.method() === "POST" && r.url().endsWith("/clinic/employees")) posts++;
    if (r.method() === "PATCH" && r.url().includes("/clinic/employees/"))
      patches++;
    if (r.method() === "POST" && r.url().endsWith("/clinic/doctors"))
      doctorWrites++;
  });
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByRole("alert").first()).toContainText(
    "faol login hisobi kerak",
  );
  expect(posts).toBe(1);
  expect(doctorWrites).toBe(0);
  await section.getByLabel("Ish jadvalini saqlash").uncheck();
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByLabel("Xodimlarni qidirish")).toBeVisible();
  expect(posts).toBe(1);
  expect(patches).toBe(1);
  expect(doctorWrites).toBe(0);
  await page.reload();
  await page.getByLabel("Xodimlarni qidirish").fill("InlineNoAccount");
  await expect(page.locator("tbody tr")).toHaveCount(1);
});
