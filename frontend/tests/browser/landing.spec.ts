import { test, expect, type Page } from "@playwright/test";

/** Public-page fixtures exercise existing navigation; no production auth changes. */
async function guest(page: Page) {
  let signedIn = false;
  const unexpected: string[] = [];
  const user = {
    id: "landing-test-user",
    login: "landing.test",
    active_tenant_id: "landing-clinic",
    must_change_password: false,
    is_platform_admin: false,
  };
  await page.route("**/api/v1/**", async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === "/api/v1/auth/login") signedIn = true;
    if (path === "/api/v1/auth/me" || path === "/api/v1/auth/login")
      return route.fulfill(signedIn ? { json: user } : { status: 401 });
    if (path === "/api/v1/auth/clinics")
      return route.fulfill({
        json: {
          data: [
            {
              id: "landing-clinic",
              name: "Landing test clinic",
              role: "Employee",
            },
          ],
        },
      });
    if (path === "/api/v1/clinic/context")
      return route.fulfill({
        json: {
          grants: { "dashboard.view": "entire_clinic" },
          branches: [],
          timezone: "Asia/Tashkent",
        },
      });
    unexpected.push(path);
    return route.fulfill({
      status: 500,
      json: { message: "Unexpected landing test API request" },
    });
  });
  await page.route("**/sanctum/csrf-cookie", (route) =>
    route.fulfill({ status: 204 }),
  );
  // External decorative photography is not part of the logo or interaction tests.
  await page.route("https://images.unsplash.com/**", (route) => route.abort());
  await page.goto("/");
  await expect(page.locator(".lp h1")).toBeVisible();
  return unexpected;
}

const shift = (page: Page, selector: string) =>
  page.locator(selector).evaluate((el) => {
    const transform = getComputedStyle(el).transform;
    return transform === "none" ? 0 : new DOMMatrixReadOnly(transform).m41;
  });
async function scrollTo(page: Page, top: number) {
  await page.evaluate(
    (top) => window.scrollTo({ top, behavior: "instant" }),
    top,
  );
}

test("original logo loads in header and footer without stretching", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  const unexpected = await guest(page);
  const logos = page.getByAltText("Blusser — Medical Software", {
    exact: true,
  });
  await expect(logos).toHaveCount(2);
  for (const logo of await logos.all()) {
    await logo.scrollIntoViewIfNeeded();
    await expect
      .poll(() =>
        logo.evaluate(
          (el: HTMLImageElement) => el.complete && el.naturalWidth > 0,
        ),
      )
      .toBe(true);
    const dimensions = await logo.evaluate((el: HTMLImageElement) => ({
      natural: el.naturalWidth / el.naturalHeight,
      rendered:
        el.getBoundingClientRect().width / el.getBoundingClientRect().height,
    }));
    expect(dimensions.rendered).toBeCloseTo(dimensions.natural, 1);
  }
  await scrollTo(page, 0);
  await page.screenshot({
    path: "/tmp/landing-desktop.png",
    fullPage: true,
    animations: "disabled",
  });
  expect(unexpected).toEqual([]);
});

test("feature rows follow scroll in opposite directions and reverse without losing cards", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.emulateMedia({ reducedMotion: "no-preference" });
  await guest(page);
  const quick = [
    "Bulutli tizim",
    "Ko‘p filial",
    "Rol va ruxsatlar",
    "Real vaqt hisobotlari",
    "Qo‘llab-quvvatlash",
  ];
  const modules = [
    "Qabul jadvali",
    "Bemorlar bazasi",
    "Shifokorlar ishi",
    "Laboratoriya",
    "Kassa va moliya",
    "Filiallar boshqaruvi",
  ];
  await expect(page.locator(".q5 .qc b")).toHaveText(quick);
  await expect(page.locator(".q6 .qc b")).toHaveText(modules);
  const initialTop = await shift(page, ".q5"),
    initialBottom = await shift(page, ".q6");
  await scrollTo(page, 300);
  await expect.poll(() => shift(page, ".q5")).toBeLessThan(initialTop - 1);
  await expect
    .poll(() => shift(page, ".q6"))
    .toBeGreaterThan(initialBottom + 1);
  const forward = [await shift(page, ".q5"), await shift(page, ".q6")];
  await scrollTo(page, 0);
  await expect.poll(() => shift(page, ".q5")).toBeGreaterThan(forward[0]! + 1);
  await expect.poll(() => shift(page, ".q6")).toBeLessThan(forward[1]! - 1);
  await scrollTo(page, 100000);
  for (const selector of [".q5", ".q6"]) {
    expect(Math.abs(await shift(page, selector))).toBeLessThan(150);
    const visibleWidths = await page.locator(selector).evaluate((el) => {
      const row = el.getBoundingClientRect(),
        clip = el.parentElement!.getBoundingClientRect();
      return {
        left: row.left,
        right: row.right,
        clipLeft: clip.left,
        clipRight: clip.right,
      };
    });
    expect(visibleWidths.left).toBeLessThanOrEqual(visibleWidths.clipLeft + 1);
    expect(visibleWidths.right).toBeGreaterThanOrEqual(
      visibleWidths.clipRight - 1,
    );
  }
  await expect(page.locator(".q5 .qc")).toHaveCount(5);
  await expect(page.locator(".q6 .qc")).toHaveCount(6);
});

