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
- Phase 4 не должна считать API-готовность готовым Inbox/SDK. Visitor realtime, routing/accept/transition, department visibility, presence и подтверждения доставки пока не реализованы.
