export function localDay(
  instant: string | number | Date,
  zone: string,
): string {
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: zone,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).formatToParts(new Date(instant));
  const part = (k: string) => parts.find((p) => p.type === k)!.value;
  return `${part("year")}-${part("month")}-${part("day")}`;
}
export function localTime(instant: string, zone: string) {
  return new Intl.DateTimeFormat("en-GB", {
    timeZone: zone,
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }).format(new Date(instant));
}
export function nextDay(date: string, delta = 1) {
  return new Date(Date.parse(date + "T12:00:00Z") + delta * 86400000)
    .toISOString()
    .slice(0, 10);
}
// Find actual local-day boundaries, including 23/25-hour DST days, without using
// the browser's timezone or guessing the branch offset. Reject skipped dates.
export function dayRange(date: string, zone: string) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || Number.isNaN(Date.parse(date)))
    throw Error("Sanani tekshiring.");
  function boundary(day: string) {
    const center = Date.parse(day + "T00:00:00Z");
    let lo = center - 36 * 3600000,
      hi = center + 36 * 3600000;
    while (lo < hi) {
      const mid = Math.floor((lo + hi) / 2);
      if (localDay(mid, zone) < day) lo = mid + 1;
      else hi = mid;
    }
    return lo;
  }
  const start = boundary(date),
    end = boundary(nextDay(date));
  if (localDay(start, zone) !== date || end <= start)
    throw Error("Bu sana filial vaqt mintaqasida mavjud emas.");
  return {
    from: new Date(start).toISOString(),
    to: new Date(end).toISOString(),
  };
}
