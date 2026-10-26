# Фактический статус — 26.10.2026

## Phase 1 — Foundation: реализована и локально проверена

Код: Laravel 13.38.0 / PHP 8.3 (обновлено с начальной Foundation на Laravel 12), React 19/Inertia 2/strict TS, Tailwind 4, локальный shadcn button и бесплатный ReUI badge. Русская адаптивная стартовая страница с сохранением light/dark. Это не Inbox и не работающий чат.

Инфраструктура: воспроизводимый PHP Dockerfile, Compose app/MySQL 8/Redis/queue/Reverb, миграции Laravel, Redis session/cache/queue, Echo factory, allowlist Reverb origins, lock files и GitHub Actions. Локальные секреты только в игнорируемом .env.

Проверено:
- Сборка PHP 8.3, запуск всех пяти сервисов; миграции на MySQL 8.
- `npm run build`: TypeScript и Vite PASS.
- Pest feature: 2 PASS, 15 assertions.
- Pest infrastructure: 2 PASS, 8 assertions; MySQL persistence и реальная обработка Redis queue job с проверкой результата через cache.
- Playwright desktop/mobile: 4 PASS; русский UI, сохранение темы, ширина viewport и настоящий WebSocket handshake Reverb.
- Composer validate --strict PASS; composer audit без advisories; npm audit — 0 vulnerabilities.
- Pint --test PASS (32 файла на этапе Foundation).

Ограничения:
- Первый CI run 40383945611 остановился: tests/Unit был пуст и не попал в Git. Удалена ссылка на пустой suite; PHPUnit env/server overrides принудительно изолируют feature tests от Compose env. Исправлено в `d0cbf92`; повторный CI run 40385249255 SUCCESS (включая MySQL/Redis и Playwright). Laravel 13 и Phase 2 также прошли новый CI run 40386939941.
- На момент Foundation auth/tenant isolation ещё отсутствовали; они реализованы в Phase 2 ниже. Chat, Inbox, widget/SDK, routing и CRM пока отсутствуют. Handshake сам по себе не доказывает обмен сообщениями.
- BLOCKED повторная сверка JIVO_ANALOG_SPEC.md: исходное вложение исчезло после обновления среды. Требования ранее прочитаны и основные положения перенесены в PROJECT_SPEC.md; полная копия не восстановлена.
- Production deployment не выполнялся. Внешних интеграций нет.

## Phase 2 — Organizations & Access: реализована, локальные проверки и GitHub Actions PASS

Код:
- Организации/автосалоны/сайты/отделы и связи сотрудников; составные FK tenant.
- Login/logout с CSRF, rate limiting, session regeneration и отключением аккаунта. Публичной регистрации нет; CLI `aidesk:organization` создаёт владельца со скрытым вводом пароля.
- Фиксированные роли Owner/Admin/Supervisor/Operator/Analyst и permissions. Запрет повышения привилегий, изменения владельца/собственной роли.
- Scoped TenantContext, fail-closed query scope, проверка writes, tenant-aware route binding и policies.
- Рабочая русская админка: создание/редактирование салонов, отделов, сайтов; создание сотрудников, назначение роли/отдела, отключение доступа. Light/dark/mobile; безопасные сообщения validation/error pages.
- Авторизация приватного Reverb-канала конкретного оператора своей организации; Echo CSRF из актуального XSRF cookie.

Проверки после обновления на Laravel 13.38.0/Pest 4.7.8:
- 14 Pest feature PASS, 128 assertions: login, active/tenant restrictions, query/write/policy/IDOR, role escalation, связи, настройки, CLI, private channel auth.
- 3 Pest infrastructure PASS, 9 assertions: MySQL 8 persistence, реальная Redis queue processing, cross-tenant FK rejection.
- TypeScript/Vite build PASS, Pint --test PASS (49 файлов), Composer validate и PHP 8.3 platform requirements PASS. Composer audit без advisories; npm audit 0 vulnerabilities.
- Playwright после обновления Laravel 13: 8 desktop/mobile PASS (16.3 с), включая реальные CRUD/login, CSRF 419, private subscription success и foreign tenant 403.
- Первый удалённый CI Foundation падал из-за отсутствующего пустого tests/Unit; исправлен `d0cbf92`, run 40385249255 SUCCESS. CI итогового код-коммита `845f5e4` — run 40386939941 SUCCESS: установка lock files, audits, build, Pint, MySQL migrations, Pest, E2E fixtures и все Playwright tests.
  https://github.com/mddevops/aidesk-chat-center/actions/runs/40386939941

