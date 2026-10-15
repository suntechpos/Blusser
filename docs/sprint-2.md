# Sprint 2: CEO и управление пользователями

Окончательная бизнес-модель заменяет первоначальную модель «platform назначает
Clinic Admin». Platform Super Admin и клиника — отдельные контуры полномочий.

## Инварианты

1. Platform создаёт tenant и ровно одного первоначального CEO. Создаются только
   одна защищённая tenant-role CEO с полным tenant-каталогом прав и одно членство.
   Нет автоматически созданных Doctor/Receptionist и рядовых сотрудников.
2. Новый CEO получает login и временный пароль единожды, вне приложения по
   защищённому каналу. Хранится только Argon2id hash; срок — 24 часа.
3. До обязательной смены пароля CEO и сотрудники не имеют tenant-доступа.
4. CEO самостоятельно создаёт tenant roles, grants action/scope, филиалы,
   сотрудников и назначения. Возможна делегация в пределах собственных прав.
5. CEO обозначается записью tenant_ceos, не именем роли или набором permissions.
   Bootstrap-role CEO и её членство защищены от изменения/удаления/деактивации
   tenant API. Передача CEO — отдельный будущий процесс, не обычное assign_role.
   Таким образом, всегда сохраняется активный полный администратор клиники.
6. Platform identities не могут состоять в клиниках или выполнять tenant use cases,
   даже если ошибочно существует membership. TenantContext и RLS запрещают доступ.
   Для иной законной работы нужен отдельный обычный аккаунт, не platform identity.
7. Platform не создаёт сотрудников после bootstrap, не редактирует roles/grants,
   не назначает филиалы, не сбрасывает пароли сотрудников. Медицинские/персональные
   данные клиник недоступны. Исключение — минимальный login первоначального CEO
   и сведения о клинике, необходимые для создания, без employee directory.
8. Изменение собственной роли/филиалов и grants используемой собой роли запрещено.
   Нельзя выдать отсутствующее право или более широкий scope; own и
   assigned_branches не считаются взаимно вложенными. entire_clinic покрывает оба.
9. Только специальные permissions дают управление сотрудниками/roles/reset.
   Сброс сотрудника не может использоваться для захвата CEO, platform account,
   более привилегированного пользователя или global identity другой клиники.
10. Все успешные изменения атомарны с аудитом. В нём нет паролей, hashes, recovery
    tokens и request bodies. Runtime не получает owner credentials, BYPASSRLS,
    прямой DML в бизнес-таблицы или общий SECURITY DEFINER SQL executor.

## Глобальный login и linking

Login нормализуется trim + ASCII lower-case, 3–64 символа [a-z0-9._-], global UNIQUE.
Обычный create никогда не обновляет существующую identity при конфликте login.
CEO/делегированный admin предлагает привязку; target подтверждает её собственным
текущим паролем, после обязательной смены временного. На accept повторно проверяются
активность инициатора, credential version, актуальные grants, роль и филиалы.
Предложение действует 24 часа, принимается один раз, не меняет пароль/профиль.
Нет глобального пользовательского поиска: exact-login lookup с permission и rate limit
возвращает лишь необходимость linking без чужих профилей/клиник.

Если выбранный CEO уже существует, platform не выдаёт ему новый пароль. Tenant
создаётся в pending-состоянии с CEO proposal и без активного членства. Только
подтверждение существующим пользователем активирует tenant и назначает CEO.
На pending tenant нельзя создать второго CEO или обычного сотрудника. Повторная
provisioning по тому же slug не выдаёт пароль повторно и не создаёт другой account.

## Recovery CEO

Это отдельная процедура, не employees.reset_password и не platform edit-user.

- Один активный Platform Super Admin открывает recovery именно действующего CEO.
- Другой активный Platform Super Admin после независимого подтверждения личности
  вне приложения утверждает request. Инициатор, verifier и target — разные лица.
  В БД сохраняется только неприватный reference проверки, не копии документов.
- Система выдаёт один случайный 256-bit token. В БД только SHA-256 digest;
  token доставляется CEO по отдельно проверенному каналу, не через аудит/логи.
