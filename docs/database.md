# Blusser: проект базы данных

Статус: foundation миграции реализованы; дополнения Sprint 2 — модель CEO и recovery.
PostgreSQL — единая база и shared schema. Идентификаторы сущностей — UUID,
время — timestamptz в UTC. Все внешние ключи и индексы создаются миграциями.

## Таблицы

| Таблица | Основные поля | Ограничения |
| --- | --- | --- |
| tenants | id, name, slug, status, created_at, updated_at | slug unique; status pending/active/suspended |
| tenant_ceos | tenant_id, user_id, membership_id | tenant_id PK; composite membership/user FK; одна immutable CEO identity на tenant |
| ceo_recovery_requests | id, tenant_id, target_user_id, initiated_by, verified_by nullable, verification_reference nullable, credential_version, token_digest nullable, token_expires_at nullable, status, expires_at, timestamps | actor/verifier/target разные; токен одноразовый; FORCE RLS, нет direct runtime DML |
| branches | id, tenant_id, name, code, active, timestamps | unique(tenant_id, code), unique(tenant_id, id) |
| users | id, login, name, email nullable, password_hash, must_change_password, temporary_password_expires_at nullable, password_changed_at, credential_version, is_platform_admin, active, timestamps | canonical login unique; credential_version positive; только hash, platform flag изменяет только platform provisioning |
| membership_link_requests | id, tenant_id, target_user_id, initiated_by_user_id, initiator_version nullable, role_id, purpose, status, expires_at, resolved_at nullable, timestamps | tenant-role composite FK; pending/accepted/rejected/expired; не содержит credentials |
| membership_link_request_branches | tenant_id, request_id, branch_id | composite FK к request и branch; unique(tenant_id, request_id, branch_id) |
| sessions | id, user_id, credential_version, auth_stage, last_reauthenticated_at nullable, payload, last_activity | FK user_id; индекс user_id; auth_stage password_change_required/full |
| auth_rate_limits | key_digest, window_started_at, attempts, blocked_until nullable, expires_at | key_digest primary key; атомарный учёт login/IP/action buckets, без raw login |
| tenant_memberships | id, tenant_id, user_id, role_id, active, timestamps | unique(tenant_id, user_id), unique(tenant_id, id) |
| membership_branches | id, tenant_id, membership_id, branch_id | unique(tenant_id, membership_id, branch_id); составные FK |
| tenant_roles | id, tenant_id, code, name, active, timestamps | unique(tenant_id, code), unique(tenant_id, id); code не даёт полномочий |
| permissions | id, code, allowed_scopes | code unique; каталог backend actions, allowed_scopes — допустимое непустое подмножество scopes |
| role_permissions | tenant_id, role_id, permission_id, scope | primary key(tenant_id, role_id, permission_id); scope: own/assigned_branches/entire_clinic |
| services | id, tenant_id, name, code, description nullable, active, timestamps | unique(tenant_id, code), unique(tenant_id, id) |
| audit_logs | id, tenant_id nullable, actor_user_id nullable, branch_id nullable, action, resource_type, resource_id nullable, metadata jsonb, request_id, created_at | append-only; tenant_id NULL только для platform/identity events |

`membership_branches` определяет назначенные филиалы для assigned_branches.
Scope хранится отдельно для каждого permission роли, не на users или tenant.
Семантика own и поддерживаемые scopes разделов определены в architecture.md;
branch/owner не выводятся из произвольного created_by. Услуги пока принадлежат
клинике целиком, без цен и филиальных overrides; им доступен только entire_clinic.
Для audit branch_id заполняется сервером по ресурсу события; для событий уровня
клиники остаётся NULL и не подходит под assigned_branches.
Server-side sessions используют PostgreSQL. Auth payload не содержит паролей,
hashes и долгоживущих permissions; raw session ID не пишется в аудит. Точные
Laravel adapters/schema будут проверены при реализации.

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
Login хранится в каноническом формате trim + ASCII lower-case с CHECK формата
и длины 3–64; глобальный UNIQUE не зависит от tenant. Email не используется
для идентификации/автопривязки и не обязан быть уникальным. Конкурентные create
одного login обрабатывают unique conflict без перезаписи существующего user.

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
tenant_memberships, membership_branches, tenant_roles, role_permissions,
membership_link_requests, membership_link_request_branches, audit_logs, tenant_ceos
и ceo_recovery_requests; users/tenants также имеют FORCE RLS. Runtime-тесты выполняются
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

## Identity: ограничения хранения и атомарность

CHECK: must_change_password=true требует temporary_password_expires_at NOT NULL;
после обычной смены expiry NULL. credential_version увеличивается при любой
смене/reset; в sessions хранится версия, актуальная при аутентификации.
Index sessions(user_id) нужен для удаления всех сессий. Прямой доступ к sessions,
auth_rate_limits и password_hash отсутствует в tenant API и tenant DB helpers.
Runtime auth service использует узкие identity functions: login lookup возвращает
hash только доверенному verifier, directory/employee serializers не получают его.
Users self-read возвращает только allowlisted profile fields, не SELECT *.
Запись credentials, credential_version и sessions управляется identity service,
а не общим employee DML. Argon2id hash — единственное сохранённое представление
обычного/временного пароля; нет reversible encryption или отдельного plaintext field.

