# Shifokor kabineti — lokal frontend namoyishi

Branch: `feature/doctor-workspace-ui`. Ushbu davomiy ishda commit/push/merge bajarilmadi.
Backend, migratsiyalar, Docker, autentifikatsiya/session, permission, API servislar,
ClinicDashboardView, haqiqiy Bemorlar sahifasi va boshqa modullar o‘zgartirilmadi.

## Dizayn va chegaralar

Yangi 9 ta reference rasm ichki modallar va yo‘llanmalar jadvali uchun ishlatildi.
Asosiy viewport 1672×941; alternativ laboratoriya reference’i 1536×1024.
Mavjud sidebar, header, logo, navbat paneli va bemor kartasi saqlandi.
Reference’dagi boshqa sidebar yoki bemor ismi ko‘chirilmadi: barcha modallar
joriy tanlangan sintetik bemordan ma’lumot oladi.
Mavjud lokal Inter, lucide-vue-next va DateField ishlatilgan. Yangi UI/font
kutubxonasi yo‘q. Barcha yangi CSS `.dw-ui` bilan chegaralangan.

PDF sahifalarini haqiqatan render qilish, fayldan sahifa sonini olish va chop
etish uchun bitta dependency — `pdfjs-dist` qo‘shildi. UI dependency’si emas.
Renderer va viewer alohida lazy chunk; worker lokal bundle’dan olinadi.
PDF’dan JavaScript yoki klinik ma’lumot ajratib olinmaydi (`isEvalSupported: false`).

## Yangi imkoniyatlar

- **Retsept:** bir nechta dori, raqamlash/o‘chirish, yangi blokka fokus, erkin
  dori nomi/tartib, majburiy maydonlar, bitta retseptga saqlash va ochiq qabulda
  qayta tahrirlash. Doza yoki davolash tartibi avtomatik belgilanmaydi.
- **Laboratoriya:** faqat natijalar tabi ustida, har bir tahlilning o‘z qiymatlari,
  birliklari va me’yorlari; yuqori/past belgilar. Me’yor bo‘lmasa “Baholanmagan”.
  UZI modali saqlandi va laboratoriyadan ajratildi. Filtr/pagination yopilganda qoladi.
- **Upload:** PDF/JPG/PNG, 20 MB, tanlash/drop, olib tashlash, majburiy metadata,
  faqat shu bemorning qabul/yo‘llanmalari. Qabul almashganda mos bo‘lmagan
  yo‘llanma tozalanadi. Tashqi natija amali kontekstni oldindan tanlaydi.
- **Viewer:** haqiqiy tanlangan Blob, PDF sahifa soni, oldingi/keyingi, zoom/fit;
  rasmlarda PDF pagination yo‘q. Xatoli/mavjud bo‘lmagan fayl uchun aniq xabar.
  UZI/laboratoriya ichidan ochilganda yopish o‘sha modalga qaytaradi.
- **Yakunlash:** shikoyat, ko‘rik, tashxis va tavsiyalar tekshiruvi; retsept yoki
  yo‘llanma majburiy emas. Kutilayotgan natijalar o‘z holati va soat belgisi bilan.
  Natija kutishga qoldirilgan qabul Kutilmoqda ro‘yxatida davom ettiriladi.
  Yakunlangan qabul Yakunlangan ro‘yxatiga o‘tadi, yozuvlar o‘qish rejimida.
- **Yo‘llanmalar:** Tavsiyalar ostidagi jadval: raqam, xizmat, qayerda, holat, ko‘rish.
  Ichki/tashqi tafsilotlar, mutaxassis tanlanmagan/klinika belgilanmagan holati.
  Tashqi yo‘llanma yaratishdan keyin shu tafsilot oynasi bir martalik yashil xabar
  bilan ochiladi; ro‘yxatdan keyin ochilganda xabar yo‘q. PDF/chop etish ishlaydi.
- X/Escape, fokus trap va qaytarish, saqlanmagan formani bekor qilish tasdig‘i,
  scroll body va ko‘rinib turadigan retsept footer’i, submit double-click himoyasi.