- CEO в течение 15 минут предъявляет token и сам задаёт новый постоянный пароль.
  Platform не получает этот пароль; повторное использование token запрещено.
- Request действует 24 часа; изменение credential version после открытия делает
  его недействительным. Все глобальные сессии отзываются атомарно при восстановлении.
  Роли, membership и филиалы не изменяются, включая другие клиники CEO.
- Возможность сговора двух привилегированных операторов относится к операционной
  границе доверия. Код не подтверждает подлинность внешних документов: до применения
  процедуры организация должна назначить независимых проверяющих и канал проверки.
  Если второго оператора/подтверждения нет, recovery закрыт, а не упрощается.

Platform не восстанавливает аккаунты рядовых сотрудников. Для multi-clinic
сотрудника обычный tenant reset запрещён; до отдельного self-service recovery
доступна собственная смена с текущим паролем. Не создаются дубли identity.

## Реализация и транспорт

Sprint 2 добавляет серверные use cases и узкие PostgreSQL SECURITY DEFINER функции:
provision clinic/CEO, password verification/change, custom roles/grants, employee
creation/assignments/reset, безопасное linking и двухсторонний recovery CEO.
Сервисные actor objects должны поступать из проверенной серверной identity,
никогда из body/header. SQL повторно проверяет active/credential version/first-login
и нужные права; app.user_id/app.tenant_id — доверенная серверная граница.

HTTP transport подключён: Sanctum использует first-party request guard с
version-aware server session, а не стандартный Eloquent SELECT * при restoration.
Default Eloquent provider и users column grants сохранены для регрессии Sprint 1;
guard получает только safe projection без hash через session_identity. Обычный
Sanctum password-hash session middleware не применяется к этому request guard:
его функцию выполняет проверка credential version и stage на каждом запросе.

REST API и эксплуатационный сценарий описаны в sprint-2-api.md и openapi.yaml.
First-party Origin/Referer, CSRF, no-store и full/limited middleware обязательны.
Frontend и медицинские/финансовые разделы не входят.

## Проверки и приёмка

Проверить две клиники и разные роли одного user; platform запреты даже с membership;
ровно одного CEO; occupied login и existing CEO consent; password_hash, first-login
и просрочку; роль/scope delegation, self-assignment, защиту CEO/последнего admin;
permission revocation до accept linking, expiry/replay, cross-tenant FK;
reset single-clinic employees и отзыв sessions; recovery two-person/expiry/replay/
stale credentials без изменения других memberships. В аудите не должно быть секретов.
Все DB-проверки выполняются runtime-role, fixtures/миграции — owner role.

## Локальная проверка и оставшаяся интеграция

Сборка, миграции и тесты выполняются командами README. Новая миграция 000004
создаёт governance schema, tenant_ceos и ceo_recovery_requests; initial platform
accounts не seed-ятся и не имеют default passwords. На старой базе с tenant без
CEO бизнес-операции закрыты до отдельной проверенной миграции владельцев. Нельзя
автоматически объявлять произвольного Clinic Admin владельцем.

Реализованы version-aware Sanctum session adapter, full/limited auth middleware,
CSRF/no-store, DTO validation, actor/trusted-IP rate limits и REST controllers.
Прокси по умолчанию не доверены: X-Forwarded-For не используется для обхода IP quota.
Первый platform operator создаётся контролируемой CLI-командой с отдельными owner
credentials и скрытым подтверждаемым вводом пароля, не через runtime API.
Transfer CEO и self-service recovery рядовых multi-clinic accounts остаются
отдельными задачами. Reject linking реализован. Для production нужны TLS,
проверенный deployment/runbook, внешняя identity verification и безопасный канал
доставки временных паролей/token; backend не заменяет эти процедуры.

Credential verifier использует узкий identity_lookup с exact login; это намеренная
серверная identity boundary, не общий SELECT пользователей и не Eloquent Auth bypass.
Функция не публикуется через HTTP, default provider/RLS/column grants не расширены.
При ошибках DB wrapper удаляет QueryException и bindings из цепочки исключений.
Не включать query binding/body logging для credential paths.
