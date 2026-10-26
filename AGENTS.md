# Правила разработки AIDesk

- Работать в develop или feature с PR в develop. main — стабильная ветка. Небольшие осмысленные коммиты; не переписывать чужую историю.
- Источник требований PROJECT_SPEC.md и исходный JIVO_ANALOG_SPEC.md при доступности. Сверять IMPLEMENTATION_STATUS.md до продолжения.
- Отдельный проект; не менять CRM V2, Landflow, Jivo. Production deployment требует подтверждения пользователя. Не удалять существующие данные.
- Русский UI, light/dark, mobile. Только бесплатно лицензированные UI-компоненты; лицензии в THIRD_PARTY_NOTICES.md.
- Tenant из authenticated context. Tenant policies + query isolation + private channel authorization обязательны. Недостаточно фильтра UI.
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
- `npx playwright install chromium && npm run test:e2e` — нужны app:8000 и reverb:8080.
- Не запускать destructive migrate:fresh на существующей БД. Setup — DEPLOYMENT.md.

## Продолжение

Прочитать статус и git log, проверить текущую ветку и незакоммиченные изменения. Закончить незавершённый gate, затем следующий конкретный шаг из IMPLEMENTATION_STATUS.md. После этапа обновить факты: код, проверки, блокеры, следующий шаг и последний коммит. DONE только с результатами тестов; handshake Reverb не равен работающему чату. Полный исходный документ недоступен — зафиксировать BLOCKED сверки, но продолжать независимые работы.
