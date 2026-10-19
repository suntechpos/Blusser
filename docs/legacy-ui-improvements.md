# Eski sahifalarning vizual va interaktiv yaxshilanishi

Branch: `feature/doctor-workspace-ui`. Commit/push/merge/reset/clean bajarilmadi.
AGENTS.md workspace/repository va ota kataloglarda topilmadi.

## Ish boshidagi manbalar va saqlangan ishlar

| Sahifa | Oldingi manba | Mavjud jarayonlar |
|---|---|---|
| Dashboard | Inline statik metric/series/visits/queue, CSS donut | Navigatsiya bor, chart/filtrlar statik |
| Registratura | Lokal `patients` va `slots` | Filtr, qidiruv, lokal chaqirish; qo‘shish/ko‘rish/ko‘proq tugmalarida demo xabarlari |
| Bemorlar | `useClinicList`, `clinicRequest`, Sanctum session/context | Server total/pagination/search/branch; create/detail/PATCH If-Match; CSV va permission |
| Qabullar | Lokal `appointments` seed | Qidiruv/sana/shifokor/holat/tab/pagination; create/view/edit/status/CSV |
| Navbatlar | Lokal `patients`, alohida statik hisoblar va called panel | Filtr/tab/pagination; lokal chaqirish/qabulga o‘tkazish; ayrim amallarda demo xabarlari |
| Tablo | Lokal `screens`, mini preview’da alohida qotirilgan raqamlar | Sozlash/qo‘shish, preview/fullscreen, clipboard, confirm delete |

Ish boshida Shifokor kabinetining oldingi vazifadan qolgan lokal o‘zgarishlari,
`docs/doctor-referrals-list.md` va screenshotlari mavjud edi. Ular bekor qilinmadi
va bu vazifada qayta ishlanmadi. Xodimlar/Doctor komponentlari tahrirlanmadi.

## Natija

- `legacy-pages.css` — faqat opt-in `legacy-ui` ildizi va har bir maqsadli
  komponentning Vue scope’i ostida. Sidebar/header/logo/global CSS’ga qo‘llanmaydi.
  Mavjud Employees Inter, ranglar va lucide ikonkalari saqlandi.
- Asosiy matn/filtr 14 px, yordamchi matn va badge/jadval amali 13 px,
  sarlavha 28 px, bo‘lim 20 px; input/select 42 px, asosiy amal 44 px,
  jadval amali kamida 36 px. Focus-visible aniq. CSS zoom/transform yo‘q.
- Jadval qatorlari, ustunlar va amallar orasida bo‘shliq ko‘paydi; jadvallar
  o‘z konteynerida scroll qiladi. Filtrlar kerakli breakpointda qatorlarga bo‘linadi.
- Registratura: asosiy jadval kengroq, o‘ng panel 1450 px dan pastda pastga tushadi;
  slotlar va shifokor tanlovi o‘qiladi. Saralash va shifokor tanlovi ishlaydi.
  Kartalar/tab hisoblari shu lokal bemorlardan olinadi; qotirilgan o‘sish/tushum
  raqamlari bilan bir manbadan bo‘lmagan statistika ko‘rsatilmaydi.
- Dashboardning eski inline kontenti `ClinicOverview.vue` ga ajratildi (parallel
  sahifa emas). Login mavjud `session.user.login`dan olinadi. Barcha demo metrikalar,
  seriyalar, donut va tafsilotlar bitta sintetik manbadan hisoblanadi. Davr va filial
  hammasiga bir xil ta’sir qiladi. SVG tooltip/keyboard/hover, legend bilan series
  ko‘rsatish/yashirish, nuqta va donut orqali lokal tafsilot filtrlash ishlaydi.
  Bemor/tushum alohida grafik va aniq birlikda. Mock chart live Patients API’ga
  filtr yubormaydi. “Barchasini ko‘rish” mavjud Qabullar/Navbatlarga o‘tadi.
- Bemorlar: faqat root klassi, scoped ko‘rinish va foydalanuvchiga tushunarli
  placeholder/tooltip matnlari yangilandi. **Script qismi whitespace’ni hisobga
  olmaganda HEAD bilan bir xil**. API/payload/store/total/ETag/export o‘zgarmadi.
  Mavjud bo‘lmagan statistikada `—`/“Ma’lumot mavjud emas”; yangi soxta qiymat yo‘q.
