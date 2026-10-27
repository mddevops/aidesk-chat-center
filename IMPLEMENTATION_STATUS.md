# Фактический статус — 27.10.2026

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

## Phase 4 — Inbox: реализован, локальные gates и GitHub Actions PASS

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
- Pint --test PASS (87 файлов). GitHub Actions для `4595baf`: run 40413980846 SUCCESS (audits, build, migrations, 42 Pest и 12 Playwright).
  https://github.com/mddevops/aidesk-chat-center/actions/runs/40413980846

Что ещё не работает/не реализовано:
- Устанавливаемого iframe-виджета/SDK и Widget Builder пока нет. Тестовые visitor запросы не выдаются за установленный продуктовый виджет.
- Round-robin/least-load/fallback, графики/presence, авто-переназначение при отключении/смене отдела и visitor realtime — Phase 6. Ручное назначение работает, но не считается автоматической маршрутизацией.
- Memory drafts не переживают reload/logout; надёжно сохранённые сообщения восстанавливаются через API.
- Global audit UI, custom roles, MFA/reset/invites, антивирус, load/restore acceptance и реальные CRM/внешние каналы остаются впереди. Production deployment не выполнялся.
- BLOCKED повторная полная сверка JIVO_ANALOG_SPEC.md: исходное вложение недоступно. Это не блокирует следующий этап по зафиксированным требованиям.

Следующий конкретный шаг: Phase 5 — отдельная версия виджета для каждого сайта: draft/published/version history/optimistic publish, безопасный preview без session/chat/lead, iframe SDK с установкой одной строкой и реальным visitor → Inbox → visitor обменом на двух внешних сайтах. Начать с модели Widget/WidgetVersion и публичного published config; затем builder и изолированный runtime.

Последние код-коммиты: Foundation `9240b71`, Access `845f5e4`, Chat domain/outbox `93f103b`, Chat API `8e7d751`, Inbox workflows `a515633`. UI Inbox и E2E `4595baf`. Последний status-коммит содержит эту запись; точный SHA: `git log -1 --oneline`.

## Phase 5 — Widget Builder и устанавливаемый чат: локальные gates и CI PASS (27.10.2026)

Реализовано:
- `/settings/messenger`: отдельный Widget для каждого сайта, draft/revision, сохранение без изменения сайта, optimistic publish и immutable WidgetVersion; восстановление прежней публикации в черновик. Latest 100 versions в UI/API.
- Tenant query scope, WidgetPolicy, composite FK site/channel/author, Site/Widget row locks, 409 при конфликте ревизий. Публичные config/frame получают только published snapshot активного канала.
- Имя/HTTPS logo, цвет, light/dark/auto, системные font stacks, размеры/позиция/отступы/скругление/тень, анимация, avatar, greeting/start buttons, мобильный fullscreen, ручной online/offline mode, обязательные имя/телефон/consent, HTTPS privacy link и allowlist отделов.
- Department validation до Contact, выбранный отдел VisitorSession → первый Conversation; чужой/неопубликованный выбор отклоняется. Обязательные поля и server phone normalization сохраняются.
- Live preview shared runtime без visitor запросов/сессий/контактов/чатов; адаптивный редактор и код установки одним script.
- `/sdk/aidesk.js`: launcher Shadow DOM, переписка iframe, CSP frame-ancestors сайта, Origin/source/targetOrigin-checked postMessage, no token in URL. Credential хранится отдельно по origin приложения/каналу в localStorage сайта; bootstrap только после клика.
- Реальные identify/send/history/read/revoke/upload/download, stored marker, same-ID/payload retry при неопределённом HTTP исходе, восстановление после reload по credential. HTTP sequence polling каждые 4 с при открытом окне; не выдаётся за visitor Reverb.
- APP_URL примера/CI исправлен на канонический local origin http://127.0.0.1:8000: прежний localhost вызывал origin_forbidden для POST из iframe. Без ослабления origin policy.

