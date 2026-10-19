# Shifokor kabineti — lokal frontend namoyishi

Ishchi branch: `feature/doctor-workspace-ui`, mavjud
`feature/departments-rooms-ui` holatidan yaratildi. Commit/push/merge yo‘q.

## Dizayn manbalari

Asosiy sahifa, tashriflar tarixi, tashrif tafsilotlari, UZI natijasi hamda
ichki/tashqi yo‘llanma maketlari: 1672×941. Tekshiruv natijalari va Hujjatlar
ichki kontenti uchun berilgan rasmlar: 1536×1024. Ularning boshqa sidebar/header
ko‘rinishi takrorlanmagan: barcha tablar mavjud ClinicDashboardView shell’ida
ishlaydi. Eski, qayerda o‘tkazilishi tanlovisiz yo‘llanma maketi ishlatilmagan.

Mavjud lokal Inter, lucide-vue-next, EmployeeDialog va DateField qayta
ishlatilgan. Yangi kutubxona, font yoki dependency qo‘shilmagan. Doctor CSS
faqat `.dw-ui` ostiga tegishli. Shell’dagi o‘zgarish faqat yangi sahifani
mavjud menyu/hash orqali ochish va shu sahifa uchun demo banner shartidir.

## Bajarilgan imkoniyatlar

- Sana/Bugun, navbat tablari/hisoblari/qidiruvi, tanlangan bemor va lokal
  keyingi bemorni chaqirish. Allergen haqida ma’lumot yo‘qligi allergiya
  yo‘qligi deb talqin qilinmaydi.
- Bugungi qabul formasi. Qoralama `patientId:encounterId` bo‘yicha ajratilgan,
  tab yoki bemor almashganda xotirada qoladi. Boshqa qabulga ko‘chirilmaydi.
  Yakunlangan demo qabul faqat o‘qish rejimida ochiladi.
- Tashriflar tarixi: eng yangi birinchi, sana/shifokor/mutaxassislik filtrlari,
  soniga mos pagination, o‘qish rejimidagi tafsilotlar va chop etish.
- Tekshiruv natijalari: qidiruv, sana/tur/holat, Buyurilgan/Jarayonda/Tayyor,
  tashqi natija belgisi, faqat tayyor yozuvlarda ko‘rish, haqiqiy soniga mos
  pagination. Bir xil tur uchun takroriy filtr qo‘shilmagan.
- Tasdiqlangan UZI modali: bemor/tekshiruv konteksti, tavsif/xulosa,
  namunaviy PDF/JPG fayllar, tasdiqlovchi va vaqt, ko‘rish/yuklab olish/chop etish.
- Hujjatlar: filtrlar va fayl ma’lumotlari, brauzerning standart viewer’i,
  yuklab olish, chop etish. Amallar haqiqiy ochiladigan sintetik PDF/JPG
  bloblarini yaratadi; haqiqiy tibbiy fayllarni olmaydi.
- Yo‘llanma: ichki/tashqi, xizmat turi, xizmatga/filialga mos mutaxassislar,
  majburiy maydonlar, yashirin qiymatlarni saqlamaslik, ixtiyoriy tashqi klinika.
  Ichki yo‘llanma natijalar mock ro‘yxatiga Buyurilgan qilib qo‘shiladi.
  Tashqi yo‘llanma Tashqi / Natija kutilmoqda belgisi bilan ajratiladi.
- Yo‘llanmalar jadvali: xizmat/tur/holat/amal. Uning qo‘shimcha rasmi o‘rnida
  qayta yuborilgan rasmda jadval ko‘rinmagan; matnda berilgan ustunlar va
  shu bo‘limning mavjud jadval uslubi ishlatilgan.
- Modallar Escape/X/bekor qilish, fokus aylanishi va qaytarilishi bilan ishlaydi;
  mobil viewport ichida qoladi, keng jadvallar alohida scroll qiladi.

## Muhim chegaralar

