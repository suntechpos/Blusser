/** Synthetic doctor workspace. Volatile and independent of all real API/store modules. */
export const DEMO_DAY = "2026-10-19";
export const DOCTOR = {
  name: "Aziz Karimov",
  specialty: "Kardiolog",
  branch: "Asosiy filial",
  room: "201-kabinet",
};
export interface Encounter {
  id: string;
  patientId: string;
  name: string;
  initials: string;
  gender: string;
  age: number;
  dob: string;
  phone: string;
  time: string;
  ticket: string;
  date: string;
  startedAt: string;
  status:
    | "in_progress"
    | "waiting"
    | "walk_in"
    | "called"
    | "awaiting_results"
    | "completed";
  color: string;
  allergy: string | null;
}
export interface Draft {
  complaints: string;
  anamnesis: string;
  examination: string;
  diagnosis: string;
  diagnosisKind: string;
  recommendations: string;
  saved: string;
}
export interface DocumentRow {
  id: string;
  name: string;
  type: string;
  format: "PDF" | "JPG" | "PNG";
  blob?: Blob;
  referral?: string;
  clinic?: string;
  note?: string;
  size: string;
  date: string;
  author: string;
  encounter: string;
  external: boolean;
}
export interface HistoryVisit {
  id: string;
  date: string;
  time: string;
  doctor: string;
  specialty: string;
  kind: string;
  complete: boolean;
  complaints: string;
  anamnesis: string;
  examination: string;
  diagnosis: string;
  recommendations: string;
  documents: DocumentRow[];
}
export interface ResultRow {
  id: string;
  name: string;
  date: string;
  type: "Laboratoriya" | "Diagnostika" | "Shifokor konsultatsiyasi";
  doctor: string;
  status: "Buyurilgan" | "Jarayonda" | "Tayyor";
  external: boolean;
  referral: string;
  documentId?: string;
  encounter?: string;
}
export interface Referral {
  createdTime?: string;
  id: string;
  encounterKey: string;
  location: "internal" | "external";
  type: string;
  service: string;
  branch: string;
  specialist: string;
  clinic: string;
  note: string;
}
export const REFERRAL_TYPES = [
  "Laboratoriya",
  "Diagnostika",
  "Shifokor konsultatsiyasi",
];
export const SERVICES = [
  {
    id: "cbc",
    name: "Umumiy qon tahlili",
    type: "Laboratoriya",
    department: "Laboratoriya",
  },
  {
    id: "glucose",
    name: "Glyukoza",
    type: "Laboratoriya",
    department: "Laboratoriya",
  },
  {
    id: "uzi",
    name: "Qorin bo‘shlig‘i UZI",
    type: "Diagnostika",
    department: "UZI va Diagnostika",
  },
  {
    id: "ecg",
    name: "EKG",
    type: "Diagnostika",
    department: "UZI va Diagnostika",
  },
  {
    id: "cardio",
    name: "Kardiolog konsultatsiyasi",
    type: "Shifokor konsultatsiyasi",
    department: "Kardiologiya",
  },
  {
    id: "therapy",
    name: "Terapevt konsultatsiyasi",
    type: "Shifokor konsultatsiyasi",
    department: "Terapiya",
  },
];
export const SPECIALISTS = [
  { name: "Demo UZI mutaxassisi", branch: "Asosiy filial", services: ["uzi"] },
  {
    name: "Demo diagnostika mutaxassisi",
    branch: "Yunusobod",
    services: ["uzi", "ecg"],
  },
  {
    name: "Demo laboratoriya mutaxassisi",
    branch: "Asosiy filial",
    services: ["cbc", "glucose"],
  },
  { name: "Aziz Karimov", branch: "Asosiy filial", services: ["cardio"] },
  { name: "Dilnoza Rasulova", branch: "Yunusobod", services: ["therapy"] },
];
export const doctorKey = (e: Encounter) => `${e.patientId}:${e.id}`;
export const emptyDraft = (): Draft => ({
  complaints: "",
  anamnesis: "",
  examination: "",
  diagnosis: "",
  diagnosisKind: "Dastlabki",
  recommendations: "",
  saved: "",
});
export function createDoctorQueue(): Encounter[] {
  const data = [
    [
      "Aliyeva Mohira",
      "AM",
      "Ayol",
      38,
      "09:00",
      "A-001",
      "in_progress",
      "purple",
    ],
    ["Qodirov Aziz", "QA", "Erkak", 45, "09:30", "A-002", "waiting", "rose"],
    [
      "Ismoilova Nilufar",
      "IN",
      "Ayol",
      29,
      "10:00",
      "A-003",
      "waiting",
      "mint",
    ],
    ["Matchonov Siroj", "MS", "Erkak", 52, "", "J-002", "walk_in", "purple"],
    [
      "Toshboyev Akmal",
      "TA",
      "Erkak",
      36,
      "10:30",
      "A-004",
      "waiting",
      "amber",
    ],
    [
      "Demo yakunlangan bemor",
      "DY",
      "Erkak",
      30,
      "08:00",
      "A-000",
      "completed",
      "blue",
    ],
    [
      "Aliyeva Mohira",
      "AM",
      "Ayol",
      38,
      "08:30",
      "A-0002",
      "completed",
      "purple",
    ],
  ];
  return data.map((a, i) => ({
    id: `Q-${i + 1}`,
    patientId: i === 6 ? "P-00124" : `P-${String(124 + i).padStart(5, "0")}`,
    name: String(a[0]),
    initials: String(a[1]),
    gender: String(a[2]),
    age: Number(a[3]),
    dob: i === 0 || i === 6 ? "1988-03-12" : "",
    phone: "+998 90 000 00 00",
    time: String(a[4]),
    ticket: String(a[5]),
    date: DEMO_DAY,
    startedAt: i === 0 ? "09:04" : "",
    status: a[6] as Encounter["status"],
    color: String(a[7]),
    allergy: i === 1 ? "Penitsillin (namunaviy ma’lumot)" : null,
  }));
}
export function createDocuments(): DocumentRow[] {
  return [
    [
      "Qabul xulosasi",
      "Xulosa",
      "PDF",
      "245 KB",
      "2026-10-19",
      "Aziz Karimov",
      "Q-00124",
    ],
    [
      "Dori retsepti",
      "Retsept",
      "PDF",
      "128 KB",
      "2026-10-19",
      "Aziz Karimov",
      "Q-00124",
    ],
    [
      "UZI uchun yo‘llanma",
      "Yo‘llanma",
      "PDF",
      "96 KB",
      "2026-10-19",
      "Aziz Karimov",
      "Q-00124",
    ],
    [
      "Tibbiy ko‘chirma",
      "Ko‘chirma",
      "PDF",
      "310 KB",
      "2026-10-12",
      "Dilshod Aliyev",
      "Q-00108",
    ],
    [
      "Boshqa klinika xulosasi",
      "Tashqi hujjat",
      "JPG",
      "1.2 MB",
      "2026-10-05",
      "Malika Sobirova",
      "",
    ],
    [
      "Muolajaga rozilik",
      "Rozilik",
      "PDF",
      "180 KB",
      "2026-10-05",
      "Malika Sobirova",
      "Q-00092",
    ],
  ].map((a, i) => ({
    id: `D-${i}`,
    name: a[0]!,
    type: a[1]!,
    format: a[2] as DocumentRow["format"],
    size: a[3]!,
    date: a[4]!,
    author: a[5]!,
    encounter: a[6]!,
    external: i === 4,
  }));
}
export function createHistory(): HistoryVisit[] {
  return [
    ["2026-10-05", "10:30", "Aziz Karimov", "Kardiolog", "Takroriy"],
    ["2026-09-15", "09:00", "Aziz Karimov", "Kardiolog", "Birlamchi"],
    ["2026-09-02", "14:00", "Dilnoza Rasulova", "Terapevt", "Birlamchi"],
    ["2026-08-18", "11:30", "Aziz Karimov", "Kardiolog", "Takroriy"],
    ["2026-08-04", "10:00", "Dilnoza Rasulova", "Terapevt", "Birlamchi"],
  ].map((a, i) => ({
    id: `H-${i}`,
    date: a[0]!,
    time: a[1]!,
    doctor: a[2]!,
    specialty: a[3]!,
    kind: a[4]!,
    complete: i !== 3,
    complaints:
      i === 0
        ? "Bemor avvalgi murojaati bo‘yicha takroriy ko‘rikka kelgan."
        : "",
    anamnesis: i === 0 ? "Oldingi tashrif yozuvlari ko‘rib chiqilgan." : "",
    examination:
      i === 0 ? "Ko‘rik ma’lumotlari tashrif yozuvida qayd etilgan." : "",
    diagnosis: "",
    recommendations:
      i === 0 ? "Tavsiyalar tashrif xulosasida qayd etilgan." : "",
    documents:
      i === 0
        ? [{ ...createDocuments()[0]!, date: a[0]!, encounter: "H-0" }]
        : [],
  }));
}
export function createResults(): ResultRow[] {
  return [
    ["Umumiy qon tahlili", "2026-10-19", "Laboratoriya", "Tayyor"],
    ["Qorin bo‘shlig‘i UZI", "2026-10-19", "Diagnostika", "Tayyor"],
    ["Glyukoza", "2026-10-19", "Laboratoriya", "Jarayonda"],
    ["EKG", "2026-10-19", "Diagnostika", "Buyurilgan"],
    ["Siydik tahlili", "2026-10-12", "Laboratoriya", "Tayyor"],
    ["UZI xulosasi", "2026-10-05", "Diagnostika", "Tayyor"],
  ].map((a, i) => ({
    id: `R-${i}`,
    name: a[0]!,
    date: a[1]!,
    type: a[2] as ResultRow["type"],
    status: a[3] as ResultRow["status"],
    doctor: i === 5 ? "—" : i === 4 ? "Dilshod Aliyev" : DOCTOR.name,
    external: i === 5,
    referral: i === 1 ? "Y-00124" : "",
    encounter: i < 4 ? "Q-1" : "",
  }));
}
export function referralErrors(r: Referral) {
  const e: Record<string, string> = {};
  const service = SERVICES.find((s) => s.id === r.service && s.type === r.type);
  if (!service) e.service = "Tekshiruv yoki xizmatni tanlang.";
  if (r.location === "internal") {
    if (!["Asosiy filial", "Yunusobod"].includes(r.branch))
      e.branch = "Filialni tanlang.";
    if (
      r.specialist &&
      !SPECIALISTS.some(
        (s) =>
          s.name === r.specialist &&
          s.branch === r.branch &&
          s.services.includes(r.service),
      )
    )
      e.specialist = "Filial va xizmatga mos mutaxassisni tanlang.";
  }
  return e;
}
export function sanitizeReferral(r: Referral): Referral {
  return {
    ...r,
    ...(r.location === "external"
      ? { branch: "", specialist: "" }
      : { clinic: "" }),
  };
}
export const UZI_DETAILS = {
  name: "Qorin bo‘shlig‘i UZI",
  time: "10:30",
  specialist: "Dilshod Rahimov",
  branch: "Asosiy filial",
  referral: "Y-00125",
  confirmedTime: "10:45",
  description:
    "Jigar, o‘t pufagi, oshqozon osti bezi, taloq va buyraklar tekshirildi. Tuzilishi, o‘lchamlari va ko‘rinishi baholandi.",
  conclusion: "Tekshiruv xulosasi mutaxassis tomonidan kiritilgan.",
  attachments: [
    { name: "UZI xulosasi.pdf", format: "PDF", size: "245 KB" },
    { name: "UZI tasvirlari.jpg", format: "JPG", size: "1.2 MB" },
  ],
};