Проверено:
- Обе миграции на существующей локальной MySQL PASS; процессы восстановлены/пересозданы без удаления данных.
- Strict TypeScript/Vite PASS (последняя сборка 5.05 с), node --check SDK PASS, git diff --check PASS. Новых зависимостей нет, lock files не менялись.
- Pest feature 39 PASS, 376 assertions; infrastructure 6 PASS, 25 assertions. Новые проверки publications/drafts/conflicts, safe configuration, tenant/role/origin, no session/contact side effects, department routing и immutable snapshots. После добавления WidgetPolicy отдельные 3 Widget tests PASS (50 assertions).
- Полный Playwright desktop/mobile 14 PASS с 2 workers (57.4 с). После усиления widget test — 2 PASS (20.0 с): реальный one-script SDK на внешних origin, builder save/publish, preview без visitor requests, обязательная identity, visitor → Inbox → visitor, PDF upload, abort HTTP после commit + retry без дубля, reload/history и изоляция двух сайтов с тем же телефоном. Проверено совпадение mobile iframe с viewport, dark mobile screenshot просмотрен.
- Pint --test PASS (96 файлов); Composer validate --strict PASS. GitHub Actions для `6a6360b`: run 40472249201 SUCCESS — audits, build, migrations, 45 Pest и 14 Playwright. https://github.com/mddevops/aidesk-chat-center/actions/runs/40472249201

Ограничения/что ещё не работает:
- Visitor Reverb/private subscriptions/presence и автоматические round-robin/least-load/fallback/расписания — Phase 6. Сейчас сообщения посетителю приходят polling, оператору — существующий Reverb.
- Несохранённый composer/retry payload хранится в памяти; reload может потерять неотправленный текст. Если storage сайта заблокирован, credential живёт до reload. Телефон по-прежнему не авторизует историю.
- Закрытый оператором диалог доступен для чтения; повторное обращение после закрытия требует новой сессии либо возврата диалога оператором. Автоматическое переоткрытие не заявлено.
- Согласие contact-v1 — техническая версия; юридический текст должен утвердить владелец организации. Антивирус, custom roles/MFA/reset/invites/global audit UI, load/restore acceptance, CRM и внешние каналы ещё впереди.
- BLOCKED повторная полная сверка исходного JIVO_ANALOG_SPEC.md: вложение недоступно; требования не выдуманы. Production deployment не выполнялся.

Последние код-коммиты: `61f1bd1` (Widget domain/publish/access/departments), `6a6360b` (builder/runtime/SDK/E2E/CI origin). Последний status-коммит: см. `git log -1 --oneline`.

Следующий конкретный шаг: Phase 6 — visitor private Reverb auth с browser credential, минимальные события без PII и sequence recovery; затем атомарные routing rules round-robin/least-load/fallback с tenant/department/active/availability checks и конкурентными MySQL tests. Сохранить HTTP recovery fallback, не заменять проверенный SDK макетом.

## Phase 6 — Realtime, receipts и routing MVP: локальные gates и CI PASS (27.10.2026)

Реализовано:
- Visitor private Reverb: отдельные bearer metadata/auth endpoints, точное имя своей session, socket validation и отсутствие operator cookie guard. Publisher исключает notes, revoked/expired session и inactive channel; envelope без body/phone/attachments.
- SDK Echo subscription success, event dedup, sequence recovery на reconnect, очередь refresh при входящем событии во время другого refresh, HTTP fallback/revalidation 15 с.
- Delivery/read ACK: server persisted monotonic markers, stored/delivered/read в history/meta и UI. GET/WS publication не означают доставку; read при видимом конце, включая явную прокрутку. Receipt outbox не повышает command version, повтор ACK не создаёт событие, visitor metadata не показывает private note sequence.
- `/settings/routing`: versioned channel/department rules, round-robin/least-load, основной pool/reserve, capacity, weekly day schedule в timezone организации. Own availability в Inbox: available/away/offline, heartbeat 30 с / TTL 90 с, revision против stale tab, logout сразу offline.
- Auto-selection при первом сообщении, повтор pending scheduler раз в минуту пакетами 100 в зафиксированном диапазоне; неподходящая команда оставляет сообщение/диалог в pending. Active/role/tenant/department/schedule/lease/capacity проверяются повторно, reserve проходит те же проверки.
- Organization coordination lock + единый порядок Conversation/Users/State для устранения гонок capacity/least-load. Assignment/system activity/outbox атомарны. Nullable system actor отображается «Система».
- Отдельные shared test fixtures для запуска выбранных Pest файлов без зависимости от загрузки ChatCoreTest.php.

