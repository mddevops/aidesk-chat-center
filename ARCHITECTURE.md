# Архитектура

## Реализованная основа

Laravel 13 (актуальная стабильная ветка, совместимая с PHP 8.3), React 19 / Inertia 2 / TypeScript, Tailwind 4. Собственные локальные UI-компоненты по MIT shadcn/ui и бесплатному ReUI. Composer platform PHP 8.3.0, точные версии — composer.lock/package-lock.json.

MySQL 8 — постоянные данные. Redis — кэш, зашифрованные сессии и очередь. Predis выбран вместо дополнительного нативного расширения. Reverb — отдельный процесс, ограничение origin. Echo создаётся по запросу после входа; секрет Reverb не попадает в Vite. Docker Compose предназначен для локальной разработки, PHP built-in server не предназначен для production.

Публичная стартовая страница не изображает работающий чат. GET /up — liveness Laravel, не readiness всех сервисов. Infrastructure Pest отдельно проверяет MySQL/Redis/реальную обработку очереди. Браузерные тесты проверяют русский UI, тему, мобильный viewport и handshake Reverb. Handshake не доказывает работу защищённых подписок или чата.

## Целевая структура модульного монолита

Модули Access, Organizations, Chat, Widgets, Routing, Integrations, Audit. До их реализации не создавать пустые слои и сервисы. HTTP controllers вызывают действия домена, policies проверяют tenant и право; транзакции защищают изменения агрегатов. События версионированы, отправляются после commit; durable outbox обеспечивает повторы интеграций.

Tenant определяется из аутентифицированного пользователя, никогда из произвольного organization_id запроса. Фоновое задание несёт явный tenant; отсутствие tenant должно закрывать доступ. Связи по tenant дополнительно проверяются при записи и, где возможно, составными FK. Visitor credential ограничен сайтом/сессией; телефон — атрибут поиска оператором, не секрет.

Messages: монотонный sequence, уникальный scoped client_message_id и hash payload; права до возврата идемпотентного результата. Публичные payload отдельно от операторских. Read-marker меняется только явной командой. Upload приватный, MIME/размер по серверной проверке, download после policy.

CRM и сторонние провайдеры — адаптеры с capabilities; никаких подключений без реального обмена. Endpoint CRM V2 пока не подтверждён. Все времена UTC, отчёты в timezone организации. Secrets шифруются и исключаются из props/log/audit diff.

## Organizations & Access (реализовано)

В текущем MVP пользователь принадлежит одной организации. Роли — фиксированный enum Owner/Admin/Supervisor/Operator/Analyst с серверной матрицей разрешений; настраиваемые роли и multi-organization membership пока не реализованы. Owner создаётся CLI-командой с интерактивным скрытым паролем, публичной регистрации нет. Admin не может назначать Admin/Owner; изменение владельца и собственной роли запрещено.

TenantContext scoped и очищается в finally. Middleware устанавливает его из authenticated user до route model binding. Showroom/Department/Site имеют fail-closed global scope и защиту model writes; составные FK MySQL запрещают межорганизационные Site→Showroom и User→Department. Organization и User намеренно доступны провайдеру авторизации/CLI без global scope: HTTP-действия используют только ID организации из контекста и явный tenant predicate для сотрудников. Нельзя добавлять неограниченный User::find в HTTP controllers.

Cookie auth: regenerate session при входе, invalidate при выходе, history clear, CSRF, rate limit по IP+email, active check на каждом защищённом запросе. Tenant responses private/no-store. Локальная админка создаёт/редактирует салоны, отделы и сайты, добавляет сотрудников, назначает роль/отдел и закрывает доступ. Удаления данных через UI нет.

Приватный Reverb-канал `organizations.{organizationId}.operators.{userId}` проверяет активность, chat.view, tenant и конкретного пользователя. Авторизация и настоящая подписка проверены браузером. На этапе Access это проверяло только подписку; реальные minimal chat notifications добавлены и проверены в Phase 3 ниже. Visitor channels, отзыв уже открытых соединений, reconnect UI и delivery — следующие фазы.

## Chat Core (Phase 3)

Новые tenant-scoped модели: Channel, Contact, VisitorSession, Conversation, Message, Attachment, ConversationRead, ChatEvent. FK составные по organization_id; уникальные ограничения защищают sequence и идемпотентность. Пока Channel.kind только website; создание канала — серверная административная операция, не публикация виджета.

