# HighLatencyP95

**Что значит.** 95-й перцентиль времени ответа какого-то route выше 1 секунды
уже 3 минуты: каждый 20-й запрос к нему медленнее секунды.

**Как проверить.**
- Дашборд «Обзор сервиса» → «Duration: p95 по route».
- Медленная БД? Панель «Среднее время запроса, топ-5» и slow-log PostgreSQL
  (`docker compose logs postgres | grep duration`), либо `make psql`:
  `SELECT query, calls, mean_exec_time FROM pg_stat_statements ORDER BY mean_exec_time DESC LIMIT 5;`
- Нехватка воркеров? Если одновременно горит PhpFpmPoolSaturated — запросы ждут
  в очереди (это время в метрике PHP даже не видно).

**Что делать.** Найти медленный запрос/участок кода; при насыщении пула —
снизить нагрузку или увеличить `pm.max_children` (если хватает CPU/памяти и
соединений к БД).

**Воспроизвести.** Несколько минут подряд:
`while true; do curl -s -o /dev/null 'http://127.0.0.1:8080/demo/slow.php?seconds=1.5'; done`.