- Qabullar: mavjud ro‘yxat, modal, filtr, amallar, pagination va CSV saqlandi;
  yangi workflow qo‘shilmadi. Mavjud sahifada alohida kalendar rejimi yo‘q edi;
  mavjud sana filtri saqlandi.
- Navbatlar: hisoblar/tablar va “Hozir chaqirilmoqda” shu lokal holatdan olinadi;
  chaqirish/qabulga o‘tkazish panel va sonlarni yangilaydi. “Yakunlangan” va
  “Kechikkan” atamalari Registratura bilan moslashtirildi.
- Tablo: ekran tanlash mavjud; mini/katta/TV preview shu tanlangan `Screen`
  va bir xil raqam yordamchilaridan foydalanadi. Kabinet sozlanganda ikkala
  preview yangilanadi. Ovoz lokal o‘zgaradi. Copy/fullscreen/confirm delete ishlaydi.
  Namuna onlayn/oflayn real monitoring emasligi ko‘rsatilgan.
- Mavjud Patients/Qabullar/Tablo modal o‘lchamlari va shriftlari ham moslashtirildi.

## Tekshiruvlar

- `npm run typecheck` — o‘tdi.
- `npm run build` — o‘tdi (vue-tsc ham bajariladi).
- `npm test` — 2 test fayli, 0 failure.
- Chromium: **39/39** (7 yangi UI oqimi, 1 capture, 17 Doctor, 8 Employees,
  6 Departments/Rooms). Oxirgi Tablo next-ticket tuzatishidan keyin capture va
  Tablo oqimi 2/2 qayta o‘tdi.
- Dashboard hover/focus/click/legend/donut, davr/filial, bo‘sh holat/navigatsiya.
- Reception qidiruv/saralash/shifokor-slot/lokal chaqirish va computed font >=14 px.
- Patients fixture: server total 23 (10 qator bilan), page 2, qidiruv/filial/detail,
  PATCH If-Match, 409/422 xabarlari, create, total 24 va CSV nomi/so‘rovi.
- Qabullar qidiruv/detail/status/menu/CSV; Navbatlar card/tab/called holatlari.
- Tablo mini-modal mosligi, sozlash/ovoz/clipboard/fullscreen/confirm delete.
- 1000 px da olti sahifa viewportdan chiqmasligi va jadvallarning ichki scrolli.
- Login/logout va CSRF/session navigatsiyasi test fixture bilan tekshirildi.
- Oldingi lokal Doctor va Departments screenshotlari piksel darajasida bir xil.
  Employees fayli o‘zgarmagan va 8 funksional regressiyasi o‘tdi; screenshotdagi
  kichik farq uchta native select matni chegarasida, layoutning qolgan qismi bir xil.
- Backend/auth/services/LoginView/Docker/migratsiya fayllarida yangi diff yo‘q.
  `git diff --check` va yangi komponent/CSS/testlar Prettier tekshiruvi o‘tdi.

Haqiqiy bemor/DB yozuvlari ishlatilmadi va o‘zgartirilmadi. Real backend Patients
integration suite bajarilmadi; HTTP kontraktlar browser route fixture bilan
tekshirildi. Bu real PostgreSQL/RLS testlari o‘tdi degani emas.

## Chegaralar

Registratura va Navbatlarning avval faqat demo xabariga ega qo‘shish, ko‘rish va
ayrim menyu amallari yangi tasdiqlanmagan workflow bilan almashtirilmadi.
Ular namoyishda tayyor emasligini ochiq bildiradi; soxta server muvaffaqiyati yo‘q.
Registraturada avval modal/drawer yo‘q edi; bu vazifada yangi klinik ish jarayoni
kiritilmadi. Qabullarda mavjud oynalar saqlandi. Mock sahifalar refresh yoki
bo‘limdan chiqishda lokal holatni yo‘qotadi. Tablo haqiqiy TV bilan sinxron emas.
Bemorlar’da mavjud bo‘lmagan server filtrlari avvalgidek disabled.

## O‘zgargan/yangi fayllar