Visitor API stateless: случайный 256-bit bearer credential, SHA-256 в БД, срок 30 дней, отзыв. UUID канала публичный и не авторизует историю. Bootstrap сессии требует точного Origin сайта; CORS без cookies. Запросы с credential дополнительно ограничены каналом/tenant/expiry/revocation. Имя/телефон/версия согласия обязательны до сообщений и uploads; контакт после идентификации неизменяем. Номер нормализуется в E.164-подобный формат (без проверки владения номером). Совпадающие телефоны не объединяют visitor sessions/контакты и не раскрывают историю; оператор ищет предыдущие обращения по телефону в пределах организации.

Первое сообщение под lockForUpdate visitor session создаёт ровно один диалог. Все отправки сериализуются блокировкой conversation; sequence и version растут атомарно. Ключ идемпотентности — conversation + sender principal + client_message_id, payload hash — kind/body/отсортированные attachment IDs. Права проверяются до cached result; повтор с иным payload даёт 409. Статус stored означает только подтверждённую запись MySQL. Delivery/read receipts провайдеров не имитируются.

Внутренние заметки доступны только операторскому API и его приватным событиям. Visitor history фильтрует kind=message; note attachments также недоступны. Получение истории не меняет read-marker. Прочтение — отдельный POST с монотонным last_read_sequence. История по sequence возвращается порциями 100 и next_after_sequence/has_more; клиент продвигает cursor по возвращённым сообщениям, а не сразу до head.

Вложения: JPEG/PNG/WebP/PDF, до 10 MiB, MIME по содержимому, частное local-хранилище со случайным именем, SHA-256. Связать можно только собственный upload, один раз, максимум 5 файлов на сообщение. Downloads требуют bearer или operator policy, идут как attachment с nosniff. Универсальная раздача local storage отключена. Антивирусный движок пока не подключён; нельзя утверждать, что файлы прошли антивирусную проверку.

Сообщение и ChatEvent (outbox) сохраняются в одной транзакции. После commit — best-effort enqueue; отказ enqueue не отменяет уже сохранённое сообщение. Scheduler раз в минуту подбирает pending events. Job несёт явный tenant, блокирует outbox row, публикует minimal envelope в приватные каналы активных операторов и затем помечает published_at. Публикация at-least-once: сбой между отправкой и commit может повторить event_id; потребитель дедуплицирует и догружает историю через HTTPS. При ошибке остаётся pending row с backoff. Даже при полной недоступности Redis уже принятые MySQL сообщения остаются; новые HTTP-запросы могут временно отказать из-за Redis cache/session/rate limits и требуют безопасного повтора.

E2E проверяет реальные cross-origin HTTP и Reverb через Redis worker. Это API-тест, не готовый SDK/iframe-виджет. Visitor realtime, presence/delivery/reconnect UI, распределение/принятие диалогов и отзыв открытых подписок относятся к следующим фазам.

## Inbox (Phase 4)

Рабочая Inertia-страница `/inbox` с четырьмя областями: навигация, cursor-paginated список, переписка, карточка клиента. На мобильном — переключение список/переписка и native modal dialog с карточкой, фокусом и Escape. Цветовые темы используют общие tokens; контраст акцентного текста в dark повышен. Данные берутся из реальных API; пустые состояния не подменяются демо-данными.

ConversationPolicy и visibleTo scope разделяют права: managers (Owner/Admin/Supervisor) работают по своей организации, Operator — общая очередь/свой отдел. Наблюдатель может читать доступный диалог, но не отправлять и не менять read marker. Входящие = pending без assignee; Мои = assigned user. Превью и unread count рассчитываются сервером (visitor messages после marker). Карточка ищет другие обращения по телефону только в операторской разрешённой области.

ChangeConversation блокирует агрегат, повторно проверяет актуального actor, permission и version. Accept атомарно назначает текущего оператора; assign проверяет tenant, active/role/department цели (row lock); переходы ограничены и возвращение в очередь снимает assignee. Safe state diff, actor, reason и version пишутся в append-only HTTP-журнал ConversationActivity, а control event — в outbox той же транзакции. Это журнал команд диалога, не весь будущий системный аудит.

Publisher теперь поддерживает nullable message_id для control events и проверяет текущую view policy каждого получателя. Событие — только сигнал перечитать разрешённое состояние; агрегатная версия сохранена в outbox. Изменение отдела не даёт новым сообщениям уйти в старый отдел. Уже открытые подписки будут полноценно отзываться в Phase 6; сейчас в них публикуются только минимальные IDs, а publisher заново фильтрует получателей.

React использует Echo после входа, дедуплицирует event_id, догружает новые sequence и не откатывает карточку на меньшую version. Поллинг/visibility/online recovery — резервный путь раз в 15 с. Метка «Обновления онлайн» появляется только после успешной private subscription. Browser notifications запрашиваются только кнопкой пользователя и не содержат персональных данных. UI не показывает ложное delivered/read: отметка сообщения означает stored на сервере.

