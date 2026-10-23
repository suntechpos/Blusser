import { test, expect } from "@playwright/test";
import { editorSession } from "./access-editor.fixture";

test("PIN card filters digits, supports editing, visibility and confirmation without changing payload", async ({
  page,
}) => {
  const s = await editorSession(page);
  await page.getByRole("button", { name: "Xodimlar", exact: true }).click();
  await page
    .getByRole("button", { name: "Xodim qo‘shish", exact: true })
    .click();
  const card = page.getByRole("region", { name: "Kassa PIN sozlamalari" });
  await expect(card).toHaveCount(0);
  const cashier = page.getByRole("checkbox", {
    name: "Bo‘lim: Kassa",
    exact: true,
  });
  await cashier.check();
  const pin = page.getByLabel("Kassa PIN-kodi", { exact: true });
  const confirmation = page.getByLabel("PIN-kodni tasdiqlash", { exact: true });
  await expect(pin).toHaveAttribute("type", "password");
  await pin.fill("a1b2!345");
  await expect(pin).toHaveValue("1234");
  await pin.press("End");
  await pin.press("Backspace");
  await expect(pin).toHaveValue("123");
  await pin.press("4");
  await page.getByRole("button", { name: "PINni ko‘rsatish" }).click();
  await expect(pin).toHaveAttribute("type", "text");
  await expect(card.locator(".employee-pin-cell").first()).toHaveText("1");
  await confirmation.fill("1235");
  await expect(card.getByText("PIN-kodlar mos emas.")).toBeVisible();
  await confirmation.fill("1234");
  await expect(card.getByText("PIN-kodlar mos emas.")).toHaveCount(0);
  await page.getByRole("button", { name: "PINni yashirish" }).click();
  await expect(pin).toHaveAttribute("type", "password");
  await page.context().grantPermissions(["clipboard-read", "clipboard-write"]);
  await page.evaluate(() => navigator.clipboard.writeText("9087"));
  await pin.selectText();
  await pin.press("Control+V");
  await expect(pin).toHaveValue("9087");
  await cashier.uncheck();
  await expect(card).toHaveCount(0);
  await cashier.check();
  await expect(pin).toHaveValue("");
  await page.setViewportSize({ width: 390, height: 844 });
  await expect(card).toBeVisible();
  expect(await card.evaluate((el) => el.scrollWidth <= el.clientWidth)).toBe(
    true,
  );
  await page.getByLabel("Familiya *", { exact: true }).fill("PinUI");
  await page.getByLabel("Ism *", { exact: true }).fill("Worker");
  await page.getByLabel("Telefon *", { exact: true }).fill("+998 90 123 45 67");
  await page
    .getByRole("switch", { name: "Tizimga kirishga ruxsat berish" })
    .click();
  await pin.fill("12");
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(card.getByText("4 raqamli PIN-kod kiriting.")).toBeVisible();
  expect(
    s.requests.filter(
      (r) => r.method === "POST" && r.path.endsWith("/employees"),
    ),
  ).toHaveLength(0);
  await pin.fill("9087");
  await confirmation.fill("9087");
  await page.getByRole("button", { name: "Saqlash", exact: true }).click();
  await expect(page.getByLabel("Xodimlarni qidirish")).toBeVisible();
  const request = s.requests.find(
    (r) => r.method === "POST" && r.path.endsWith("/employees"),
  )!;
  expect(request.body.cashier_pin).toBe("9087");
  expect(request.body.cashier_pin_confirmation).toBe("9087");
});
