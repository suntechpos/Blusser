import { watch, type Directive } from "vue";
import { session, can } from "./session";
/** Demo-only interaction boundary. Real endpoints independently authorize every command. */
const normalize = (s: string) =>
  s
    .toLocaleLowerCase()
    .replace(/[‘’ʻʼ`]/g, "'")
    .replace(/\s+/g, " ")
    .trim();
const policies: Record<string, { write: string; rules: [RegExp, string][] }> = {
  cashier: {
    write: "cashier.pay",
    rules: [
      [/smena|kassani ochish|kassani yopish/, "cashier.shift"],
      [/chegirma/, "cashier.discount"],
      [/qaytarish|pul qaytar/, "cashier.refund"],
      [/eksport|chek|tarix|pdf|chop|yuklab/, "cashier.history"],
      [/kassaga kirish|^kirish$|keyingi bemor/, "cashier.view"],
    ],
  },
  workspace: {
    write: "workspace.consult",
    rules: [
      [/retsept/, "workspace.prescribe"],
      [/yo'llanma/, "workspace.refer"],
      [/fayl|yuklash/, "workspace.upload"],
      [/yakunla|tugat/, "workspace.complete"],
      [/pdf|chop|yuklab|eksport/, "workspace.export"],
    ],
  },
  laboratory: {
    write: "laboratory.result",
    rules: [
      [/namuna olish|namuna olingan/, "laboratory.sample"],
      [/boshlash/, "laboratory.start"],
      [/qayta ishlash/, "laboratory.return"],
      [/tekshirishga yubor/, "laboratory.review"],
      [/tasdiq/, "laboratory.confirm"],
      [/tuzatish/, "laboratory.amend"],
      [/pdf|chop|yuklab|eksport/, "laboratory.export"],
    ],
  },
  diagnostics: {
    write: "diagnostics.result",
    rules: [
      [/boshlash/, "diagnostics.start"],
      [/tasdiq/, "diagnostics.confirm"],
      [/tuzatish/, "diagnostics.amend"],
      [/pdf|chop|yuklab|eksport/, "diagnostics.export"],
    ],
  },
  services: {
    write: "services.update",
    rules: [
      [/kategoriya.*qo'sh|yangi kategoriya/, "categories.create"],
      [/kategoriya/, "categories.update"],
      [/xizmat.*qo'sh|yangi xizmat/, "services.create"],
      [/faolsiz|faollash/, "services.delete"],
      [/eksport|csv/, "categories.export"],
    ],
  },
  rooms: {
    write: "rooms.update",
    rules: [
      [/bo'lim.*qo'sh|yangi bo'lim/, "departments.create"],
      [/xona.*qo'sh|yangi xona/, "rooms.create"],
      [/bo'lim/, "departments.update"],
    ],
  },
  reception: {
    write: "appointments.create",
    rules: [
      [/navbatsiz/, "visits.create"],
      [/kelgan|^keldi/, "appointments.check_in"],
    ],
  },
  appointments: {
    write: "appointments.update",
    rules: [
      [/qabulga yoz|qabul yarat/, "appointments.create"],
      [/bekor qilish/, "appointments.cancel"],
      [/eksport/, "appointments.export"],
    ],
  },
  queues: {
    write: "queues.call",
    rules: [
      [/qabulga o'tkaz|boshlash/, "queues.start"],
      [/jonli navbat qo'sh/, "queues.create"],
      [/oldindan yoz/, "appointments.create"],
    ],
  },
  display: {
    write: "display.update",
    rules: [
      [/qo'sh|yangi ekran/, "display.create"],
      [/o'chirish/, "display.delete"],
    ],
  },
};
export function actionPermission(
  module: string,
  element: HTMLElement,
): string | null {
  const explicit = element.getAttribute("data-permission");
  if (explicit) return explicit;
  const p = policies[module];
  if (!p) return null;
  const label = normalize(
    element.getAttribute("aria-label") ??
      element.getAttribute("title") ??
      element.textContent ??
      "",
  );
  if (
    /^(bekor qilish|yopish|ortga|orqaga|ro'yxatga|bosh sahifaga|shu yerda qolish|qayta urinish|saqlamasdan|o'zgarishlarni bekor)/.test(
      label,
    ) &&
    module !== "appointments"
  )
    return null;
  const context = normalize(
    element
      .closest("dialog,[role=dialog],.ap-modal,.tb-dialog")
      ?.querySelector("h2")?.textContent ?? "",
  );
  if (/^(bekor qilish|yopish)$/.test(label) && context) return null;
  for (const [match, code] of p.rules)
    if (match.test(label + " " + context)) return code;
  if (
    /ko'rish|oldindan|filtr|qidir|tanlash|barchasi|barcha|^umumiy|^navbat|^bugun|^kecha|^ertaga|^bemor|^natijalar|^ish jadvali|^versiya|^xizmatlar$|^kategoriyalar$|^to'lovlar tarixi$|^qaytarishlar$|^(yopish|bekor qilish|ortga|orqaga|ro'yxatga)|^\d+$/.test(
      label,
    )
  )
    return null;
  if (element.matches("input[type=file]"))
    return module === "workspace" ? "workspace.upload" : p.write;
  if (!label && element.matches("button") && element.querySelector("svg")) {
    const icon = element.querySelector("svg")?.getAttribute("class") ?? "";
    if (/eye|chevron|search|zoom|minus|plus|x\b|more|ellipsis/.test(icon))
      return null;
    if (/download|printer/.test(icon)) return `${module}.export`;
  }
  return p.write;
}
const cleanup = new WeakMap<HTMLElement, () => void>();
export const accessSurface: Directive<HTMLElement, string> = {
  mounted(root, binding) {
    const denied = (el: HTMLElement) => {
      const code = actionPermission(binding.value, el);
      return !!code && !can(code);
    };
    const update = () => {
      root
        .querySelectorAll<HTMLElement>(
          "button,a[download],input[type=file],[data-permission]",
        )
        .forEach((el) => {
          const off = denied(el);
          el.hidden = off;
          el.dataset.accessDenied = String(off);
          el.setAttribute("aria-disabled", String(off));
        });
    };
    const guard = (e: Event) => {
      const el = (e.target as Element)?.closest<HTMLElement>(
        "button,a[download],input[type=file],form,[data-permission]",
      );
      if (el && denied(el)) {
        e.preventDefault();
        e.stopImmediatePropagation();
      }
    };
    const observer = new MutationObserver(update);
    observer.observe(root, {
      childList: true,
      subtree: true,
      characterData: true,
    });
    const stop = watch(() => session.grants, update, { deep: true });
    root.addEventListener("click", guard, true);
    root.addEventListener("submit", guard, true);
    update();
    cleanup.set(root, () => {
      observer.disconnect();
      stop();
      root.removeEventListener("click", guard, true);
      root.removeEventListener("submit", guard, true);
    });
  },
  unmounted(root) {
    cleanup.get(root)?.();
    cleanup.delete(root);
  },
};
