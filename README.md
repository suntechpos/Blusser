# Blusser

Multi-tenant SaaS для независимых частных клиник. Sprint 1: **Backend Foundation**;
Sprint 2: Sanctum REST API, CEO и управление пользователями ([контракт](docs/sprint-2.md)).
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
  к привилегированным ролям. Разрешённые RLS reads и точечный EXECUTE; session
  INSERT/UPDATE выполняется только через version-aware persistence.
- `blusser_security`: NOLOGIN, BYPASSRLS; точечные privileges для узких read helpers
  и governance mutations с фиксированным search_path. Runtime не член этой роли
  и может вызывать только явно разрешённые функции.

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
Результаты Sprint 1 — в [историческом отчёте](docs/sprint-1.md); текущие проверки
и границы Sprint 2 — в [отчёте](docs/sprint-2-validation.md).

## Sprint 2: доступ к API

Полный контракт: [OpenAPI](docs/openapi.yaml), сценарий запуска и границы:
[API guide](docs/sprint-2-api.md). Реализованы cookie login/logout, first-login password
change, platform clinic/CEO provisioning, tenant roles/grants, employees/assignments,
branches, scoped reads/audit, safe linking и CEO recovery.

Первый platform operator создаётся **вручную** в migration-сервисе с hidden prompts:

```sh
docker compose run --rm migrate php artisan blusser:platform-admin YOUR_LOGIN
```

Runtime API не получает эти credentials. Нет default passwords, seeds, публичной
регистрации, bearer tokens или remember-me. Не передавайте пароли в аргументах CLI,
URL, истории shell или Git. Для независимого подтверждения CEO recovery нужны два
разных platform operators и внешняя процедура проверки личности.

Cookie flow: CSRF handshake → login → обязательная смена временного пароля →
список своих клиник → выбор active clinic → tenant API. На изменяющих запросах
обязателен X-XSRF-TOKEN; Origin/Referer должен соответствовать first-party allowlist.
После login/change/logout cookies и CSRF token меняются. Cookie HttpOnly,
SameSite=Lax; Secure включён по умолчанию и обязателен на HTTPS. Только локальный
Compose HTTP использует явное SESSION_SECURE_COOKIE=false. Payload в БД зашифрован.

Сессия проверяет current active/credential version/first-login state на каждом
запросе. Права и memberships не кэшируются в cookies; все tenant reads используют
RLS/context и scope. Запись сессии после logout/reset не восстанавливает доступ.
Platform identities не входят в tenant context, даже с ошибочным membership.
Tenant admin не сбрасывает пароль CEO, platform, себя, более привилегированного
или multi-clinic пользователя. Назначение новой клиники требует consent владельца.

Остаются эксплуатационные задачи: HTTPS web server, trusted proxy configuration,
backup/restore, production Argon2id benchmark, безопасная доставка credentials,
внешняя проверка личности и policy хранения audit. Transfer CEO и self-service
recovery обычных multi-clinic identities — отдельные функции. Frontend и
медицинские/финансовые модули отсутствуют. Health не означает production readiness.
RLS/GUC — серверная граница; SQL injection/утечка DB credentials не входят в её
модель защиты. Не включайте request body/query binding logging для auth paths.

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
