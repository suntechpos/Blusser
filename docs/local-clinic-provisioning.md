# Lokal klinika, CEO va filial provisioning

`python3 scripts/provision-local-clinic` mavjud Sanctum/provisioning API’dan
foydalanadi. SQL, migration credentials, role flag o‘zgartirish yoki platform
uchun klinik tenant bypass qo‘llanmaydi. Frontend sahifalari o‘zgarmaydi.

## WSL’da ishga tushirish

Python 3.10+ va loyihaning tayyor lokal Docker muhiti kerak. PR #10 backend
kodi va migratsiyalari, shu jumladan 000011 context projection, mavjud bo‘lsin.
Oddiy lokal ishga tushirish (mavjud .env va volume’ni saqlagan holda):

```sh
docker compose up -d --wait db
docker compose run --rm migrate
docker compose up -d --wait backend
python3 scripts/provision-local-clinic
```

Skriptning o‘zi migratsiya yoki DB reset bajarmaydi. Test fixture va test suite’ni
lokal `blusser` bazasida ishlatmang; ular faqat disposable `blusser_test` uchun.

Default API — `http://localhost:8000`, Origin — `http://localhost:5173`.
Portlar boshqacha bo‘lsa:

```sh
python3 scripts/provision-local-clinic \
  --base-url http://localhost:8001 \
  --origin http://localhost:5173
```

API va Origin bir xil hostname/sxemada bo‘lsin. Origin backend’ning mavjud
first-party allowlist’iga mos bo‘lishi kerak. Local HTTP cookie konfiguratsiyasi
README’da bayon qilingan; HTTPS uchun TLS tekshiruvi o‘chirilmaydi.

Skriptni branch almashmasdan olish ham mumkin (repo ildizida):

```sh
git fetch origin
git show origin/feature/patients-frontend-integration:scripts/provision-local-clinic > /tmp/blusser-provision-local.py
python3 /tmp/blusser-provision-local.py
```

Bu yo‘l faqat yordamchi skriptni oladi: backend kodi va migratsiyalari alohida
ravishda yuqoridagi shartlarga mos bo‘lishi kerak.

## Terminal jarayoni

1. Platform admin login/paroli. Parol ko‘rinmaydi. Skript yangi, xotiradagi cookie
   session orqali haqiqiy login qiladi va to‘liq platform vakolatini tekshiradi.
2. Klinika slug’i. Shu slug mavjud bo‘lsa, faqat active klinika qayta ishlatiladi.
   Yangi bo‘lsa klinika nomi, alohida CEO logini va vaqtinchalik parol so‘raladi.
3. `POST /api/v1/platform/clinics` orqali standart provisioning. Global login
   band bo‘lsa `pending_consent` holatida to‘xtaydi; account paroli o‘zgartirilmaydi.
   Consent/recovery bu skript bilan avtomatlashtirilmaydi.
4. Platform skript sessiyasi yopiladi. CEO real login qiladi. `must_change_password`
   bo‘lsa yangi doimiy parol ikki marta yashirin so‘raladi va haqiqiy
   `/auth/password` orqali almashtiriladi. Vaqtinchalik parol qayta ishlatilmaydi.
5. CEO’ning o‘z klinikalari ro‘yxati tekshiriladi, serverda faol klinika tanlanadi.
   O‘z membership’i va protected `ceo` role API orqali tasdiqlanadi. Role nomi
   o‘zicha ruxsat bermaydi; barcha amallar backend RBAC/RLS tekshiruvidan o‘tadi.
6. Filial kodi/nomi so‘raladi. Shu kodli faol filial bo‘lsa qayta ishlatiladi;
   yo‘q bo‘lsa CEO orqali mavjud `POST /clinic/branches` chaqiriladi. Mavjud
   filialni rename/reactivate qilish yoki membership o‘zgartirish bajarilmaydi.
7. Patients ro‘yxatiga haqiqiy GET bilan kirish tekshiriladi. Skript sessiyalari
   yopiladi. Brauzerdagi oldingi platform sessiyasi alohida: undan chiqing,
   CEO login va yangi doimiy parol bilan kiring, `/#bemorlar`ni oching.

Klinika, account, parol almashtirish va filial auditini mavjud backend yozadi.
Skript parol, cookie yoki request/response dump’larini faylga saqlamaydi.
Parollar CLI argument yoki environment variable orqali olinmaydi; `getpass`
ko‘rinadigan input’ga fallback qilsa skript to‘xtaydi. Secret’larni Git/chat/log’ga
ko‘chirmang. Brauzerdagi mavjud login/logout jarayoni o‘zgarmaydi.

## Qisman bajarilish va xatolar

API yozishlari avtomatik takrorlanmaydi. Timeout’dan keyin natija noma’lum bo‘lishi
mumkin. Ayni slug/filial kodi bilan qayta ishga tushirish mavjud yozuvlarni topadi;
klinika va filial qayta yaratilmaydi. Mavjud CEO uchun joriy parol so‘raladi:
parol avval almashtirilgan bo‘lsa doimiy parolni kiriting. Noma’lum/eskirgan
parol uchun standart CEO recovery kerak; hash yoki platform flag o‘zgartirmang.
Pending/suspended klinika yoki faol bo‘lmagan filial avtomatik faollashtirilmaydi.

HTTP xatolarida status va umumiy izoh ko‘rsatiladi; secret aks etishi mumkin bo‘lgan
server response body hech qachon chiqarilmaydi. Redirect hamda tashqi hostlar rad
etiladi. Logout tasdiqlanmasa alohida xabar chiqadi; xotiradagi cookie baribir
o‘chiriladi. Skriptning o‘zi to‘liq transaction emas: har bir API amali backend’da
alohida transaction/audit bilan bajariladi.

## Tekshiruv

```sh
python3 -m unittest discover -s scripts/tests -v
```

6 ta test: CSRF cookie rotation/URL decode, secret redaction, mutatsiyani avtomatik
qaytarmaslik, redirect’ni to‘xtatish, lokal URL chegarasi, yashirin input fallback
va noto‘g‘ri CLI argumentidagi secret’ni chiqarmaslik.

Haqiqiy HTTP smoke faqat `blusser_test`ga ulangan runtime API bilan bajarildi:
yangi klinika/CEO, majburiy password change, filial va Patients read muvaffaqiyatli;
qayta ishga tushirish mavjud klinika/filialni ishlatdi. Test uchun parollar xotirada
yaratildi, output’da yo‘qligi tekshirildi. WSL’dagi bazangizda hech qanday amal
bu cloud muhitdan bajarilmadi. Boshqa sahifalar va frontend integratsiya scope’i
kengaytirilmagan.
