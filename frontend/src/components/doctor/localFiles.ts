/** Local Blob operations. Never fetches/uploads files or substitutes a missing file. */
import workerUrl from "pdfjs-dist/build/pdf.worker.min.mjs?url";
export function downloadBlob(blob: Blob, name: string) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = name;
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 60000);
}
export async function printBlob(blob: Blob) {
  const popup = window.open("", "_blank");
  if (!popup) throw Error("Chop etish oynasiga brauzer ruxsat bermadi.");
  popup.opener = null;
  popup.document.title = "Blusser — lokal hujjat";
  const style = popup.document.createElement("style");
  style.textContent =
    "body{margin:0}img{display:block;max-width:100%;break-after:page}img:last-child{break-after:auto}@page{margin:12mm}";
  popup.document.head.append(style);
  let url = "";
  try {
    if (blob.type === "application/pdf") {
      const { getDocument, GlobalWorkerOptions } = await import("pdfjs-dist");
      GlobalWorkerOptions.workerSrc = workerUrl;
      const task = getDocument({
        data: new Uint8Array(await blob.arrayBuffer()),
        isEvalSupported: false,
      });
      try {
        const pdf = await task.promise;
        for (let i = 1; i <= pdf.numPages; i++) {
          const page = await pdf.getPage(i);
          const canvas = document.createElement("canvas");
          const viewport = page.getViewport({ scale: 1.4 });
          canvas.width = viewport.width;
          canvas.height = viewport.height;
          await page.render({ canvas, viewport }).promise;
          const image = popup.document.createElement("img");
          image.src = canvas.toDataURL();
          popup.document.body.append(image);
          await image.decode();
        }
      } finally {
        await task.destroy();
      }
    } else if (["image/png", "image/jpeg"].includes(blob.type)) {
      url = URL.createObjectURL(blob);
      const image = popup.document.createElement("img");
      image.src = url;
      popup.document.body.append(image);
      await image.decode();
    } else throw Error("Fayl formatini chop etib bo‘lmaydi.");
    popup.focus();
    popup.print();
  } catch (e) {
    popup.close();
    throw e;
  } finally {
    if (url) URL.revokeObjectURL(url);
  }
}
