/** Isolated diagnostic demo state. No network, clinical permissions, or browser persistence. */
import { localDate } from "./reception.ts";
export type DiagnosticStatus = "new" | "waiting" | "in_progress" | "completed";
export type Actor = { id: string; name: string; specialty: string };
export type SampleCapabilities = {
  start: boolean;
  draft: boolean;
  confirm: boolean;
  amend: boolean;
};
export const sampleCapabilities: SampleCapabilities = {
  start: true,
  draft: true,
  confirm: true,
  amend: true,
};
export type Attachment = {
  id: string;
  name: string;
  blob: Blob;
  size: number;
  mime: string;
};
export type ResultDraft = {
  number: number;
  description: string;
  conclusion: string;
  files: Attachment[];
  reason: string;
  author: Actor;
  updatedAt: string | null;
};
export type ResultVersion = Readonly<{
  number: number;
  description: string;
  conclusion: string;
  files: readonly Readonly<Attachment>[];
  reason: string;
  author: Readonly<Actor>;
  confirmedAt: string;
}>;
export type DiagnosticReferral = {
  id: string;
  number: string;
  examId: string;
  patientId: string;
  patientName: string;
  dob: string;
  gender: string;
  phone: string;
  testId: string;
  testName: string;
  doctorId: string;
  doctorName: string;
  doctorSpecialty: string;
  branchId: string;
  branchName: string;
  createdAt: string;
  scheduledAt: string | null;
  reason: string;
  note: string;
  target: "internal" | "external";
  clinicId: string;
};
export type DiagnosticExam = {
  id: string;
  resultId: string;
  referralId: string;
  patientId: string;
  status: DiagnosticStatus;
  startedAt: string | null;
  performer: Actor | null;
  draft: ResultDraft | null;
  versions: ResultVersion[];
};
export type DiagnosticsStore = {
  referrals: DiagnosticReferral[];
  exams: Record<string, DiagnosticExam>;
};
export const tests = [
  { id: "abdomen", name: "Qorin bo‘shlig‘i UZI" },
  { id: "kidney", name: "Buyrak UZI" },
  { id: "thyroid", name: "Qalqonsimon bez UZI" },
  { id: "ecg", name: "EKG tekshiruvi" },
  { id: "echo", name: "Yurak ECHO" },
];
export const sampleClinicId = "diagnostics-demo-clinic";
export function plainText(html: string) {
  return html
    .replace(/<\/(?:p|div|li|h[1-6])>/gi, "\n")
    .replace(/<br\s*\/?\s*>/gi, "\n")
    .replace(/<[^>]*>/g, "")
    .replace(/&nbsp;/gi, " ")
    .replace(/&lt;/gi, "<")
    .replace(/&gt;/gi, ">")
    .replace(/&quot;/gi, '"')
    .replace(/&#39;|&apos;/gi, "'")
    .replace(/&amp;/gi, "&")
    .replace(/[\u200B-\u200D\uFEFF]/g, "")
    .trim();
}
export function validateResult(d: ResultDraft) {
  const errors: Record<string, string> = {};
  for (const field of ["description", "conclusion"] as const) {
    const text = plainText(d[field]);
    if (!text) errors[field] = "Maydonni to‘ldiring.";
    else if (/\[(?:ma.{0,3}lumot|tekshiruv|xulosa)[^\]]*\]/i.test(text))
      errors[field] =
        "Shablonning barcha joylarini tekshiruv natijasi bilan to‘ldiring.";
  }
  return errors;
}
export const cloneDraft = (d: ResultDraft): ResultDraft => ({
  ...d,
  author: { ...d.author },
  files: d.files.map((f) => ({ ...f })),
});
export function versionSnapshot(d: ResultDraft, at: string): ResultVersion {
  return Object.freeze({
    number: d.number,
    description: d.description,
    conclusion: d.conclusion,
    reason: d.reason,
    author: Object.freeze({ ...d.author }),
    files: Object.freeze(d.files.map((f) => Object.freeze({ ...f }))),
    confirmedAt: at,
  });
}
function requireCapability(
  caps: SampleCapabilities,
  key: keyof SampleCapabilities,
) {
  if (!caps[key]) throw Error("Bu amal uchun lokal namuna ruxsati yo‘q.");
}
export function startExam(
  e: DiagnosticExam,
  actor: Actor,
  caps: SampleCapabilities,
  at = new Date().toISOString(),
) {
  requireCapability(caps, "start");
  if (e.status === "in_progress") return;
  if (e.status !== "waiting")
    throw Error("Faqat kutilayotgan tekshiruv boshlanadi.");
  e.startedAt = at;
  e.performer = { ...actor };
  e.draft = {
    number: 1,
    description: "",
    conclusion: "",
    files: [],
    reason: "",
    author: { ...actor },
    updatedAt: null,
  };
  e.status = "in_progress";
}
export function saveDraft(
  e: DiagnosticExam,
  d: ResultDraft,
  caps: SampleCapabilities,
  at = new Date().toISOString(),
) {
  requireCapability(caps, "draft");
  if (e.versions.length) requireCapability(caps, "amend");
  if (
    !e.draft ||
    d.number !== e.draft.number ||
    d.number !== e.versions.length + 1
  )
    throw Error("Qoralama eskirgan. Amaldagi natijani qayta oching.");
  if (e.status === "new" || e.status === "waiting")
    throw Error("Tekshiruv boshlanmagan.");
  if (e.status === "completed" && !d.reason.trim())
    throw Error("Tuzatish sababi majburiy.");
  const saved = cloneDraft(d);
  saved.updatedAt = at;
  e.draft = saved;
  return saved;
}
export function confirmResult(
  e: DiagnosticExam,
  d: ResultDraft,
  actor: Actor,
  caps: SampleCapabilities,
  at = new Date().toISOString(),
) {
  requireCapability(caps, "confirm");
  if (e.versions.length) requireCapability(caps, "amend");
  if (
    !e.draft ||
    d.number !== e.draft.number ||
    d.number !== e.versions.length + 1
  )
    throw Error("Bu qoralama allaqachon tasdiqlangan yoki eskirgan.");
  if (e.status !== "in_progress" && e.status !== "completed")
    throw Error("Tekshiruv boshlanmagan.");
  const errors = validateResult(d);
  if (Object.keys(errors).length) throw Error(Object.values(errors)[0]);
  if (e.versions.length && !d.reason.trim())
    throw Error("Tuzatish sababi majburiy.");
  const version = versionSnapshot(
    { ...cloneDraft(d), author: { ...actor } },
    at,
  );
  e.versions.push(version);
  e.draft = null;
  e.status = "completed";
  return version;
}
export function amendResult(
  e: DiagnosticExam,
  reason: string,
  actor: Actor,
  caps: SampleCapabilities,
) {
  requireCapability(caps, "amend");
  if (e.status !== "completed" || !e.versions.length)
    throw Error("Tasdiqlangan natija mavjud emas.");
  if (e.draft) return cloneDraft(e.draft);
  if (!reason.trim()) throw Error("Tuzatish sababini kiriting.");
  const current = e.versions.at(-1)!;
  e.draft = {
    number: current.number + 1,
    description: current.description,
    conclusion: current.conclusion,
    files: current.files.map((f) => ({ ...f })),
    reason: reason.trim(),
    author: { ...actor },
    updatedAt: null,
  };
  return cloneDraft(e.draft);
}
export function draftSignature(d: ResultDraft) {
  return JSON.stringify([
    d.number,
    d.description,
    d.conclusion,
    d.reason,
    d.files.map((f) => [f.id, f.name, f.size, f.mime]),
  ]);
}
export type DiagnosticFilter = {
  date: string;
  query: string;
  testId: string;
  doctorId: string;
  branchId: string;
  status: string;
};
export function internalReferrals(
  store: DiagnosticsStore,
  clinicId = sampleClinicId,
) {
  return store.referrals.filter(
    (r) => r.target === "internal" && r.clinicId === clinicId,
  );
}
export function filteredReferrals(
  store: DiagnosticsStore,
  f: DiagnosticFilter,
) {
  const q = f.query.trim().toLocaleLowerCase();
  return internalReferrals(store).filter(
    (r) =>
      (!f.date || r.createdAt.slice(0, 10) === f.date) &&
      (!q ||
        `${r.number} ${r.patientName} ${r.patientId}`
          .toLocaleLowerCase()
          .includes(q)) &&
      (!f.testId || r.testId === f.testId) &&
      (!f.doctorId || r.doctorId === f.doctorId) &&
      (!f.branchId || r.branchId === f.branchId) &&
      (!f.status || store.exams[r.examId]?.status === f.status),
  );
}
export function createDiagnosticsStore(date = localDate()): DiagnosticsStore {
  const fixtureActor = {
    id: "sample-specialist",
    name: "Madina Sobirova",
    specialty: "UZI mutaxassisi (namuna)",
  };
  const names = [
    "Aliyeva Mohira",
    "Usmonov Dilshod",
    "Karimova Nigora",
    "Rahimov Sardor",
    "Saidova Malika",
    "Yusupov Aziz",
    "Toshboyev Akmal",
    "Ahmedova Dilnoza",
  ];
  const numbers = [125, 126, 127, 128, 129, 130, 123, 124],
    testIds = [
      "abdomen",
      "kidney",
      "thyroid",
      "ecg",
      "abdomen",
      "echo",
      "kidney",
      "ecg",
    ];
  const statuses: DiagnosticStatus[] = [
    "waiting",
    "new",
    "in_progress",
    "waiting",
    "new",
    "new",
    "completed",
    "completed",
  ];
  const times = [
    "10:30",
    "11:00",
    "10:00",
    "11:30",
    null,
    null,
    "09:00",
    "09:30",
  ];
  const referrals: DiagnosticReferral[] = names.map((name, i) => ({
    id: `ref-${numbers[i]}`,
    number: `YL-${String(numbers[i]).padStart(5, "0")}`,
    examId: `exam-${numbers[i]}`,
    patientId: `P-${String([124, 126, 127, 128, 129, 130, 125, 131][i]).padStart(5, "0")}`,
    patientName: name,
    dob: i === 0 ? "1992-03-12" : "1990-01-12",
    gender: i % 2 ? "Erkak" : "Ayol",
    phone: "+998 90 123 45 67",
    testId: testIds[i]!,
    testName: tests.find((t) => t.id === testIds[i])!.name,
    doctorId:
      i === 1 || i === 6
        ? "rahmonova"
        : i === 2
          ? "jabborov"
          : i === 4
            ? "alimova"
            : "karimov",
    doctorName:
      i === 1 || i === 6
        ? "Dilnoza Rahmonova"
        : i === 2
          ? "Sardor Jabborov"
          : i === 4
            ? "Nodira Alimova"
            : "Aziz Karimov",
    doctorSpecialty:
      i === 2 ? "Endokrinolog" : i === 4 ? "Gastroenterolog" : "Terapevt",
    branchId: i === 1 ? "yunus" : "main",
    branchName: i === 1 ? "Yunusobod" : "Asosiy filial",
    createdAt: `${date}T09:10:00`,
    scheduledAt: times[i] ? `${date}T${times[i]}:00` : null,
    reason: "Ichki yo‘llanma bo‘yicha tekshiruv",
    note: "Tekshiruv natijasini qayd etish so‘ralgan.",
    target: "internal",
    clinicId: sampleClinicId,
  }));
  const exams: Record<string, DiagnosticExam> = {};
  referrals.forEach((r, i) => {
    const status = statuses[i]!;
    const draft: ResultDraft = {
      number: 1,
      description:
        status === "completed"
          ? "<p>Namunaviy tekshiruv tavsifi. Haqiqiy tibbiy ma’lumot emas.</p>"
          : "",
      conclusion:
        status === "completed"
          ? "<p>Namunaviy xulosa. Tibbiy tavsiya emas.</p>"
          : "",
      reason: "",
      files: [],
      author: { ...fixtureActor },
      updatedAt: status === "completed" ? `${date}T10:00:00` : null,
    };
    exams[r.examId] = {
      id: r.examId,
      resultId: `result-${numbers[i]}`,
      referralId: r.id,
      patientId: r.patientId,
      status,
      startedAt:
        status === "in_progress" || status === "completed"
          ? `${date}T09:32:00`
          : null,
      performer:
        status === "in_progress" || status === "completed"
          ? { ...fixtureActor }
          : null,
      draft: status === "in_progress" ? draft : null,
      versions:
        status === "completed"
          ? [versionSnapshot(draft, `${date}T10:00:00`)]
          : [],
    };
  });
  referrals.push(
    {
      ...referrals[0]!,
      id: "external-ref",
      number: "YL-00999",
      target: "external",
    },
    {
      ...referrals[0]!,
      id: "foreign-ref",
      number: "YL-00998",
      clinicId: "other-demo-clinic",
    },
  );
  return { referrals, exams };
}
export const diagnosticTemplates = [
  {
    id: "abdomen",
    name: "Qorin bo‘shlig‘i UZI",
    description:
      "<p><b>Jigar:</b> [ma’lumot kiriting]</p><p><b>O‘t pufagi:</b> [ma’lumot kiriting]</p><p><b>Oshqozon osti bezi:</b> [ma’lumot kiriting]</p><p><b>Taloq:</b> [ma’lumot kiriting]</p><p><b>Qo‘shimcha kuzatuvlar:</b> [ma’lumot kiriting]</p>",
    conclusion: "<p>[Tekshiruv asosida xulosani kiriting]</p>",
  },
  {
    id: "kidney",
    name: "Buyrak va siydik pufagi UZI",
    description:
      "<p><b>O‘ng buyrak:</b> [ma’lumot kiriting]</p><p><b>Chap buyrak:</b> [ma’lumot kiriting]</p><p><b>Siydik pufagi:</b> [ma’lumot kiriting]</p>",
    conclusion: "<p>[Tekshiruv asosida xulosani kiriting]</p>",
  },
  {
    id: "thyroid",
    name: "Qalqonsimon bez UZI",
    description:
      "<p><b>O‘ng bo‘lak:</b> [ma’lumot kiriting]</p><p><b>Chap bo‘lak:</b> [ma’lumot kiriting]</p><p><b>Tuzilishi:</b> [ma’lumot kiriting]</p>",
    conclusion: "<p>[Tekshiruv asosida xulosani kiriting]</p>",
  },
  {
    id: "echo",
    name: "Yurak ECHO",
    description:
      "<p><b>Kameralar:</b> [ma’lumot kiriting]</p><p><b>Klapanlar:</b> [ma’lumot kiriting]</p><p><b>O‘lchovlar:</b> [ma’lumot kiriting]</p>",
    conclusion: "<p>[Tekshiruv asosida xulosani kiriting]</p>",
  },
];
