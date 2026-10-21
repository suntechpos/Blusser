/** Local demonstration only. No API, persistence or clinical authorization. */
import { localDate } from "./reception.ts";
import type { Actor, Attachment } from "./diagnostics";
export type LabStatus =
  | "new"
  | "collected"
  | "in_progress"
  | "review"
  | "completed";
export type LabCapabilities = {
  collect: boolean;
  start: boolean;
  draft: boolean;
  submit: boolean;
  review: boolean;
  confirm: boolean;
  amend: boolean;
};
export const sampleLabCapabilities: LabCapabilities = {
  collect: true,
  start: true,
  draft: true,
  submit: true,
  review: true,
  confirm: true,
  amend: true,
};
export type ReferenceRule = {
  gender?: string;
  minAge?: number;
  maxAge?: number;
  method?: string;
  min?: number;
  max?: number;
  label?: string;
};
export type Indicator = {
  id: string;
  name: string;
  unit: string;
  kind: "numeric" | "qualitative" | "text";
  required: boolean;
  options?: string[];
  references: ReferenceRule[];
};
export type LabTest = {
  id: string;
  name: string;
  sampleType: string;
  method: string;
  indicators: Indicator[];
};
const numeric = (
  id: string,
  name: string,
  unit: string,
  min: number,
  max: number,
  method: string,
): Indicator => ({
  id,
  name,
  unit,
  kind: "numeric",
  required: true,
  references: [{ minAge: 18, maxAge: 120, method, min, max }],
});
export const labTests: LabTest[] = [
  {
    id: "cbc",
    name: "Umumiy qon tahlili",
    sampleType: "Venoz qon",
    method: "demo-cbc",
    indicators: [
      {
        id: "hgb",
        name: "Gemoglobin (HGB)",
        unit: "g/L",
        kind: "numeric",
        required: true,
        references: [
          {
            gender: "Ayol",
            minAge: 18,
            method: "demo-cbc",
            min: 120,
            max: 150,
          },
          {
            gender: "Erkak",
            minAge: 18,
            method: "demo-cbc",
            min: 130,
            max: 170,
          },
        ],
      },
      numeric("rbc", "Eritrotsitlar (RBC)", "10¹²/L", 3.8, 5.2, "demo-cbc"),
      numeric("wbc", "Leykotsitlar (WBC)", "10⁹/L", 4, 10, "demo-cbc"),
      numeric("plt", "Trombotsitlar (PLT)", "10⁹/L", 150, 400, "demo-cbc"),
      numeric("hct", "Gematokrit (HCT)", "%", 36, 46, "demo-cbc"),
      numeric("mcv", "MCV", "fL", 80, 100, "demo-cbc"),
      numeric("esr", "ECHT (ESR)", "mm/soat", 0, 20, "demo-cbc"),
    ],
  },
  {
    id: "urine",
    name: "Umumiy siydik tahlili",
    sampleType: "Siydik",
    method: "demo-urine",
    indicators: [
      {
        id: "color",
        name: "Rangi",
        unit: "—",
        kind: "text",
        required: true,
        references: [],
      },
      {
        id: "nitrite",
        name: "Nitritlar",
        unit: "—",
        kind: "qualitative",
        required: true,
        options: ["Aniqlanmadi", "Aniqlandi"],
        references: [],
      },
      numeric("ph", "pH", "pH", 5, 7.5, "demo-urine"),
      {
        id: "density",
        name: "Nisbiy zichlik",
        unit: "g/mL",
        kind: "numeric",
        required: false,
        references: [],
      },
    ],
  },
  {
    id: "glucose",
    name: "Qonda glyukoza",
    sampleType: "Venoz qon",
    method: "demo-glucose",
    indicators: [
      numeric("glucose", "Glyukoza", "mmol/L", 3.9, 6.1, "demo-glucose"),
    ],
  },
  {
    id: "chemistry",
    name: "Biokimyoviy tahlil",
    sampleType: "Venoz qon",
    method: "demo-chemistry",
    indicators: [
      numeric("alt", "ALT", "U/L", 0, 40, "demo-chemistry"),
      numeric("ast", "AST", "U/L", 0, 40, "demo-chemistry"),
      numeric("protein", "Umumiy oqsil", "g/L", 64, 83, "demo-chemistry"),
    ],
  },
];
export type LabReferral = {
  id: string;
  number: string;
  resultId: string;
  patientId: string;
  patientName: string;
  dob: string;
  gender: string;
  testIds: string[];
  doctorId: string;
  doctorName: string;
  doctorSpecialty: string;
  branchId: string;
  branchName: string;
  createdAt: string;
  expectedAt: string | null;
  reason: string;
  note: string;
  target: "internal" | "external";
  clinicId: string;
};
export type LabSample = {
  id: string;
  type: string;
  testIds: string[];
  collectedAt: string;
  collector: Actor;
  note: string;
};
export type LabRow = {
  id: string;
  testId: string;
  name: string;
  unit: string;
  kind: Indicator["kind"];
  required: boolean;
  options?: string[];
  reference: ReferenceRule | null;
  value: string;
};
export type ReturnEvent = { reason: string; actor: Actor; at: string };
export type LabDraft = {
  number: number;
  phase: "editing" | "review";
  rows: LabRow[];
  note: string;
  files: Attachment[];
  reason: string;
  enteredBy: Actor;
  updatedAt: string | null;
  submittedAt: string | null;
  submittedBy: Actor | null;
  returns: ReturnEvent[];
};
export type LabVersion = Readonly<{
  number: number;
  rows: readonly Readonly<LabRow>[];
  note: string;
  files: readonly Readonly<Attachment>[];
  reason: string;
  enteredBy: Readonly<Actor>;
  submittedBy: Readonly<Actor>;
  submittedAt: string;
  confirmedBy: Readonly<Actor>;
  confirmedAt: string;
  returns: readonly Readonly<ReturnEvent>[];
}>;
export type LabJob = {
  referralId: string;
  resultId: string;
  status: LabStatus;
  samples: LabSample[];
  startedAt: string | null;
  startedBy: Actor | null;
  draft: LabDraft | null;
  versions: LabVersion[];
};
export type LabStore = {
  referrals: LabReferral[];
  jobs: Record<string, LabJob>;
};
export const sampleLabClinic = "lab-demo-clinic";
export const testNames = (r: LabReferral) =>
  r.testIds
    .map((id) => labTests.find((t) => t.id === id)?.name ?? id)
    .join(", ");