Account create + membership + роль + audit выполняются атомарно с проверкой
permissions. При занятом login разрешён только отдельный link flow, не upsert
credentials. link_requests имеют unique(tenant_id, id) для composite FK в branch
pivot и partial unique(tenant_id, target_user_id) WHERE status='pending'.
Просроченный pending переводится в expired перед повторным созданием.
FK (tenant_id, role_id) ссылается на tenant_roles; FK request/branch в pivot
исключают межклиническую связь. При accept повторно проверяются expiration,
инициатор, role/grants/branches, отсутствие self-assignment и membership unique.
Целевой user читает только собственные pending proposals через ограниченный
identity endpoint/helper, даже без membership: только клиника, роль и срок,
без списка её сотрудников/grants. Запись proposal допускается tenant RLS и
employees.create/assign_role; accept требует полноценную self-session и reauth,
не tenant admin impersonation. Один proposal не может быть принят дважды.

Для create/reset/change/link и изменений membership используется согласованный
порядок блокировок: затронутые users по UUID, затем tenants по UUID, затем
остальные строки. Операция, начавшая с tenant lock, не захватывает user lock позже;
при необходимости пересобирается транзакция с правильным порядком. Это дополняет
сериализацию tenant RBAC и исключает deadlock с глобальной деактивацией пользователя.
Reset проверяет все memberships, а не только видимые под текущим tenant RLS;
узкий helper возвращает разрешение/отказ, не чужие строки. Другой tenant или
platform identity → tenant reset запрещён. Сравнение полномочий actor/target
исключает обход потолка делегирования через знание временного пароля.

Reset/change под user lock обновляют hash/flags/version, удаляют старые sessions
и создают audit event в одной транзакции. Change может создать новую session
актуальной версии; reset не создаёт сессию для target. Session save/login сверяют
версию, поэтому конкурентное восстановление старой session row не восстанавливает
доступ. Новый reset делает ранее выданный временный пароль недействительным.
Rate-limit buckets используют digest нормализованного login/IP с серверным ключом
вне Git, timestamps и атомарный increment/upsert; TTL cleanup не удаляет активную
блокировку. Перезапуск процесса не сбрасывает лимиты.

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
membership сохраняется защищённый CEO из tenant_ceos с полным активным bootstrap-role.
Проверка не использует name/code роли; его grants/member недоступны для tenant mutation. Начальное
создание tenant_roles и grants — часть атомарного platform provisioning, а не
глобальные seed-роли. Все записи grants принадлежат ровно одной клинике.

## Аудит

Tenant SELECT audit_logs требует tenant predicate и audit.view. Platform/identity events
с tenant_id NULL не доступны platform через tenant API. Platform видит лишь
allowlist operational events provisioning/recovery без employee identity history.
UPDATE и DELETE не разрешены runtime-role; права очистки остаются вне API.
INSERT идёт через audit helper: он проверяет context, подставляет actor и tenant,
принимает только allowlisted action/resource/metadata. Platform creation пишет
событие внутри provisioning-функции. Обычная роль не получает прямой INSERT
с произвольным tenant_id NULL или поддельным actor.

Identity audit использует глобальные события tenant_id NULL с subject user ID
в allowlisted metadata и actor_user_id (actor=subject для self-change). Tenant
create/reset/link пишет дополнительное событие только в инициирующую клинику.
Глобальные auth failures/limits идут в security log; для неизвестного login
сохраняется digest, не пароль или raw login. Tenant-admin не получает global
identity audit других клиник. password_hash и все секреты исключены даже из
before/after diff. Сроки и access к security log определяются до production.

Бизнес-изменение и его audit event атомарны. Записываются создание клиники,
изменения филиалов, memberships/branch assignments, tenant_roles, permissions
со scopes и услуг. Изменение grants фиксирует старый и новый разрешённый набор;
неуспешные попытки доступа записываются отдельно в технический security log
без включения их в откатываемую бизнес-транзакцию. Metadata содержит только allowlist
технических изменений, без паролей, токенов и полных request bodies. Audit первого
этапа не заявляется как внешнее tamper-proof хранилище; retention определяется
отдельно перед production.

## Порядок миграций

1. users с login/credential state и глобальный каталог permissions с допустимыми scopes.
2. tenants, branches, tenant_roles, role_permissions, memberships и membership_branches.
3. services, link_requests с branch pivot и audit_logs, составные FK и индексы.
4. DB roles/grants, helper/provisioning функции, RLS policies и FORCE RLS.
5. Server-side sessions с credential version/auth stage, auth_rate_limits
   и остальные действительно нужные Laravel таблицы.

Создание DB roles может потребовать bootstrap-команды с административным
credential; такой credential доступен только Compose initialization/migrations.
Повторное применение bootstrap не сбрасывает пароли и не уничтожает данные.
Точные grants, SQL и rollback будут проверены интеграционными тестами после
согласования этого проекта.

