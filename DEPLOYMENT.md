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
