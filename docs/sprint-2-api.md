# Sprint 2 API: cookie auth и управление клиникой

Контракт: [openapi.yaml](openapi.yaml), OpenAPI 3.1 в JSON-совместимом YAML 1.2.
Файл можно импортировать в Swagger Editor/Postman. В image он включён для
автоматической проверки полного совпадения documented paths/methods с Laravel routes.

## Локальный запуск

Требуются Docker/Compose, Git и Python 3. Из корня:

```sh
./scripts/init-local-env # только если корневой .env ещё отсутствует
docker compose build backend
docker compose up -d --wait db
docker compose run --rm migrate
docker compose up -d --wait backend
docker compose run --rm test
```

Существующую `.env` не перезаписывать и data volume не удалять. Новая миграция
000005 применяется поверх Sprint 1/CEO governance, без изменения старых миграций.
Runtime `backend` не получает owner/postgres credentials. Все API queries выполняются
как blusser_app; только migrate/test services получают отдельные owner credentials.

Первого platform operator создаёт deployer:

```sh
docker compose run --rm migrate php artisan blusser:platform-admin YOUR_LOGIN
```

Команда скрыто запрашивает пароль дважды, хранит Argon2id hash, пишет audit.
Она не повышает существующий account и не принимает пароль через CLI arguments.
В runtime без migration credentials команда откажет. Нет default/seeder accounts.
Для CEO recovery нужны два разных полномочных platform operators. Не создавайте
реальные клинические аккаунты до TLS/deployment/runbook проверки.

## Session flow

1. GET `/sanctum/csrf-cookie`: сохранить session и XSRF-TOKEN cookies.
2. POST `/api/v1/auth/login`: JSON login/password; Origin (или Referer) из
   SANCTUM_STATEFUL_DOMAINS и X-XSRF-TOKEN со значением текущей XSRF cookie.
3. Для временного пароля разрешены только GET auth/me, POST auth/password и
   POST auth/logout. Password change требует current_password, password и
   password_confirmation; password 12–128 символов, отличается от текущего.
4. После смены/входа/logout принять новые cookies/CSRF token: session id
   и token ротируются; старые cookies нельзя повторно использовать.
5. GET auth/clinics → POST auth/active-clinic с tenant_id из собственного списка.
   Tenant context берётся только из server session; произвольные tenant/actor
   поля и заголовки не используются для авторизации.
6. Все `/api/v1/clinic/*` проверяют actual membership, current grants и RLS scopes.
   Выбор другой клиники заново проверяется на сервере; отзыв членства работает
   даже для уже выбранной клиники. Full platform identity не выбирает клинику.

Cookie HttpOnly, SameSite=Lax; Secure по умолчанию, local Compose HTTP — явное
исключение. Deploy только по HTTPS; задайте APP_URL и first-party allowlist без
wildcards, SESSION_SECURE_COOKIE=true. Trusted proxies по умолчанию отсутствуют;
не доверять X-Forwarded-For от клиента. Перед proxy deployment требуется явная
проверка адресов и HTTPS forwarding. Bearer/remember-me/registration отсутствуют.
Все ответы API/CSRF — no-store. Нельзя логировать credential request/response body,
query bindings, cookies, XSRF или recovery token. Current password требуется заново
на каждой sensitive reset/link/recovery операции, не хранится в сессии.

Login: atomic PostgreSQL quota 5 неудач/login/15 минут и 30 попыток/IP/15 минут.
Дополнительные actor/action/IP buckets защищают change/reset/lookup/link/recovery;
на этом этапе ceilings фиксированы. Успех не сбрасывает IP bucket. Нет вечного
lockout. Сессии живут 120 минут inactivity по умолчанию. Пароль/reset/recovery
увеличивает credential version и удаляет все сессии; текущая смена создаёт одну
новую сессию. Logout отзывает только текущую. Late authenticated writer не может
вставить удалённую сессию, даже если credential version не изменилась.

## Platform и tenant операции

Platform создаёт `/platform/clinics` с name, slug, ceo_login и временным паролем
для нового global login. Возвращаются только tenant_id/pending_consent; пароль
передаётся CEO отдельно безопасным каналом. Для existing login — pending tenant и
согласие владельца через auth/links, без нового пароля или дубликата. Ровно одна
защищённая CEO role/member; нет рядовых сотрудников или готовых должностей.

CEO создаёт custom roles и grants (code + scope). Own/assigned_branches применяются
только к поддерживаемым permissions; для административных mutations нужен
entire_clinic. Создание сотрудника требует employees.create + assign_role и
assign_branches при назначении филиалов. Администратор задаёт временный пароль,
не получает существующий hash; response содержит только membership id. Global login
conflict — 409, вместо перезаписи требуется identity-lookup/link consent.
Для assigned_branches дополнительно сравниваются реальные branch sets, а не
только названия scope: нельзя выдать, изменить или reset-нуть роль/аккаунт, который
позволит получить филиалы за пределами полномочий actor. Проверка выполняется
в той же транзакции после mutation, до commit, под её locks.
Own membership/role/branches и protected CEO менять нельзя. Старые и новые role
grants проверяются относительно потолка actor, last CEO не удаляется.

Employee DELETE — деактивация membership, не удаление global user. Reset требует
current password actor + employees.reset_password; запрещён для self, CEO,
platform, higher-privilege и любых multi-clinic identities, включая inactive links.
Multi-clinic сотрудник меняет свой пароль с текущим; self-service recovery — вне
Sprint 2. Назначение филиалов не даёт доступа к другим tenants.

Lists ограничены 50 rows/page. Read employee directory возвращает только разрешённую
projection без credentials; own/assigned_branches и known foreign UUID проверены
HTTP-тестами. Нет массового поиска global identities; lookup возвращает лишь boolean.
Tenant audit проверяет audit.view/scope; platform audit — только provisioning/CEO
recovery allowlist. Критические успешные изменения атомарны с audit; SQL permission
отказы пишут operation.denied после rollback, без параметров запроса.

## CEO recovery и границы

Оператор открывает request действующего CEO, другой независимо подтверждает
личность вне приложения и утверждает. Non-personal verification_reference —
ссылка на завершённую внешнюю проверку, не её замена. Оба заново вводят свой пароль.
Одноразовый случайный token возвращается verifier один раз для доставки по
проверенному каналу; сохраняется digest, срок token 15 минут/request 24 часа.
CEO предъявляет token и сам задаёт новый пароль; sessions отзываются, memberships
не меняются. Ordinary employees не восстанавливаются platform. Отзыв оператора,
смена версии credentials target, expiry/replay приводят к отказу.

Frontend, services CRUD, пациенты, медицина, очереди, платежи и передача CEO не
входят. Нужны production TLS/web server, backup/restore, Argon2id benchmark,
аудит retention и эксплуатационные recovery/delivery procedures. Backend completion
не означает production certification или автоматическую проверку личности.

Истёкший или отклонённый CEO linking proposal оставляет tenant в pending, без
доступа. Повторная выдача такого proposal через HTTP пока не реализована и требует
отдельного контролируемого процесса; нельзя обходить consent новым дубликатом user.
