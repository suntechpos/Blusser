# Blusser: архитектура первого этапа

Статус: архитектура актуализирована для Sprint 2. Окончательная модель CEO и
границы реализации описаны в sprint-2.md; Sprint 1 foundation уже реализован.

## Цель и границы

SaaS для независимых частных клиник. Клиника является tenant, филиал принадлежит
ровно одной клинике. Пользователь имеет одну глобальную учётную запись и может
состоять в нескольких клиниках с разными ролями.

В первом этапе: управление клиниками, филиалами, сотрудниками и услугами,
аутентификация, RBAC, аудит и изоляция данных. Медицинские карты, диагнозы,
лабораторные данные, платежи и финансовые операции исключены. В примерах и
тестах используются только синтетические данные.

## Технологии и структура

- Backend: Laravel, REST API, modular monolith.
- Frontend: Vue 3, TypeScript, Vite, Pinia.
- База данных: PostgreSQL с Row-Level Security (RLS).
- Локальный запуск: Docker Compose; без дополнительных микросервисов.

Предлагаемая структура после согласования:

```text
backend/
  app/Modules/
    Identity/          # users, login, logout, sessions
    Platform/          # platform admin, создание клиник
    Tenancy/           # tenant context, memberships, branches
    Access/            # roles, permissions, authorization
    Catalog/           # services
    Audit/             # audit events
  routes/api.php
  database/migrations/
  tests/Feature/
frontend/
  src/
    app/               # router, API client, initialization
    modules/           # auth, platform, clinic, branches, employees, services
    stores/            # session, clinic selection
    locales/           # uz, затем ru
    shared/
docs/
compose.yaml           # будет создан на этапе реализации
```

Модули организуют код внутри одного Laravel-приложения. Они не становятся
отдельными сервисами. Межмодульные операции используют явные application services
и транзакции. Необходимые версии Laravel/PHP, PostgreSQL и npm-пакетов будут
выбраны из совместимых поддерживаемых версий перед установкой и закреплены в
Docker-образах и lockfiles. Внешние библиотеки добавляются только по необходимости.

## Аутентификация

First-party SPA работает с Laravel Sanctum через cookie session, без хранения
bearer tokens в localStorage. В production frontend и API публикуются на одном
origin; в development Vite проксирует API и auth-запросы в backend.

Login требует CSRF-защиты, ограничения частоты попыток и регенерации session ID.
Logout завершает серверную сессию. Cookie: HttpOnly, Secure в production,
SameSite=Lax. Изменяющие запросы требуют CSRF token. Пароли хешируются средствами
Laravel; пароли и cookies никогда не записываются в аудит.

### Создание сотрудника и глобальная identity

Публичная регистрация отсутствует. Первый Platform Super Admin создаётся
отдельным deployment процессом с безопасным вводом пароля, без пароля по умолчанию.
CLI bootstrap и HTTP auth transport реализованы (см. sprint-2-api.md).
Platform создаёт только первоначального CEO клиники. Далее CEO или
уполномоченный им через RBAC администратор создаёт сотрудников:
он задаёт глобальный login, имя и временный пароль. Login — отдельное поле, не
email и не tenant-local username. Канонизация: trim + ASCII lower-case, допустимы
`[a-z0-9._-]`, длина 3–64; уникальность canonical login обеспечивает БД глобально.
Email необязателен и не используется для входа или автоматического объединения.
Изменение login через tenant employee update не разрешено.

Создание требует employees.create и employees.assign_role; назначение филиалов —
также employees.assign_branches. Backend проверяет tenant, роль, scope и потолок
делегирования. User, membership, branch assignments и account.created audit
создаются атомарно. Поиск существующего пользователя выполняется только точным
нормализованным login, с отдельным employees.lookup_identity, rate limit и аудитом;
нет глобального справочника или поиска по подстроке. Ответ содержит только
«можно создать» либо «необходима привязка существующей учётной записи», без чужих
клиник, профиля и user ID. Для неавторизованного клиента такого endpoint нет.

Если login занят, создание не перезаписывает пароль/имя и не создаёт дубликат.
Вместо этого администратор с employees.create/assign_role создаёт предложение
привязки с выбранной tenant-role. Существующий пользователь подтверждает его
в собственной полноценной сессии после повторного ввода текущего пароля;
перед подтверждением ему показываются клиника и предлагаемая роль. При принятии
backend повторно проверяет полномочия инициатора, потолок делегирования и состояние
роли/клиники; membership создаётся один раз под unique constraint. Предложение
действует 24 часа, одноразовое, отклоняемое; подтверждение не меняет global identity.
Привязка не требует email-провайдера и не позволяет администратору получить доступ
к уже существующему аккаунту. Создание proposal самому себе запрещено.

