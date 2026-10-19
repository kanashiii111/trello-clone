# PhpFpmPoolSaturated

**Что значит.** Все воркеры php-fpm заняты (`pm.max_children` = 5 в
`docker/php/www.conf`), новые запросы ждут в очереди. Это опережающий
сигнал: дальше вырастут latency и появятся таймауты.

**Как проверить.**
- Дашборд «Обзор сервиса» → «Воркеры php-fpm» и «Очередь».
- `docker compose exec php sh -c "SCRIPT_NAME=/status SCRIPT_FILENAME=/status QUERY_STRING=full REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000"`
  — что именно делает каждый воркер (какой скрипт, сколько уже выполняется).

**Что делать.**
- Воркеры висят на медленных запросах (БД, внешние API) — лечить причину,
  а не увеличивать пул: больше воркеров = больше соединений к той же медленной БД.
- Воркеры реально заняты CPU — масштабировать (`pm.max_children`, реплики php)
  с оглядкой на память и `max_connections` PostgreSQL.

**Воспроизвести.** Параллельных запросов больше, чем 5 воркеров, например 10 таких циклов в фоне:
`while true; do curl -s -o /dev/null 'http://127.0.0.1:8080/demo/slow.php?seconds=1'; done &`.