На конец Phase 2 не было реализовано: пользовательский редактор ролей, multi-org membership, password reset/invites/MFA, audit UI, чат/Inbox/SDK/виджеты/маршрутизация/CRM. Текущие роли и отделы не означают готовые права на будущие диалоги. Бизнес-события по WebSocket ещё не передаются; отзыв активных соединений и visitor subscriptions впереди. Данные через UI не удаляются, production deployment не выполнялся.

BLOCKED: повторная полная сверка исходного JIVO_ANALOG_SPEC.md — вложение недоступно после обновления среды. Требования пользователя и ранее прочитанный документ учтены; полная копия не выдумана. Реальные внешние каналы/CRM требуют отдельных тестовых доступов и контрактов.

## Phase 3 — Chat Core: backend реализован, локальные gates и GitHub Actions PASS

Реализовано:
- Tenant-scoped Channel/Contact/VisitorSession/Conversation/Message/Attachment/ConversationRead/ChatEvent и составные FK MySQL.
- Website channel provisioning API без имитации внешних подключений. Публичный UUID канала не авторизует историю.
- Stateless visitor API с 256-bit credential (только SHA-256 в БД), TTL/отзывом, CORS/origin, именем/нормализованным телефоном/согласием до отправки. Одинаковый телефон не объединяет историю разных сессий.
- Operator API v1: список/фильтры/поиск по имени и телефону, карточка, сообщения/заметки, явные read markers, uploads/downloads; серверные policies и tenant binding.
- Атомарное создание первого диалога, sequence/version, scoped client_message_id и payload conflict 409. GET не меняет прочтение; восстановление истории пакетами 100 с явным cursor.
- Приватные JPEG/PNG/WebP/PDF до 10 MiB, MIME по содержимому, upload ownership, одноразовая привязка, защищённое скачивание с nosniff. Note attachments не видны посетителю.
- Message + durable outbox в одной транзакции, best-effort enqueue после commit, scheduler recovery/backoff, scoped queue jobs, at-least-once private Reverb notifications с минимальным envelope без текста/телефона/секретов.
- Request IDs и стабильные JSON error codes без stacktrace; безопасный logging исключений Chat API.
- Scheduler в Compose, upload limits PHP/CI и исправление внутреннего PHP → Reverb HTTP-доступа через NO_PROXY для собственных сервисов.

Проверки:
- `npm run build` PASS; Vite 5.69 с, strict TS PASS, `git diff --check` PASS. Длительная задержка группового вызова не воспроизвелась в отдельной сборке; активных зависших процессов не найдено.
- Pest feature: 27 PASS, 256 assertions (включая 13 новых Chat Core tests).
- Pest infrastructure на MySQL 8/Redis: 5 PASS, 22 assertions. Проверены queue outage с сохранёнными message/outbox; 4 параллельных повтора дают одну запись, 4 разных параллельных отправки получают sequence 2–5.
- Полный Playwright: 10 PASS (desktop/mobile), включая реальные cross-origin HTTP, private Reverb уведомление через Redis worker, ответ оператора, недоступность заметки, восстановление history и изоляцию двух сайтов/сессий.
- Отдельный параллельный desktop/mobile прогон нового сценария: 2 PASS с двумя workers. Реальная загрузка PDF через PHP и скачивание visitor также проверены: финальный прогон 2 PASS с двумя workers (12.9 с).
- Pint --test PASS (78 файлов), Composer validate --strict PASS. Зависимости не менялись; lock files сохранены.
- GitHub Actions для код-коммита `8e7d751`: run 40406995045 SUCCESS, включая composer/npm audits, build, миграции, 32 Pest-теста и 10 Playwright-тестов.
  https://github.com/mddevops/aidesk-chat-center/actions/runs/40406995045

Ограничения: это backend/API, не готовый Inbox или установленный виджет. SDK/iframe builder/preview/publish отсутствуют; visitor realtime auth, presence, routing/accept/assignment/status transitions, scoped department visibility и provider delivery receipts ещё не реализованы. stored = подтверждение записи MySQL, не прочтение/доставка внешним провайдером. Повторы event_id допустимы; HTTPS history — источник истины. Антивирус и юридический текст согласия требуют отдельной эксплуатационной подготовки. Внешние каналы/CRM не подключены. Production deployment и удаление существующих данных не выполнялись.

BLOCKED: исходный JIVO_ANALOG_SPEC.md по-прежнему недоступен для повторной полной сверки. Реальные внешние API/CRM требуют отдельных контрактов и тестовых доступов; это не блокирует Inbox.