### Временный пароль, смена и сброс

Только безопасный Argon2id hash через Laravel Hash сохраняется в БД; параметры
подбираются на целевом runtime, rehash выполняется при необходимости. Для обычного
и временного пароля: 12–128 символов, без скрытого усечения; новый пароль отличается
от текущего. Временный пароль задаётся администратором через HTTPS-форму и передаётся
сотруднику вне приложения безопасным каналом. Он не возвращается API, не сохраняется
в localStorage, аналитике, логах, flash/session payload или аудите; форма очищается
после отправки, ответы чувствительных endpoints имеют Cache-Control: no-store.
Администратор не может просмотреть или восстановить существующий пароль/хеш.

Создание и reset устанавливают must_change_password=true и срок действия
временного пароля 24 часа. Просроченный пароль не позволяет войти: требуется новый
сброс. Первый успешный вход создаёт только ограниченную серверную сессию:
разрешены смена пароля, logout и минимальный auth status; tenant/platform API,
принятие привязки и управление сотрудниками заблокированы на backend. Экран смены
пароля обязателен и не обходится прямым запросом. Смена требует текущий пароль,
новый пароль и подтверждение, CSRF и rate limit. Успешная смена очищает временное
состояние, увеличивает credential version, отзывает остальные сессии и создаёт
новую полноценную сессию с новым ID. Повторное использование временного пароля
после смены не работает. Обычная смена собственного пароля требует те же проверки.

Административный reset — специальное employees.reset_password (entire_clinic),
не подразумеваемое employees.update. Нужны недавняя повторная аутентификация actor
(не старше 5 минут), активное целевое membership и отдельный ввод нового временного
пароля. Самосброс, сброс Platform Super Admin через tenant API и сброс пользователя
с более широкими effective permissions запрещены; сравниваются action и scope.
Reset не изменяет роль и не снимает ограничения global user/membership.

Глобальный пароль даёт доступ ко всем клиникам пользователя, поэтому tenant-admin
не может сбросить пароль multi-clinic identity: это позволило бы войти в другую
клинику. Tenant reset разрешён только при единственном membership пользователя
в этой клинике (включая неактивные memberships при проверке других клиник).
Для multi-clinic пользователя доступны собственная смена пароля либо отдельная
self-service recovery после отдельного согласования. Для CEO предусмотрена
отдельная two-person процедура из sprint-2.md; platform не сбрасывает пароли
рядовых сотрудников. Recovery UI не входит в текущую backend-реализацию. В tenant API
отказ не раскрывает названия/число других клиник. Предложение привязки не даёт
reset-доступа. Сотрудников с несколькими клиниками нельзя дублировать ради сброса.

Reset атомарно меняет hash, выставляет обязательную смену/expiry, увеличивает
credential version и удаляет все серверные сессии пользователя во всех клиниках.
Remember-me не включается на первом этапе. На каждом запросе и при создании сессии
сверяется credential version: старая сессия, в том числе сохранённая конкурентным
запросом после reset, не даёт доступа. Login/change/reset/link синхронизируются
блокировкой user; session выдаётся только для актуальной версии. Новые запросы
со старыми cookies отвергаются после commit reset. Уже выполняющийся запрос не
может быть «отозван задним числом»; критические записи повторно проверяют версию
под блокировкой перед commit. Проверки последнего администратора сохраняются;
reset не является удалением его membership.

### Защита входа и аудит identity

Login возвращает одинаковую ошибку для неизвестного login, неверного пароля,
блокировки и недоступной учётной записи, с сопоставимым путём hash-проверки.
Предварительные лимиты: 5 неудач на нормализованный login за 15 минут → временная
блокировка попыток этого login на 15 минут; дополнительно 30 попыток на IP за
15 минут, включая несуществующие logins. Успех не сбрасывает IP-счётчик;
параллельные попытки учитываются атомарно. Нет бессрочной блокировки, снимаемой
только администратором. Лимиты настраиваемые, проверяются на сервере до дорогого
hash; trusted proxies задаются явно, X-Forwarded-For от клиента не доверяется.
Change/reset/lookup/link имеют собственные actor/IP rate limits. Состояние
лимитов общее для процессов в PostgreSQL, не in-memory и без нового сервиса.

