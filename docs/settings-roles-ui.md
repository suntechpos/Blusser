> Historical UI design notes. Runtime persistence/auth now follow [Access control integration](access-control-integration.md); local demo storage descriptions below no longer describe Settings/Employees.

# Sozlamalar → Rollar va ruxsatlar

Frontend lokal namuna rejimi. Header, sidebar, login/logout, Patients API va server sessiya grantlari o‘zgartirilmagan. Sozlamalar uchun mavjud shell ichida `#sozlamalar` navigatsiyasi qo‘shildi. Umumiy va To‘lovlar tablari faqat “Tez orada” holatini ko‘rsatadi.

Ikki ustunli rol ro‘yxati va tahrirlash paneli qidirish, har bir rolning alohida ruxsatlari, accordion, klaviatura checkboxlari, inline rol yaratish, nom validatsiyasi, bekor qilish va saqlashni qo‘llaydi. Nomlar katta-kichik harf va ortiqcha probellarga qaramay noyob bo‘lishi kerak. Kamida bitta ko‘rish ruxsati talab qilinadi. Biriktirilgan xodimli rolning ruxsatlari saqlanishidan oldin barcha xodimlarga taalluqliligi tasdiqlanadi. Rol, tab, sidebar, klinika yoki hash navigatsiyasida saqlanmagan o‘zgarishlar uchun ogohlantirish bor; browser reload/chiqish uchun native beforeunload ishlatiladi.

Xodimi yo‘q oddiy rol tasdiq bilan o‘chiriladi; biriktirilgan (hatto faol bo‘lmagan) xodimli rol o‘chirilmaydi. Administrator himoyalangan. Uning `roles.view`, `roles.update`, `roles.manage_permissions` boshqaruv huquqlari va kamida bitta faol, tizimga kirishi yoqilgan boshqaruvchi saqlanishi shart. Bu qoida xodimni qayta biriktirish yoki kirishini o‘chirishda ham tekshiriladi.

## Yagona lokal manba va saqlanish

`src/mocks/settingsRoles.ts` — UI katalogi, validatsiya va ruxsat tekshirish mantiqi. `canDemo` rolning guruh ko‘rish/amal ruxsatlarini tekshiradi; `employeeCanDemo` faol, kirishi yoqilgan xodimning `roleId` orqali shu ruxsatlarga ega ekanini hisoblaydi. Bu funksiyalar haqiqiy route, sidebar yoki API cheklovlariga qo‘llanmaydi.

`src/components/settings/store.ts` — Sozlamalar va mavjud Xodim formasi uchun yagona reaktiv manba. Versiyalangan kalit: `blusser.settings.roles.demo.v1.<active_tenant_id>`, schema 1. Rollar, xodimlar va biriktirishlar bitta atomik lokal snapshotda saqlanadi. Xodimning mavjud jadvali, istisnolari, surati va boshqa maydonlari saqlanadi; parol formadan chiqmaydi va saqlanmaydi. Login faqat sintetik demo xodim ma’lumoti sifatida saqlanadi; haqiqiy account yaratilmaydi.

Oldingi Xodim sahifasi browser persistence ishlatmagan. Mavjud fixture va schema 1 snapshotidagi eski `role` matnlari faqat mos, aniq rol nomi mavjud bo‘lsa ID’ga ko‘chiriladi. Eski matn va boshqa maydonlar yo‘qotilmaydi. Qorovul, farrosh, haydovchi yoki mos ruxsat roli bo‘lmagan Hamshira lavozimlari avtomatik yangi rolga aylantirilmaydi; tizimga kirish yoqilgan formani saqlashda haqiqiy lokal rol tanlash kerak. Lavozim va ruxsat roli alohida. Qayta nomlashda `roleId` o‘zgarmaydi; profil va select yangi nomni shu ID orqali ko‘rsatadi.

Muvaffaqiyatsiz storage yozuvi asosiy holatni o‘zgartirmaydi. Revision boshqa oynadagi yozuv bilan farq qilsa saqlash rad etiladi, sahifani yangilash so‘raladi. Buzilgan JSON o‘qilganda xato ko‘rsatiladi, mavjud disk ma’lumoti avtomatik o‘chirilmaydi yoki almashtirilmaydi. Saqlash uchun xodim formasining eski volatile demo testlari yangi persistence talabiga moslashtirildi.

## Backend bilan moslik va keyingi integratsiya

O‘qib tekshirilgan manbalar:

