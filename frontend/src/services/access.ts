import { can } from "./session";
export const pages = [
  { label: "Bosh sahifa", hash: "", permission: "dashboard.view" },
  { label: "Registratura", hash: "registratura", permission: "reception.view" },
  { label: "Bemorlar", hash: "bemorlar", permission: "patients.view" },
  { label: "Qabullar", hash: "qabullar", permission: "appointments.view" },
  { label: "Navbatlar", hash: "navbatlar", permission: "queues.view" },
  { label: "Tablo boshqaruvi", hash: "tablo", permission: "display.view" },
  {
    label: "Shifokor kabineti",
    hash: "shifokor-kabineti",
    permission: "workspace.view",
  },
  {
    label: "Laboratoriya",
    hash: "laboratoriya",
    permission: "laboratory.view",
  },
  {
    label: "UZI va Diagnostika",
    hash: "diagnostika",
    permission: "diagnostics.view",
  },
  { label: "Xizmatlar", hash: "xizmatlar", permission: "services.view" },
  { label: "Kassa", hash: "kassa", permission: "cashier.view" },
  { label: "Moliya", hash: "moliya", permission: "finance.view" },
  { label: "Ombor", hash: "ombor", permission: "inventory.view" },
  { label: "Xodimlar", hash: "xodimlar", permission: "employees.view" },
  { label: "Filiallar", hash: "filiallar", permission: "branches.view" },
  {
    label: "Bo‘limlar va Xonalar",
    hash: "bolimlar-xonalar",
    permission: "rooms.view",
  },
  { label: "Hisobotlar", hash: "hisobotlar", permission: "reports.view" },
  { label: "Sozlamalar", hash: "sozlamalar", permission: "roles.view" },
];
export const allowedPage = (label: string) => {
  const p = pages.find((p) => p.label === label);
  return !!p && can(p.permission);
};
export const firstAllowed = () => pages.find((p) => can(p.permission));
export const pageFromHash = (hash: string) =>
  pages.find((p) => p.hash === hash.replace(/^#/, ""));
