# HealthCheckFailing

**Что значит.** Black-box проба дольше минуты получает неуспешный ответ:
пользователи видят ошибку. Это алерт на *симптом* — причина может быть любой.

**Как проверить.**
- `curl -i http://127.0.0.1:8080/health.php` — код и тело. `503` и
  `"database":"fail"` — проблема с БД; `502` — nginx не достучался до php-fpm.
- `curl -i http://127.0.0.1:8080/nginx-health` — жив ли сам nginx.
- В Prometheus: `probe_http_status_code`, `probe_failed_due_to_regex`
  (ответ 200, но тело не то).

**Что делать.** Идти по цепочке nginx → php-fpm → PostgreSQL: `make ps`,
логи сервиса, который отвечает с ошибкой. Если одновременно горит
PostgresDown — этот алерт подавлен (inhibit) и причину сразу видно в нём.

**Воспроизвести.** `docker compose stop php` (502) или `docker compose stop postgres`
(503 — но тогда придёт PostgresDown, а этот алерт будет подавлен).
