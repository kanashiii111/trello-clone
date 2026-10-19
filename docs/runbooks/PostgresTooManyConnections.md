# PostgresTooManyConnections

**Что значит.** Занято больше 80% `max_connections` (100 в
`postgresql.conf`). Когда лимит кончится, новые подключения получат
`FATAL: sorry, too many clients already`.

**Как проверить.**
- Дашборд «Обзор сервиса» → «Соединения по состоянию».
- `make psql`:
  `SELECT usename, application_name, state, count(*) FROM pg_stat_activity GROUP BY 1, 2, 3 ORDER BY 4 DESC;`
- Много `idle in transaction` — код открывает транзакцию и не закрывает.

**Что делать.**
- Найти, кто держит соединения, и починить утечку (не закрытые
  соединения/транзакции, слишком большой пул в приложении).
- Срочно — завершить зависшие сессии: `SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE state = 'idle in transaction' AND state_change < now() - interval '5 minutes';`
- Поднимать `max_connections` — последнее средство: каждое соединение в
  PostgreSQL — отдельный процесс и память. Обычно ставят пулер (PgBouncer).

**Воспроизвести.** Открыть 85 соединений, которые 4 минуты ничего не делают:
`docker compose exec postgres sh -c 'for i in $(seq 85); do psql -U app -d app -qc "SELECT pg_sleep(240)" & done; wait'`.
`wait` в конце обязателен. В контейнере postgres сам postmaster — PID 1,
и фоновые процессы, брошенные завершившимся `sh`, становятся его «детьми».
Когда такой процесс завершится с ошибкой, postmaster примет это за падение
серверного процесса и перезапустит все соединения с recovery.
