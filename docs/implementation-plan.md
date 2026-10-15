# Blusser: пошаговый план первого этапа

Статус: Sprint 1 реализован. Приоритет Sprint 2 — окончательная модель CEO,
описанная в sprint-2.md. Старые упоминания первого Clinic Admin означают CEO;
platform не управляет обычными сотрудниками. Проверки будущих этапов — план.

## 0. Согласовать проект

Рассмотреть architecture.md и database.md: shared-schema RLS, membership per clinic,
tenant-owned роли, action permissions и scopes, bootstrap первого администратора, узбекскую латиницу,
отсутствие финансовых и медицинских модулей. После явного подтверждения перейти
к коду. Изменения оформлять отдельной веткой и PR; merge требует согласия.

## 1. Каркас и воспроизводимая среда

Создать backend Laravel и frontend Vue 3/TypeScript/Vite/Pinia. Закрепить совместимые
поддерживаемые версии инструментов и dependencies в Docker/lockfiles. Добавить
/docs, compose.yaml, .env.example без секретов и игнорирование локальных .env.
Compose: frontend, backend, PostgreSQL, healthchecks, persistent volume.
Не включать Redis, брокеры, отдельные auth-сервисы и другие микросервисы.

Критерий: сборка из чистого checkout, корректный health endpoint, миграции
на пустой базе. Проверить повторный запуск без потери сохранённых данных.

## 2. Схема, DB-роли и RLS

Реализовать tenant_roles, role_permissions со scope, глобальный каталог
permissions и составные tenant FK по database.md. При provisioning создаётся
только защищённая CEO role; остальные должности создаёт CEO. Глобальных
назначаемых tenant-ролей нет.
Разделить owner/migration и runtime connections; включить FORCE RLS.
Реализовать ограниченные helpers, provisioning и audit functions. Не выдавать
runtime-role superuser, ownership или BYPASSRLS.

Критерий: интеграционные PostgreSQL-тесты подтверждают fail-closed без context,
изоляцию SELECT/INSERT/UPDATE/DELETE и запрет cross-tenant composite FK.
SQLite для этих тестов не подходит. Migration tests используют owner;
все isolation assertions — runtime-role с проверкой её атрибутов.

## 3. Identity, tenant context, RBAC

Реализовать session auth с Sanctum, CSRF, rate limits, login/logout,
безопасное создание первого Platform Super Admin. Tenant middleware устанавливает
проверенный context внутри транзакции до route binding. Реализовать self-memberships,
выбор клиники, action/scope policies и защиту последнего полного администратора.
Добавить tenant-owned роли, редактирование grants и отдельные действия назначения
роли/филиалов. Проверять потолок делегирования, запрет self-escalation (включая
изменение общей роли и собственных филиалов), итоговый граф активных capabilities.
Сериализовать все RBAC changes блокировкой tenant, перечитывать права и писать аудит.
Определить predicates own/assigned_branches/entire_clinic до выполнения запросов;
неподдерживаемые пары permission/scope отклонять.

Добавить login canonicalization/global UNIQUE, hash-only credentials, временный
пароль с expiry, first-login middleware, смену собственного пароля и отдельный
admin reset. Реализовать credential version, ограниченные/full sessions и отзыв
всех сессий, включая конкурентный login/session save. Добавить атомарные общие
PostgreSQL rate limits на login/IP и отдельные identity actions, одинаковые login
ошибки. Провести Argon2id benchmark без отключения проверки hash.
Развести employees.create, assign_role, assign_branches, lookup_identity и
reset_password; create не подразумевает reset. Link существующего user требует
self-confirmation/reauth, expiry и повторную проверку инициатора; пароль не меняется.
Для multi-clinic identity запретить tenant reset. Для CEO — отдельный двухоператорный
recovery; для остальных — будущий self-service recovery, без platform employee reset.
Согласованный user→tenant lock order обязателен для identity и membership writes.

Критерий: анонимный запрос отклоняется; неактивные user/membership/tenant не дают
доступа; один user корректно переключается между двумя клиниками с разными ролями.
Platform Super Admin не читает tenant API даже с ошибочным membership. Права не передаются
из клиента и не сохраняются устаревшими в session claims.

## 4. Базовые REST-модули

Реализовать platform clinics list/create и атомарное создание единственного первоначального CEO (existing login — consent).
В tenant API: branches, employees/memberships, services, dashboard, audit view,
tenant roles и permissions editor. Каталог содержит отдельные view/create/update/
delete и специальные actions только для поддерживаемых операций. Query services
применяют scopes к спискам, поиску, aggregates и ресурсам, write policies проверяют
исходное и итоговое состояние.
При создании клиники branch не обязателен: филиал создаётся отдельной операцией.
Ограничить employee responses необходимыми полями, не возвращать password hash.
Реализовать создание сотрудника администратором и сброс временного пароля без
почтового сервиса. Публичная регистрация и email recovery остаются вне этапа;
не откладывать согласованные create/change/reset workflows из-за отсутствия email.