## Дополнения Sprint 2

У membership добавляется unique(tenant_id, id, user_id); tenant_ceos ссылается на
всю тройку. Это исключает несовпадение CEO identity и membership. tenant_roles
получает protected-флаг; CEO role/grants/member нельзя менять обычными RBAC use cases.
Стартовые роли Doctor/Receptionist не создаются. Protected CEO обеспечивает
неудаляемого активного полного администратора; transfer CEO — отдельный будущий flow.

Link purpose: employee или ceo. Для существующего CEO — pending tenant и bootstrap
proposal; platform никогда не переназначает его пароль. Employee proposals требуют
актуальных полномочий инициатора в tenant. При accept проверяется initiator_version.

Recovery CEO требует двух разных platform identities и внешнего verification
reference. В таблице только digest случайного token; в audit нет digest/секретов.
Потребление token атомарно увеличивает credential_version и удаляет sessions; роли
и memberships других клиник не меняются. Ordinary employee reset не принимает CEO,
platform identities или пользователя с memberships в других клиниках.

Новые функции находятся в закрытой schema governance с фиксированным search_path,
квалифицированными объектами и точечными EXECUTE grants; runtime не получает owner
credential, BYPASSRLS или прямой business DML. is_active_member и TenantContext
также запрещают platform identities. Неизвестная legacy CEO identity не выводится
автоматически из имени Clinic Admin или существующего permission набора.


## Sprint 2: versioned cookie sessions (миграция 000005)

Session schema сохраняет credential_version/auth_stage; payload Laravel зашифрован
и base64 encoded. Внутри только server identity id/version/stage, CSRF и выбранный
tenant, без credentials/grants. Cookie содержит зашифрованный случайный session ID.
Runtime не имеет прямого INSERT/UPDATE sessions; узкие функции session_start/write
проверяют identity под user lock. session_start регистрирует новый случайный id
после password verification. Authenticated session_write только обновляет уже
существующий id с совпадающими user/version/stage. Он никогда не вставляет
удалённую row: даже старый запрос после logout не восстановит сессию при той же
credential version. Anonymous upsert не может перезаписать authenticated row.
Reset/change/recovery удаляют все sessions; смена user credential version делает
позднее сохранение старой сессии недействительным. Имеется двухсоединительный тест
с lock_timeout, проверяющий сериализацию session issuance/password change.

session_identity возвращает только safe projection по серверным id/version при
active identity и непросроченном temporary state. Нет общего SELECT hash или
изменения users RLS. my_clinics возвращает только собственные активные memberships;
select_clinic перепроверяет членство и пишет audit, а tenant operations всегда
проверяют текущие permissions и scope. employee_directory — узкая authorized
projection login/name/role/branches с can_view_member, без чужих tenant/credentials.
Platform reads возвращают только clinic metadata и allowlisted operational audit.
Все functions имеют fixed search_path, закрытого owner и точечные EXECUTE grants.

После отказа SQL mutation откатывается; operation.denied пишется отдельной
транзакцией только от актуальной identity, с server operation code без bindings.
Успешные mutations и их audit по-прежнему атомарны. Auth lifecycle audit глобален
и не раскрывается через platform tenant-data API. CLI platform bootstrap — owner
операция с отдельным аудитом, недоступная runtime API.

assert_branch_delegation — узкий boolean/exception guard без read projection:
Governance вызывает его в той же транзакции после role/member/reset/link mutation,
до commit и освобождения user/tenant locks. Для assigned_branches проверяется
фактическое подмножество branch ids actor; equal scope names не разрешают иной
набор филиалов. Для role edits проверяются существующие members; при link accept
повторно используется initiating actor. Ошибка откатывает accounts/hash/sessions
и исходный audit вместе с mutation, затем фиксируется operation.denied.

## Sprint 3 stage 1 — planned security schema

See [sprint-3-security-schema.md](sprint-3-security-schema.md) for future additive
relations, composite constraints, RLS predicates, controlled writes, concurrency
and migration provenance requirements. No Sprint 3 migration runs at stage 1.

## Sprint 3 stage 2 — additive Patients schema

Migration 000006 adds patients/patient_branches, locked tenant patient counters,
PII-free idempotency references and permission/grant provenance. See
[sprint-3-stage-2.md](sprint-3-stage-2.md) for actual RLS/command boundaries and
non-destructive rollback refusal rules. No doctor, appointment or visit table exists.

## Sprint 3 stage 3 — additive scheduling schema

Migration 000008 adds tenant/branch IANA timezone configuration and 11 FORCE-RLS
relations: specialties, doctor_profiles, doctor_services, doctor_branches, rooms,
schedule_rules, schedule_breaks, schedule_exceptions, schedule_exception_breaks,
scheduling_idempotency and append-only scheduling_changes. Tenant-composite
relationships include branch in room references. Runtime has no direct DML.
See [Stage 3](sprint-3-stage-3.md) for live eligibility, protected commands,
transaction lock order, deployment/backfill and non-destructive rollback.
