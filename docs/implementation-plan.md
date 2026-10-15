# Blusser: пошаговый план первого этапа

Статус: ожидает подтверждения архитектуры. Сейчас изменяются только документы.
Описанные ниже команды и проверки являются планом, а не выполненными результатами.

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
permissions и составные tenant FK по database.md. Стартовые шаблоны копируются
в tenant при provisioning; глобальных назначаемых ролей нет.
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

Критерий: анонимный запрос отклоняется; неактивные user/membership/tenant не дают
доступа; один user корректно переключается между двумя клиниками с разными ролями.
Platform Super Admin без membership не читает tenant API. Права не передаются
из клиента и не сохраняются устаревшими в session claims.

## 4. Базовые REST-модули

Реализовать platform clinics list/create и атомарное назначение первого Clinic Admin.
В tenant API: branches, employees/memberships, services, dashboard, audit view,
tenant roles и permissions editor. Каталог содержит отдельные view/create/update/
delete и специальные actions только для поддерживаемых операций. Query services
применяют scopes к спискам, поиску, aggregates и ресурсам, write policies проверяют
исходное и итоговое состояние.
При создании клиники branch не обязателен: филиал создаётся отдельной операцией.
Ограничить employee responses необходимыми полями, не возвращать password hash.
Публичную регистрацию, email delivery и восстановление пароля не подключать
без отдельно согласованного решения.

Критерий: создание клиники и филиала, валидация, пагинация, consistent errors,
permission checks и атомарный аудит. Нет медицинских и финансовых endpoints.

## 5. Интерфейс

Создать login, выбор клиники и семь запрошенных экранов: Super Admin Dashboard,
Clinics List, Create Clinic, Clinic Admin Dashboard, Branches, Employees, Services.
Добавить Roles & Permissions: создание должностей, редактор action/scope grants
и назначение должности/филиалов сотруднику с backend validation feedback.
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
- Platform Super Admin без membership не получает tenant data и scopes bypass;
  tenant-admin не может назначить глобальный platform-признак.
- Создание клиники + первого admin + audit атомарно; отдельное создание филиала
  доступно только разрешённой роли. Ошибка оставляет БД без частичных записей.
- Нельзя привязать сотрудника A к филиалу B; конкурентное удаление/понижение
  администраторов не оставляет клинику без активного Clinic Admin.
- Session login/logout, CSRF и throttling; disabled identities лишаются доступа.
- Audit нельзя изменить/удалить или подделать actor; секретов в metadata нет.

Frontend: typecheck и production build, тесты выбора клиники, очистки state,
прав и scope, редактора должностей, основных форм и узбекских сообщений; локальный HTTP smoke
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

Это будущий контракт команд: сейчас compose.yaml и приложение отсутствуют,
поэтому запускать эти команды пока нельзя. В реализации будут документированы
инициализация APP_KEY, раздельные DB credentials и правильный выбор migration
connection. Не использовать down -v как штатную остановку: он удаляет данные.

Сохранить необходимые cloud install/start instructions, проверить старт после
остановки процессов и повторный install по lockfiles. В PR включить список файлов,
фактические проверки и ограничения. Git push и PR не означают merge или публикацию.

## Известные ограничения текущего этапа

Существуют только архитектурные документы и README. Автоматических тестов,
сборки, API, UI и миграций пока нет. Readiness приложения не заявляется.
Перед production отдельно потребуются backup/restore, TLS, управление секретами,
доставка приглашений/восстановление доступа, мониторинг и политика хранения аудита.
