/** Strict rich-text allowlist. No links, images, styles, events, or executable nodes. */
export function safeHtml(value: string) {
  const doc = new DOMParser().parseFromString(value, "text/html");
  const allowed = new Set([
    "P",
    "BR",
    "B",
    "STRONG",
    "I",
    "EM",
    "UL",
    "OL",
    "LI",
    "DIV",
  ]);
  for (const node of [...doc.body.querySelectorAll("*")]) {
    if (
      [
        "SCRIPT",
        "STYLE",
        "IFRAME",
        "OBJECT",
        "SVG",
        "MATH",
        "TEMPLATE",
      ].includes(node.tagName)
    ) {
      node.remove();
      continue;
    }
    for (const a of [...node.attributes]) node.removeAttribute(a.name);
    if (!allowed.has(node.tagName)) node.replaceWith(...node.childNodes);
  }
  return doc.body.innerHTML;
}
export const fileSize = (size: number) =>
  size >= 1024 * 1024
    ? `${(size / 1024 / 1024).toFixed(1)} MB`
    : `${Math.ceil(size / 1024)} KB`;
export function timestamp(value: string | null) {
  if (!value) return "Belgilanmagan";
  const d = new Date(value);
  return `${String(d.getDate()).padStart(2, "0")}.${String(d.getMonth() + 1).padStart(2, "0")}.${d.getFullYear()} · ${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
}
export function age(dob: string) {
  const d = new Date(`${dob}T12:00:00`),
    now = new Date();
  return (
    now.getFullYear() -
    d.getFullYear() -
    (now.getMonth() < d.getMonth() ||
    (now.getMonth() === d.getMonth() && now.getDate() < d.getDate())
      ? 1
      : 0)
  );
}