test("reveals finish visibly after scrolling and refresh", async ({ page }) => {
  await page.addInitScript(() => {
    const records: { text: string; delay: number; duration: number }[] = [];
    Reflect.set(window, "landingMotion", records);
    const animate = Element.prototype.animate;
    Element.prototype.animate = function (keyframes, options) {
      records.push({
        text: this.textContent?.trim() ?? "",
        delay: typeof options === "object" ? Number(options.delay ?? 0) : 0,
        duration:
          typeof options === "number"
            ? options
            : Number(options?.duration ?? 0),
      });
      return animate.call(this, keyframes, options);
    };
  });
  await page.setViewportSize({ width: 1440, height: 1000 });
  await guest(page);
  await expect
    .poll(() => page.locator("[data-reveal]").count())
    .toBeGreaterThan(5);
  const pricing = page.locator("#pricing");
  await pricing.scrollIntoViewIfNeeded();
  await expect(
    pricing.getByRole("heading", { name: "Tariflar", exact: true }),
  ).toBeVisible();
  await expect
    .poll(() =>
      pricing
        .getByRole("heading", { name: "Tariflar", exact: true })
        .evaluate((el) => getComputedStyle(el).opacity),
    )
    .toBe("1");
  await expect
    .poll(() =>
      page.evaluate(() => {
        const records = Reflect.get(window, "landingMotion") as {
          text: string;
          delay: number;
          duration: number;
        }[];
        const title = records.some(
          (record) =>
            record.text === "Tariflar" &&
            record.duration >= 400 &&
            record.duration <= 800,
        );
        const plans = records.filter((record) =>
          /^(Start|Eng ommabop|Standard|Enterprise)/.test(record.text),
        );
        return (
          title &&
          plans.length === 3 &&
          new Set(plans.map((record) => record.delay)).size === 3
        );
      }),
    )
    .toBe(true);
  await page.reload();
  await expect(page.locator(".lp")).toBeVisible();
  await expect(
    pricing.getByRole("heading", { name: "Tariflar", exact: true }),
  ).toBeVisible();
  await expect
    .poll(() => pricing.evaluate((el) => getComputedStyle(el).opacity))
    .toBe("1");
});

test("missing IntersectionObserver leaves public content visible", async ({
  page,
}) => {
  await page.addInitScript(() =>
    Object.defineProperty(window, "IntersectionObserver", {
      value: undefined,
      configurable: true,
    }),
  );
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await guest(page);
  for (const selector of [
    ".hero",
    "#features",
    "#pricing",
    "#faq",
    ".cta",
    ".ft",
  ]) {
    await expect(page.locator(selector)).toBeVisible();
    await expect(page.locator(selector)).toHaveCSS("opacity", "1");
  }
  expect(errors).toEqual([]);
});

test("reduced motion disables movement while content and anchor navigation remain usable", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.emulateMedia({ reducedMotion: "reduce" });
  await guest(page);
  await scrollTo(page, 300);
  expect(await shift(page, ".q5")).toBe(0);
  expect(await shift(page, ".q6")).toBe(0);
  await expect(page.locator("#faq")).toHaveCSS("opacity", "1");
  expect(await page.evaluate(() => document.getAnimations().length)).toBe(0);
  await page
    .locator(".nav")
    .getByRole("button", { name: "Tariflar", exact: true })
    .click();
  await expect(page.locator("#pricing")).toBeInViewport();
});

test("mobile preserves menu, FAQ, card counts and zero horizontal overflow", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await guest(page);
  await page.getByRole("button", { name: "Menyu", exact: true }).click();
  await page
    .locator(".mob")
    .getByRole("button", { name: "FAQ", exact: true })
    .click();
  await expect(page.locator(".mob")).toHaveCount(0);
  const question = page.getByRole("button", {
    name: "Demo olish mumkinmi?",
    exact: true,
  });
  await question.click();
  await expect(question).toHaveAttribute("aria-expanded", "true");
  await expect(
    page.getByText(
      "Ha, “Demo so‘rash” tugmasi orqali murojaat qoldiring va biz siz bilan bog‘lanamiz.",
      { exact: true },
    ),
  ).toBeVisible();
  expect(await shift(page, ".q5")).toBe(0);
  expect(await shift(page, ".q6")).toBe(0);
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
  await expect(page.locator(".q5 .qc")).toHaveCount(5);
  await expect(page.locator(".q6 .qc")).toHaveCount(6);
  await scrollTo(page, 0);
  await page.screenshot({
    path: "/tmp/landing-mobile.png",
    fullPage: true,
    animations: "disabled",
  });
  await page.setViewportSize({ width: 768, height: 1024 });
  await scrollTo(page, 300);
  expect(await shift(page, ".q5")).toBe(0);
  expect(await shift(page, ".q6")).toBe(0);
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
});

test("existing landing to login to authenticated clinic navigation is preserved", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  const unexpected = await guest(page);
  await page
    .locator(".hd")
    .getByRole("button", { name: "Tizimga kirish", exact: true })
    .click();
  await expect(page).toHaveURL(/\/login$/);
  await expect(
    page.getByRole("heading", { name: "Xush kelibsiz!", exact: true }),
  ).toBeVisible();
  await page.getByLabel("Login", { exact: true }).fill("landing.test");
  await page
    .getByLabel("Parol", { exact: true })
    .fill("Synthetic-password-42!");
  await page.getByRole("button", { name: "Kirish", exact: true }).click();
  await expect(page.locator(".clinic-sidebar")).toBeVisible();
  await expect(page).toHaveURL(/\/$/);
  await expect(page.locator(".lp")).toHaveCount(0);
  expect(unexpected).toEqual([]);
});
