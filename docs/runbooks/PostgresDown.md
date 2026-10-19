# PostgresDown

**Что значит.** postgres-exporter дольше 30 секунд не может подключиться к
PostgreSQL (`pg_up == 0`). Приложение без БД не работает: этот алерт
подавляет HealthCheckFailing (inhibit_rules в Alertmanager), чтобы
дежурный получил одно письмо о причине, а не пачку о симптомах.

**Как проверить.**
- `make ps` — запущен ли контейнер postgres и healthy ли он.
- `docker compose logs --tail 50 postgres` — причина остановки (нехватка
  места, ошибка в `postgresql.conf`, OOM).
- `docker compose exec postgres pg_isready`.

**Что делать.**
- Контейнер остановлен — `make up`.
- Не стартует после правки конфига — откатить `docker/postgres/postgresql.conf`.
- Кончилось место на диске/томе — освободить место (`docker system df`).

**Воспроизвести.** `make demo-db-down`, вернуть — `make demo-db-up`.
