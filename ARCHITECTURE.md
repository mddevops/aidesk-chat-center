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

Приватный Reverb-канал `organizations.{organizationId}.operators.{userId}` проверяет активность, chat.view, tenant и конкретного пользователя. Авторизация и настоящая подписка проверены браузером. Доменные chat events пока не отправляются; visitor channels, отзыв уже открытых соединений, reconnect и delivery — следующие фазы. Не отправлять бизнес-данные до реализации их gates.

## Chat Core (Phase 3)

Новые tenant-scoped модели: Channel, Contact, VisitorSession, Conversation, Message, Attachment, ConversationRead, ChatEvent. FK составные по organization_id; уникальные ограничения защищают sequence и идемпотентность. Пока Channel.kind только website; создание канала — серверная административная операция, не публикация виджета.

Visitor API stateless: случайный 256-bit bearer credential, SHA-256 в БД, срок 30 дней, отзыв. UUID канала публичный и не авторизует историю. Bootstrap сессии требует точного Origin сайта; CORS без cookies. Запросы с credential дополнительно ограничены каналом/tenant/expiry/revocation. Имя/телефон/версия согласия обязательны до сообщений и uploads; контакт после идентификации неизменяем. Номер нормализуется в E.164-подобный формат (без проверки владения номером). Совпадающие телефоны не объединяют visitor sessions/контакты и не раскрывают историю; оператор ищет предыдущие обращения по телефону в пределах организации.

Первое сообщение под lockForUpdate visitor session создаёт ровно один диалог. Все отправки сериализуются блокировкой conversation; sequence и version растут атомарно. Ключ идемпотентности — conversation + sender principal + client_message_id, payload hash — kind/body/отсортированные attachment IDs. Права проверяются до cached result; повтор с иным payload даёт 409. Статус stored означает только подтверждённую запись MySQL. Delivery/read receipts провайдеров не имитируются.

Внутренние заметки доступны только операторскому API и его приватным событиям. Visitor history фильтрует kind=message; note attachments также недоступны. Получение истории не меняет read-marker. Прочтение — отдельный POST с монотонным last_read_sequence. История по sequence возвращается порциями 100 и next_after_sequence/has_more; клиент продвигает cursor по возвращённым сообщениям, а не сразу до head.

Вложения: JPEG/PNG/WebP/PDF, до 10 MiB, MIME по содержимому, частное local-хранилище со случайным именем, SHA-256. Связать можно только собственный upload, один раз, максимум 5 файлов на сообщение. Downloads требуют bearer или operator policy, идут как attachment с nosniff. Универсальная раздача local storage отключена. Антивирусный движок пока не подключён; нельзя утверждать, что файлы прошли антивирусную проверку.

Сообщение и ChatEvent (outbox) сохраняются в одной транзакции. После commit — best-effort enqueue; отказ enqueue не отменяет уже сохранённое сообщение. Scheduler раз в минуту подбирает pending events. Job несёт явный tenant, блокирует outbox row, публикует minimal envelope в приватные каналы активных операторов и затем помечает published_at. Публикация at-least-once: сбой между отправкой и commit может повторить event_id; потребитель дедуплицирует и догружает историю через HTTPS. При ошибке остаётся pending row с backoff. Даже при полной недоступности Redis уже принятые MySQL сообщения остаются; новые HTTP-запросы могут временно отказать из-за Redis cache/session/rate limits и требуют безопасного повтора.

E2E проверяет реальные cross-origin HTTP и Reverb через Redis worker. Это API-тест, не готовый SDK/iframe-виджет. Visitor realtime, presence/delivery/reconnect UI, распределение/принятие диалогов и отзыв открытых подписок относятся к следующим фазам.
