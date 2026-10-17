# Lokal Swagger UI

Swagger UI alohida `docs` Compose profilida ishlaydi. Oddiy Compose start uni
ishga tushirmaydi. Image immutable digest bilan pin qilingan.

## Ishga tushirish

Mavjud lokal `.env`ni saqlagan holda, loyiha ildizida:

```sh
docker compose --profile docs up -d --wait swagger
```

Brauzerda **http://127.0.0.1:8081** ni oching. Port faqat loopback manziliga
bog‘langan. Swagger uchun yangi parol, token yoki `.env` kaliti kerak emas.
Servis backend, PostgreSQL yoki pgAdmin’ni ishga tushirmaydi.

`docs/openapi.yaml` konteynerga read-only ulanadi va Swagger UI shu faylni
ko‘rsatadi. Boshqa OpenAPI fayllari avtomatik birlashtirilmaydi. Faylni
yangilagandan keyin nusxasini qayta yuklash uchun:

```sh
docker compose --profile docs restart swagger
```

So‘ng brauzer sahifasini yangilang.

## Xavfsizlik va cheklovlar

Bu dokumentatsiyani ko‘rish interfeysi. `Try it out` va API operatsiyalarini
yuborish, authorization’ni doimiy saqlash, URL query orqali konfiguratsiyani
almashtirish hamda tashqi validator o‘chirilgan. API autentifikatsiyasi uchun
token yoki cookie konfiguratsiyaga kiritilmagan.

Mavjud Sanctum login/logout, CSRF, permissions va RLS o‘zgartirilmagan.
Swagger uchun backend CORS yoki session sozlamalari kengaytirilmagan.
Autentifikatsiyali API amallari mavjud frontend yoki amaldagi xavfsiz API
jarayoni orqali bajariladi.

pgAdmin avvalgidek `admin` profilida `127.0.0.1:5050` orqali ishlaydi;
[pgAdmin yo‘riqnomasi](local-pgadmin.md) va mavjud volume’lar saqlangan.

## To‘xtatish

```sh
docker compose --profile docs stop swagger
```

Volume’larni o‘chiradigan `down -v` yoki prune buyrug‘i kerak emas.

## Tekshiruv natijalari

- Compose konfiguratsiyasi valid; konteyner healthy holatga keldi.
- Port binding faqat `127.0.0.1:8081`; spec mount read-only.
- HTTP orqali berilgan spec Git’dagi `docs/openapi.yaml` bilan baytma-bayt bir xil.
- Chromium’da barcha 68 ta path va mavjud security schemes to‘g‘ri yuklandi.
- API yuborish tugmalari, saqlanadigan authorization va tashqi so‘rovlar yo‘q.
- Avvalgi barcha servislar, pgAdmin va volume konfiguratsiyalari o‘zgarmagan.
- Ilova kodi o‘zgarmagani uchun backend/DB testlari bajarilmadi; DB yozuvlari
  o‘zgartirilmadi.