account.created, password.changed, password.reset, membership.link_requested/
accepted/rejected фиксируются с actor, subject, request_id, временем и результатом.
Создание/reset, ревокация и audit атомарны. Identity events глобальные, с
tenant_id NULL; инициировавшая клиника получает отдельное tenant event без данных
других клиник. Смена собственного пароля создаёт глобальное событие; она не
копирует историю во все клиники. В аудите нет паролей, hash, cookies, session ID,
CSRF tokens или содержимого форм. Неуспешный вход/лимит — отдельный security event
без raw login для неизвестного пользователя. Публичный email recovery и рассылка
приглашений не входят в этап; account lifecycle работает без почтового провайдера.

## Tenant context и защита доступа

Клиент выбирает клинику через `/api/v1/clinics/{tenant}`. URL является только
идентификатором запроса: сервер не доверяет tenant ID из URL, body или header.
Порядок обработки tenant-запроса:

1. Аутентифицировать пользователя из серверной сессии.
2. Открыть DB-транзакцию на runtime connection и установить transaction-local
   `app.user_id` из аутентифицированного пользователя.
3. Проверить активное членство пользователя в выбранной активной клинике через
   ограниченную self-policy таблицы memberships; роль пользователя не берётся
   из клиентского запроса.
4. Установить `app.tenant_id` через параметризованный `set_config(..., true)`.
5. Выполнить permission policy и только затем разрешить привязку tenant-ресурса
   из маршрута, чтение и запись.
6. Commit или rollback завершает context; исключения также закрывают транзакцию.

Middleware работает до tenant route-model binding. Eloquent scopes и policies
служат дополнительной защитой, но не заменяют RLS. Входные tenant_id и actor_id
запрещены к mass assignment; сервер подставляет проверенные значения.

Отсутствующий context запрещает доступ. Все tenant queries выполняются внутри
транзакции с SET LOCAL; streaming-ответы и запросы к БД после завершения
транзакции запрещены. Фоновые задания, если понадобятся позже, должны отдельно
восстанавливать и проверять context. Глобальные mutable/static tenant values
и session-level SET не используются. Тесты проверяют повторное использование
соединения после успешных и неуспешных запросов.

## Platform plane и CEO

Platform Super Admin создаёт tenant и ровно одного первоначального CEO, не обычных
сотрудников. Выдача login/временного пароля нового CEO выполняется один раз;
повторная provisioning не сбрасывает пароль. Существующая identity требует своего
подтверждения linking, без нового пароля; до него клиника остаётся pending.

CEO фиксируется в tenant_ceos с составной ссылкой на membership. Его защищённая
bootstrap-role содержит весь tenant-каталог с entire_clinic. Tenant API не меняет
это назначение и не удаляет последнего полного администратора. CEO сам создаёт
остальные должности, права и сотрудников; стандартные роли заранее не копируются.

Platform identities не допускаются к tenant data даже при ошибочном membership.
Им не доступны employee create/reset, roles, grants и назначения филиалов.
Для CEO recovery существует только отдельный audited two-person процесс из
sprint-2.md; существующие пароли никогда не читаются/показываются оператору.
Медицинские и персональные данные сотрудников для platform по умолчанию закрыты.
Минимальные данные первоначального CEO для provisioning не образуют employee directory.

## RBAC: роли клиники, действия и scope

Каждая клиника самостоятельно создаёт должности в `tenant_roles` и назначает
им grants из глобального каталога `permissions`. Каталог кодов определяется
приложением: клиника не создаёт новые backend-действия произвольными строками.
CEO — защищённая начальная tenant-role. Остальные должности (включая названия
Clinic Admin, Receptionist, Doctor) CEO создаёт самостоятельно; глобальных
назначаемых ролей нет. Название должности не даёт прав.
Членство имеет одну tenant-role; один пользователь может иметь разные роли
в разных клиниках. Множественные роли в одном членстве пока не предусмотрены.

Grant — пара `(permission, scope)` в role_permissions. Для каждой пары роль/право
хранится один scope. Решение backend: активный user + tenant + membership + role,
наличие права на конкретное действие и попадание ресурса в разрешённый scope.
Default deny применяется при отсутствии любого условия. Права одной клиники
никогда не объединяются с правами другой.