- `backend/database/migrations/2026_10_15_000002_seed_permission_catalog.php` — kodlar, allowed_scopes.
- `backend/app/Modules/ClinicOperations/Contracts/OperationsContract.php` — Patients, appointments, queues, rooms/reception kodlari. Shartnoma ichidagi hali UI’da yo‘q queue amallari bu editor katalogiga qo‘shilmadi.
- `backend/app/Modules/Access/Permissions.php` — server scope tekshiruvi, implicit permission hierarchy yo‘q.
- `backend/app/Modules/Tenancy/Http/ClinicController.php` — rollar: `id`, `code`, `name`, `active`, `protected`, `grants[{code,scope}]`; permission katalogida `allowed_scopes`; role saqlashda code/name/grants talab qilinadi.

UI’da mavjud server kodlari aynan saqlangan. Mahalliy ID’lar server UUID/membership ID emas; real integratsiya adapteri server rol ID va barqaror code’ni olishi kerak. Backenddagi himoyalangan CEO (`code=ceo`) lokal Administrator demosi bilan avtomatik tenglashtirilmaydi. UI-only izoh uchun server field/migratsiya mosligi kerak. Checkboxlar scope tanlamaydi: `own`, `assigned_branches`, `entire_clinic` qiymatlarini taxminiy scope bilan to‘ldirish mumkin emas; adapter server allowed_scopes va mavjud grantlarni saqlashi shart. UI’dagi ko‘rish → ichki amal bog‘liqligi editor qoidasi, backendda implicit hierarchy degani emas.

`demo.*` kodlari backend permission katalogida mavjud emas; ularni real grantga aylantirishdan oldin alohida backend kontrakti, scope, endpoint authorization va audit talab qilinadi. Moliya, Ombor, Filiallar, Hisobotlar hozirgi frontendda placeholder: faqat ko‘rish ruxsati bor, sun’iy CRUD qo‘shilmadi. Kassa tarixini ko‘rish ruxsati shu mavjud tarix/chek/eksport UI oqimini ifodalaydi; to‘lovni o‘chirish ruxsati yo‘q. Haqiqiy route/API himoyasi hamda server role/membership assignment kelajakdagi ish; ushbu localStorage ma’lumoti real sessiyani almashtirmaydi.

## Tekshiruv

- Alohida publication nusxasida umumiy fixture brauzer regressiyasi: **80/80 o‘tdi** (3.6 daqiqa), flaky/skipped yo‘q. Kassa, Diagnostika, Laboratoriya, Shifokor, Xodimlar, Registratura, Patients kontrakt fixturelari, Xizmatlar va Bo‘limlar/Xonalar qamrab olindi.
- Yakuniy legacy-ID va asosiy sahifa → bog‘langan guruh tuzatishlaridan so‘ng ta’sirlangan Sozlamalar/Xodim brauzer testlari alohida qayta tekshirildi: **18/18 o‘tdi** (43.2 soniya). Yakuniy source fayllari publication nusxasi bilan baytma-bayt solishtirildi; build va unit testlar shu nusxada yana o‘tdi.
- `npm test`: **8 test fayli o‘tdi**, shu jumladan Sozlamalar bo‘yicha 8 ta domain holati (ID migratsiyasi, nomlar, ruxsat cascade, rollar/xodim biriktirishlari, o‘chirish/boshqaruvchi himoyasi, persistence/quota/revision).
- `npm run build`: Vue/TypeScript bilan o‘tdi. Mavjud chunk hajmi va PDF mixed-import ogohlantirishlari saqlangan.
- Brauzer: Playwright 1.64.1 + system Chromium 154; 1536 px, 760 px va 390 px ko‘rinishlar. Login/logout/CSRF va Patients ETag/paging/export oqimlari network fixturelar bilan tekshirildi. Live-backend `patients.spec.ts` ishga tushirilmadi.
- Lokal loglar: `/tmp/settings-final-browser.log` (80 ta to‘liq regressiya), `/tmp/settings-full-results.json`, `/tmp/settings-targeted-final.log`, `/tmp/settings-final-unit.log`, `/tmp/settings-final-build.log`; yonidagi `.exit` fayllar test jarayonining chiqish kodlarini saqlaydi.

 Screenshot va xom loglar faqat `/tmp` ichida; commitga kiritilmaydi. Native printer, live database/API va server ruxsat enforcement testlari bu UI vazifasida bajarilmaydi. Begona 8 ta Doctor referrals fayli saqlanadi va commitdan chiqariladi.

## Boshlang‘ich demo rollar — aniq jadval

Xodim sonlari mavjud 24 ta sintetik xodimning `roleId` biriktirishlaridan hisoblanadi (faol bo‘lmagan biriktirishlar ham sanaladi). Laborant/UZI lavozimlari bu fixture ichida yo‘q: ularga xodim to‘qib qo‘shilmadi.