Retsept/qoralama/yo‘llanmalar `patientId:encounterId` bo‘yicha ajratilgan.
Hujjatlar/natijalar bemor bo‘yicha, bog‘liq qabul/yo‘llanma metadata bilan saqlanadi.
Yuklangan fayl bitta Blob: tashqi natijadagi `documentId` shu hujjatga havola.
Viewer URL’lari unmountda, download URL’lari qisqa taymerda tozalanadi.

## Tekshiruvlar

Frontend katalogida:

```sh
npm run typecheck
npm test
npm run build
PW_CHROMIUM_PATH=/usr/bin/chromium PW_BASE_URL=http://127.0.0.1:5173 \
  npm run test:browser -- doctor-workspace.spec.ts doctor-workflows.spec.ts \
  employees.spec.ts departments-rooms.spec.ts
```

- TypeScript va production build o‘tdi.
- `npm test`: 2 test fayli o‘tdi, 0 failure.
- Chromium: 30/30 o‘tdi (8 yangi workflow, 8 Doctor regressiya, 8 Employees,
  6 Departments/Rooms). Oxirgi upload/date/validatsiya tuzatishidan keyin yangi
  8 workflow testi qayta o‘tdi.
- Retsept validation/add/remove/edit/discard/fokus/izolyatsiya, laboratoriya va
  UZI farqi, noma’lum me’yor, nested viewer, upload format/hajm/cancel, tashqi
  faylga bitta havola, corrupt PDF, reload, completion/wait/readonly tekshirildi.
- Ikki sahifali haqiqiy lokal PDF: sahifa soni fayldan, navigatsiya, zoom/fit,
  yuklab olingan baytlar original bilan aynan bir xil; print oynasida ikkala
  sahifa render qilingan. Bo‘sh yoki soxta muvaffaqiyat tugmasi yo‘q.
- Qo‘shimcha API so‘rovi yuborilmasligi tekshirildi. Login/logout/CSRF uchun
  mavjud browser fixture regressiyasi o‘tdi; production auth kodida fake login yo‘q.
- Oldingi 7 sahifa (Bosh sahifa, Registratura, Bemorlar, Qabullar, Navbatlar,
  Xodimlar, Bo‘limlar va Xonalar) HEAD va joriy nusxada bir xil test fixture bilan
  ochildi: runtime xato yo‘q, screenshotlar piksel darajasida bir xil.
- Reference o‘lchamlarida 11 yangi screenshot olindi; ko‘rib chiqilib laboratoriya
  qatorlari, modal balandligi, upload inputlari va footer joylashuvi tuzatildi.
- `git diff --check` va o‘zgargan frontend fayllarining Prettier tekshiruvi o‘tdi.

Haqiqiy backend login/Patients integratsiya testi bajarilmadi:
`127.0.0.1:8000/api/v1/health` ulanishi rad etildi (curl exit 7, HTTP 000).
DB testlari/yozuvlari bajarilmadi. Haqiqiy backend regressiyasi o‘tdi degan da’vo yo‘q.

## Qolgan chegaralar

**Refresh yoki bu bo‘limdan chiqishdan keyin lokal mock o‘zgarishlar va yuklangan
fayllar yo‘qoladi. Serverga upload yoki bazaga yozish yo‘q.**
Bu klinik amaliyot/retsept berish tizimi emas, sintetik frontend namoyishi.
Eski demo fayllarning jadvaldagi hajmi reference metadata; yangi yuklangan fayl
hajmi haqiqiy Blob hajmidan olinadi. Sintetik PDF oddiy namuna hujjatdir;
standart PDF fonti qo‘llamaydigan belgilar Latin yozuviga soddalashtiriladi.
PDF/rasmdan laboratoriya jadvali avtomatik chiqarilmaydi.
Bemor kartasiga haqiqiy API/demo ID bilan murojaat qo‘shilmadi.
Print oynasini yakuniy chiqarish/saqlash brauzerning print dialogi va popup
ruxsatlariga bog‘liq. Backend ruxsatlari bu mock UI tomonidan almashtirilmaydi.

