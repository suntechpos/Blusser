# Проверка изменения модели CEO (Sprint 2)

Ветка: `sprint-2/ceo-user-governance`, база — main после PR #2.
Merge не выполнялся. Только backend, без медицинских/финансовых модулей и frontend.

## Реализовано

- `docs/architecture.md`, `database.md`, `implementation-plan.md` актуализированы;
  отдельный подробный контракт — `sprint-2.md`.
- `Identity/Services/Identity.php`: проверка hash, временные credentials, смена
  пароля, PostgreSQL rate limits; не публикует password_hash в actor object.
- `Platform/Services/ClinicProvisioning.php`, `RecoveryToken.php`: атомарный
  tenant/CEO bootstrap и двухоператорный одноразовый CEO recovery.
- `Tenancy/Services/ClinicAdministration.php`: custom roles/grants, филиалы,
  сотрудники/назначения/reset, exact login lookup и consent linking.
- `Access/Services/Governance.php`, `GovernanceDenied.php`: runtime-only
  транзакционная граница, allowlist операций и исключения без SQL credential bindings.
- Миграция `000004_add_ceo_governance.php` и `database/sql/governance.sql`:
  protected role, tenant_ceos, recovery requests, link purpose/version,
  точечные SECURITY DEFINER операции и platform deny в tenant RLS.
- `TenantContext.php`: явный запрет platform identities даже с membership.
- `CeoGovernanceTest.php`: 16 сценариев bootstrap, делегации, linking, credential
  lifecycle, recovery, tenant isolation и запретов platform.
  Усилены DatabaseSecurityTest/MigrationLifecycleTest и очистка соединений/limiter
  в FoundationTestCase. README/OpenAPI отражают фактическую границу HTTP surface.

## Фактические проверки

PostgreSQL 17, PHP 8.4.26, Laravel 13; runtime `blusser_app`, fixtures/DDL — owner.

- `php artisan test`: **55 passed, 356 assertions**.
- `vendor/bin/pint --test`: passed.
- `composer validate --strict`: passed.
- `composer check-platform-reqs`: passed.
- Apply/rollback/reapply миграций проверяются интеграционными тестами.

Docker image обновляется исходниками поверх ранее проверенного dependency layer
с неизменённым composer.lock. Чистая загрузка/пересборка зависимостей для этого
изменения не повторяется; обычная команда полной сборки остаётся в README.
Все тестовые identities и пароли синтетические; рабочие аккаунты не создавались.

## Запуск и границы готовности

Из корня: `./scripts/init-local-env` (только если .env отсутствует),
`docker compose build backend`, `docker compose up -d --wait db`,
`docker compose run --rm migrate`, `docker compose up -d --wait backend`.
Проверка: `curl --fail http://localhost:8000/api/v1/health`;
тесты: `docker compose run --rm test` (только blusser_test).
Не удалять существующий volume для обновления.

Готовы внутренние серверные use cases, **не весь Sprint 2 и не production login**.
Нет HTTP business controllers/session adapter, UI, доставки credentials,
platform operator CLI и self-service employee recovery. Следующие задачи и
эксплуатационные prerequisites перечислены в sprint-2.md. Внешнее подтверждение
личности CEO требует независимой организационной проверки, код не заменяет её.
Реальные concurrent HTTP/session-save гонки нужно тестировать после session adapter;
текущие DB mutations сериализуют user→tenant locks и повторно проверяют credentials.