## Phase 4 — Inbox: реализован, локальные gates PASS; удалённый CI ожидается

Реализовано:
- Защищённый `/inbox`: sidebar, входящие/мои/все, поиск по имени/телефону, status/channel/department filters, cursor pagination и серверные unread counts.
- Центральная переписка: последние 100 сообщений, загрузка ранних страниц, догрузка sequence, обычные ответы/внутренние заметки, файлы, авторы и truthful stored marker.
- Composer с Ctrl+Enter, private uploads, управляемыми быстрыми ответами и memory drafts по диалогу. После неопределённого HTTP исхода повторяет прежний client_message_id/payload.
- Карточка клиента с контактом, ответственным, отделом, поиском прошлых обращений, приёмом/передачей/закрытием/возвратом и журналом команд.
- Атомарные серверные accept/assign/transition с version/lock/policy, проверкой active/role/department назначения. Activity и control outbox фиксируются вместе с изменением.
- Operator visibility: общий пул и свой отдел; observer не отправляет и не меняет read marker. Managers работают по своему tenant. Realtime publisher фильтрует получателей актуальной policy.
- Echo private subscription, event_id dedup, version guard, reconnect/focus/online/polling recovery, in-app alerts и opt-in browser notifications без PII. Online label только после private subscription success.
- Light/dark и mobile: переключение списка/переписки, native dialog карточки с focus/Escape, safe area и повышенный контраст dark accent.
- Quotas чтения/записи разделены (600 GET/120 mutations в минуту), чтобы polling не блокировал команды; Retry-After сохранён в JSON errors.

Проверено:
- Миграция на существующей локальной MySQL 8 PASS; queue/scheduler/reverb перезапущены. Существующие данные не удалялись.
- `npm run build` PASS (strict TypeScript, Vite 5.59 с); `git diff --check` PASS; Composer validate --strict PASS. Новых зависимостей нет, lock files не менялись.
- Pest feature: 36 PASS, 326 assertions. Policies/tenant/department, unread и observers, версии/переходы/назначения, active/mismatched/foreign targets, tenant quick replies, history pagination, realtime recipients, page auth/metadata.
- Pest infrastructure: 6 PASS, 25 assertions на MySQL/Redis. Два параллельных оператора: один accepted, другой version_conflict, одна activity; прежние durability/idempotency/sequence tests PASS.
- Полный Playwright desktop/mobile: 12 PASS с 2 workers (1.0 мин). Новый Inbox сценарий проверяет реальный UI: live visitor message, приём, ответ с PDF, быстрый ответ, note privacy, потерю HTTP-ответа после commit и retry без дубля, передачу, ответ назначенного оператора, закрытие/возврат, темы/ширину viewport.
- Скриншоты desktop light/dark и mobile просмотрены. Исправлен найденный 429 при общем лимите чтения/записи; финальный полный прогон PASS. Native OS notification popup не автоматизирован; UI permission opt-in реализован.
- Pint --test PASS (87 файлов). Удалённый CI будет проверен после push.

Что ещё не работает/не реализовано:
- Устанавливаемого iframe-виджета/SDK и Widget Builder пока нет. Тестовые visitor запросы не выдаются за установленный продуктовый виджет.
- Round-robin/least-load/fallback, графики/presence, авто-переназначение при отключении/смене отдела и visitor realtime — Phase 6. Ручное назначение работает, но не считается автоматической маршрутизацией.
- Memory drafts не переживают reload/logout; надёжно сохранённые сообщения восстанавливаются через API.
- Global audit UI, custom roles, MFA/reset/invites, антивирус, load/restore acceptance и реальные CRM/внешние каналы остаются впереди. Production deployment не выполнялся.
- BLOCKED повторная полная сверка JIVO_ANALOG_SPEC.md: исходное вложение недоступно. Это не блокирует следующий этап по зафиксированным требованиям.

Следующий конкретный шаг: Phase 5 — отдельная версия виджета для каждого сайта: draft/published/version history/optimistic publish, безопасный preview без session/chat/lead, iframe SDK с установкой одной строкой и реальным visitor → Inbox → visitor обменом на двух внешних сайтах. Начать с модели Widget/WidgetVersion и публичного published config; затем builder и изолированный runtime.

Последние код-коммиты: Foundation `9240b71`, Access `845f5e4`, Chat domain/outbox `93f103b`, Chat API `8e7d751`, Inbox workflows `a515633`. UI Inbox и текущий статус — следующий коммит; точный последний SHA: `git log -1 --oneline`.
