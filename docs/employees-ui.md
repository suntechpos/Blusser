> Historical UI design notes. Runtime persistence/auth now follow [Access control integration](access-control-integration.md); local demo storage descriptions below no longer describe Settings/Employees.

# Xodimlar — frontend namoyishi

Manba: foydalanuvchi yuborgan 6 ta 1672×941 maket. Mavjud sidebar, header,
login/logout, Patients integratsiyasi, pgAdmin va Swagger saqlangan.
Backend, API kontrakti, migratsiyalar va avtorizatsiya kodi o‘zgarmagan.

## Tayyor ko‘rinishlar

- Xodimlar ro‘yxati: 24 ta sintetik yozuv, hisoblanadigan statistika,
  F.I.Sh./telefon qidiruvi, lavozim/filial/holat filtrlari, pagination.
- Qo‘shish va tahrirlash: surat preview, majburiy maydonlar tekshiruvi,
  shifokor maydonlari, tizimga kirish toggle’i, alohida lavozim va ruxsat roli.
- Profil: umumiy ma’lumotlar, hisoblanadigan yosh/ishlash muddati,
  tizimga kirish holati, faqat shifokor uchun kasbiy ma’lumotlar.
- Ish jadvali: haftaning 7 kuni, bo‘sh holat, shifokor uchun kabinet.
- Jadval modali: kunlar bo‘yicha smenalar, nusxalash, qo‘shimcha interval,
  tanaffus, tungi smena va hafta chegarasidagi to‘qnashuvlar tekshiruvi.
- Istisno modali: sana oralig‘i, ishlamaslik/o‘zgargan ish vaqti, tahrirlash.

Modallar native dialog va qo‘shimcha Tab/Shift+Tab fokus aylanishidan
foydalanadi. Escape/X/bekor qilish o‘zgarishlarni saqlamaydi; fokus ochgan
tugmaga qaytadi. Keng jadvallar o‘z konteynerida scroll qiladi.

## Chegaralar

Bu faqat frontend namoyishi. Yangi API chaqiruvi yoki soxta endpoint yo‘q.
Xodimlar alohida `src/mocks/employees.ts` qatlamidan keladi. Saqlash faqat
komponent xotirasini yangilaydi; reload yoki bo‘limdan chiqish uni tiklaydi.
Faol klinika konteksti almashganda mavjud shell komponentni qayta yaratadi.

Demo parol faqat forma xotirasida turadi va saqlash/yopishda tashlanadi.
Employee obyektida password maydoni yo‘q; localStorage/sessionStorage
ishlatilmagan. Haqiqiy akkaunt yaratish, loginning global noyobligi,
permission berish, majburiy parol almashtirish, kabinet bandligi va mavjud
qabullar bilan to‘qnashuvlar ushbu UI orqali bajarilmaydi.

Mavjud font oilasi — Inter. Google Fonts cloud muhitida 403 qaytargani
uchun aynan Inter’ning 400/500/600/700/800 vaznlari faqat Xodimlar ichida
lokal asset sifatida ishlatiladi. Manba: @fontsource/inter 5.3.0,
SIL Open Font License; litsenziya `public/assets/fonts/inter/LICENSE`da.
Yangi npm dependency qo‘shilmagan. Umumiy font import o‘zgarmagan.

## Tekshirish

Frontend katalogida:

```sh
npm run typecheck
npm test
npm run build
npx playwright test tests/browser/employees.spec.ts
```

Tizim Chromium’i ishlatilsa:

```sh
PW_CHROMIUM_PATH=/usr/bin/chromium PW_BASE_URL=http://127.0.0.1:5173 \
  npx playwright test tests/browser/employees.spec.ts
```

Brauzer testi autentifikatsiya/context uchun faqat test ichidagi network
fixture’lardan foydalanadi; production auth kodi o‘zgarmagan. Haqiqiy
Sanctum integratsiya testi qayta bajarilmadi va DB’ga ulanilmadi.

Natija: 8/8 browser ssenariysi o‘tdi; typecheck, npm test (2 test fayli),
production build va git diff --check o‘tdi. Unit tekshiruvlari smena,
tanaffus, filial/kabinet, hafta chegarasi, istisno va sanalarni qamraydi.
Alohida lint script repoda yo‘q; yangi fayllar Prettier bilan formatlangan.

Screenshotlar browser testi orqali 1672×941 viewport’da olinadi;
standart joylashuv `/tmp/blusser-employees-screenshots`. Boshqa katalog
uchun `EMPLOYEE_SCREENSHOTS` muhit o‘zgaruvchisini bering. Oltita nom:
`01-employees-list`, `02-employee-create`, `03-employee-profile`,
`04-employee-schedule`, `05-schedule-dialog`, `06-exception-dialog`.

Maketdagi klinika/foydalanuvchi nomi o‘rnida screenshotlarda sintetik
sessiya fixture’i ishlatiladi. Mavjud umumiy shell qayta dizayn qilinmagan;
demo holati haqidagi izohlar va xavfsiz bo‘sh parol maydoni ataylab saqlangan.

## Saqlangan screenshotlar

- [Xodimlar ro‘yxati](screenshots/employees/01-employees-list.png)
- [Xodim qo‘shish](screenshots/employees/02-employee-create.png)
- [Xodim profili](screenshots/employees/03-employee-profile.png)
- [Ish jadvali](screenshots/employees/04-employee-schedule.png)
- [Jadval modali](screenshots/employees/05-schedule-dialog.png)
- [Istisno modali](screenshots/employees/06-exception-dialog.png)