| Rol (barqaror ID) | Xodim | Boshlang‘ich ruxsat kodlari |
| --- | ---: | --- |
| Administrator (administrator) | 2 | `demo.cashier.view`, `demo.cashier.shift`, `demo.cashier.pay`, `demo.cashier.history`, `demo.cashier.discount`, `demo.cashier.refund`, `dashboard.view`, `reception.view`, `demo.reception_actions.view`, `demo.reception_actions.book`, `demo.reception_actions.arrive`, `patients.view`, `patients.create`, `patients.update`, `patients.archive`, `patients.restore`, `patients.export`, `appointments.view`, `appointments.create`, `appointments.update`, `appointments.export`, `appointments.check_in`, `appointments.cancel`, `appointments.no_show`, `queues.view`, `queues.call`, `queues.start`, `demo.display.view`, `demo.display.create`, `demo.display.update`, `demo.display.delete`, `demo.display.preview`, `demo.workspace.view`, `demo.workspace.consult`, `demo.workspace.prescribe`, `demo.workspace.refer`, `demo.workspace.upload`, `demo.workspace.complete`, `demo.workspace.export`, `demo.laboratory.view`, `demo.laboratory.sample`, `demo.laboratory.start`, `demo.laboratory.result`, `demo.laboratory.review`, `demo.laboratory.return`, `demo.laboratory.confirm`, `demo.laboratory.amend`, `demo.laboratory.export`, `demo.diagnostics.view`, `demo.diagnostics.start`, `demo.diagnostics.result`, `demo.diagnostics.confirm`, `demo.diagnostics.amend`, `demo.diagnostics.export`, `services.view`, `services.create`, `services.update`, `services.delete`, `demo.categories.view`, `demo.categories.create`, `demo.categories.update`, `demo.categories.status`, `demo.categories.export`, `demo.finance.view`, `demo.inventory.view`, `employees.view`, `employees.create`, `employees.update`, `employees.assign_role`, `demo.employee_schedule.view`, `demo.employee_schedule.update`, `branches.view`, `rooms.view`, `rooms.create`, `rooms.update`, `demo.departments.view`, `demo.departments.create`, `demo.departments.update`, `demo.departments.status`, `demo.reports.view`, `roles.view`, `roles.create`, `roles.update`, `roles.delete`, `roles.manage_permissions` |
| Registrator (registrator) | 3 | `dashboard.view`, `reception.view`, `demo.reception_actions.view`, `demo.reception_actions.book`, `demo.reception_actions.arrive`, `patients.view`, `patients.create`, `patients.update`, `appointments.view`, `appointments.create`, `appointments.update`, `appointments.export`, `appointments.check_in`, `appointments.cancel`, `appointments.no_show`, `queues.view`, `queues.call`, `queues.start` |
| Shifokor (doctor) | 6 | `dashboard.view`, `patients.view`, `appointments.view`, `queues.view`, `demo.workspace.view`, `demo.workspace.consult`, `demo.workspace.prescribe`, `demo.workspace.refer`, `demo.workspace.upload`, `demo.workspace.complete`, `demo.workspace.export` |
| Laborant (lab) | 0 | `demo.laboratory.view`, `demo.laboratory.sample`, `demo.laboratory.start`, `demo.laboratory.result`, `demo.laboratory.review`, `demo.laboratory.export` |
| UZI mutaxassisi (uzi) | 0 | `demo.diagnostics.view`, `demo.diagnostics.start`, `demo.diagnostics.result`, `demo.diagnostics.confirm`, `demo.diagnostics.amend`, `demo.diagnostics.export` |
| Kassir (cashier) | 2 | `demo.cashier.view`, `demo.cashier.shift`, `demo.cashier.pay`, `demo.cashier.history` |

## UI katalogi va integratsiya kelib chiqishi

`demo.*` — faqat mavjud frontend amallari uchun lokal kodlar; ularni serverga grant sifatida yuborish mumkin emas. Server kodlari backend katalogi bilan mos. Quyidagi katalogda har bir guruhning birinchi ruxsati ko‘rish ruxsati; o‘chirilsa, guruhdagi ichki amallar ham o‘chadi. Asosiy sahifa ko‘rish huquqi o‘chirilsa, unga bog‘langan kategoriya, registratura amallari, xodim jadvali va bo‘lim guruhlari ham tozalanadi va bloklanadi.

