# Локальный запуск и эксплуатация

Production deployment в этой сессии не выполняется. Compose — локальное окружение, порты HTTP/Reverb доступны только на 127.0.0.1; MySQL/Redis наружу не публикуются.

## Требования

Docker/Compose v2 с BuildKit; Node 22 (Vite 7 требует Node >=22.12), npm. PHP на хосте не нужен. На обычной машине Docker использует стандартный trust store. В managed cloud сохранять proxy и передать CA как build secret; `CODEX_PROXY_CERT` задаётся платформой. При read-only home: `export BUILDX_CONFIG=/tmp/aidesk-buildx` и `export npm_config_cache=/tmp/aidesk-npm`.

## Первичная настройка

1. `cp .env.example .env`.
2. Задать уникальные DB_PASSWORD, MYSQL_ROOT_PASSWORD и REVERB_APP_SECRET в .env (например `openssl rand -hex 32` для каждого). Не коммитить .env. Для другого UID/GID задать LOCAL_UID/LOCAL_GID.
3. `docker compose build app`.
4. `docker compose run --rm --no-deps app composer install --no-interaction`.
   В managed cloud Composer требует read-only mount CA и COMPOSER_CAFILE. Пример:

   ```sh
   docker compose run --rm --no-deps -v "$CODEX_PROXY_CERT:/run/proxy-ca.pem:ro" -e COMPOSER_CAFILE=/run/proxy-ca.pem -e COMPOSER_HOME=/tmp/composer app composer install --no-interaction
   ```

5. `docker compose run --rm --no-deps app php artisan key:generate`.
6. `npm ci && npm run build`.
7. `docker compose up -d mysql redis`.
8. `docker compose run --rm app php artisan migrate --force`.
9. `docker compose up -d app queue reverb`.
10. Открыть http://127.0.0.1:8000 . Проверки — AGENTS.md.

`docker compose logs app queue reverb` — диагностика; не публиковать логи с персональными данными. `docker compose stop` останавливает процессы без удаления данных. Не использовать `down -v` на существующих данных.

## Конфигурация

REVERB_HOST — серверный адрес (reverb в Compose); VITE_REVERB_HOST — адрес браузера (localhost). При тестировании браузера используются 127.0.0.1:8000/8080. Origin allowlist — REVERB_ALLOWED_ORIGINS. Секрет только серверный. Внешние iframe origins и visitor auth будут добавлены вместе с widget module. Не ставить wildcard в production.

Нельзя менять APP_KEY после появления данных без плана ротации. Не заменять пароли MySQL простым редактированием .env существующего volume — это не меняет учётные данные внутри БД.

## Перед production

Отдельные FPM/Nginx или поддерживаемый application server, TLS/WSS, APP_ENV=production, APP_DEBUG=false, secure cookies, доверенные прокси/hosts, секрет-хранилище, выделенные DB/Redis и отдельные учётные данные. Управляемые процессы queue/reverb, queue restart после релиза, миграции с backup/rollback plan. Обязательны проверка восстановления БД/приватных файлов, retention, метрики queue/outbox lag и провайдерских ошибок. Политики auth/tenant/visitor, load/security/restore gates должны пройти до разрешения запуска. Текущая Foundation не является готовым чат-продуктом.

## Создание организации и вход (Phase 2)

После миграций:

```sh
docker compose exec app php artisan aidesk:organization 'Название автодилера' 'owner@example.ru' --owner-name='Имя владельца'
```

Команда запросит скрытый пароль (от 12 символов, буквы и цифры). Не передавать пароль аргументом shell. Затем открыть `/login`. Владелец создаёт автосалоны, отделы, сайты и сотрудников на `/settings/organization`. Текущий пользователь закреплён за одной организацией. Роли фиксированы; изменение владельца и собственной роли из UI запрещено. Отзыв доступа действует на следующий HTTP/auth-запрос; отзыв уже открытых бизнес-подписок будет реализован до отправки chat events.

Для E2E на отдельной тестовой среде: `docker compose exec -T app php artisan db:seed --class=E2ESeeder`. Это только синтетические учётные записи `example.test` с известным тестовым паролем из исходника. Seeder запрещён вне local/testing и не перезаписывает существующие аккаунты; не применять его к рабочим данным. Обычный DatabaseSeeder не создаёт аккаунтов. После fixtures выполнить `npm run test:e2e`.

