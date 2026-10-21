import type {
  LabReferral,
  LabJob,
  LabVersion,
  LabSample,
} from "../../mocks/laboratory";
import { testNames, referenceLabel, rowFlag } from "../../mocks/laboratory";
import { textPdf } from "../diagnostics/report";
import { timestamp } from "../diagnostics/content";
export function labPdf(
  r: LabReferral,
  j: LabJob,
  v: LabVersion,
  historical = false,
) {
  return textPdf([
    "Blusser — LABORATORIYA NATIJASI — NAMUNAVIY MA’LUMOTLAR",
    historical ? "TARIXIY NUSXA" : "Amaldagi tasdiqlangan natija",
    `Bemor: ${r.patientName} · ${r.patientId} · ${r.dob} · ${r.gender}`,
    `Tahlil: ${testNames(r)}`,
    `Yo‘llanma: ${r.number} · ${r.id}`,
    `Natija: ${j.resultId} · ${v.number}-versiya`,
    ...j.samples.map(
      (s) =>
        `Namuna: ${s.id} · ${s.type} · ${timestamp(s.collectedAt)} · ${s.collector.name}`,
    ),
    `Natijani kiritgan: ${v.enteredBy.name}`,
    `Tekshirishga yuborgan: ${v.submittedBy.name} · ${timestamp(v.submittedAt)}`,
    `Tasdiqlagan: ${v.confirmedBy.name} · ${timestamp(v.confirmedAt)}`,
    `Tuzatish sababi: ${v.reason || "—"}`,
    "Ko‘rsatkich | Natija | Birlik | Me’yor oralig‘i | Belgi",
    ...v.rows.map(
      (row) =>
        `${row.name} | ${row.value || "—"} | ${row.unit} | ${referenceLabel(row.reference)} | ${{ normal: "Me’yorda", low: "Past", high: "Yuqori", unknown: "Belgilanmagan", qualitative: "Sifat natijasi", empty: "—", invalid: "Noto‘g‘ri format" }[rowFlag(row)]}`,
    ),
    `Laborant izohi: ${v.note || "—"}`,
    `Fayllar: ${v.files.map((f) => f.name).join(", ") || "—"}`,
  ]);
}
import { specimenBarcode } from "./barcode";
export { specimenBarcode } from "./barcode";
export async function labelPng(r: LabReferral, s: LabSample) {
  const barcode = specimenBarcode(s.id);
  const ns = "http://www.w3.org/2000/svg",
    svg = document.createElementNS(ns, "svg");
  svg.setAttribute("width", "900");
  svg.setAttribute("height", "420");
  svg.setAttribute("viewBox", "0 0 900 420");
  function text(value: string, x: number, y: number, size = 24) {
    const node = document.createElementNS(ns, "text");
    node.setAttribute("x", String(x));
    node.setAttribute("y", String(y));
    node.setAttribute("font-family", "Arial,sans-serif");
    node.setAttribute("font-size", String(size));
    node.textContent = value;
    svg.append(node);
  }
  const bg = document.createElementNS(ns, "rect");
  bg.setAttribute("width", "900");
  bg.setAttribute("height", "420");
  bg.setAttribute("fill", "white");
  svg.append(bg);
  text("Blusser · NAMUNA YORLIG‘I · LOKAL NAMUNA", 30, 40);
  text(`${r.patientName} · ${r.patientId}`, 30, 83, 27);
  text(`${s.type} · ${timestamp(s.collectedAt)}`, 30, 122);
  const path = document.createElementNS(ns, "path");
  path.setAttribute("d", barcode.path);
  path.setAttribute(
    "transform",
    `translate(30 155) scale(${840 / barcode.width} 2.3)`,
  );
  svg.append(path);
  text(s.id, 30, 338, 30);
  text("Namuna hujjati — haqiqiy klinik foydalanish uchun emas", 30, 392, 20);
  const url = URL.createObjectURL(
    new Blob([new XMLSerializer().serializeToString(svg)], {
      type: "image/svg+xml",
    }),
  );
  try {
    const image = new Image();
    image.src = url;
    await image.decode();
    const c = document.createElement("canvas");
    c.width = 900;
    c.height = 420;
    const ctx = c.getContext("2d");
    if (!ctx) throw Error("Yorliq yaratib bo‘lmadi.");
    ctx.drawImage(image, 0, 0);
    return await new Promise<Blob>((resolve, reject) =>
      c.toBlob(
        (b) => (b ? resolve(b) : reject(Error("Yorliq yaratilmadi."))),
        "image/png",
      ),
    );
  } finally {
    URL.revokeObjectURL(url);
  }
}