## O‘zgargan fayllar

- `frontend/src/views/DoctorWorkspaceView.vue` — yangi amallar va lokal holat.
- `frontend/src/components/doctor/`: WorkspaceDialog, PatientStrip,
  PrescriptionDialog, LabResultDialog, UploadDialog, DocumentViewer,
  FinishDialog, ReferralDetailsDialog, localFiles — yangi fayllar.
- Shu katalogdagi RecordsPanel, ReferralDialog, ResultDialog, mockFiles va
  doctor.css — mavjud komponentlar davom ettirildi.
- `frontend/src/mocks/doctorWorkspace.ts` — yangi mock turlari va laboratoriya natijalari.
- `frontend/package.json`, `frontend/package-lock.json` — faqat PDF renderer dependency’si.
- `frontend/tests/browser/doctor-workflows.spec.ts` — 8 yangi test.
- `frontend/tests/browser/doctor-workspace.spec.ts` — yangi funksiyalarga mos regressiya.
- Ushbu hisobot va `docs/screenshots/doctor-workflows/` — 11 yangi screenshot.

## Yangi screenshotlar

| Holat | Screenshot |
|---|---|
| Bitta dori | [12-prescription-single](screenshots/doctor-workflows/12-prescription-single.png) |
| Ko‘p dori | [13-prescription-multiple](screenshots/doctor-workflows/13-prescription-multiple.png) |
| Laboratoriya | [14-laboratory](screenshots/doctor-workflows/14-laboratory.png) |
| Laboratoriya 1536×1024 | [15-laboratory-source-viewport](screenshots/doctor-workflows/15-laboratory-source-viewport.png) |
| Upload | [16-upload](screenshots/doctor-workflows/16-upload.png) |
| PDF viewer | [17-document-viewer](screenshots/doctor-workflows/17-document-viewer.png) |
| Yakunlash tekshiruvi | [18-finish](screenshots/doctor-workflows/18-finish.png) |
| Yo‘llanmalar | [19-referrals-list](screenshots/doctor-workflows/19-referrals-list.png) |
| Yangi tashqi yo‘llanma | [20-referral-created](screenshots/doctor-workflows/20-referral-created.png) |
| Qayta ochilgan tafsilot | [21-referral-detail](screenshots/doctor-workflows/21-referral-detail.png) |
| Yuklangan ikki sahifali PDF | [22-uploaded-two-page-pdf](screenshots/doctor-workflows/22-uploaded-two-page-pdf.png) |

Barchasi 1672×941, faqat 15-rasm 1536×1024. Dastlabki screenshotlar o‘chirilmagan.

## Dastlabki bosqich screenshotlari (saqlangan)

1672×941, to‘rtta tab uchun bir xil umumiy shell:

1. [Bugungi qabul](screenshots/doctor-workspace/01-current-visit.png)
2. [Tashriflar tarixi](screenshots/doctor-workspace/02-history.png)
3. [Tashrif tafsilotlari](screenshots/doctor-workspace/03-history-detail.png)
4. [Tekshiruv natijalari](screenshots/doctor-workspace/04-results.png)
5. [UZI natijasi](screenshots/doctor-workspace/05-uzi-result.png)
6. [Hujjatlar](screenshots/doctor-workspace/06-documents.png)
7. [Ichki yo‘llanma](screenshots/doctor-workspace/07-internal-referral.png)
8. [Tashqi yo‘llanma](screenshots/doctor-workspace/08-external-referral.png)
9. [Yo‘llanmalar ro‘yxati](screenshots/doctor-workspace/09-referrals-list.png)

1536×1024 qo‘shimcha source viewport tekshiruvi, to‘g‘ri umumiy sidebar bilan:

10. [Tekshiruv natijalari](screenshots/doctor-workspace/10-results-source-viewport.png)
11. [Hujjatlar](screenshots/doctor-workspace/11-documents-source-viewport.png)
