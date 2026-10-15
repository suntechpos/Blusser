# Sprint 1 — Backend Foundation

Основание: согласованные architecture.md, database.md и implementation-plan.md
из main, commit 988775be423bc05bf26a09807a05d468083938a4. Эти три документа не
изменены. Ветка: `sprint-1/backend-foundation`. Merge не выполняется.

## Реализовано

- Backend на Laravel 13.36.0, Sanctum 4.3.3, PHP 8.4; версии пакетов в composer.lock.
- Docker Compose: PostgreSQL 17, backend, одноразовые migrate/test services.
  Образы upstream закреплены digest; API запускается без root.
- Генерация локального ignored .env с разными случайными credentials,
  .env.example без секретов, credential-free .env.testing.
- Глобальная identity и tenant-схема: users, tenants, branches, tenant_roles,
  permissions, role_permissions, tenant_memberships, membership_branches,
  membership_link_requests и их branch pivot, services, audit_logs,
  sessions и auth_rate_limits.
- Глобальная уникальность canonical login, credential flags/version,
  hash-only storage constraint, composite tenant FK, допустимые permission scopes.
- Отдельные migration/runtime credentials; NOLOGIN helper role с ограниченными
  grants. API не получает пароли owner/Postgres admin.
- ENABLE + FORCE RLS на 11 бизнес/identity таблицах, контекстные read policies,
  own/assigned_branches/entire_clinic для соответствующих reads. Нет write grants
  или write policies на бизнес-таблицы для runtime; RBAC/catalog/audit нельзя менять
  через обычный runtime SQL. Permissions catalog доступен только на чтение.
- TenantContext: проверка active identity, credential version, first-login flag,
  membership/role/tenant; transaction-local settings и запрет nested context.
  Permissions service не подразумевает иерархию actions или scopes.
- Sanctum stateful/CSRF foundation без bearer tokens и публичной регистрации.
- `/api/v1/health` с реальной проверкой БД/роли/RLS; ошибки без connection details.
- OpenAPI 3.1 и GitHub Actions workflow для Compose build/test/smoke.

## Результаты проверки текущей среды

- PHPUnit: **35 passed, 153 assertions**, без failed/skipped/warnings.
  Реальный PostgreSQL; fixtures записываются migration-owner, assertions
  изоляции выполняются через `blusser_app` (NOSUPERUSER, NOBYPASSRLS).
- Проверены запрет cross-tenant reads/known UUID access, отсутствие context,
  transaction cleanup, inactive/stale/temporary identities, role/permission
  revocation и разные права одного user в разных клиниках.
- Проверены запрет runtime DDL/SET ROLE/DML, fail-closed RLS при случайном
  дополнительном DML grant, отсутствие PUBLIC EXECUTE на helpers, scope constraints,
  составные FK, login uniqueness, link uniqueness и session credential constraints.
- Миграции на пустой БД, повторное применение и rollback/reapply пройдены.
  Откат только security-миграции отзывает также column-level grants и закрывает
  session storage. Повторный bootstrap не сбрасывает пароли и не удаляет данные.
- Sanctum: anonymous CSRF handshake, 401 на test-only guarded route и bearer token,
  реальная проверка CSRF без test bypass, отсутствие незавершённых auth/CRUD routes.
- Laravel Pint, composer validate --strict и check-platform-reqs пройдены.
- OpenAPI проверена openapi-spec-validator 0.7.2 (временный validation tool,
  не зависимость приложения).
- Итоговый Docker image собран, тесты выполнены без source bind mount.
  После docker compose down/up с сохранением volume health снова вернул 200
  с `{"status":"ok"}`; CSRF handshake возвращает 204.
- Дополнительный `composer audit --locked` не выполнен: сетевой proxy возвращает
  403 для packagist.org/api/security-advisories. Отсутствие уязвимостей не заявляется.
- GitHub Actions описан, но его удалённый запуск не подтверждён локальными тестами.

## Что сознательно закрыто до следующего спринта

Sprint 1 не является готовой системой аутентификации или администрирования клиники.
Нет login/logout/reset/change-password/provisioning и tenant CRUD endpoints.
Anonymous database sessions работают; authenticated sessions ещё потребуют
credential-version-aware adapter и всех согласованных lifecycle tests.
Не реализованы atomic authentication limiter и mutation/provisioning/audit helpers;
storage и read foundations не выдаются за эти законченные функции.

При включении следующих endpoints обязательны action + scope policies, self-escalation
и delegation checks, защита последнего администратора и атомарность с аудитом.
До этого write access закрыт, а не упрощён. SQL custom settings — доверенная
серверная граница, не самостоятельный механизм аутентификации пользователя.

Нет frontend, пациентов, карт, очередей и платежей. Нет реальных персональных
или медицинских данных. Контейнер dev-only, production TLS/web server, backup,
полный auth и recovery требуют следующих этапов.

## Локальный запуск и тесты

Полные инструкции, proxy/CA troubleshooting и границы безопасности — в README.md.
Краткая последовательность из корня:

```sh
./scripts/init-local-env
docker compose build backend
docker compose up -d --wait db
docker compose run --rm migrate
docker compose up -d --wait backend
curl --fail http://localhost:8000/api/v1/health
docker compose run --rm test
docker compose down
```

Тестовый сервис очищает только `blusser_test`. Не используйте `down -v` при штатной
остановке локальной среды. Существующий .env не перезаписывается; после изменения
исходников необходимо пересобрать backend image.

## Основные файлы изменений

| Группа | Файлы |
| --- | --- |
| Контейнеры | compose.yaml, docker/backend.Dockerfile, docker/postgres/10-bootstrap.sh, .dockerignore |
| Конфигурация | .env.example, .gitignore, scripts/init-local-env, backend/.env.example, backend/.env.testing, backend/config/* |
| Laravel | backend/artisan, bootstrap/*, public/index.php, composer.json/lock, app/Providers/* |
| Модули | app/Modules/Identity/Models/User.php, Tenancy/TenantContext.php, Access/PermissionScope.php, Access/Permissions.php, Platform/Http/HealthController.php (все внутри backend/) |
| БД | backend/database/migrations/*, database/sql/foundation.sql, database/sql/security.sql, database/seeders/DatabaseSeeder.php (все внутри backend/) |
| API | backend/routes/api.php, routes/web.php, routes/console.php (внутри backend/), docs/openapi.yaml |
| Тесты | backend/phpunit.xml, tests/FoundationTestCase.php, tests/Feature/*, tests/Unit/UserTest.php (внутри backend/) |
| CI и документация | .github/workflows/backend.yml, README.md, docs/sprint-1.md |

Остальные scaffold-файлы — стандартные Laravel entrypoints, config и .gitignore
для storage/bootstrap cache. Vendor, секреты и generated cache в Git не включаются.