export function ageAt(dob: string, date = localDate()) {
  const b = new Date(dob + "T12:00:00"),
    d = new Date(date + "T12:00:00");
  return (
    d.getFullYear() -
    b.getFullYear() -
    (d.getMonth() < b.getMonth() ||
    (d.getMonth() === b.getMonth() && d.getDate() < b.getDate())
      ? 1
      : 0)
  );
}
export function resolveReference(
  i: Indicator,
  r: Pick<LabReferral, "dob" | "gender">,
  method: string,
  date = localDate(),
): ReferenceRule | null {
  const age = ageAt(r.dob, date);
  const rules = i.references.filter(
    (x) =>
      (!x.gender || x.gender === r.gender) &&
      (x.minAge === undefined || age >= x.minAge) &&
      (x.maxAge === undefined || age <= x.maxAge) &&
      (!x.method || x.method === method),
  );
  if (rules.length !== 1) return null;
  return { ...rules[0]! };
}
export const referenceLabel = (r: ReferenceRule | null) =>
  r ? (r.label ?? `${r.min ?? "—"} – ${r.max ?? "—"}`) : "Belgilanmagan";
export function numericValue(value: string) {
  const s = value.trim();
  return /^[-+]?(?:\d+(?:[.,]\d+)?|[.,]\d+)$/.test(s) &&
    Number.isFinite(Number(s.replace(",", ".")))
    ? Number(s.replace(",", "."))
    : null;
}
export function rowFlag(row: LabRow) {
  if (!row.value.trim()) return "empty";
  if (row.kind !== "numeric") return "qualitative";
  const n = numericValue(row.value);
  if (n === null) return "invalid";
  if (
    !row.reference ||
    (row.reference.min === undefined && row.reference.max === undefined)
  )
    return "unknown";
  if (row.reference.min !== undefined && n < row.reference.min) return "low";
  if (row.reference.max !== undefined && n > row.reference.max) return "high";
  return "normal";
}
export function validateRows(d: LabDraft) {
  const errors: Record<string, string> = {};
  for (const row of d.rows) {
    if (!row.value.trim()) {
      if (row.required) errors[row.id] = "Natijani kiriting.";
    } else if (row.kind === "numeric" && numericValue(row.value) === null)
      errors[row.id] = "To‘g‘ri raqam kiriting.";
    else if (
      row.kind === "qualitative" &&
      !row.options?.includes(row.value.trim())
    )
      errors[row.id] = "Ro‘yxatdan sifat natijasini tanlang.";
    else if (row.value.length > 200) errors[row.id] = "200 belgidan oshmasin.";
  }
  if (d.note.length > 500) errors.note = "Izoh 500 belgidan oshmasin.";
  return errors;
}
export function draftRows(r: LabReferral, date = localDate()): LabRow[] {
  return r.testIds.flatMap((testId) => {
    const test = labTests.find((t) => t.id === testId);
    if (!test) throw Error("Tahlil shabloni topilmadi.");
    return test.indicators.map((i) => ({
      id: testId + ":" + i.id,
      testId,
      name: i.name,
      unit: i.unit,
      kind: i.kind,
      required: i.required,
      options: i.options ? [...i.options] : undefined,
      reference: resolveReference(i, r, test.method, date),
      value: "",
    }));
  });
}
export function cloneLabDraft(d: LabDraft): LabDraft {
  return {
    ...d,
    rows: d.rows.map((r) => ({
      ...r,
      options: r.options ? [...r.options] : undefined,
      reference: r.reference ? { ...r.reference } : null,
    })),
    files: d.files.map((f) => ({ ...f })),
    enteredBy: { ...d.enteredBy },
    submittedBy: d.submittedBy ? { ...d.submittedBy } : null,
    returns: d.returns.map((e) => ({ ...e, actor: { ...e.actor } })),
  };
}
export const labSignature = (d: LabDraft) =>
  JSON.stringify([
    d.number,
    d.rows.map((r) => [r.id, r.value]),
    d.note,
    d.files.map((f) => [f.id, f.name, f.size]),
    d.reason,
  ]);