| Раздел | Отдельные permissions | Поддерживаемые scopes |
| --- | --- | --- |
| Dashboard | dashboard.view | own, assigned_branches, entire_clinic |
| Филиалы | branches.view/create/update/delete | view/update/delete: assigned_branches, entire_clinic; create: entire_clinic |
| Сотрудники | employees.view/create/update/delete; employees.assign_role; employees.assign_branches; employees.lookup_identity; employees.reset_password | view: все три; остальные: entire_clinic |
| Услуги | services.view/create/update/delete | entire_clinic |
| Должности | roles.view/create/update/delete; roles.manage_permissions | entire_clinic |
| Аудит | audit.view | own, assigned_branches, entire_clinic |

Коды действий не подразумевают друг друга: view не даёт update, update не даёт
assign_role или manage_permissions. Read-only аудит не имеет create/update/delete
в пользовательском каталоге. Отключение сотрудника выполняется employees.delete
как деактивация membership, не удаление глобального user. Неиспользуемую роль
можно удалить, используемую — только после разрешённого переназначения сотрудников.
Platform clinics.view/create/update остаются вне tenant-каталога.

### Семантика областей доступа

- `own`: employees — собственное membership; audit — события текущего actor.
  Это не означает «всё, что пользователь когда-либо создал».
- `assigned_branches`: актуальные активные филиалы из membership_branches;
  branches — эти филиалы, employees — сотрудники с общим назначенным филиалом,
  audit — события с явным branch_id из этого набора. Пустой набор не даёт доступа.
- `entire_clinic`: все разрешённые ресурсы только текущего tenant.

Услуги и роли — общеклинические справочники без owner/branch; own и
assigned_branches для них отклоняются при сохранении grants. Tenant-wide audit
с branch_id NULL не попадает в assigned_branches; own может видеть собственное
такое событие. Dashboard применяет соответствующие predicates каждого показателя;
счётчик показывается лишь при наличии dashboard.view и view-права на исходный
раздел, с пересечением обоих scopes. Неопределённая семантика scope означает
отказ, а не переход к entire_clinic.

Backend применяет scope к спискам, поиску, counts, отдельным ресурсам и операциям.
Для update проверяется как исходное, так и итоговое состояние; нельзя переместить
ресурс в недоступный филиал. Create проверяет право и scope по серверным FK и
итоговому ресурсу. Для текущих разделов create разрешён только entire_clinic.
Клиентские branch_id, owner_id и tenant_id не расширяют контекст доступа.
RLS сохраняет безусловную tenant-границу; action/scope проверяют backend policies
и query services, включая ограниченные DB helpers. Изоляция внутри клиники
не заявляется как защита от произвольного SQL с runtime credential.

### Делегирование и защита администрирования

Только пользователи с соответствующими roles.* и employees.assign_role/
employees.assign_branches могут управлять RBAC; название «администратор»
не является проверкой. roles.update изменяет метаданные, но не grants;
изменение grants требует отдельного roles.manage_permissions.

Для создания/изменения grants и назначения роли действует потолок делегирования:
каждое выдаваемое право должно принадлежать actor с не меньшим scope.
entire_clinic покрывает остальные допустимые scopes; own и assigned_branches
не считаются взаимозаменяемыми или вложенными автоматически: собственная запись
может находиться вне назначенных филиалов. Для делегирования между ними нет
неявного расширения; требуется тот же scope или entire_clinic. Для прав управления RBAC и назначения ролей допустим
только entire_clinic, что исключает передачу branch-доступа шире собственных
назначений. Роль с лишним правом нельзя назначить даже если actor её не создавал.
Создание пустой роли само по себе не даёт возможности назначить ей permissions.

Запрещены изменение собственной роли, собственных назначений филиалов и изменение
grants используемой собой роли; редактирование такой роли другим администратором
также не должно расширять права инициатора через косвенную операцию. В частности,
actor не может редактировать общую роль так, чтобы повысить свои полномочия,
создать membership самому себе, назначить себе другую роль или использовать
employee update для обхода специального action. Самостоятельное изменение имени
профиля вне RBAC не затрагивает членство. Повышение пользователя выполняет другой
уполномоченный администратор в пределах собственного потолка делегирования.

Операции с ролями, grants, memberships и branch assignments сериализуются
блокировкой строки tenant, перечитывают права после блокировки и атомарно пишут
аудит. Анализируется итоговое состояние, включая всех пользователей изменяемой
общей роли. Защита последнего администратора основана на capabilities:
должно остаться хотя бы одно активное user/membership/role с entire_clinic
для roles.view/create/update/delete/manage_permissions и
employees.view/create/update/delete/assign_role/assign_branches.
Нельзя удалить/отключить/понизить последнего такого пользователя, его роль или
нужный grant. Глобальная деактивация user проверяет инвариант во всех его клиниках
с блокировками tenants в стабильном порядке. Первая полная роль создаётся только
provisioning-функцией, до появления actor с полномочиями делегирования.

