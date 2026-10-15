# Blusser

Multi-tenant SaaS для независимых частных клиник. Sprint 1: **Backend Foundation**.
Laravel 13, PHP 8.4, PostgreSQL 17, Docker Compose. Frontend не реализован.
Согласованные архитектурные документы находятся в [docs](docs/architecture.md).

## Что входит в Sprint 1

- Laravel REST-каркас, `/api/v1/health`, начальная [OpenAPI](docs/openapi.yaml).
- PostgreSQL-схема global identity, tenants, branches, гибкого RBAC, services,
  linking, sessions, rate-limit storage и audit; только синтетические тестовые fixtures.
- Раздельные migration/runtime роли, FORCE RLS, составные tenant FK, read policies
  с action/scope и отказ по умолчанию. Runtime не может изменять бизнес-таблицы.
- Sanctum: first-party stateful middleware, CSRF-cookie handshake и cookie guard.
  Bearer tokens, публичная регистрация и default credentials отсутствуют.
- Интеграционные тесты выполняют проверки как `blusser_app`, а не superuser.

## Локальный запуск

Нужны Docker Engine с Compose v2, Git и Python 3 для генерации локальных секретов.
PHP/Composer на хосте не нужны. Команды выполняются из корня репозитория.

```sh
./scripts/init-local-env
docker compose build backend
docker compose up -d --wait db
docker compose run --rm migrate
docker compose up -d --wait backend
curl --fail http://localhost:8000/api/v1/health
# Ожидается {"status":"ok"}.
```

`init-local-env` создаёт ignored `.env` с правами 0600 и отдельными случайными
паролями, не печатает их и отказывается перезаписать существующий файл.
Шаблоны `.env.example` и `backend/.env.example` не содержат секретов. Для Compose
используется только **корневой** `.env`; создавать backend/.env не требуется.
Если `.env` уже создан вручную из примера, заполните пустые значения локально;
значения с `$`, `#` или пробелами нужно корректно заключать в кавычки Compose env.
APP_KEY — `base64:` плюс 32 случайных байта в base64. Не коммитьте значения.

БД не публикует порт на хост, backend доступен только на 127.0.0.1:8000.
Изменить порт можно через BACKEND_PORT. Secure cookie отключена только для
локального HTTP; для HTTPS укажите SESSION_SECURE_COOKIE=true.
`artisan serve` и image с dev-зависимостями предназначены для локальной разработки,
не для production deployment. Production TLS/web server и backup — следующий этап.

## Миграции и роли

`db` инициализирует `blusser` и отдельную `blusser_test`, а также роли:

- `blusser_owner`: LOGIN, NOSUPERUSER, NOBYPASSRLS; владелец таблиц и миграций.
- `blusser_app`: LOGIN, NOSUPERUSER, NOBYPASSRLS, не owner, без CREATE/SET ROLE
  к привилегированным ролям. Только разрешённые reads и anonymous session storage.
- `blusser_security`: NOLOGIN, BYPASSRLS; только SELECT нужных таблиц для узких
  boolean/scope helpers с фиксированным search_path. Runtime не член этой роли.

Owner credential получает только одноразовый сервис `migrate` и тестовый сервис.
Процесс API не получает MIGRATION_DB_PASSWORD или POSTGRES_PASSWORD.
Миграции запускаются явно с `--database=migration`, не автоматически при старте API.
Migration-owner имеет отдельные RLS policies для provisioning/DDL; app — нет.
Bootstrap роли находятся в docker/postgres/10-bootstrap.sh. Скрипт используется
только при первом создании data volume: изменение `.env` не меняет пароли в
существующей БД. Ротацию выполняют отдельно с административным доступом, без
удаления пользовательских данных. Пароли не передаются SQL-литералами в Git.

Повторный `docker compose run --rm migrate` безопасен: Laravel отслеживает миграции.
Rollback для проверки на disposable базе: `docker compose run --rm migrate php
artisan migrate:rollback --database=migration --force`. Он удаляет схему, поэтому
не применяйте к базе с нужными данными. Штатная остановка сохраняет volume:

```sh
docker compose down
docker compose up -d --wait db backend
```

**Не используйте `down -v` для обычной остановки:** это удаляет данные.

## Проверки

```sh
# Сервис test жёстко направлен в blusser_test, НЕ рабочую blusser.
# Тесты пересоздают схему и очищают synthetic fixtures этой базы.
docker compose run --rm test
# Проверка стиля и целостности зависимостей:
docker compose run --rm test vendor/bin/pint --test
docker compose run --rm test composer validate --strict
docker compose run --rm test composer check-platform-reqs
```

PHPUnit дополнительно отказывается выполнять destructive setup, если database name
не `blusser_test`. Для генерации нового образа после изменения файлов повторите
`docker compose build backend`: исходники включены в image, bind mount не используется.
Параллельный запуск test-сервиса на одной test DB не поддерживается.
Результаты текущей проверки и границы готовности — в [отчёте Sprint 1](docs/sprint-1.md).

## Границы безопасности и незавершённые функции

- Нет login/logout/password-change/reset, employee/clinic/role CRUD, provisioning
  первого администратора или authenticated business endpoints. Не выдавайте
  сотрудникам временные пароли через этот Sprint: соответствующие flows ещё закрыты.
- Sanctum подготовлен, но полный auth lifecycle **не готов**. CSRF endpoint создаёт
  только anonymous session. Перед включением login обязателен credential-version
  aware session adapter, проверка active/first-login state, CSRF, atomic rate limits,
  reset/revocation, audit и все согласованные тесты. Session CHECK не позволяет
  стандартному Laravel handler сохранить user-session без версии и auth stage.
- Роли, grants, memberships, links и audit пока доступны на запись только migration/
  provisioning-owner. Узкие mutation services с потолком делегирования, запретом
  self-escalation и защитой последнего admin реализуются в следующем спринте.
  Отсутствие endpoints/write grants не заменяется временным CRUD с обходом правил.
- `TenantContext` — внутренняя серверная транзакционная граница: actor должен
  поступать из проверенной аутентификации, а не body/header. Нет публичного способа
  установить app.user_id/app.tenant_id. Nested contexts запрещены; SET LOCAL
  сбрасывается при commit/rollback. Для будущих маршрутов нужны auth middleware
  перед context и context перед route-model binding.
- RLS защищает tenant boundary; custom settings не аутентифицируют человека.
  SQL injection/утечка DB credentials остаются за этой границей доверия.
  Соблюдайте backend policies и query predicates, даже при наличии RLS read scopes.
- Схема auth_rate_limits подготовлена, но auth handlers и атомарный limiter ещё
  не реализованы. Нет медицинских, пациентских, финансовых модулей и реальных данных.
- Health проверяет runtime-подключение и наличие 11 FORCE RLS таблиц; это не
  подтверждение полной готовности будущих функций или production environment.

## Сборка в сети с корпоративным/облачным proxy

Dockerfile поддерживает необязательный BuildKit secret `build_ca` с доверенным
PEM CA bundle. Нельзя отключать TLS, package signatures или проверку hash.
Если Docker build не получает DNS запись proxy, передайте её через `--add-host`
с адресом, выданным вашей средой, и proxy через стандартные build args:

```sh
docker build -f docker/backend.Dockerfile -t blusser-backend:local \
  --build-arg HTTP_PROXY --build-arg HTTPS_PROXY --build-arg NO_PROXY \
  --build-arg http_proxy --build-arg https_proxy \
  --secret id=build_ca,src=/etc/ssl/certs/ca-certificates.crt .
```

При необходимости добавьте `--add-host proxy:YOUR_APPROVED_PROXY_IP` к этой команде.
На обычной локальной сети достаточно `docker compose build backend`.
Composer по умолчанию использует dist и lockfile. Если сеть запрещает
api.github.com, добавьте `--build-arg COMPOSER_PREFER_INSTALL=source` для официальных
HTTPS Git sources. VCS metadata удаляются из итогового dev image, временный cache
остаётся только в BuildKit cache mount; версии заданы composer.lock.