Критерий: создание клиники и филиала, валидация, пагинация, consistent errors,
permission checks и атомарный аудит. Нет медицинских и финансовых endpoints.

## 5. Интерфейс

Создать login, выбор клиники и семь запрошенных экранов: Super Admin Dashboard,
Clinics List, Create Clinic, Clinic Admin Dashboard, Branches, Employees, Services.
Добавить Roles & Permissions: создание должностей, редактор action/scope grants
и назначение должности/филиалов сотруднику с backend validation feedback.
Добавить создание с login/временным паролем, reset с reauth, обязательную смену
при первом входе и self-confirmation link. Секретные поля очищаются, не сохраняются
в Pinia persistence/localStorage/analytics и не отображаются повторно.
Добавить typed API client и Pinia session/context stores. При переключении клиники
очищать tenant state, отменять/игнорировать старые запросы, чтобы ответы предыдущей
клиники не попадали на новый экран.

Все пользовательские строки — в узбекском словаре; предусмотреть подключение ru,
locale fallback и Intl. Loading, empty, forbidden и validation states обязательны.
Dashboard получает реальные counts через API; до появления данных показывает нули
или пустое состояние без выдуманных метрик.

## 6. Автоматические проверки

Обязательные backend feature/integration сценарии:

- Две клиники A/B, филиалы, услуги и memberships с синтетическими fixtures.
- A не может перечислить, прочитать, изменить или удалить ресурсы B, в том числе
  по угаданному UUID, через nested routes и подмену body/header tenant_id.
- Прямые SQL-запросы как runtime-role подтверждают RLS для всех scoped таблиц;
  INSERT/UPDATE с tenant B отклоняются; отсутствующий context не открывает данные.
- Контекст не протекает при повторном использовании соединения, rollback,
  исключении и переключении A→B; self-policy раскрывает лишь собственные memberships.
- Клиники создают собственные роли; одинаковые названия не связывают права.
  Чужая роль/grant не назначается через ID, nested route или прямой SQL.
- Для каждого action проверить независимость view/create/update/delete/special;
  default deny и неподдерживаемые permission/scope combinations.
- Для own, assigned_branches, entire_clinic проверить списки, поиск, pagination,
  counts/dashboard, detail и writes. Пустые филиалы, NULL branch и общие справочники
  не получают fallback к clinic-wide. Проверить пересечение scopes dashboard/source.
- Один user с разными ролями в A/B не объединяет permissions; отключение роли,
  отзыв grant и смена филиалов действуют на следующем запросе.
- Только уполномоченные actors управляют ролями и назначениями; нельзя делегировать
  отсутствующий action или более широкий scope, в том числе назначением готовой роли.
- Нельзя повысить себя через membership, общую роль, собственные branch assignments,
  employee update или косвенное создание self-membership. Другой администратор
  может изменить доступ в пределах своего потолка; операции обязательно аудируются.
- Проверить конкурентные изменения grants/roles/memberships, деактивацию user/role,
  удаление роли и отзыв последнего admin-capability: активный полный администратор
  сохраняется, даже при изменении общей роли и нескольких clinics одного user.
- Platform Super Admin даже с ошибочным membership не получает tenant data и scopes bypass;
  tenant-admin не может назначить глобальный platform-признак.
- Создание клиники + первого admin + audit атомарно; отдельное создание филиала
  доступно только разрешённой роли. Ошибка оставляет БД без частичных записей.
- Нельзя привязать сотрудника A к филиалу B; конкурентное удаление/понижение
  администраторов не оставляет клинику без активного Clinic Admin.
- Создание аккаунта/роли/membership/audit атомарно; без каждого требуемого права
  create/assign_role/assign_branches операция отклоняется. employees.update/view
  не даёт reset. Только синтетические login/password fixtures.
- Login глобально уникален после канонизации: case/space и конкурентный create
  не создают дубликат; конфликт не меняет чужие password/profile/memberships.
- Exact-login lookup доступен только по permission, ограничен rate limit, не выдаёт
  чужие профили/клиники. Link подтверждает сам существующий user после reauth;
  подмена user/tenant/role, expiry, повторное принятие, отзыв прав инициатора и
  concurrent accept не обходят проверки и не создают дубликат membership.
- Первый вход с временным паролем даёт только password-change session;
  backend запрещает tenant/platform API и link accept до смены даже по прямому URL.
  Проверить expiry 24h, новый пароль≠текущий, границы длины, hash verify/rehash,
  отказ старого временного пароля после смены/reset. В БД только hash.
