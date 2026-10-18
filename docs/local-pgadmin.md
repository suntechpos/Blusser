# Lokal pgAdmin

pgAdmin alohida `admin` Compose profilida. Backend yoki DB’ni oddiy ishga
tushirish uni start qilmaydi. Web port qat’iy `127.0.0.1:5050:80`;
PostgreSQL’ning hostga port ochish konfiguratsiyasi qo‘shilmagan.

## Birinchi sozlash

Repo ildizidagi mavjud, Git ignore qilingan `.env`ni lokal muharrirda oching:

```sh
nano .env
```

Faqat quyidagi pgAdmin kalitlarini qo‘shing/to‘ldiring:

```dotenv
PGADMIN_DEFAULT_EMAIL=admin@example.com
PGADMIN_DEFAULT_PASSWORD=
```

Parol uchun `PGADMIN_DEFAULT_PASSWORD`ning bo‘sh qiymatini muharrirda o‘zingiz
tanlagan kuchli parol bilan to‘ldiring. Haqiqiy qiymatni `.env.example`, shell
buyrug‘i, Git yoki hisobotga ko‘chirmang. `.env` qiymatida `$`, `#` yoki bo‘shliq
ishlatilsa Compose’ning single-quote qoidalariga rioya qiling. Mavjud DB
parollari, APP_KEY va boshqa konfiguratsiyani saqlang. Fayl ruxsati:

```sh
chmod 600 .env
```

pgAdmin web login’i PostgreSQL yoki Blusser ilovasi loginidan alohida.
Bo‘sh web parol bilan konteyner ishga tushmaydi. Default email ishlatilishi
mumkin, ammo repoda default parol yo‘q.

## Ishga tushirish

Loyiha ildizidan, lokal Docker Compose v2 bilan:

```sh
docker compose --profile admin up -d --wait pgadmin
```

Brauzerda **http://127.0.0.1:5050** ni oching. `.env`dagi
`PGADMIN_DEFAULT_EMAIL` va `PGADMIN_DEFAULT_PASSWORD` bilan kiring.
`up` mavjud `postgres_data`ni qayta ishlatadi; DB init yoki migratsiya
buyrug‘i bajarilmaydi. DB allaqachon healthy bo‘lsa uni umuman qayta boshqarmaslik
uchun `up -d --wait --no-deps pgadmin` ishlatish ham mumkin.

## PostgreSQL ulanishi

pgAdmin’da **Register → Server** tanlang:

| Maydon | Qiymat |
|---|---|
| General → Name | `Blusser local` |
| Connection → Host name/address | `db` |
| Port | `5432` |
| Maintenance database | `blusser` |
| Username | `blusser_owner` |
| Password | Mavjud root `.env`dagi `DB_OWNER_PASSWORD` qiymatini maxfiy kiriting |
| Save password | O‘chirilgan holda qoldiring |
| SSL mode | `Prefer` — standart lokal Compose PostgreSQL ulanishi uchun |

Host `db` — umumiy Compose tarmog‘idagi PostgreSQL servisi. pgAdmin ichida
`localhost` pgAdmin konteynerining o‘zini anglatadi.

`blusser_owner` mavjud lokal deployment/DB-ma’muri roli. Bu ulanish Laravel
runtime konfiguratsiyasini o‘zgartirmaydi va platform admin akkauntiga tenant
huquqi bermaydi. `blusser_app` runtime roli uchun FORCE RLS va tenant context
talablari saqlanadi; GUI’da runtime roli bilan kontekstsiz klinik yozuvlar
ko‘rinmasligi kutilgan holat. Hech qanday yangi DB role yoki RLS istisnosi yo‘q.

## Saqlash va to‘xtatish

- Eski DB ma’lumotlari: avvalgidek `postgres_data:/var/lib/postgresql/data`.
- Yangi pgAdmin sozlamalari: `pgadmin_data:/var/lib/pgadmin`.
- pgAdmin image’i versiya va immutable digest bilan pin qilingan.
- `.env`dagi pgAdmin default login/paroli faqat bo‘sh pgAdmin volume birinchi
  inicializatsiyasida qo‘llanadi. Keyinchalik `.env`ni almashtirish mavjud web
  akkaunt parolini qayta o‘rnatmaydi; parolni pgAdmin interfeysidan o‘zgartiring.

Faqat pgAdmin’ni to‘xtatish:

```sh
docker compose --profile admin stop pgadmin
```

Mavjud volume’larni saqlash uchun `docker compose down -v` yoki volume prune
ishlatmang. Ushbu qo‘shimcha DB schema, backend, frontend yoki provisioning
jarayonlarini o‘zgartirmaydi.

## Bajarilgan tekshiruvlar

- Oddiy Compose va `admin` profil konfiguratsiyalari validatsiyadan o‘tdi.
- Avvalgi barcha service konfiguratsiyalari va `postgres_data` ta’rifi bir xil.
- pgAdmin healthy holatga keldi va haqiqiy Chromium brauzerida login o‘tdi.
- Docker port binding aynan `127.0.0.1:5050` ekanligi tekshirildi.
- pgAdmin konteyneridan `db:5432`ga `blusser_test`da faqat o‘qish probe’i o‘tdi.
- Ishlayotgan PostgreSQL konteyner ID’si va volume mount’lari o‘zgarmadi.
- Bo‘sh web parol bilan start rad etildi.

Cloud tekshiruvi alohida vaqtinchalik pgAdmin volume va xotirada yaratilgan
parol bilan bajarildi; bu sizning WSL bazangiz yoki pgAdmin akkauntingiz emas.
