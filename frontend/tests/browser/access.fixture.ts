import { readFileSync } from "node:fs";
export const serverPermissions: {
  code: string;
  label: string;
  section: string;
  section_label: string;
  view: string;
  parent: string | null;
  ui_only: boolean;
}[] = JSON.parse(
  readFileSync(
    new URL(
      "../../../backend/app/Modules/Access/Contracts/ui-permissions.json",
      import.meta.url,
    ),
    "utf8",
  ),
);
/** Explicit synthetic authority for legacy layout regressions; never imported by the app. */
export const allServerGrants = Object.fromEntries(
  serverPermissions.map((p) => [p.code, "entire_clinic"]),
);
