# План

1. Foundation: Laravel/React/Inertia/TS/Tailwind/UI, MySQL/Redis/Reverb, lock files, Pest/Playwright, CI, runbook. DONE; локальные gates и удалённый CI Foundation PASS, актуализировано до Laravel 13.
2. Organizations & Access: реализованы организация/салон/сайт/отдел, пользователи, фиксированные роли/permissions, auth, tenant policies и админка. Локальные HTTP/query/FK/private subscription gates PASS; удалённый CI run 40386939941 PASS. Custom roles/multi-org membership не входят в этот инкремент.
3. Chat Core: контакты, защищённые visitor sessions, сообщения, идемпотентность, события, приватные вложения и durable очередь.
4. Inbox: рабочие операторские экраны с реальными данными, назначения, заметки, фильтры и клиентская карточка.
5. Widget Builder: draft/publish/versioning/preview, iframe SDK, форма имени/телефона, полный обмен посетитель ↔ оператор.
6. Realtime & Routing: подписки, recovery/sequence, статусы, конкурентное принятие, round-robin/load/fallback/presence.
7. CRM Integration: согласованный API v1 и outbox; проверенная передача в тестовую CRM. Внешние каналы только при наличии доступа.
8. Stabilization: security/E2E/failure/load/restore проверки, инструкции production и приёмка.

Приоритет к 01.11: сайт-виджет, Inbox, операторы, распределение и настройки. Прогноз даты зависит от прохождения gates, не считать срок доказательством готовности.
