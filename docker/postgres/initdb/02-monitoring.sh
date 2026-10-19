#!/bin/sh
# Подготовка PostgreSQL к мониторингу: отдельный пользователь для postgres-exporter
# и extension pg_stat_statements.
#
# Это .sh, а не .sql, потому что пароль экспортера берётся из переменных окружения
# (POSTGRES_EXPORTER_*), а в чистом SQL их не прочитать.
#
# Скрипт идемпотентный, и это важно: как и 01-init.sql, сам он выполнится только
# при инициализации пустого тома pgdata. На уже поднятом окружении его запускает
# `make pg-monitoring` — и повторный запуск ничего не ломает.
set -e

EXPORTER_USER="${POSTGRES_EXPORTER_USER:-postgres_exporter}"
EXPORTER_PASSWORD="${POSTGRES_EXPORTER_PASSWORD:-exporter_dev_password}"

# Подключаемся через unix-сокет внутри контейнера — и при initdb (сервер ещё не слушает TCP),
# и при `docker compose exec`. Значения передаём psql-переменными (:'var' / :"var"),
# а не подстановкой shell в текст SQL — так psql сам корректно экранирует строки и имена
psql -v ON_ERROR_STOP=1 \
    --username "$POSTGRES_USER" \
    --dbname "$POSTGRES_DB" \
    -v exporter_user="$EXPORTER_USER" \
    -v exporter_password="$EXPORTER_PASSWORD" <<'EOSQL'
-- pg_stat_statements запоминает и utility-команды вместе с текстом — включая
-- ALTER ROLE ... PASSWORD 'секрет'. А postgres-exporter отдаёт текст запросов
-- в Prometheus (pg_stat_statements_query_id{query=...}), то есть пароль уехал бы
-- в метрики. Отключаем запись utility-команд только для этой сессии.
-- Мораль для лекции: текст запросов в метриках/логах — потенциальная утечка
SET pg_stat_statements.track_utility = off;

-- CREATE ROLE не умеет IF NOT EXISTS — создаём роль, только если её ещё нет
SELECT format('CREATE ROLE %I LOGIN', :'exporter_user')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'exporter_user')
\gexec

-- Пароль выставляем всегда: если его поменяли в .env, повторный запуск его обновит
ALTER ROLE :"exporter_user" WITH LOGIN PASSWORD :'exporter_password';

-- pg_monitor — встроенная роль ровно для этой задачи: читать pg_stat_* и настройки
-- без прав суперпользователя и без доступа к данным приложения. Экспортеру не нужен
-- (и не должен быть) пользователь приложения — принцип минимальных привилегий
GRANT pg_monitor TO :"exporter_user";

-- Представление pg_stat_statements. Без shared_preload_libraries в postgresql.conf
-- extension создастся, но запросы к нему будут падать — нужны оба шага
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
EOSQL
