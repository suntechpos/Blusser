import type {
  DiagnosticReferral,
  DiagnosticExam,
  ResultVersion,
} from "../../mocks/diagnostics";
import { plainText } from "../../mocks/diagnostics";
/** Raster pages preserve Uzbek glyphs without an external font or network dependency. */
export async function resultPdf(
  r: DiagnosticReferral,
  e: DiagnosticExam,
  v: ResultVersion,
  historical = false,
) {
  const lines = [
    "Blusser — NAMUNAVIY MA’LUMOTLAR",
    historical ? "TARIXIY VERSIYA" : "Tasdiqlangan natija",
    r.testName,
    `Bemor: ${r.patientName} · ${r.patientId}`,
    `Tug‘ilgan sana: ${r.dob}`,
    `Yo‘llanma: ${r.number}`,
    `Tekshiruv: ${e.id} · Natija: ${e.resultId}`,
    `Versiya: ${v.number} · ${v.confirmedAt}`,
    `Tasdiqlagan: ${v.author.name} · ${v.author.specialty}`,
    `Tuzatish sababi: ${v.reason || "—"}`,
    "Tekshiruv tavsifi",
    plainText(v.description),
    "Xulosa",
    plainText(v.conclusion),
    "Fayllar: " + (v.files.map((f) => f.name).join(", ") || "—"),
  ];
  return textPdf(lines);
}
/** Shared plain-text report renderer; preserves existing diagnostic export output. */
export async function textPdf(lines: string[]) {
  const c = document.createElement("canvas");
  c.width = 1240;
  c.height = 1754;
  const x = c.getContext("2d");
  if (!x) throw Error("PDF yaratib bo‘lmadi.");
  x.font = "24px sans-serif";
  const wrapped: string[] = [];
  for (const line of lines)
    for (const para of line.split("\n")) {
      let row = "";
      for (const character of para) {
        if (x.measureText(row + character).width > 1090) {
          const space = row.lastIndexOf(" ");
          if (space > 0) {
            wrapped.push(row.slice(0, space));
            row = row.slice(space + 1) + character;
          } else {
            wrapped.push(row);
            row = character;
          }
        } else row += character;
      }
      wrapped.push(row);
      wrapped.push("");
    }
  const pages: Uint8Array[] = [];
  for (let start = 0; start < wrapped.length; start += 43) {
    x.fillStyle = "white";
    x.fillRect(0, 0, c.width, c.height);
    x.fillStyle = "#101c43";
    x.font = "24px sans-serif";
    wrapped
      .slice(start, start + 43)
      .forEach((line, i) => x.fillText(line, 70, 90 + i * 36));
    x.fillText(`${pages.length + 1} · NAMUNAVIY MA’LUMOTLAR`, 70, 1690);
    const blob = await new Promise<Blob>((resolve, reject) =>
      c.toBlob(
        (b) => (b ? resolve(b) : reject(Error("PDF xatosi"))),
        "image/jpeg",
        0.92,
      ),
    );
    pages.push(new Uint8Array(await blob.arrayBuffer()));
  }
  const enc = new TextEncoder(),
    parts: Uint8Array[] = [],
    offsets = [0];
  let length = 0;
  const add = (s: string | Uint8Array) => {
    const a = typeof s === "string" ? enc.encode(s) : s;
    parts.push(a);
    length += a.length;
  };
  const object = (id: number, body: string) => {
    offsets[id] = length;
    add(`${id} 0 obj\n${body}\nendobj\n`);
  };
  add("%PDF-1.4\n");
  object(1, "<< /Type /Catalog /Pages 2 0 R >>");
  object(
    2,
    `<< /Type /Pages /Count ${pages.length} /Kids [${pages.map((_, i) => `${3 + i * 3} 0 R`).join(" ")}] >>`,
  );
  pages.forEach((jpeg, i) => {
    const id = 3 + i * 3;
    object(
      id,
      `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im ${id + 1} 0 R >> >> /Contents ${id + 2} 0 R >>`,
    );
    offsets[id + 1] = length;
    add(
      `${id + 1} 0 obj\n<< /Type /XObject /Subtype /Image /Width 1240 /Height 1754 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${jpeg.length} >>\nstream\n`,
    );
    add(jpeg);
    add("\nendstream\nendobj\n");
    const stream = "q 595 0 0 842 0 0 cm /Im Do Q";
    object(
      id + 2,
      `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`,
    );
  });
  const cross = length;
  add(`xref\n0 ${offsets.length}\n0000000000 65535 f \n`);
  for (const offset of offsets.slice(1))
    add(`${String(offset).padStart(10, "0")} 00000 n \n`);
  add(
    `trailer\n<< /Size ${offsets.length} /Root 1 0 R >>\nstartxref\n${cross}\n%%EOF`,
  );
  return new Blob(parts as BlobPart[], { type: "application/pdf" });
}