export interface Medicine {
  name: string;
  form: string;
  strength: string;
  dose: string;
  unit: string;
  route: string;
  frequency: string;
  duration: string;
  instruction: string;
}
export interface Prescription {
  id: string;
  date: string;
  medicines: Medicine[];
  note: string;
}
export const blankMedicine = (): Medicine => ({
  name: "",
  form: "",
  strength: "",
  dose: "",
  unit: "",
  route: "",
  frequency: "",
  duration: "",
  instruction: "",
});
export const MEDICINE_NAMES = ["Namunaviy preparat A", "Namunaviy preparat B"];
export function prescriptionErrors(medicines: Medicine[]) {
  return medicines.map((m) =>
    Object.fromEntries(
      (["name", "dose", "unit", "frequency", "duration"] as const)
        .filter((k) => !m[k].trim())
        .map((k) => [k, "Majburiy maydon."]),
    ),
  );
}
export const LAB_RESULTS: Record<
  string,
  {
    order: string;
    laboratory: string;
    sampled: string;
    ready: string;
    confirmedBy: string;
    comment: string;
    values: {
      name: string;
      value: string;
      unit: string;
      range: string;
      flag: "low" | "high" | "normal" | "unknown";
    }[];
  }
> = {
  "R-0": {
    order: "L-00142",
    laboratory: "Shifo Med laboratoriyasi",
    sampled: "19.10.2026 · 09:40",
    ready: "19.10.2026 · 10:20",
    confirmedBy: "Malika Sobirova",
    comment: "Qo‘shimcha izoh kiritilmagan.",
    values: [
      {
        name: "Gemoglobin",
        value: "118",
        unit: "g/L",
        range: "120 – 150",
        flag: "low",
      },
      {
        name: "Eritrotsitlar",
        value: "4.3",
        unit: "×10¹²/L",
        range: "3.8 – 5.2",
        flag: "normal",
      },
      {
        name: "Leykotsitlar",
        value: "11.2",
        unit: "×10⁹/L",
        range: "4.0 – 9.0",
        flag: "high",
      },
      {
        name: "Trombotsitlar",
        value: "260",
        unit: "×10⁹/L",
        range: "180 – 320",
        flag: "normal",
      },
      {
        name: "Gematokrit",
        value: "38",
        unit: "%",
        range: "36 – 46",
        flag: "normal",
      },
      {
        name: "ECHT",
        value: "24",
        unit: "mm/soat",
        range: "2 – 20",
        flag: "high",
      },
    ],
  },
  "R-4": {
    order: "L-00108",
    laboratory: "Namuna laboratoriya",
    sampled: "12.10.2026 · 08:30",
    ready: "12.10.2026 · 10:00",
    confirmedBy: "Demo mutaxassis",
    comment: "Namunaviy natija.",
    values: [
      {
        name: "pH",
        value: "6.0",
        unit: "",
        range: "5.0 – 7.0",
        flag: "normal",
      },
      {
        name: "Rang",
        value: "Och sariq",
        unit: "",
        range: "",
        flag: "unknown",
      },
    ],
  },
};
