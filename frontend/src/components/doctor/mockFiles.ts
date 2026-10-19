/** Actual synthetic PDF/JPEG blobs; never fetch or upload clinical documents. */
export function demoPdf(title: string): Blob {
  const ascii = (value: string) =>
    value
      .normalize("NFKD")
      .replace(/[‘’ʻʼ]/g, "'")
      .replace(/[^\x20-\x7e]/g, " ")
      .replace(/[()\\]/g, " ");
  const lines = title.split(" | ").flatMap((value) => {
    const words = ascii(value).split(/\s+/);
    const wrapped: string[] = [];
    let line = "";
    for (const word of words) {
      if (line.length + word.length > 68) {
        wrapped.push(line);
        line = "";
      }
      line += (line ? " " : "") + word;
    }
    if (line) wrapped.push(line);
    return wrapped;
  });
  const stream =
    `BT /F1 16 Tf 50 760 Td (Blusser - NAMUNAVIY HUJJAT) Tj 0 -36 Td /F1 12 Tf ` +
    lines
      .slice(0, 24)
      .map((line) => `(${line}) Tj 0 -24 Td `)
      .join("") +
    `0 -30 Td /F1 10 Tf (DEMO ONLY - no real medical record or advice.) Tj ET`;
  const objects = [
    "<< /Type /Catalog /Pages 2 0 R >>",
    "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
    "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>",
    "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`,
  ];
  let pdf = "%PDF-1.4\n";
  const offsets = [0];
  objects.forEach((o, i) => {
    offsets.push(pdf.length);
    pdf += `${i + 1} 0 obj\n${o}\nendobj\n`;
  });
  const start = pdf.length;
  pdf +=
    `xref\n0 6\n0000000000 65535 f \n` +
    offsets
      .slice(1)
      .map((n) => `${String(n).padStart(10, "0")} 00000 n \n`)
      .join("") +
    `trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${start}\n%%EOF`;
  return new Blob([pdf], { type: "application/pdf" });
}
export async function demoFile(title: string, format = "PDF"): Promise<Blob> {
  if (format !== "JPG") return demoPdf(title);
  const canvas = document.createElement("canvas");
  canvas.width = 900;
  canvas.height = 600;
  const ctx = canvas.getContext("2d")!;
  ctx.fillStyle = "#f6faff";
  ctx.fillRect(0, 0, 900, 600);
  ctx.fillStyle = "#0866ff";
  ctx.font = "36px sans-serif";
  ctx.fillText("Blusser — DEMO", 50, 100);
  ctx.fillStyle = "#18345c";
  ctx.font = "22px sans-serif";
  ctx.fillText("Synthetic attachment. No real medical data.", 50, 160);
  return new Promise((resolve, reject) =>
    canvas.toBlob(
      (b) => (b ? resolve(b) : reject(new Error("Demo rasm yaratilmagan."))),
      "image/jpeg",
    ),
  );
}
export async function fileAction(
  title: string,
  format: string,
  action: "view" | "download",
) {
  const blob = await demoFile(title, format);
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  if (action === "download")
    a.download = `blusser-demo.${format === "JPG" ? "jpg" : "pdf"}`;
  else {
    a.target = "_blank";
    a.rel = "noopener noreferrer";
  }
  document.body.append(a);
  a.click();
  a.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 60000);
}
export function printDemo(title: string, lines: string[]) {
  const popup = window.open("", "_blank");
  if (!popup) return false;
  popup.opener = null;
  popup.document.title = title;
  const style = popup.document.createElement("style");
  style.textContent =
    "body{font:16px Arial,sans-serif;padding:30px;color:#14264a}h1{font-size:24px}pre{white-space:pre-wrap;line-height:1.6}";
  const h = popup.document.createElement("h1");
  h.textContent = title;
  const pre = popup.document.createElement("pre");
  pre.textContent = [
    "BLUSSER — FRONTEND NAMOYISHI. Haqiqiy tibbiy hujjat emas.",
    ...lines,
  ].join("\n\n");
  popup.document.head.append(style);
  popup.document.body.append(h, pre);
  popup.document.close();
  popup.focus();
  popup.print();
  return true;
}
