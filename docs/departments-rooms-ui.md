# Bo‘limlar va Xonalar — frontend verstka

Branch: `feature/departments-rooms-ui`, mavjud `feature/employees-ui`
holatidan yaratildi. Commit, push yoki merge bajarilmadi.

## Vizual manba va komponentlar

Foydalanuvchining 4 ta 1672×941 rasmi: bo‘limlar ro‘yxati, bo‘lim qo‘shish,
xonalar ro‘yxati va xona qo‘shish. Shu viewport’da Chromium screenshotlari
olindi, jadval/filtr balandligi, modal joylashuvi va backdrop solishtirib
moslashtirildi. Mavjud umumiy shell qayta dizayn qilinmadi.

Xodimlardagi lokal Inter, mavjud `lucide-vue-next` va `EmployeeDialog`
qayta ishlatilgan. Xona belgisi Lucide’ning mavjud `createLucideIcon`
komponenti orqali maketdagi shaklda chiziladi. Yangi font, UI kutubxonasi
va npm dependency qo‘shilmagan. Yangi CSS faqat `.dr-ui` ichida scoped.

## Ishlaydigan UI

- Bo‘limlar va xonalar tablari; qidiruv, filial/holat filtrlari va pagination.
- Xonalar uchun bo‘lim filtri; filial o‘zgarsa mos bo‘lmagan tanlov tozalanadi.
- Xonalar soni mock yozuvlardan hisoblanadi; raqam tegishli filial/bo‘lim
  filtrlangan xonalar tabiga o‘tkazadi.
- Qo‘shish va mavjud qiymatlar bilan tahrirlash modallari.
- Majburiy maydonlar, bir filialdagi nomlarning trim/case-insensitive
  takrorlanishi, xona/bo‘lim filial mosligi va ixtiyoriy qavat tekshiruvi.
- Saqlash faqat frontend xotirasini yangilaydi; bekor qilish/X/Escape
  o‘zgarishlarni tashlaydi. Fokus dialog ichida saqlanadi va ochgan tugmaga qaytadi.
- Mobil modal viewport ichida qoladi; keng jadvallar o‘z konteynerida scroll qiladi.

Mocklar faqat `src/mocks/departmentsRooms.ts`da. Boshqa modullar mocklari
va mavjud auth/permission kodi o‘zgarmagan. Yangi API so‘rovi, endpoint,
DB ulanishi, migratsiya yoki Docker o‘zgarishi yo‘q. Reload/bo‘limdan chiqish
namunaviy holatni qayta tiklaydi. Bazaga yozildi degan muvaffaqiyat xabari yo‘q.

23 ta xona va 8 ta bo‘limning o‘zaro bog‘lanishi izchil. Shu sabab mock
yozuvlardan hisoblangan Terapiya soni maketdagi 5 o‘rniga 8; UI hisobni
sun’iy ravishda almashtirmaydi. Xonalari bor bo‘limni boshqa filialga
ko‘chirish ushbu demo’da maydon ostidagi xato bilan rad etiladi: mavjud
xona/bo‘lim bog‘lanishlari buzilmaydi. Xona turlari alohida `ROOM_TYPES`
konstantasida; turlarni boshqarish sahifasi yoki shifokorni doimiy
biriktirish maydoni qo‘shilmagan.

## Tekshiruvlar

Frontend katalogidan:

```sh
npm run typecheck
npm test
npm run build
PW_CHROMIUM_PATH=/usr/bin/chromium PW_BASE_URL=http://127.0.0.1:5173 \
  npx playwright test tests/browser/departments-rooms.spec.ts tests/browser/employees.spec.ts
```

- TypeScript va production build: o‘tdi.
- Mavjud unit testlar: 2 ta test fayli o‘tdi.
- Chromium: 14/14 ssenariy (6 ta yangi + 8 ta Employees regressiyasi) o‘tdi.
- Brauzer testlari mavjud auth/context uchun test-only network fixture’lar
  ishlatadi; production login kodi o‘zgarmagan. Yangi modul amallarida
  qo‘shimcha API so‘rovi yuborilmagani tekshirildi.
- Bosh sahifa, Registratura, Bemorlar, Qabullar va Navbatlar uchun HEAD
  nusxasi alohida vaqtinchalik Vite serverda ochildi. Bir xil synthetic
  sessiya/empty Patients fixture’i bilan barcha 5 screenshot joriy holatga
  pikselma-piksel teng chiqdi; navigatsiya va runtime exception tekshirildi.
- Employees’ning 6 screenshoti oldingi saqlangan nusxalarga pikselma-piksel teng.
- Backend, Docker, auth/services, umumiy CSS, Employees va boshqa sahifalar
  fayllari HEAD bilan bir xil. Shell faylida faqat yangi bo‘limni import qilish,
  mavjud menyu/hash orqali ochish va shu sahifa uchun demo banner sharti qo‘shilgan.
- Yangi fayllar Prettier bilan formatlangan; `git diff --check` o‘tdi.
  Alohida lint script mavjud emas. DB testlari va haqiqiy backend integratsiya
  testlari bu frontend vazifasida bajarilmagan.

Lokal ochish: mavjud haqiqiy klinika sessiyasi bilan `/#bolimlar-xonalar`.
Frontend start: `npm run dev` (frontend katalogidan).

## Screenshotlar

Barchasi 1672×941; headerdagi klinika/profil test sessiyasidan olinadi,
haqiqiy foydalanuvchi o‘rniga synthetic fixture ishlatilgan.

- [Bo‘limlar ro‘yxati](screenshots/departments-rooms/01-departments.png)
- [Bo‘lim qo‘shish](screenshots/departments-rooms/02-department-modal.png)
- [Xonalar ro‘yxati](screenshots/departments-rooms/03-rooms.png)
- [Xona qo‘shish](screenshots/departments-rooms/04-room-modal.png)
