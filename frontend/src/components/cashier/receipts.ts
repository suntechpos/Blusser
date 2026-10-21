import {
  cashierConfig,
  methodNames,
  method,
  type Payment,
  type Refund,
} from "../../mocks/cashier";
import { money } from "../../mocks/servicesCatalog";
import { timestamp } from "../diagnostics/content";
import { textPdf } from "../diagnostics/report";
export function receiptLines(record: Payment | Refund, payment?: Payment) {
  const refund = "paymentId" in record;
  const lines = [
    cashierConfig.clinic.toUpperCase(),
    refund ? "QAYTARISH CHEKI" : "TO‘LOV CHEKI",
    `Chek: ${record.id}`,
    `Hisob: ${record.invoiceId}`,
    `Sana: ${timestamp(record.at)}`,
    `Kassir: ${record.cashier.name}`,
    `Bemor: ${record.patientName}`,
    `Bemor ID: ${record.patientId}`,
  ];
  if (refund) {
    lines.push(
      `Asl chek: ${record.paymentId}`,
      ...record.lines.map((l) => `${l.name}: ${money(l.amount)}`),
      `QAYTARILDI: ${money(record.amount)}`,
      `Usul: ${methodNames[method(record)]}`,
      `Naqd: ${money(record.cash)} · Karta: ${money(record.card)}`,
      `Sabab: ${{ cancel: "Xizmat bekor qilindi", duplicate: "Takroriy to‘lov", other: "Boshqa" }[record.reason]}`,
      `Izoh: ${record.note || "—"}`,
    );
    if (payment) lines.push(`Asl to‘lov: ${money(payment.amount)}`);
  } else
    lines.push(
      ...record.lines.flatMap((l) => [
        l.name,
        `${l.qty} × ${money(l.price)} = ${money(l.qty * l.price)}`,
      ]),
      `Xizmatlar: ${money(record.gross)}`,
      `Chegirma: ${money(record.discount)}`,
      `Hisob jami (shu paytda): ${money(record.total)}`,
      `Aynan shu to‘lov: ${money(record.amount)}`,
      `Usul: ${methodNames[method(record)]}`,
      `Naqd qism: ${money(record.cash)}`,
      `Karta qism: ${money(record.card)}`,
      `Berilgan naqd: ${money(record.tendered)}`,
      `QAYTIM: ${money(record.change)}`,
      `Oldin to‘langan (shu paytda): ${money(record.paidBefore)}`,
      `Qolgan qarz (shu paytda): ${money(record.debtAfter)}`,
    );
  return [
    ...lines,
    "Sog‘lig‘ingiz uchun rahmat!",
    "Namunaviy chek. Fiskal hujjat emas",
  ];
}
export const receiptPdf = (record: Payment | Refund, payment?: Payment) =>
  textPdf(receiptLines(record, payment), { receipt: true });
export function printReceipt(lines: string[]) {
  const popup = window.open("", "_blank");
  if (!popup)
    throw Error(
      "Chop etish oynasiga brauzer ruxsat bermadi. To‘lov saqlangan.",
    );
  popup.opener = null;
  popup.document.title = "Blusser — namunaviy chek";
  const style = popup.document.createElement("style");
  style.textContent =
    "@page{size:80mm 300mm;margin:4mm}body{width:72mm;margin:0 auto;color:#101010;font:11px Arial,sans-serif}p{white-space:pre-wrap;overflow-wrap:anywhere;margin:8px 0;border-bottom:1px dashed #ddd;padding-bottom:4px}p:nth-child(-n+2),p:last-child{text-align:center;font-weight:bold}";
  popup.document.head.append(style);
  for (const line of lines) {
    const p = popup.document.createElement("p");
    p.textContent = line;
    popup.document.body.append(p);
  }
  try {
    popup.focus();
    popup.print();
  } catch {
    throw Error("Chekni chop etib bo‘lmadi. To‘lov saqlangan.");
  }
  return popup;
}
