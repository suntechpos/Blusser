# Blusser: проект базы данных

Статус: схема для согласования, SQL-миграции пока не созданы.
PostgreSQL — единая база и shared schema. Идентификаторы сущностей — UUID,
время — timestamptz в UTC. Все внешние ключи и индексы создаются миграциями.

## Таблицы

| Таблица | Основные поля | Ограничения |
| --- | --- | --- |
| tenants | id, name, slug, status, created_at, updated_at | slug unique; status active/suspended |
| branches | id, tenant_id, name, code, active, timestamps | unique(tenant_id, code), unique(tenant_id, id) |
| users | id, name, email, password, is_platform_admin, active, timestamps | normalized email unique; platform flag изменяет только platform provisioning |
| tenant_memberships | id, tenant_id, user_id, role_id, active, timestamps | unique(tenant_id, user_id), unique(tenant_id, id) |
| membership_branches | id, tenant_id, membership_id, branch_id | unique(tenant_id, membership_id, branch_id); составные FK |
| roles | id, code, name | code unique: clinic_admin, receptionist, doctor |
| permissions | id, code | code unique |
| role_permissions | role_id, permission_id | composite primary key |
| services | id, tenant_id, name, code, description nullable, active, timestamps | unique(tenant_id, code), unique(tenant_id, id) |
| audit_logs | id, tenant_id nullable, actor_user_id nullable, action, resource_type, resource_id nullable, metadata jsonb, request_id, created_at | append-only; tenant_id NULL только для platform events |

`membership_branches` фиксирует принадлежность сотрудника филиалам. В первом этапе
права действуют на всю клинику: этот pivot сам по себе не ограничивает доступ
к филиалам. Услуги принадлежат клинике, без цен, оплат и филиальных overrides.
Дополнительные технические таблицы Laravel для server-side sessions создаются
при реализации и не содержат tenant-owned бизнес-данных.

## Связи и жизненный цикл

- tenants 1:N branches, memberships, services, tenant audit events.
- users N:M tenants через memberships; одна роль на membership.
- memberships N:M branches через membership_branches.
- roles N:M permissions через role_permissions.
- FK `(tenant_id, membership_id)` и `(tenant_id, branch_id)` в pivot ссылаются
  на соответствующие composite unique keys и исключают межклинические связи.
- Tenant-owned данные ссылаются на tenants; tenant_id обязателен, кроме audit_logs.
- Hard delete клиник и пользователей не предоставляется в первом API.
  Используются suspended/active flags; FK RESTRICT сохраняют историю.
- audit actor_user_id ссылается на users, без каскадного удаления событий.

Индексы: memberships(user_id, active, tenant_id), memberships(tenant_id, role_id),
branches(tenant_id, active), services(tenant_id, active),
audit_logs(tenant_id, created_at, id). Порядок списка стабилизируется id.
Уникальность email проверяется по нормализованному lower-case значению,
не только case-sensitive unique исходного ввода.

## Роли БД

- `blusser_owner`: отдельный migration/provisioning credential, владеет схемой;
  не используется API и не передаётся frontend.
- `blusser_app`: NOSUPERUSER, NOBYPASSRLS, не владелец таблиц, без CREATE в public,
  без права смены owner-role. Только необходимые DML/SELECT/EXECUTE.
- Владельцем security-definer функций является закрытая provisioning-role,
  не доступная через SET ROLE пользователю приложения. Её повышенные права
  ограничиваются функциями и таблицами provisioning/audit.

Приложение проверяет identity перед установкой app.user_id. Custom PostgreSQL
settings сами по себе не аутентифицируют пользователя: SQL injection или утечка
runtime credentials выходят за эту границу доверия. Поэтому параметризованные
запросы, закрытые credentials и минимальные DB grants обязательны.

## RLS: политики и fail-closed