Composer поддерживает обычный ответ, заметку, до 5 приватных файлов, управляемые tenant quick replies и Ctrl+Enter. Неотправленный текст/файлы хранятся в памяти текущего Inbox по conversation ID, без долговременной записи переписки в localStorage. При потере HTTP-ответа повторяет замороженный client_message_id/payload; серверная запись не дублируется. Перезагрузка страницы/выход не сохраняют этот memory draft. История открывается с последних 100 сообщений, старые страницы загружаются отдельно, live recovery идёт вперёд по sequence.

Phase 4 не реализует round-robin/load/presence/schedules, автоматическую перепривязку при отключении сотрудника, visitor WebSocket или виджет. Эти части следуют после рабочего Widget Builder/SDK и соответствующих gates.

## Widget module

Widget принадлежит одному Site/Channel с tenant composite FK; Site lock сериализует идемпотентное создание. Widget.draft + revision отделены от immutable WidgetVersion.configuration; publish под row lock проверяет ожидаемую revision, записывает новый снимок и атомарно меняет published_version. Save/publish доступны directory.manage своего tenant. Публичные GET configuration/frame читают только опубликованный снимок активного website channel; никакой visitor credential/history там нет.

SDK launcher использует Shadow DOM, переписка — отдельный iframe приложения. Session bootstrap выполняется fetch на origin сайта; iframe обменивается credential с parent посредством проверки event.origin и event.source. CSP frame-ancestors ограничивает embedding сайтом канала. Visitor API остаётся stateless bearer без cookies оператора. Shared React WidgetRuntime в preview не создаёт сетевых side effects; production frame использует реальные visitor endpoints. Phone не участвует в выборе сессии. Published department allowlist проверяется до создания Contact и переносится из visitor session в первый Conversation. Отдел не меняется через visitor identity повторно.

На этапе Phase 5 visitor history восстанавливается последовательными HTTP страницами, polling 4 с только при открытом окне. Это не visitor WebSocket. Reverb уже доставляет минимальные private события оператору. Memory draft/retry не обещают сохранение после reload; сохранённая сервером история восстанавливается browser credential.

## Realtime, receipts и распределение

Visitor bearer auth не использует operator cookie guard: `/widget/v1/channels/{uuid}/broadcasting/auth` проверяет socket_id и точное private channel name своей сессии перед стандартной подписью Reverb. Идентификатор подписки выдаёт защищённый `/realtime`; public channel UUID не авторизует subscription. Publisher проверяет expiry/revocation и active channel, отправляет visitor только event_id/schema/aggregate ID/version/public sequence. Message kind=note не публикуется посетителю. Источник содержания — защищённый HTTP history; на reconnect/subscription success клиент догружает sequence, дедуплицирует события, повторяет refresh, поступивший во время другого refresh. Fallback/revalidation — 15 секунд при открытом окне.

Receipt markers монотонны и принимают только существующий public sequence. Browser delivered ACK возникает после получения/разбора истории, read ACK — при видимом конце открытой переписки. GET и публикация Reverb не меняют receipt. Operator read marker проверяет актуальную reply policy под блокировкой. Receipt outbox не повышает command version; повтор того же ACK не порождает новый event. DTO/meta вычисляют stored/delivered/read; visitor metadata не включает sequence внутренних заметок. Это подтверждения приложения, не статусы внешних провайдеров и не доказательство внимания человека.

RoutingRule однозначен по channel + scope_key (global либо department:id); nullable department не используется как единственная unique-гарантия MySQL. Изменение правила требует revision. Runtime повторно проверяет active/role/tenant/department, явную доступность с heartbeat ≤90 секунд, расписание в timezone организации и лимит открытых диалогов. Reserve проходит те же проверки. Невозможное назначение оставляет pending. Auto-assignment + system activity (nullable actor) + outbox находятся в одной транзакции.

Для корректного least-load и capacity MVP сериализует записи сообщений/команды/auto-selection по Organization row lock, затем Conversation и детерминированно Users/OperatorStates; visitor transaction сначала блокирует собственную сессию. Это осознанное ограничение пропускной способности одного tenant; заменять его более узкой блокировкой можно после конкурентных/load tests. Manual accept/assign — явное действие оператора/менеджера и может превышать автоматическую capacity. Scheduler `aidesk:route-pending` каждые 60 с обходит зафиксированный диапазон pending по 100 записей, не застревая на первых 500.

OperatorState revision защищает смену статуса от stale heartbeat другой вкладки; logout повышает revision и сразу выключает доступность. Heartbeat не делает off-shift оператора eligible. Уже принятые open диалоги не передаются автоматически при уходе оператора: менеджер использует явную передачу. Расписание MVP — один интервал в день, без ночных смен.
