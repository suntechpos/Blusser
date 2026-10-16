# Sprint 2: Backend API validation

Ветка: `sprint-2/ceo-user-governance`, существующий PR #3. Merge не выполняется.
Только backend; frontend, медицинские/финансовые данные и модули не добавлены.

## Изменения завершающего этапа

- VersionedSessionHandler + request guard/Sanctum: real encrypted cookie sessions,
  first-login limited stage, login/logout/password change, session id/CSRF rotation,
  no-store и принудительные first-party/CSRF/full-identity checks.
- Миграция 000005/sql/sessions.sql: безопасная выдача/обновление/отзыв sessions,
  safe identity projection, own-clinic selection, scoped employee directory,
  allowlisted platform reads, link reject, secret-free denied-operation audit.
  Runtime session INSERT/UPDATE отозваны; поздняя запись только UPDATE existing row.
- AuthController, platform/clinic REST controllers и routes/api.php: все CEO use
  cases подключены к HTTP, включая custom roles/grants, branch assignments,
  employees/reset, linking и двухоператорный CEO recovery.
- CLI CreatePlatformOperator: hidden password confirmation, отдельный owner
  credential, global UNIQUE login и atomic audit; нет runtime/bootstrap endpoint.
- OpenAPI 3.1 описывает каждый опубликованный path/method, cookie/CSRF protocol,
  input/output schemas, permissions/scopes, success/error codes и recovery boundary.
- SprintTwoApiTest использует реальные cookies, CSRF и PostgreSQL, без actingAs.
  OpenApiContractTest проверяет route coverage/security и все schema references;
  PlatformBootstrapTest проверяет CLI/credential boundary. Сохранены регрессии Sprint 1.
- README, архитектура, схема БД, план и sprint-2-api.md содержат актуальные инструкции.

## Проверки

PostgreSQL 17; PHP 8.4.26; Laravel 13; SQL под blusser_app, fixtures/DDL под owner.
- php artisan test: **72 passed, 1943 assertions** (55 regressions + 17 новых tests).
- vendor/bin/pint --test: **57 files, passed**.
- composer validate --strict: passed.
- composer check-platform-reqs: passed.
- OpenAPI: все **35 operations / 27 paths**, security declarations и references проверены.
- Apply/rollback/reapply и runtime-role/RLS checks включены в integration suite.

Проверены реальные cookie/CSRF rotation и запрет cross-origin/bearer/anonymous;
first-login и password confirmation; create tenant/CEO только platform; делегация
без scope expansion; own/assigned branch visibility; foreign role/branch/member
UUID и forged tenant parameters; protected CEO/self-role; session revocation после
reset/change/logout; stale writer resurrection; multi-clinic consent/switch/revocation;
recovery two-operator/expiry/replay/stale credential; login rate limits; FORCE RLS,
column grants, migration rollback/reapply и отсутствие owner use в API.
Проверена защита от получения чужих branch scopes через создание/reset аккаунтов,
переназначение филиалов и изменение общей роли: сравниваются реальные branch sets,
все отказы атомарны с rollback.
Двухсоединительный PostgreSQL тест с lock_timeout подтверждает, что password change
ждёт user lock выдачи session, а после смены поздний writer не восстанавливает row.
Все identities/пароли в тестах синтетические. Рабочие accounts не создавались.

Выполнена чистая сборка docker/backend.Dockerfile по неизменённому composer.lock.
Использованы официальные HTTPS Git sources и доверенный CA bundle через build secret;
TLS/package verification не отключались. Финальные исходники добавлены поверх
проверенного dependency layer. Финальные
тесты выполняются без source mounts, от www-data; Dockerfile включает OpenAPI для
contract tests. Обычная полная сборка: docker compose build backend.

## Локальный запуск и ограничения

Проверенные команды и cookie workflow — [sprint-2-api.md](sprint-2-api.md), README.
У существующей среды выполнить build, migrate и recreate backend без удаления volume.
Tests используют только blusser_test, destructive setup защищён проверкой имени базы.
Runtime backend не получает MIGRATION_DB_PASSWORD/POSTGRES_PASSWORD.

Sprint 2 backend scope реализован; production readiness отдельно не заявляется.
Production требует TLS/web server, explicit trusted proxy/first-party configuration,
backup/restore, Argon2id benchmark, audit retention, безопасную доставку credentials
и внешнее независимое подтверждение личности CEO. Код не подтверждает документы.
Transfer CEO и self-service recovery обычных multi-clinic identities остаются вне
этого Sprint. Нет services CRUD, пациентов, frontend, очередей и финансов.
