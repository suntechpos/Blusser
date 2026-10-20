import type { Attachment } from "../../mocks/diagnostics";
export async function readAttachments(
  files: File[],
): Promise<{ accepted: Attachment[]; errors: string[] }> {
  const accepted: Attachment[] = [],
    errors: string[] = [];
  for (const file of files) {
    if (file.size > 20 * 1024 * 1024) {
      errors.push(`${file.name}: har bir fayl 20 MB gacha bo‘lsin.`);
      continue;
    }
    if (!file.size) {
      errors.push(`${file.name}: fayl bo‘sh.`);
      continue;
    }
    const b = new Uint8Array(await file.slice(0, 8).arrayBuffer());
    const mime =
      b[0] === 0xff && b[1] === 0xd8 && b[2] === 0xff
        ? "image/jpeg"
        : b[0] === 0x89 && b[1] === 0x50 && b[2] === 0x4e && b[3] === 0x47
          ? "image/png"
          : new TextDecoder().decode(b.slice(0, 5)) === "%PDF-"
            ? "application/pdf"
            : "";
    const extension = file.name.toLowerCase().split(".").at(-1);
    const valid =
      mime === "image/jpeg"
        ? ["jpg", "jpeg"].includes(extension ?? "")
        : mime === "image/png"
          ? extension === "png"
          : mime === "application/pdf"
            ? extension === "pdf"
            : false;
    if (!valid || (file.type && file.type !== mime)) {
      errors.push(`${file.name}: faqat JPG, PNG yoki PDF fayl tanlang.`);
      continue;
    }
    accepted.push({
      id: crypto.randomUUID(),
      name: file.name,
      blob: file,
      size: file.size,
      mime,
    });
  }
  return { accepted, errors };
}
