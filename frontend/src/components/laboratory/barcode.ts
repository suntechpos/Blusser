// Standard Code 39 encodings, start/stop *, narrow:wide = 1:3, 10-module quiet zone.
const patterns: Record<string, number> = {
  "0": 0x034,
  "1": 0x121,
  "2": 0x061,
  "3": 0x160,
  "4": 0x031,
  "5": 0x130,
  "6": 0x070,
  "7": 0x025,
  "8": 0x124,
  "9": 0x064,
  N: 0x013,
  M: 0x142,
  "-": 0x085,
  "*": 0x094,
};
export function specimenBarcode(id: string) {
  if (!/^NM-\d{5,}$/.test(id)) throw Error("Namuna ID formati mos emas.");
  let x = 10,
    path = "";
  for (const char of "*" + id + "*") {
    const pattern = patterns[char]!;
    for (let bit = 8; bit >= 0; bit--) {
      const w = pattern & (1 << bit) ? 3 : 1;
      if (bit % 2 === 0) path += `M${x} 0h${w}v60h-${w}z`;
      x += w;
    }
    x++;
  }
  return { path, width: x + 9 };
}