Feature Pest принудительно использует testing/SQLite :memory:/array/sync через env и server overrides; этот suite не должен получать рабочую MySQL. Infrastructure suite не вызывает RefreshDatabase и выполняет probe с удалением только созданной тестовой записи/rollback транзакции, но всё равно запускается только в local/CI. CI HTTP server работает с APP_ENV=local, чтобы браузерный тест действительно проверял CSRF (419 без токена).

## Chat Core / очередь событий

После обновления: `docker compose exec -T app php artisan migrate --force`, затем `docker compose up -d app queue reverb scheduler`. Новый scheduler запускает `schedule:work`; production использует один scheduler/cron `php artisan schedule:run` каждую минуту и управляемые workers. В Compose после изменения кода долгоживущих jobs/config — `docker compose restart queue scheduler reverb`. Новый PHP ini согласует upload_max_filesize=10M и post_max_size=12M с лимитом приложения.

Сообщение сначала фиксируется в MySQL вместе с outbox row. Очередь — ускорение обработки события после commit. Если enqueue не удался, scheduler снова подберёт событие. Ручной безопасный запуск: `docker compose exec -T app php artisan aidesk:dispatch-chat-events`. published_at — публикация уведомления Reverb, не подтверждение просмотра клиентом. Duplicate event_id возможен при восстановлении; клиент догружает HTTPS историю по sequence. Новые HTTP-запросы могут временно отказать при полном отказе Redis (session/cache/rate limiter); повторять с тем же client_message_id.

Compose сохраняет внешний proxy и добавляет только внутренние сервисы mysql/redis/reverb/app в NO_PROXY. Без этого запросы PHP → Reverb в managed cloud могут ошибочно попасть во внешний proxy. Не отключать TLS и не обходить внешнюю сетевую политику.

`API_CHAT_V1.md` описывает фактические endpoints и форматы. Website channel создаётся административным API; SDK/iframe и конструктор пока не созданы. Visitor credential не передавать в URL, логи или аналитику. Сессия не восстанавливается по телефону. Вложения приватные, скачиваются через проверяемый API; local storage route отключён. Сканер вредоносных файлов пока не подключён; перед production согласовать файловую и retention policy, юридический текст согласия.

E2E поднимает синтетические сайты на портах 8091–8094 и проверяет реальный CORS, HTTP-обмен и Redis → Reverb notification. В CI обязательно нужен queue worker (добавлен в workflow). Эти страницы — тестовые hosts, не продуктовый виджет. Infrastructure tests проверяют конкурентную отправку в отдельных PHP-процессах на MySQL; удаляют только записи собственной синтетической probe-организации, не очищают общие таблицы.

## Inbox

Обновить схему (`php artisan migrate --force`), собрать frontend и перезапустить queue/scheduler/reverb. В меню настроек появился переход «Диалоги», прямой адрес — `/inbox`. Операторы принимают доступные pending диалоги; руководитель назначает оператора/отдел; закрытые возвращаются в очередь. Отделы и сотрудники создаются в действующей админке. Быстрые ответы создаёт/редактирует руководитель из composer.

Для private Echo подписок нужны корректные VITE_REVERB_* при сборке (публичный ключ и браузерный host/port) и REVERB_* на сервере; секрет никогда не выставляется в Vite. Браузерный статус online относится к подписке, не к presence сотрудника. При отсутствии WebSocket работает polling 15 с. Уведомления браузера включаются пользователем; разрешение может быть недоступно на обычном HTTP вне localhost.

Новая миграция разрешает control events без message_id и добавляет aggregate_version/activities/quick_replies. На данных после Phase 4 нельзя вслепую откатывать старый publisher, который ожидает message_id у каждого pending event. Предпочтителен forward fix; перед откатом нужен отдельный план outbox/backup. В этой сессии rollback/delete данных не выполняются.

Автоматическое переназначение при отключении/смене отдела оператора ещё не настроено: руководитель использует «Все диалоги» и передаёт оставшиеся обращения вручную. Это не означает готовую автоматическую маршрутизацию.
