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
