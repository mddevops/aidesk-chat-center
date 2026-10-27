# Правила разработки AIDesk

Пользователь разрешил автономно запускать необходимые локальные процессы и проверки без повторных вопросов. Production deployment и удаление существующих данных по-прежнему требуют отдельного разрешения.

- Работать в develop или feature с PR в develop. main — стабильная ветка. Небольшие осмысленные коммиты; не переписывать чужую историю.
- Источник требований PROJECT_SPEC.md и исходный JIVO_ANALOG_SPEC.md при доступности. Сверять IMPLEMENTATION_STATUS.md до продолжения.
- Отдельный проект; не менять CRM V2, Landflow, Jivo. Production deployment требует подтверждения пользователя. Не удалять существующие данные.
- Русский UI, light/dark, mobile. Только бесплатно лицензированные UI-компоненты; лицензии в THIRD_PARTY_NOTICES.md.
- Текущий пользователь закреплён за одной организацией, роли — enum Role. Organization/User без global scope нужны auth/CLI; в HTTP User всегда выбирать с organization_id из контекста. Для Showroom/Department/Site действует fail-closed global scope. Tenant из authenticated context. Tenant policies + query isolation + private channel authorization обязательны. Недостаточно фильтра UI.
- Visitor history только по защищённому browser credential; телефон не авторизует. Внутренние заметки никогда не включать в visitor stream.
- Durable messages, idempotency с проверкой payload и прав, realtime после commit, integration outbox. Никаких фальшивых подключений.
- Не коммитить .env/ключи/пароли/production payload, не печатать секреты. Не отключать TLS. Lock files обязательны.
- В managed cloud перед работой читать cloud-environment-runtime skill. Для Docker использовать предусмотренные proxy/CA mounts. BUILDX_CONFIG=/tmp/aidesk-buildx при read-only home; не менять DOCKER_CONFIG.
- Субагенты не требуются. Реализовывать последовательно, проверять каждый этап, исправлять ошибки до следующего.

## Проверки

- `npm ci && npm run build` (включает strict TypeScript).
- `docker compose exec -T app composer validate --strict`
- `docker compose exec -T app composer audit` и `npm audit --audit-level=high`
- `docker compose exec -T app vendor/bin/pint --test`
- `docker compose exec -T app vendor/bin/pest` — быстрые feature tests, infrastructure исключена.
- `docker compose exec -T app vendor/bin/pest --group=infrastructure -c phpunit.infrastructure.xml` — только локальная/CI тестовая БД, реальная MySQL/Redis/очередь.
- `npx playwright install chromium && npm run test:e2e` — нужны app:8000, reverb:8080 и E2ESeeder только в отдельной local/testing среде. При read-only home задать PLAYWRIGHT_BROWSERS_PATH=/tmp/aidesk-browsers.
- Не запускать destructive migrate:fresh на существующей БД. Setup — DEPLOYMENT.md.

## Продолжение

Прочитать статус и git log, проверить текущую ветку и незакоммиченные изменения. Закончить незавершённый gate, затем следующий конкретный шаг из IMPLEMENTATION_STATUS.md. После этапа обновить факты: код, проверки, блокеры, следующий шаг и последний коммит. DONE только с результатами тестов; handshake Reverb не равен работающему чату. Полный исходный документ недоступен — зафиксировать BLOCKED сверки, но продолжать независимые работы.

## Chat Core

- Реализованный API — API_CHAT_V1.md. Visitor endpoints stateless bearer, operator endpoints cookie + CSRF. Session bootstrap только с Origin сайта; public_id не даёт доступа к истории.
- Contact не объединяется по телефону автоматически. Credential хранится в БД только как SHA-256; bearer не помещать в URL/логи. Identity и consent обязательны на сервере.
- Сообщение/outbox создаются одной транзакцией; VisitorSession lock защищает первый диалог, Conversation lock — sequence/idempotency. Проверять права до cached result; payload conflict = 409.
- GET не меняет read-marker. Visitor API и downloads исключают notes. Upload должен принадлежать отправителю; файлы приватные. Не включать body/phone/token/path в realtime envelope или exception logs.
- После commit enqueue best-effort; pending outbox восстанавливает scheduler. PublishChatEvent получает явный tenant и очищает/восстанавливает context. At-least-once events дедуплицируются по event_id; sequence — источник восстановления истории.
- Для history >100 записей использовать meta.next_after_sequence/has_more, не прыгать сразу к meta.last_sequence.
- Инфраструктурные и E2E tests создают только синтетические данные. Для настоящей realtime проверки запустить queue, reverb и scheduler. При изменении job-кода перезапустить долгоживущие процессы.
- Inbox и ручные accept/assign/transition/department visibility реализованы в Phase 4. Не путать их с готовым SDK или автоматической маршрутизацией. Visitor realtime/presence/provider delivery ещё впереди.

## Inbox workflows

- Для списков Conversation применять visibleTo(user), для объектов/сообщений/files/activity — ConversationPolicy. Operator: общий пул/свой отдел, reply/read/transition только своего принятого диалога. Manager: свой tenant. Ни UI permissions, ни cached idempotency не заменяют серверную policy.
- Все команды accept/assign/transition требуют version и lockForUpdate. Не делать прямой update назначения из controller/UI. Сохранять activity + control outbox в той же транзакции. Target operator должен быть активным и подходить по отделу.
- Nullable chat_events.message_id обозначает control event. Не откатывать publisher на старый без отдельного плана сохранности outbox.
- Echo получает только минимальные envelope; проверять view policy каждого получателя. UI fetches проверяются повторно. Наблюдение не меняет marker; mark read только видимого конца переписки.
- Сетевой retry сообщения использует прежний client_message_id и payload. Не создавать новый ID автоматически после неопределённого HTTP исхода. Memory drafts не считать сохранением после reload.
- Browser notifications только после клика; не включать PII в OS notification. Читать статус realtime только после private subscription success.
- E2E Inbox проверяет реальный UI desktop/mobile, retry после commit/потери ответа, notes/files/quick replies, передачу, ответ назначенного оператора и закрытие/возврат. Не заменять этот gate API-only тестом.

## Widgets

- Настройки `/settings/messenger`, API `/api/v1/widgets`, runtime `/widget/v1/channels/{uuid}/frame`, SDK `/sdk/aidesk.js`. Только directory.manage своего tenant меняет настройки.
- Widget draft/revision и immutable WidgetVersion разделены. Publish/save только с ожидаемой revision и row lock. Не читать draft публично; не менять снимки или обнулять счётчик версий.
- Preview shared runtime с preview=true не должен отправлять ни session, ни identify/message/read/revoke/upload. Проверять браузерные requests, а не только текст «предпросмотр».
- postMessage только с точным origin/source/targetOrigin; bearer не попадёт в iframe URL, публичный config, лог или cookie приложения. SDK session bootstrap остаётся запросом сайта; не разрешать app Origin для произвольного bootstrap вместо сайта.
- Отделы доступны только из published tenant allowlist; выбранный отдел фиксируется в visitor session до первого Conversation. Не расширять fillable Conversation для обхода command policies.
- `tests/e2e/widget.spec.ts` проверяет настоящий one-script SDK на двух внешних origin, UI builder/publish, visitor identity, Inbox reply, reload/history и изоляцию. HTTP polling в Phase 5 не считать реализованным visitor Reverb.
