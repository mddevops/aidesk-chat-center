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

## Phase 2 — Organizations & Access: реализована, локальные проверки PASS

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
- Первый удалённый CI Foundation падал из-за отсутствующего пустого tests/Unit; исправлен `d0cbf92`, run 40385249255 SUCCESS. CI нового кода проверяется после push.

Не реализовано: пользовательский редактор ролей, multi-org membership, password reset/invites/MFA, audit UI, чат/Inbox/SDK/виджеты/маршрутизация/CRM. Текущие роли и отделы не означают готовые права на будущие диалоги. Бизнес-события по WebSocket ещё не передаются; отзыв активных соединений и visitor subscriptions впереди. Данные через UI не удаляются, production deployment не выполнялся.

BLOCKED: повторная полная сверка исходного JIVO_ANALOG_SPEC.md — вложение недоступно после обновления среды. Требования пользователя и ранее прочитанный документ учтены; полная копия не выдумана. Реальные внешние каналы/CRM требуют отдельных тестовых доступов и контрактов.

Следующий конкретный шаг: Phase 3 — создать tenant-scoped Contacts/VisitorSessions/Conversations/Messages; нормализацию телефона и browser credentials; серверный запрет сообщений до идентификации, sequence и идемпотентность с payload conflict. Сначала отрицательные tests (same phone ≠ history, tenant isolation, internal note exclusion), затем API и durable message flow. К Inbox переходить после этого gate.

Последние завершённые коммиты: Foundation `9240b71`, test isolation `d0cbf92`, актуальные Laravel/Pest `61a5ec2`. Phase 2 — коммит, содержащий этот статус; точный SHA: `git log -1 --oneline`.
