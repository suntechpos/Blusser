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
| tenant_roles | id, tenant_id, code, name, active, timestamps | unique(tenant_id, code), unique(tenant_id, id); code не даёт полномочий |
| permissions | id, code, allowed_scopes | code unique; каталог backend actions, allowed_scopes — допустимое непустое подмножество scopes |
| role_permissions | tenant_id, role_id, permission_id, scope | primary key(tenant_id, role_id, permission_id); scope: own/assigned_branches/entire_clinic |
| services | id, tenant_id, name, code, description nullable, active, timestamps | unique(tenant_id, code), unique(tenant_id, id) |
| audit_logs | id, tenant_id nullable, actor_user_id nullable, branch_id nullable, action, resource_type, resource_id nullable, metadata jsonb, request_id, created_at | append-only; tenant_id NULL только для platform events |

`membership_branches` определяет назначенные филиалы для assigned_branches.
Scope хранится отдельно для каждого permission роли, не на users или tenant.
Семантика own и поддерживаемые scopes разделов определены в architecture.md;
branch/owner не выводятся из произвольного created_by. Услуги пока принадлежат
клинике целиком, без цен и филиальных overrides; им доступен только entire_clinic.
Для audit branch_id заполняется сервером по ресурсу события; для событий уровня
клиники остаётся NULL и не подходит под assigned_branches.
Дополнительные технические таблицы Laravel для server-side sessions создаются
при реализации и не содержат tenant-owned бизнес-данных.

## Связи и жизненный цикл

- tenants 1:N branches, memberships, services, tenant audit events.
- users N:M tenants через memberships; одна роль на membership.
- memberships N:M branches через membership_branches.
- tenants 1:N tenant_roles; tenant_roles 1:N memberships, N:M permissions через role_permissions.
- FK memberships(tenant_id, role_id) → tenant_roles(tenant_id, id) и
  role_permissions(tenant_id, role_id) → tenant_roles(tenant_id, id) запрещают
  назначение роли другой клиники. role_id обязателен.
- FK audit_logs(tenant_id, branch_id) → branches(tenant_id, id); CHECK запрещает
  branch_id без tenant_id. NULL branch_id означает событие уровня клиники.
- permissions — глобальный неизменяемый runtime-каталог. FK permission_id,
  CHECK scope enum и проверка allowed_scopes при изменении grants обязательны;
  узкая DB-функция проверяет допустимость пары permission/scope.
- FK `(tenant_id, membership_id)` и `(tenant_id, branch_id)` в pivot ссылаются
  на соответствующие composite unique keys и исключают межклинические связи.
- Tenant-owned данные ссылаются на tenants; tenant_id обязателен, кроме audit_logs.
- Hard delete клиник и пользователей не предоставляется в первом API.
  Используются suspended/active flags; FK RESTRICT сохраняют историю.
- audit actor_user_id ссылается на users, без каскадного удаления событий.

Индексы: memberships(user_id, active, tenant_id), memberships(tenant_id, role_id),
tenant_roles(tenant_id, active), membership_branches(tenant_id, branch_id, membership_id),
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
tenant_memberships, membership_branches, tenant_roles, role_permissions, audit_logs. Runtime-тесты выполняются
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
policy разрешает чтение сотрудников лишь с employees.view. Политики SELECT
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
проверяет context и employees.view. Запись чужих profiles через tenant API
не предоставляется. Каталог permissions доступен на чтение; его изменения
запрещены runtime-role. tenant_roles и role_permissions защищены tenant RLS.
Роль и grants доступны через roles.view; минимальные собственные effective
permissions возвращаются через /me для UI без открытия всего каталога ролей.
Изменения RBAC выполняются только узкими DB-функциями, проверяющими action,
scope, потолок делегирования, self-escalation и последнего администратора;
прямой DML runtime-role в tenant_roles, role_permissions, memberships и
membership_branches отозван. Эти функции не принимают доверенный actor от клиента.

Чтобы избежать рекурсивных RLS policies на memberships, проверки активного
членства/права выносятся в узкие security-definer helper functions. Они читают
membership/role mappings и возвращают только boolean для текущих app.user_id
и app.tenant_id; не принимают произвольный actor и не раскрывают строки.
Владельцу разрешено чтение необходимых таблиц в обход RLS только внутри
закрытого helper boundary. Фиксированный search_path, квалифицированные имена,
REVOKE EXECUTE FROM PUBLIC и минимальный GRANT обязательны. Тесты должны
проверить отсутствие рекурсии, fail-closed и отсутствие повышения прав.

## Scope и целостность RBAC

Tenant RLS — обязательная нижняя граница для любой операции, включая helpers.
Backend query services добавляют action-specific predicate scope до пагинации,
агрегации и route binding. У employee-directory и audit helpers те же ограничения;
они не могут возвращать весь tenant при assigned_branches/own. Для update
валидируются и исходная, и новая области доступа. Tests отдельно проверяют
RLS tenant boundary и backend scope boundary.

При любом изменении RBAC блокируется tenant, заново читаются actor grants и
все затронутые memberships; затем проверяется итоговый граф полномочий.
Узкие функции не допускают self-assignment, изменения собственных branch
assignments или grants используемой actor роли. Выдаваемая роль/grant не может
превышать effective permissions actor по action и scope. Прямые employee update
не меняют role_id/branch assignments в обход специальных functions.

Удаление используемой роли ограничено FK RESTRICT; сначала необходимо отдельное
разрешённое переназначение. При деактивации роли или user, отзыве grants и изменении
membership сохраняется хотя бы один полный активный администратор по capability
набору из architecture.md. Проверка не использует name/code роли. Начальное
создание tenant_roles и grants — часть атомарного platform provisioning, а не
глобальные seed-роли. Все записи grants принадлежат ровно одной клинике.

## Аудит

Tenant SELECT audit_logs требует tenant predicate и audit.view. Platform events
с tenant_id NULL доступны только platform admin через отдельную read policy.
UPDATE и DELETE не разрешены runtime-role; права очистки остаются вне API.
INSERT идёт через audit helper: он проверяет context, подставляет actor и tenant,
принимает только allowlisted action/resource/metadata. Platform creation пишет
событие внутри provisioning-функции. Обычная роль не получает прямой INSERT
с произвольным tenant_id NULL или поддельным actor.

Бизнес-изменение и его audit event атомарны. Записываются создание клиники,
изменения филиалов, memberships/branch assignments, tenant_roles, permissions
со scopes и услуг. Изменение grants фиксирует старый и новый разрешённый набор;
неуспешные попытки доступа записываются отдельно в технический security log
без включения их в откатываемую бизнес-транзакцию. Metadata содержит только allowlist
технических изменений, без паролей, токенов и полных request bodies. Audit первого
этапа не заявляется как внешнее tamper-proof хранилище; retention определяется
отдельно перед production.

## Порядок миграций

1. users и глобальный каталог permissions с допустимыми scopes.
2. tenants, branches, tenant_roles, role_permissions, memberships и membership_branches.
3. services и audit_logs, составные FK и индексы.
4. DB roles/grants, helper/provisioning функции, RLS policies и FORCE RLS.
5. Server-side sessions и остальные действительно нужные Laravel таблицы.

Создание DB roles может потребовать bootstrap-команды с административным
credential; такой credential доступен только Compose initialization/migrations.
Повторное применение bootstrap не сбрасывает пароли и не уничтожает данные.
Точные grants, SQL и rollback будут проверены интеграционными тестами после
согласования этого проекта.
