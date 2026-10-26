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
- Первый CI run 40383945611 остановился: tests/Unit был пуст и не попал в Git. Удалена ссылка на пустой suite; PHPUnit env/server overrides принудительно изолируют feature tests от Compose env. Исправлено в `d0cbf92`; повторный CI run 40385249255 SUCCESS (включая MySQL/Redis и Playwright). Обновление Laravel 13 и Phase 2 требуют нового CI.
- Chat, auth/tenant isolation, Inbox, widget/SDK, routing и CRM ещё не реализованы. Handshake не доказывает защищённые подписки или обмен сообщениями.
- BLOCKED повторная сверка JIVO_ANALOG_SPEC.md: исходное вложение исчезло после обновления среды. Требования ранее прочитаны и основные положения перенесены в PROJECT_SPEC.md; полная копия не восстановлена.
- Production deployment не выполнялся. Внешних интеграций нет.

Следующий конкретный шаг: Phase 2 — login/logout, организации/салоны/сайты/отделы/роли, fail-closed tenant context и policies, отрицательные HTTP/query/realtime-тесты, затем русскоязычные административные экраны.

Последний завершённый этап: Foundation `9240b71`. Исправление конфигурации проверок — коммит, содержащий эту запись; получить `git log -1 --oneline`. Текущие зависимости обновлены до актуального Laravel 13.38.0/Pest 4.7.8; PHP platform check PASS на 8.3.36. Tinker удалён как неиспользуемый и несовместимый с Laravel 13. Phase 2 сейчас в рабочем дереве; приёмка завершается.
