# План

1. Foundation: Laravel/React/Inertia/TS/Tailwind/UI, MySQL/Redis/Reverb, lock files, Pest/Playwright, CI, runbook. DONE; локальные gates и удалённый CI Foundation PASS, актуализировано до Laravel 13.
2. Organizations & Access: реализованы организация/салон/сайт/отдел, пользователи, фиксированные роли/permissions, auth, tenant policies и админка. Локальные HTTP/query/FK/private subscription gates PASS; удалённый CI run 40386939941 PASS. Custom roles/multi-org membership не входят в этот инкремент.
3. Chat Core: реализованы контакты, защищённые visitor sessions, сообщения, идемпотентность, outbox, приватные вложения, operator/visitor API и проверенный Redis → Reverb operator notification. Локальные gates и CI run 40406995045 PASS; подробности — IMPLEMENTATION_STATUS.md.
4. Inbox: реализован рабочий UI desktop/mobile с реальными API, приёмом/назначением/статусами, заметками, файлами, быстрыми ответами, фильтрами, карточкой, unread и Echo recovery. Локальные gates и CI run 40413980846 PASS; подробности — IMPLEMENTATION_STATUS.md.
5. Widget Builder: реализованы draft/publish/versioning/preview, iframe SDK, обязательная identity/consent/отделы, реальный обмен посетитель ↔ Inbox, файлы, безопасный retry и browser history. Локальные gates и CI run 40472249201 PASS; подробности — IMPLEMENTATION_STATUS.md. Visitor пока использует HTTP polling.
6. Realtime & Routing MVP: реализованы visitor private Reverb/recovery, browser delivery/read ACK, round-robin/least-load/reserve, capacity, lease availability и расписания, UI и MySQL concurrency tests. Полный gate PASS: 57 Pest, 16 Playwright, CI run 40490297394; подробности — IMPLEMENTATION_STATUS.md. Нагрузочная готовность и автопередача уже открытых диалогов не заявлены.
7. CRM Integration: согласованный API v1 и outbox; проверенная передача в тестовую CRM. Внешние каналы только при наличии доступа.
8. Stabilization: security/E2E/failure/load/restore проверки, инструкции production и приёмка.

Приоритет к 01.11: сайт-виджет, Inbox, операторы, распределение и настройки. Прогноз даты зависит от прохождения gates, не считать срок доказательством готовности.