Проверено к моменту записи:
- Миграции 000003/000004 на существующей локальной MySQL PASS; данные не удалялись, queue/scheduler/reverb перезапущены.
- Pest feature 47 PASS (493 assertions); infrastructure 7 PASS (29 assertions), включая 4 одновременных посетителя, 2 оператора capacity=1: два разных назначения и два pending. Отдельный финальный Routing/Receipt прогон 6 PASS (91 assertions).
- Build strict TS/Vite PASS (4.98 с после финальной правки scroll/read), Pint --test PASS (110 файлов), Composer validate --strict PASS, git diff --check PASS. Новых зависимостей нет.
- До receipts полный новый widget/routing прогон 4 PASS (43.3 с): реальные visitor private WS frames, reconnect c удержанием нового сокета и ответом в разрыве, UI routing/availability/capacity desktop/mobile.
- После receipts полный browser suite первоначально 15/16 PASS: desktop read test ожидал read у сообщения ниже видимой области. Исправлены layout scroll и explicit read при прокрутке, тест явно показывает ответ. Финальный полный Playwright: 16/16 PASS с 2 workers (1.6 мин), включая реальный private WS, ответ в разрыве соединения/reconnect recovery, read receipts, routing UI, capacity, старые access/core/Inbox scenarios. Gate закрыт.
- Первый GitHub Actions Phase 6 run 40481656610: build/migrations/audits/Pest PASS, Playwright 14/16. Артефакты подтвердили POST /login=429 в двух mobile tests: прежний общий 20/min IP quota блокировал разные аккаунты за одним NAT. Исправлен двухуровневый login limiter: 20/account+IP и 60/IP, существующий failed-password=5 сохраняется. Добавлены 3 regression tests, итоговый feature suite 50 PASS (741 assertions). Исправление `695e476`: GitHub Actions run 40490297394 SUCCESS — 50 feature PASS (741 assertions), 7 infrastructure PASS (29 assertions), 16 Playwright PASS (33.1 с), без flaky/retry; audits/build/Pint/migrations PASS. https://github.com/mddevops/aidesk-chat-center/actions/runs/40490297394. Локальный повтор браузера после перезапуска среды остановился до тестов из-за отсутствующего Chromium; браузер восстановлен, соответствующий полный gate подтверждён CI.

Ограничения:
- MVP сериализует chat writes одного tenant; высокую пропускную способность/нагрузочную готовность не заявляем до Phase 8 load tests.
- Manual accept/assign — явный override auto capacity. Уже открытые диалоги при offline/деактивации автоматически не передаются; менеджер использует ручную передачу. Расписание: один интервал в день, ночные смены не поддержаны.
- Browser receipts подтверждают состояние клиента приложения, не внешнего провайдера и не внимание человека. При отказе WS остаётся HTTP recovery, полный отказ Redis может временно блокировать новые HTTP запросы; сохранённая MySQL история остаётся источником истины.
- CRM V2/внешние каналы не подключены. BLOCKED реальная передача в CRM: нет согласованного endpoint/auth/test account. BLOCKED повторная полная сверка исходного JIVO_ANALOG_SPEC.md: вложение недоступно. Production deployment не выполнялся.

Код-коммиты: `2b2bb27` backend, `2ecd21c` UI/runtime/E2E, `695e476` login quota fix + regression tests. Последний status-коммит: `git log -1 --oneline`; эта запись сохраняется после подтверждённого CI.
Следующий конкретный шаг: Phase 7 — версионированный контракт интеграции/адаптеры и интеграционный outbox без изменения CRM и без имитации подключённого канала.