Barcha yangi ma’lumotlar faqat shu bo‘lim frontend xotirasida. Reload yoki
bo‘limdan chiqishda qayta tiklanadi. Boshqa modullar store/mocklari o‘zgarmagan.
Yangi API so‘rovi, endpoint, DB yozuvi, migratsiya yoki Docker o‘zgarishi yo‘q.
Haqiqiy auth/session/permission kodiga tegilmagan; login mock qilinmagan.
Brauzer avtomatik testlaridagi sessiya fixture’lari faqat test faylida.

Namoyish yo‘llanmasi qabulni yakunlamaydi, sana/vaqt band qilmaydi, to‘lov
amalini bajarmaydi va haqiqiy bo‘limga buyurtma/bildirishnoma yubormaydi.
PDF/JPG fayllarining ichida DEMO belgisi bor. Jadvaldagi hajmlar maketning
sintetik metadata qiymatlari; yuklanadigan demo payload kichikroq bo‘lishi mumkin.
Chop etish va viewer brauzer imkoniyatlaridan foydalanadi; foydalanuvchi
brauzeridagi popup/print ruxsatlari amal qiladi.

Tasdiqlanmagan jarayonlar uchun katta yangi oyna yaratilmadi:

- Retsept yozish va qabulni yakunlash: qisqa izoh, qabul holati o‘zgarmaydi.
- Laboratoriya natijasi tafsilotlari: qisqa izoh; qon tahlili uchun UZI modali
  ishlatilmaydi.
- Hujjat yuklash va tashqi natija qo‘shish: qisqa izoh, haqiqiy upload yo‘q.
- Bemor kartasi uchun yangi modal: shu bosqichda yo‘q; haqiqiy Bemorlar API’siga
  demo patient ID bilan murojaat qilinmaydi.
- Tashqi yo‘llanmaning muvaffaqiyat/ko‘rish modali va alohida PDF viewer:
  yaratilmagan; standart brauzer fayl amallari ishlatiladi.

## Tekshiruvlar

Frontend katalogidan:

```sh
npm run typecheck
npm test
npm run build
PW_CHROMIUM_PATH=/usr/bin/chromium PW_BASE_URL=http://127.0.0.1:5173 \
  npx playwright test tests/browser/doctor-workspace.spec.ts \
  tests/browser/employees.spec.ts tests/browser/departments-rooms.spec.ts
```

- TypeScript, mavjud unit testlar (2 fayl) va production build o‘tdi.
- Chromium: 22/22 test (8 yangi, 8 Employees, 6 Departments/Rooms) o‘tdi.
- Bemor/qabul qoralama izolyatsiyasi, navbat/sana, filtr/pagination, ichki/tashqi
  yo‘llanma, read-only tarix/UZI, print oynasi, PDF xref va PDF/JPG fayl
  baytlari, fokus/mobil holat hamda qo‘shimcha API so‘rovi yo‘qligi tekshirildi.
- To‘rtta tabda bemor kartasi/navbat DOM’i va geometriyasi bir xil.
- Eski 7 sahifa bir xil test sessiyasi bilan oldingi HEAD nusxasi va joriy
  branchdagi ikkita vaqtinchalik Vite serverda tekshirildi: navigatsiya va
  runtime exception tekshiruvlari o‘tdi; screenshotlar pikselma-piksel solishtirildi.
- Backend, Docker, umumiy CSS, auth/services, App va oldingi sahifa/modul
  fayllari HEAD bilan bir xil. `git diff --check` va yangi fayllar Prettier
  tekshiruvi bajarildi. Alohida lint script mavjud emas.
- Haqiqiy backend login/Patients integratsiya testi qayta bajarilmadi:
  `127.0.0.1:8000/api/v1/health` ulanishi rad etildi (curl exit 7, HTTP 000).
  Shu sabab real backend regressiyasi o‘tdi degan da’vo yo‘q. DB’ga ulanilmadi.

Lokal ochish: mavjud haqiqiy klinika sessiyasida `/#shifokor-kabineti`.
Frontend start: frontend katalogida `npm run dev`.

## Screenshotlar

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