Membership, role, grants и branch assignments перечитываются при каждом запросе;
изменения действуют со следующего запроса, без долгоживущих permission claims.
Клиент скрывает недоступные действия, но backend выполняет все проверки независимо
от UI. Platform Super Admin не назначается через tenant API и не допускается к tenant context.

## REST и интерфейсы

Планируемые маршруты: `/api/v1/auth/*`, `/api/v1/me`,
`/api/v1/platform/clinics`, `/api/v1/clinics/{tenant}/dashboard`,
`/branches`, `/employees`, `/services`, `/roles` внутри выбранного tenant.
Изменение role permissions, назначение роли и филиалов — отдельные actions
с собственными backend policies. Создание identity, exact-login lookup, reset
и предложения привязки имеют отдельные handlers; auth/password/change доступен
ограниченной first-login session, но не даёт обхода tenant authorization.
Для списков обязательны ограниченная пагинация, allowlist сортировки и валидация.
Ошибки: 401 без сессии, 403 при отсутствии права на разрешённую операцию,
404 для недоступной клиники или ресурса другой клиники, 422 для валидации.

Экраны: Super Admin Dashboard, Clinics List, Create Clinic, Clinic Admin Dashboard,
Branches, Employees, Services; дополнительно login, выбор доступной клиники
и Roles & Permissions с редактором прав и scope. Назначение должности и филиалов
сотруднику доступно только при наличии специальных прав. В Employees добавляются
создание с временным паролем, привязка существующего аккаунта и reset; пароль
никогда не отображается повторно. Отдельные экраны — обязательная смена пароля
и подтверждение привязки клиники; все сообщения локализуются на uz.
Dashboard показывает только фактические доступные количества из БД или честное
пустое состояние. Нет демо-выручки, выдуманных посещений и медицинских показателей.

Основной язык — узбекский (`uz`, латиница). Все UI-строки используют ключи словаря,
форматы дат и чисел — Intl. Ошибки API содержат стабильный code и field errors
для перевода клиентом. Структура словарей и locale resolver допускают `ru` без
переписывания экранов; перевод на русский не входит в первый этап.

## Эксплуатационные границы

Compose содержит frontend, backend и PostgreSQL. База хранится в named volume;
healthcheck БД предшествует миграциям. Schema migrations выполняются отдельной
командой с owner credential; API использует ограниченного DB-пользователя.
Пароли задаются локально через ignored .env; в Git только .env.example без секретов.
Логи не содержат паролей, session tokens, request bodies или медицинских данных.
Настройка backup, TLS, production deployment и disaster recovery требует отдельного
этапа перед реальной эксплуатацией.

## Sprint 3 stage 1 — planned clinic operations

The approved tenant-wide patient registry, active doctor-patient relationship scope,
appointment/visit/ticket boundaries and transition contracts are specified in
[sprint-3.md](sprint-3.md). This is a design-stage addition; no clinical APIs are
implemented by stage 1. Existing identity, CEO governance and RLS remain unchanged.

## Sprint 3 stage 2 — Patients

[Stage 2 delivery](sprint-3-stage-2.md) implements the tenant-wide registry with
branch-scoped projections and controlled writes. Doctor own scope remains
fail-closed until actual care relations exist. Approved optimistic concurrency
clarification: Patient JSON unchanged; ETag exposes expected_version for archive/
restore. Later operational modules and frontend integration are still deferred.

## Sprint 3 stage 3 — Doctors and scheduling

[Stage 3 delivery](sprint-3-stage-3.md) adds live membership/branch-scoped doctor,
room and schedule reference APIs. Approved extensions: existing-clinic timezone
backfill to Asia/Tashkent and specialties POST/PUT using doctors.create/update.
Availability is explicitly schedule-only until Stage 4; patient own scope stays
fail-closed. No clinical grants or platform bypass are introduced.

## Sprint 3 stage 4 — Appointments and Visits

[Stage 4 delivery](sprint-3-stage-4.md) adds exclusion-protected shared occupancy,
versioned appointments, separate Visits and typed event history. The owner-approved
minimal queue foundation creates only internal waiting tickets/counters; no public
Queue actions are enabled. Real active care relations now gate patient own scope,
and active attendance blocks archive. Availability incorporates current occupancy
while remaining a hint. Platform identities receive no clinical bypass.
