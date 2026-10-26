# Chat API v1 — реализованный контракт

Все ошибки JSON: `error.code`, русское `error.message`, `request_id`; validation также `errors`. X-Request-Id возвращается в заголовке. Ответы не содержат credential hash, storage path, секретов или stacktrace. Успех: `data`, для списков также `meta`.

## Создание канала

Cookie auth оператора + CSRF + directory.manage: `POST /api/v1/channels` с `{name, site_id}`. Только сайт текущей организации, только website, один канал на сайт. Возвращает public_id. `GET /api/v1/channels` доступен с chat.view. Эта операция не означает, что виджет опубликован или установлен.

## Visitor API

Базовый путь: `/widget/v1/channels/{public_id}`. Bootstrap `POST /sessions` с JSON `{}` и Origin, равным origin сайта. Ответ 201: `credential`, `session_id`, `expires_at`. Credential необходимо хранить только в защищённой области браузерного SDK; не включать в URL, аналитику или логи. Полноценный SDK пока не реализован.

Остальные запросы требуют `Authorization: Bearer <credential>`. Cookies оператора не авторизуют посетителя. Разрешён CORS origin сайта и origin приложения (для будущего iframe); bootstrap разрешён только origin сайта. Origin является защитой браузерного доступа, не удостоверением личности и не заменяет credential.

| Метод и путь | Данные / поведение |
|---|---|
| POST /identify | name, phone, consent=true, consent_version=contact-v1; техверсия текста согласия, юридический текст требует согласования |
| GET /conversations/current | Собственный диалог или null |
| GET /messages?after_sequence=0 | Только собственные публичные сообщения, максимум 100; meta: last_sequence (head), next_after_sequence (cursor), has_more |
| POST /messages | client_message_id (UUID), body (до 10000 символов), attachment_ids (до 5); текст или файлы обязательны, kind запрещён |
| POST /read | last_read_sequence, не убывает и не превышает public head |
| POST /attachments | multipart file, JPEG/PNG/WebP/PDF, до 10 MiB, только после идентификации |
| GET /attachments/{id} | Только собственный upload или публичный файл своего диалога; attachment/nosniff |
| POST /revoke | Отзывает текущую browser session, не удаляет данные |

При POST message: 201 новая запись, 200 повтор той же записи, 409 idempotency_conflict при другом payload. identity_required=403 до идентификации. invalid_credential=401 для отсутствующего/истёкшего/отозванного/чужого credential. Повтор identify с другой identity — 409. Номер телефона никогда не восстанавливает историю.

## Operator API

Cookie auth + CSRF для POST + active tenant context. chat.view/reply проверяются сервером. Права Phase 3 расширены в Phase 4 правилами отдела и назначения; см. Inbox workflows ниже.

| Метод и путь | Поведение |
|---|---|
| GET /api/v1/conversations | q (имя/цифры телефона), view=all/mine/incoming, status=pending/open/closed, cursor; 30 диалогов |
| GET /api/v1/conversations/{id} | Карточка, контакт, канал, version/sequence |
| GET /api/v1/conversations/{id}/messages | after_sequence, 100 сообщений, включая заметки; явный cursor как у visitor |
| POST /api/v1/conversations/{id}/messages | client_message_id/body/attachment_ids, kind=message или note; chat.reply |
| POST /api/v1/conversations/{id}/read | last_read_sequence; явное изменение прочтения |
| POST /api/v1/attachments | Приватный upload оператора, chat.reply |
| GET /api/v1/attachments/{id} | Связанный файл разрешённого диалога или собственный несвязанный upload |

Назначение и статусы добавлены в Phase 4 ниже; visitor realtime auth ещё не предоставлен. Начальный статус pending. Новые сообщения в closed отклоняются, но повтор уже сохранённого сообщения остаётся идемпотентным после повторной проверки прав.

## Reverb event

Оператор подписывается на `private-organizations.{organizationId}.operators.{userId}` через `/broadcasting/auth`. Event: `chat.message_available.v1`. Envelope: event_id, schema_version=1, type=message.created, aggregate_id (conversation UUID), aggregate_version (sequence), occurred_at, data (conversation_id/message_id/sequence). Текст, телефон, credential и содержимое вложений не передаются. Event — сигнал догрузить данные с проверкой прав, а не источник истории. Возможны повторы event_id; fallback — HTTPS history by sequence.

## Inbox workflows (Phase 4)

Операторская страница `/inbox` использует эти же защищённые API. Все POST-команды требуют CSRF и актуальный `version` диалога. 409 version_conflict означает необходимость перечитать состояние, а не слепо повторить изменение.

| Метод и путь | Контракт |
|---|---|
| POST /api/v1/conversations/{id}/accept | `{version}`; только pending без assignee; один победитель при конкурентном принятии, присваивает текущего оператора и open |
| POST /api/v1/conversations/{id}/assign | `{version, assigned_user_id: id или null, department_id: id или null, reason?}`; chat.manage, только свой tenant, активный chat.reply оператор и допустимый отдел |
| POST /api/v1/conversations/{id}/transition | `{version, status, reason?}`; open→closed/pending, pending→closed (руководитель), closed→pending; pending снимает assignee |
| GET /api/v1/conversations/{id}/activities | Последние 20 изменений с actor/type/safe changes/reason/version; только view policy |
| GET /api/v1/quick-replies | Сохранённые ответы текущей организации, chat.view |
| POST /api/v1/quick-replies | `{title, body}`, chat.manage |
| PATCH /api/v1/quick-replies/{id} | Изменение своего tenant-шаблона, chat.manage |

Роли Owner/Admin/Supervisor имеют chat.manage и видят все диалоги своей организации. Operator видит общую очередь и свой отдел; отвечает/оставляет заметки/меняет read marker только в собственном принятом диалоге. Остальные видимые диалоги — наблюдение. Между отделами/операторами передаёт руководитель. Analyst не имеет доступа к Inbox. Изменение отдела/отключение сотрудника не выполняет автоматическое переназначение — это следующая фаза routing.

Список также принимает channel_id/department_id, отдаёт preview/preview_kind, assigned_user, department, unread_count (только visitor messages), last_read_sequence и серверные permissions. Поиск нормализует полный российский телефон с 8/+7. Счётчик в UI относится к загруженному отфильтрованному списку, не ко всей организации.

Operator history: `latest=1` — последние 100 сообщений, `before_sequence=N` — предыдущая страница; meta.has_older/next_before_sequence. `after_sequence=N` сохраняет прежнюю семантику восстановления с meta.has_more/next_after_sequence. GET не меняет прочтение. UI отправляет read только при реальном видимом просмотре конца переписки и разрешении read.

Новый приватный event `chat.conversation_changed.v1` содержит тот же минимальный envelope с data.conversation_id и aggregate_version. Новые message events добавляют data.sender_type для уведомлений. Publisher проверяет актуальную view policy каждого получателя, включая отдел. Body/phone/files не попадают в broadcast. Aggregate version новых events — версия диалога; sequence сообщения находится отдельно в data.sequence.

Лимиты operator API разделены: 600 GET/мин и 120 mutations/мин на пользователя, upload дополнительно ограничен отдельным лимитом. Чтение и polling не расходуют квоту записи. 429 сохраняет Retry-After.
