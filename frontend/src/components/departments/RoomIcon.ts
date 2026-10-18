import { createLucideIcon } from "lucide-vue-next";
/** Reference room symbol, rendered with the existing Lucide SVG component. */
export default createLucideIcon("RoomDoor", [
  ["path", { d: "M5 21V8a7 7 0 0 1 14 0v13", key: "arch" }],
  ["path", { d: "M3 21h18", key: "base" }],
]);
