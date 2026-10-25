# Registratura — kelish va jonli navbat API

Ushbu o‘zgarish faqat `Bemorni ro‘yxatga olish` modalini haqiqiy API'ga o‘tkazadi. `Qabulga yozish` saqlanadi.

## Uchta oqim

1. **Oldindan yozilgan**: `GET /patients` → `GET /patients/{id}/appointments` (filialning bugungi kuni bo‘yicha) → `POST /appointments/{id}/check-in` (`expected_version`). Server `visit`, `queue_ticket` va `ticket.number` qaytaradi.
2. **Jonli navbat**: `GET /patients` yoki `POST /patients` → `GET /appointments/booking-options` → `POST /queue-tickets` (`patient_id`, `doctor_id`, `branch_id`, `service_id`). Appointment yaratilmaydi; server navbat raqamini ajratadi.
3. **Faqat ro‘yxatga olish**: `POST /patients`. Boshqa attendance API chaqirilmaydi. Mavjud bemor tanlansa yangi bemor yaratilmaydi.

Agar tanlangan bemor filialga biriktirilmagan bo‘lsa, `POST /patients/{id}/branches` talab qilinadi; `patients.link_branch` huquqi shart. Permission va filialni tekshirish backendda qoladi.

`clinicRequest` session/CSRF, context guard va mutatsiyalardagi `Idempotency-Key`ni saqlaydi. Ikki marta bosish frontendda bloklangan, serverdagi idempotency va unikallik qo‘shimcha himoya beradi. Bemor yaratilib, keyingi navbat so‘rovi xato bersa, tanlangan bemor saqlanadi va qayta yaratish talab qilinmaydi.

## Filial va sanalar

Filial `timezone` qiymatiga ko‘ra `localDay` va DST-safe `dayRange` ishlatiladi. Jonli navbatda vaqt belgilanmaydi; navbat raqami serverdan olinadi. Registratura “Jonli navbat” tabi `GET /queue-tickets`ni o‘qiydi; hozircha navbatni chaqirish/yakunlash boshqa modullarning vazifasi.

## Kechikib kelish siyosati (migratsiya 000023)

Oldingi `check_in` har qanday o'tgan start vaqtini rad qilardi. Endi check-in **belgilangan qabul tugaganidan 60 daqiqa keyingacha** qabul qilinadi. Erta kelish uchun eski siyosat saqlangan. Qabulning asl start/end va rezervatsiyasi o‘zgartirilmaydi. Server `scheduled` holat, `expected_version`, haqiqiy filial/doctor/service/room, reservation va permissionlarni tekshiradi; muddati o‘tib ketgan qabul yoki bekor qilingan qabul qayta check-in qilinmaydi.

Migratsiya avvalgi `governance.booking_command`ni backup qiladi va qaytarish (`down`) imkonini beradi. Testdan oldin `blusser_test` DB, real bazada esa backup bo‘lishi kerak.

## Tekshirish

- `cd frontend && npm run build`
- `cd frontend && npx playwright test tests/browser/reception-arrival-api.spec.ts tests/browser/reception-permissions.spec.ts`
- `cd backend && php artisan test --filter='test_late_patient_can_check_in_within_grace_window_without_duplicate_visit'` (`blusser_test` konfiguratsiyasi talab qilinadi)
- Mavjud API/PostgreSQL queue regressiyalarini `AppointmentsApiTest` va `QueuesApiTest`da ko‘rish mumkin.

**Tekshiruv statusi:** kod va testlar GitHub'ga yozildi; ushbu ChatGPT muhitida haqiqiy Docker/PostgreSQL yoki browser testlari ishga tushirilmagan. CI manual holatda qoladi. Merge faqat tegishli testlar o‘tgach.