- `frontend/src/views/ClinicDashboardView.vue` — faqat Dashboard kontenti/navigatsiya
  ulanishi; header/sidebar markup va stillari saqlandi.
- `frontend/src/views/RegistraturaView.vue`
- `frontend/src/views/BemorlarView.vue`
- `frontend/src/views/QabullarView.vue`
- `frontend/src/views/NavbatlarView.vue`
- `frontend/src/views/TabloBoshqaruviView.vue`
- `frontend/src/components/dashboard/ClinicOverview.vue`
- `frontend/src/styles/legacy-pages.css`
- `frontend/tests/browser/legacy.fixture.ts`
- `frontend/tests/browser/legacy-ui.spec.ts`
- `frontend/tests/browser/legacy-capture.spec.ts`
- Ushbu hisobot va `screenshots/legacy-ui/{before,after}/` — jami 32 screenshot.

Yangi dependency/font/UI/chart kutubxonasi qo‘shilmadi.

## Oldin / keyin screenshotlar

Har ikkala nusxa bir xil sintetik sessiya va Patients API fixture bilan ochilgan.
Viewport: 1672×1000 va 1000×1000; sahifalar full-page, modallar viewport capture.

| Sahifa | Oldin 1672 | Keyin 1672 | Keyin 1000 |
|---|---|---|---|
| Dashboard | [oldin](screenshots/legacy-ui/before/0-1672.png) | [keyin](screenshots/legacy-ui/after/0-1672.png) | [tor](screenshots/legacy-ui/after/0-1000.png) |
| Registratura | [oldin](screenshots/legacy-ui/before/1-1672.png) | [keyin](screenshots/legacy-ui/after/1-1672.png) | [tor](screenshots/legacy-ui/after/1-1000.png) |
| Bemorlar | [oldin](screenshots/legacy-ui/before/2-1672.png) | [keyin](screenshots/legacy-ui/after/2-1672.png) | [tor](screenshots/legacy-ui/after/2-1000.png) |
| Qabullar | [oldin](screenshots/legacy-ui/before/3-1672.png) | [keyin](screenshots/legacy-ui/after/3-1672.png) | [tor](screenshots/legacy-ui/after/3-1000.png) |
| Navbatlar | [oldin](screenshots/legacy-ui/before/4-1672.png) | [keyin](screenshots/legacy-ui/after/4-1672.png) | [tor](screenshots/legacy-ui/after/4-1000.png) |
| Tablo | [oldin](screenshots/legacy-ui/before/5-1672.png) | [keyin](screenshots/legacy-ui/after/5-1672.png) | [tor](screenshots/legacy-ui/after/5-1000.png) |

| Modal | Oldin | Keyin |
|---|---|---|
| Bemor tafsiloti | [oldin](screenshots/legacy-ui/before/patients-modal.png) | [keyin](screenshots/legacy-ui/after/patients-modal.png) |
| Qabul tafsiloti | [oldin](screenshots/legacy-ui/before/appointments-modal.png) | [keyin](screenshots/legacy-ui/after/appointments-modal.png) |
| Tablo preview | [oldin](screenshots/legacy-ui/before/tablo-preview.png) | [keyin](screenshots/legacy-ui/after/tablo-preview.png) |
| Tablo sozlash | [oldin](screenshots/legacy-ui/before/tablo-settings.png) | [keyin](screenshots/legacy-ui/after/tablo-settings.png) |

## GitHub nashri uchun alohida tekshiruv

`origin/develop` yangilangach (1734776, PR #14), uning fayl daraxti avvalgi
Doctor commitiga tengligi tekshirildi. Oldingi Doctor commitlari qayta kiritilmadi.
Faqat ushbu 44 fayl `feature/legacy-ui-polish` uchun ajratildi. Commitga kirmaydigan
Doctor lokal o‘zgarishlarisiz, Git archive orqali alohida nusxada typecheck/build,
2 unit test fayli va **38/38 browser testi** o‘tdi (8 legacy, 16 Doctor,
8 Employees, 6 Departments). Yuqoridagi 39/39 natija avvalgi ishchi katalogdagi
qo‘shimcha lokal Doctor testini ham o‘z ichiga oladi; u bu PR’ga kiritilmadi.