function allow(c: LabCapabilities, key: keyof LabCapabilities) {
  if (!c[key]) throw Error("Bu amal uchun lokal namuna ruxsati yo‘q.");
}
export function nextSampleId(store: LabStore) {
  const nums = Object.values(store.jobs)
    .flatMap((j) => j.samples)
    .map((s) => Number(s.id.replace("NM-", "")))
    .filter(Number.isFinite);
  return "NM-" + String(Math.max(100, ...nums) + 1).padStart(5, "0");
}
export function pendingTypes(r: LabReferral, j: LabJob) {
  return [
    ...new Set(
      r.testIds
        .filter((id) => !j.samples.some((s) => s.testIds.includes(id)))
        .map((id) => labTests.find((t) => t.id === id)!.sampleType),
    ),
  ];
}
export function collectSample(
  store: LabStore,
  r: LabReferral,
  type: string,
  at: string,
  note: string,
  actor: Actor,
  c: LabCapabilities,
  now = new Date(),
) {
  allow(c, "collect");
  const j = store.jobs[r.id]!;
  if (j.status !== "new") throw Error("Namuna avval olingan.");
  if (!pendingTypes(r, j).includes(type))
    throw Error("Tahlilga mos yangi namuna turini tanlang.");
  const d = new Date(at);
  if (
    !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/.test(at) ||
    !Number.isFinite(d.getTime()) ||
    localDate(d) !== at.slice(0, 10) ||
    d > now ||
    d < new Date(r.createdAt)
  )
    throw Error("Namuna vaqti yo‘llanma yaratilganidan hozirgacha bo‘lsin.");
  const sample = {
    id: nextSampleId(store),
    type,
    testIds: r.testIds.filter(
      (id) =>
        labTests.find((t) => t.id === id)?.sampleType === type &&
        !j.samples.some((s) => s.testIds.includes(id)),
    ),
    collectedAt: at,
    collector: { ...actor },
    note: note.trim(),
  };
  j.samples.push(sample);
  if (!pendingTypes(r, j).length) j.status = "collected";
  return sample;
}
export function startLab(
  j: LabJob,
  r: LabReferral,
  actor: Actor,
  c: LabCapabilities,
  at = new Date().toISOString(),
) {
  allow(c, "start");
  if (j.status === "in_progress") return;
  if (j.status !== "collected" || pendingTypes(r, j).length)
    throw Error("Avval barcha kerakli namunalarni oling.");
  j.draft = {
    number: 1,
    phase: "editing",
    rows: draftRows(r),
    note: "",
    files: [],
    reason: "",
    enteredBy: { ...actor },
    updatedAt: null,
    submittedAt: null,
    submittedBy: null,
    returns: [],
  };
  j.startedAt = at;
  j.startedBy = { ...actor };
  j.status = "in_progress";
}
function currentDraft(j: LabJob, d: LabDraft, phase: LabDraft["phase"]) {
  if (
    !j.draft ||
    j.draft.number !== d.number ||
    d.number !== j.versions.length + 1 ||
    j.draft.phase !== phase ||
    d.phase !== phase
  )
    throw Error("Qoralama eskirgan yoki boshqa bosqichda.");
  if (j.status === "new" || j.status === "collected")
    throw Error("Tahlil boshlanmagan.");
  if (j.versions.length && !d.reason.trim())
    throw Error("Tuzatish sababi majburiy.");
  const expected = j.draft.rows;
  if (
    d.rows.length !== expected.length ||
    d.rows.some((r, i) => r.id !== expected[i]?.id)
  )
    throw Error("Ko‘rsatkichlar shabloni mos emas.");
}
export function saveLab(
  j: LabJob,
  d: LabDraft,
  actor: Actor,
  c: LabCapabilities,
  at = new Date().toISOString(),
) {
  allow(c, "draft");
  if (j.versions.length) allow(c, "amend");
  currentDraft(j, d, "editing");
  j.draft = cloneLabDraft({ ...d, enteredBy: { ...actor }, updatedAt: at });
  return cloneLabDraft(j.draft);
}
export function submitLab(
  j: LabJob,
  d: LabDraft,
  actor: Actor,
  c: LabCapabilities,
  at = new Date().toISOString(),
) {
  allow(c, "submit");
  if (j.versions.length) allow(c, "amend");
  currentDraft(j, d, "editing");
  const errors = validateRows(d);
  if (Object.keys(errors).length) throw Error(Object.values(errors)[0]);
  j.draft = cloneLabDraft({
    ...d,
    phase: "review",
    enteredBy: { ...actor },
    submittedBy: { ...actor },
    submittedAt: at,
    updatedAt: at,
  });
  if (!j.versions.length) j.status = "review";
}
export function returnLab(
  j: LabJob,
  reason: string,
  actor: Actor,
  c: LabCapabilities,
  at = new Date().toISOString(),
) {
  allow(c, "review");
  if (!j.draft || j.draft.phase !== "review")
    throw Error("Natija tekshirishga yuborilmagan.");
  if (!reason.trim()) throw Error("Qaytarish sababini kiriting.");
  const d = cloneLabDraft(j.draft);
  d.returns.push({ reason: reason.trim(), actor: { ...actor }, at });
  d.phase = "editing";
  j.draft = d;
  if (!j.versions.length) j.status = "in_progress";
}
export function confirmLab(
  j: LabJob,
  actor: Actor,
  c: LabCapabilities,
  at = new Date().toISOString(),
) {
  allow(c, "confirm");
  if (j.versions.length) allow(c, "amend");
  const d = j.draft;
  if (!d) throw Error("Bu natija allaqachon tasdiqlangan.");
  currentDraft(j, d, "review");
  const errors = validateRows(d);
  if (Object.keys(errors).length) throw Error(Object.values(errors)[0]);
  if (!d.submittedAt || !d.submittedBy)
    throw Error("Tekshirishga yuborish ma’lumotlari yo‘q.");
  const clone = cloneLabDraft(d);
  const v = Object.freeze({
    number: d.number,
    rows: Object.freeze(
      clone.rows.map((r) =>
        Object.freeze({
          ...r,
          options: r.options ? Object.freeze(r.options) : undefined,
          reference: r.reference ? Object.freeze(r.reference) : null,
        }),
      ),
    ),
    note: d.note,
    files: Object.freeze(clone.files.map((f) => Object.freeze(f))),
    reason: d.reason,
    enteredBy: Object.freeze(clone.enteredBy),
    submittedBy: Object.freeze(clone.submittedBy!),
    submittedAt: d.submittedAt,
    confirmedBy: Object.freeze({ ...actor }),
    confirmedAt: at,
    returns: Object.freeze(
      clone.returns.map((e) =>
        Object.freeze({ ...e, actor: Object.freeze(e.actor) }),
      ),
    ),
  });
  j.versions.push(v as LabVersion);
  j.draft = null;
  j.status = "completed";
  return v as LabVersion;
}
export function amendLab(
  j: LabJob,
  reason: string,
  actor: Actor,
  c: LabCapabilities,
) {
  allow(c, "amend");
  if (j.status !== "completed" || !j.versions.length)
    throw Error("Tasdiqlangan natija mavjud emas.");
  if (j.draft) return cloneLabDraft(j.draft);
  if (!reason.trim()) throw Error("Tuzatish sababini kiriting.");
  const v = j.versions.at(-1)!;
  j.draft = {
    number: v.number + 1,
    phase: "editing",
    rows: v.rows.map((r) => ({
      ...r,
      options: r.options ? [...r.options] : undefined,
      reference: r.reference ? { ...r.reference } : null,
    })),
    note: v.note,
    files: v.files.map((f) => ({ ...f })),
    reason: reason.trim(),
    enteredBy: { ...actor },
    updatedAt: null,
    submittedAt: null,
    submittedBy: null,
    returns: [],
  };
  return cloneLabDraft(j.draft);
}
export type LabFilter = {
  date: string;
  query: string;
  testId: string;
  doctorId: string;
  branchId: string;
  status: string;
};
export function internalLabs(s: LabStore) {
  return s.referrals.filter(
    (r) => r.target === "internal" && r.clinicId === sampleLabClinic,
  );
}
export function filterLabs(s: LabStore, f: LabFilter) {
  const q = f.query.trim().toLocaleLowerCase();
  return internalLabs(s).filter(
    (r) =>
      (!f.date || r.createdAt.slice(0, 10) === f.date) &&
      (!q ||
        `${r.patientName} ${r.patientId} ${r.number}`
          .toLocaleLowerCase()
          .includes(q)) &&
      (!f.testId || r.testIds.includes(f.testId)) &&
      (!f.doctorId || r.doctorId === f.doctorId) &&
      (!f.branchId || r.branchId === f.branchId) &&
      (!f.status || s.jobs[r.id]?.status === f.status),
  );
}
export function createLabStore(date = localDate()): LabStore {
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
  const testIds = [
    "cbc",
    "urine",
    "glucose",
    "chemistry",
    "cbc",
    "urine",
    "glucose",
    "cbc",
  ];
  const states: LabStatus[] = [
    "new",
    "new",
    "collected",
    "collected",
    "in_progress",
    "in_progress",
    "review",
    "completed",
  ];
  const collector = {
      id: "sample-collector",
      name: "Dilfuza Ismoilova",
      specialty: "Hamshira (namuna)",
    },
    laborant = {
      id: "sample-technician",
      name: "Nodira Rasulova",
      specialty: "Laborant (namuna)",
    },
    reviewer = {
      id: "sample-reviewer",
      name: "Saida Karimova",
      specialty: "Laboratoriya shifokori (namuna)",
    };
  const referrals: LabReferral[] = names.map((patientName, i) => ({
    id: `lab-ref-${201 + i}`,
    number: `YL-${String(201 + i).padStart(5, "0")}`,
    resultId: `lab-result-${201 + i}`,
    patientId: `P-${String([124, 126, 127, 128, 129, 130, 125, 131][i]).padStart(5, "0")}`,
    patientName,
    dob: i === 0 ? "1992-03-12" : "1990-01-12",
    gender: [
      "Ayol",
      "Erkak",
      "Ayol",
      "Erkak",
      "Ayol",
      "Erkak",
      "Erkak",
      "Ayol",
    ][i]!,
    testIds: [testIds[i]!],
    doctorId: i % 3 ? "rahmonova" : "karimov",
    doctorName: i % 3 ? "Dilnoza Rahmonova" : "Aziz Karimov",
    doctorSpecialty: i === 2 ? "Endokrinolog" : "Terapevt",
    branchId: i === 3 ? "yunus" : "main",
    branchName: i === 3 ? "Yunusobod" : "Asosiy filial",
    createdAt: `${date}T09:${String(10 + i * 5).padStart(2, "0")}:00`,
    expectedAt: null,
    reason: "Shifokor tavsiyasiga ko‘ra laboratoriya tekshiruvi",
    note: "Buyurilgan tahlil natijalarini qayd etish so‘ralgan.",
    target: "internal",
    clinicId: sampleLabClinic,
  }));
  const jobs: Record<string, LabJob> = {};
  for (const [i, r] of referrals.entries()) {
    const state = states[i]!;
    const j: LabJob = {
      referralId: r.id,
      resultId: r.resultId,
      status: "new",
      samples: [],
      startedAt: null,
      startedBy: null,
      draft: null,
      versions: [],
    };
    jobs[r.id] = j;
    if (state !== "new") {
      j.samples = [
        {
          id: `NM-${String(101 + i).padStart(5, "0")}`,
          type: labTests.find((t) => t.id === r.testIds[0])!.sampleType,
          testIds: [...r.testIds],
          collectedAt: `${date}T10:00:00`,
          collector: { ...collector },
          note: "",
        },
      ];
      j.status = "collected";
    }
    if (["in_progress", "review", "completed"].includes(state)) {
      startLab(j, r, laborant, sampleLabCapabilities, `${date}T10:05:00`);
      if (state !== "in_progress") {
        j.draft!.rows.forEach(
          (row, k) =>
            (row.value =
              r.testIds[0] === "cbc"
                ? String([128, 4.4, 11.2, 250, 39, 89, 12][k])
                : "5.4"),
        );
        submitLab(
          j,
          j.draft!,
          laborant,
          sampleLabCapabilities,
          `${date}T10:15:00`,
        );
        if (state === "completed")
          confirmLab(j, reviewer, sampleLabCapabilities, `${date}T10:20:00`);
      }
    }
  }
  referrals.push(
    {
      ...referrals[0]!,
      id: "external-lab",
      number: "YL-00999",
      target: "external",
    },
    {
      ...referrals[0]!,
      id: "foreign-lab",
      number: "YL-00998",
      clinicId: "foreign-demo-clinic",
    },
  );
  return { referrals, jobs };
}