| Guruh | Kod va foydalanuvchi nomi |
| --- | --- |
| Kassa | `demo.cashier.view` — Kassani ko‘rish; `demo.cashier.shift` — Smena ochish va yopish; `demo.cashier.pay` — To‘lov qabul qilish; `demo.cashier.history` — To‘lovlar tarixini ko‘rish; `demo.cashier.discount` — Chegirma berish; `demo.cashier.refund` — Pul qaytarish |
| Bosh sahifa | `dashboard.view` — Bosh sahifani ko‘rish |
| Registratura | `reception.view` — Registraturani ko‘rish |
| Registratura amallari | `demo.reception_actions.view` — Registratura amallarini ko‘rish; `demo.reception_actions.book` — Yozilish va navbatsiz qabul; `demo.reception_actions.arrive` — Kelgan bemorni qabulga olish |
| Bemorlar | `patients.view` — Bemorlarni ko‘rish; `patients.create` — Bemor qo‘shish; `patients.update` — Bemorni tahrirlash; `patients.archive` — Arxivlash; `patients.restore` — Arxivdan tiklash; `patients.export` — Bemorlar eksporti |
| Qabullar | `appointments.view` — Qabullarni ko‘rish; `appointments.create` — Qabul yaratish; `appointments.update` — Qabulni tahrirlash va holatini o‘zgartirish; `appointments.export` — Qabullar eksporti; `appointments.check_in` — Kelganini belgilash; `appointments.cancel` — Qabulni bekor qilish; `appointments.no_show` — Kelmaganini belgilash |
| Navbatlar | `queues.view` — Navbatlarni ko‘rish; `queues.call` — Chaqirish; `queues.start` — Qabulni boshlash |
| Tablo boshqaruvi | `demo.display.view` — Tablolarni ko‘rish; `demo.display.create` — Ekran qo‘shish; `demo.display.update` — Ekranni sozlash; `demo.display.delete` — Ekranni o‘chirish; `demo.display.preview` — TV ko‘rinishini ochish |
| Shifokor kabineti | `demo.workspace.view` — Shifokor kabinetini ko‘rish; `demo.workspace.consult` — Ko‘rik ma’lumotlarini saqlash; `demo.workspace.prescribe` — Retsept yozish; `demo.workspace.refer` — Yo‘llanma berish; `demo.workspace.upload` — Fayl biriktirish; `demo.workspace.complete` — Qabulni yakunlash; `demo.workspace.export` — Natijani PDF / chop etish |
| Laboratoriya | `demo.laboratory.view` — Laboratoriyani ko‘rish; `demo.laboratory.sample` — Namuna olish; `demo.laboratory.start` — Tahlilni boshlash; `demo.laboratory.result` — Natija kiritish; `demo.laboratory.review` — Tekshirishga yuborish; `demo.laboratory.return` — Qayta ishlashga qaytarish; `demo.laboratory.confirm` — Natijani tasdiqlash; `demo.laboratory.amend` — Tuzatish kiritish; `demo.laboratory.export` — PDF / chop etish |
| UZI va Diagnostika | `demo.diagnostics.view` — Diagnostikani ko‘rish; `demo.diagnostics.start` — Tekshiruvni boshlash; `demo.diagnostics.result` — Natija va fayllar kiritish; `demo.diagnostics.confirm` — Natijani tasdiqlash; `demo.diagnostics.amend` — Tuzatish kiritish; `demo.diagnostics.export` — PDF / chop etish |
| Xizmatlar | `services.view` — Xizmatlarni ko‘rish; `services.create` — Xizmat qo‘shish; `services.update` — Xizmatni tahrirlash; `services.delete` — Xizmatni faolsizlantirish / faollashtirish |
| Xizmat kategoriyalari | `demo.categories.view` — Kategoriyalarni ko‘rish; `demo.categories.create` — Kategoriya qo‘shish; `demo.categories.update` — Kategoriyani tahrirlash; `demo.categories.status` — Kategoriya holatini o‘zgartirish; `demo.categories.export` — Xizmatlar CSV eksporti |
| Moliya | `demo.finance.view` — Moliya sahifasini ko‘rish |
| Ombor | `demo.inventory.view` — Ombor sahifasini ko‘rish |
| Xodimlar | `employees.view` — Xodimlarni ko‘rish; `employees.create` — Xodim qo‘shish; `employees.update` — Xodimni tahrirlash; `employees.assign_role` — Ruxsat rolini biriktirish |
| Xodim ish jadvali | `demo.employee_schedule.view` — Ish jadvalini ko‘rish; `demo.employee_schedule.update` — Jadval va istisnolarni boshqarish |
| Filiallar | `branches.view` — Filiallar sahifasini ko‘rish |
| Bo‘limlar va Xonalar | `rooms.view` — Xonalarni ko‘rish; `rooms.create` — Xona qo‘shish; `rooms.update` — Xonani tahrirlash |
| Bo‘limlar boshqaruvi | `demo.departments.view` — Bo‘limlarni ko‘rish; `demo.departments.create` — Bo‘lim qo‘shish; `demo.departments.update` — Bo‘limni tahrirlash; `demo.departments.status` — Bo‘lim / xona holatini o‘zgartirish |
| Hisobotlar | `demo.reports.view` — Hisobotlar sahifasini ko‘rish |
| Sozlamalar — rollar | `roles.view` — Rollarni ko‘rish; `roles.create` — Rol qo‘shish; `roles.update` — Rolni tahrirlash; `roles.delete` — Rolni o‘chirish; `roles.manage_permissions` — Ruxsatlarni boshqarish |
