# Фактический статус — 26.10.2026

## Phase 1 — Foundation: реализована и локально проверена

Код: Laravel 12.70.1 / PHP 8.3, React 19/Inertia 2/strict TS, Tailwind 4, локальный shadcn button и бесплатный ReUI badge. Русская адаптивная стартовая страница с сохранением light/dark. Это не Inbox и не работающий чат.

Инфраструктура: воспроизводимый PHP Dockerfile, Compose app/MySQL 8/Redis/queue/Reverb, миграции Laravel, Redis session/cache/queue, Echo factory, allowlist Reverb origins, lock files и GitHub Actions. Локальные секреты только в игнорируемом .env.

Проверено:
- Сборка PHP 8.3, запуск всех пяти сервисов; миграции на MySQL 8.
- `npm run build`: TypeScript и Vite PASS.
- Pest feature: 2 PASS, 15 assertions.
- Pest infrastructure: 2 PASS, 8 assertions; MySQL persistence и реальная обработка Redis queue job с проверкой результата через cache.
- Playwright desktop/mobile: 4 PASS; русский UI, сохранение темы, ширина viewport и настоящий WebSocket handshake Reverb.
- Composer validate --strict PASS; composer audit без advisories; npm audit — 0 vulnerabilities.
- Pint исправил 3 замечания; финальный --test выполняется перед коммитом.

Ограничения:
- CI workflow создан; удалённый запуск проверяется после push. Локальные PASS не означают удалённый CI PASS.
- Chat, auth/tenant isolation, Inbox, widget/SDK, routing и CRM ещё не реализованы. Handshake не доказывает защищённые подписки или обмен сообщениями.
- BLOCKED повторная сверка JIVO_ANALOG_SPEC.md: исходное вложение исчезло после обновления среды. Требования ранее прочитаны и основные положения перенесены в PROJECT_SPEC.md; полная копия не восстановлена.
- Production deployment не выполнялся. Внешних интеграций нет.

Следующий конкретный шаг: Phase 2 — login/logout, организации/салоны/сайты/отделы/роли, fail-closed tenant context и policies, отрицательные HTTP/query/realtime-тесты, затем русскоязычные административные экраны.

Последний коммит до Foundation: `b9f0c54`. Коммит Foundation — коммит, содержащий этот статус (самоссылочный SHA не записывается); получить `git log -1 --oneline`.