- Администратор не может читать/retrieve пароль/hash; ни API, ни аудит, ни session,
  security logs, exception/validation output не содержат введённые секреты.
- Self-change требует текущий пароль/CSRF, admin reset — permission и недавний reauth;
  нельзя reset самого себя, чужого tenant, platform admin или более привилегированного
  сотрудника. Multi-clinic target и pending-only link не дают tenant reset.
- Reset отзывает все target sessions; change отзывает старые и выдаёт новую.
  Проверить stale cookies, rollback, повторный reset, конкурентные reset/login,
  change/session save и login/link. Старый credential_version всегда запрещает доступ;
  ошибки не оставляют частично обновлённый hash, audit или sessions.
- Account created/password changed/reset имеют actor/subject/request_id/result;
  tenant audit не раскрывает другие clinics, global audit закрыт tenant-admin.
- Login limits атомарны на login и IP, включая неизвестные logins и параллельные
  процессы; временный cooldown истекает по времени тестовых часов, restart не
  сбрасывает его. Нельзя обходить лимит регистром login или подменой X-Forwarded-For.
  Ошибки входа не раскрывают существование/блокировку аккаунта.
- Session login/logout, CSRF и отдельные change/reset/lookup/link limits;
  disabled identities лишаются доступа; согласованный lock order не даёт deadlocks.
- Audit нельзя изменить/удалить или подделать actor; секретов в metadata нет.

Frontend: typecheck и production build, тесты выбора клиники, очистки state,
прав и scope, редактора должностей, first-login/reset/link flows, основных форм и узбекских сообщений; локальный HTTP smoke
на собранном UI и API. Конкретный минимальный test runner выбирается при реализации,
без введения дополнительных сервисов.

Критерий: реальные ненулевые test counts, отдельно отражённые passed/failed/skipped,
проверенный runtime DB-user. Нельзя объявлять readiness по одному открытому порту.

## 7. Документация запуска и PR

После реализации зафиксировать и фактически выполнить следующие интерфейсы команд
(точные service names и scripts появятся в Compose/package.json):

```sh
cp .env.example .env
# Задать локальные непубличные DB credentials, без коммита .env.
docker compose build
docker compose up -d db
docker compose run --rm backend php artisan migrate --force
# Выполнить документированную команду безопасного provisioning первого admin.
docker compose up -d backend frontend
docker compose exec backend php artisan test
docker compose exec frontend npm run typecheck
docker compose exec frontend npm run test -- --run
docker compose exec frontend npm run build
docker compose down
```

Это исходный целевой контракт; актуальные проверенные команды приведены в README.
Sprint 1 уже содержит Compose и backend. Документированы
инициализация APP_KEY, раздельные DB credentials и правильный выбор migration
connection. Не использовать down -v как штатную остановку: он удаляет данные.

Сохранить необходимые cloud install/start instructions, проверить старт после
остановки процессов и повторный install по lockfiles. В PR включить список файлов,
фактические проверки и ограничения. Git push и PR не означают merge или публикацию.

## Известные ограничения текущего этапа

Sprint 1 реализован. Sprint 2 теперь подключает cookie auth lifecycle и REST API
к governance use cases; контракт и проверки — sprint-2-api.md и sprint-2-validation.md.
UI отсутствует. Production readiness отдельно не заявляется.
Перед production отдельно потребуются backup/restore, TLS, управление секретами,
self-service recovery рядовых multi-clinic identities, мониторинг и политика
хранения аудита. Создание сотрудников и admin reset single-clinic аккаунтов
входят в реализацию этапа; почтовая доставка не является их prerequisite.

## Приоритет Sprint 2: окончательная модель CEO

1. Ввести immutable tenant_ceos, protected bootstrap-role, pending provisioning
   для существующего login и отдельный двухоператорный CEO recovery.
2. Разделить platform-only provisioning/recovery и tenant-only RBAC/employees.
   Platform flag запрещает tenant context даже при наличии membership.
3. Реализовать backend use cases с узкими DB-функциями, password_hash verification,
   first-login guard, изменением пароля, scopes и delegated administration.
4. Сохранить exact-login linking с согласия target, expiry/revalidation и без reset.
5. Проверить полный набор негативных сценариев и атомарный audit без секретов.
6. REST auth transport подключён вместе с version-aware session adapter,
   CSRF, rate limits, scoped reads и HTTP lifecycle/security тестами.
7. Проверить image без bind mounts, OpenAPI route coverage, apply/rollback и
   отсутствие runtime migration credentials; обновить существующий PR без merge.
