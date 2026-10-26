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

Cookie auth + CSRF для POST + active tenant context. chat.view/reply проверяются сервером. Права текущего инкремента — область всей собственной организации; более узкие правила назначения/отделов ещё не реализованы.

| Метод и путь | Поведение |
|---|---|
| GET /api/v1/conversations | q (имя/цифры телефона), view=all/mine/incoming, status=pending/open/closed, cursor; 30 диалогов |
| GET /api/v1/conversations/{id} | Карточка, контакт, канал, version/sequence |
| GET /api/v1/conversations/{id}/messages | after_sequence, 100 сообщений, включая заметки; явный cursor как у visitor |
| POST /api/v1/conversations/{id}/messages | client_message_id/body/attachment_ids, kind=message или note; chat.reply |
| POST /api/v1/conversations/{id}/read | last_read_sequence; явное изменение прочтения |
| POST /api/v1/attachments | Приватный upload оператора, chat.reply |
| GET /api/v1/attachments/{id} | Связанный файл разрешённого диалога или собственный несвязанный upload |

Назначение, статусы/переоткрытие и visitor realtime auth ещё не предоставлены. Начальный статус pending. Новые сообщения в closed отклоняются, но повтор уже сохранённого сообщения остаётся идемпотентным после повторной проверки прав.

## Reverb event

Оператор подписывается на `private-organizations.{organizationId}.operators.{userId}` через `/broadcasting/auth`. Event: `chat.message_available.v1`. Envelope: event_id, schema_version=1, type=message.created, aggregate_id (conversation UUID), aggregate_version (sequence), occurred_at, data (conversation_id/message_id/sequence). Текст, телефон, credential и содержимое вложений не передаются. Event — сигнал догрузить данные с проверкой прав, а не источник истории. Возможны повторы event_id; fallback — HTTPS history by sequence.