ENABLE и FORCE ROW LEVEL SECURITY обязательны на branches, services,
tenant_memberships, membership_branches, audit_logs. Runtime-тесты выполняются
именно как blusser_app: тестирование под superuser не подтверждает изоляцию.

Tenant predicate использует:

```sql
tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
```

Нет настройки — сравнение не проходит. Некорректный UUID даёт отказ/ошибку,
не fallback к глобальному доступу. SELECT/DELETE требуют USING, INSERT —
WITH CHECK, UPDATE — оба условия. WITH CHECK не позволяет сменить tenant_id.
Проверки memberships дополнительно требуют active user/membership/tenant.

Для bootstrap context у memberships есть отдельная SELECT policy:
`user_id = app.user_id AND active`. Она позволяет получить только собственные
членства, даже до установки tenant context. После установки context tenant
policy разрешает чтение сотрудников лишь с employees.read. Политики SELECT
составляются явно, с учётом того, что permissive policies объединяются через OR.
Self-policy никогда не разрешает UPDATE/DELETE/INSERT и не раскрывает чужие
членства. Получение собственных клиник ограничено EXISTS self-membership.
Реестр tenants доступен platform admin либо собственным активным membership;
операции создания и изменения клиник идут через узкий platform service.

RLS гарантирует tenant boundary; HTTP policies проверяют operation permissions.
Bootstrap SELECT — документированное исключение к tenant-only чтению:
пользователь видит список своих клиник. Ни branches, ни services не имеют
подобного исключения. Users — глобальная identity-таблица, не общий справочник
сотрудников: runtime SELECT ограничен self-policy; tenant API получает только
id/name/email сотрудников выбранной клиники через узкую DB-функцию, которая
проверяет context и employees.read. Запись чужих profiles через tenant API
не предоставляется. Role каталоги доступны на чтение, изменения запрещены
runtime-role; seed/migration наполняют фиксированную матрицу.

Чтобы избежать рекурсивных RLS policies на memberships, проверки активного
членства/права выносятся в узкие security-definer helper functions. Они читают
membership/role mappings и возвращают только boolean для текущих app.user_id
и app.tenant_id; не принимают произвольный actor и не раскрывают строки.
Владельцу разрешено чтение необходимых таблиц в обход RLS только внутри
закрытого helper boundary. Фиксированный search_path, квалифицированные имена,
REVOKE EXECUTE FROM PUBLIC и минимальный GRANT обязательны. Тесты должны
проверить отсутствие рекурсии, fail-closed и отсутствие повышения прав.

## Аудит

Tenant SELECT audit_logs требует tenant predicate и audit.read. Platform events
с tenant_id NULL доступны только platform admin через отдельную read policy.
UPDATE и DELETE не разрешены runtime-role; права очистки остаются вне API.
INSERT идёт через audit helper: он проверяет context, подставляет actor и tenant,
принимает только allowlisted action/resource/metadata. Platform creation пишет
событие внутри provisioning-функции. Обычная роль не получает прямой INSERT
с произвольным tenant_id NULL или поддельным actor.

Бизнес-изменение и его audit event атомарны. Записываются создание клиники,
изменения филиалов, membership/roles и услуг. Metadata содержит только allowlist
технических изменений, без паролей, токенов и полных request bodies. Audit первого
этапа не заявляется как внешнее tamper-proof хранилище; retention определяется
отдельно перед production.

## Порядок миграций

1. users и каталоги roles/permissions, фиксированные grants ролей.
2. tenants, branches, memberships и membership_branches.
3. services и audit_logs, составные FK и индексы.
4. DB roles/grants, helper/provisioning функции, RLS policies и FORCE RLS.
5. Server-side sessions и остальные действительно нужные Laravel таблицы.

Создание DB roles может потребовать bootstrap-команды с административным
credential; такой credential доступен только Compose initialization/migrations.
Повторное применение bootstrap не сбрасывает пароли и не уничтожает данные.
Точные grants, SQL и rollback будут проверены интеграционными тестами после
согласования этого проекта.
