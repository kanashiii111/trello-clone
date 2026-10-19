# TargetDown

**Что значит.** Prometheus дольше минуты не может забрать метрики с target'а
(`up == 0`). Сам сервис может и работать, но мы его не видим: остальные
алерты по этому компоненту молчат.

**Как проверить.**
- Prometheus → Status → Targets: у target'а будет `DOWN` и текст ошибки
  (`connection refused`, `no such host`, `context deadline exceeded`).
- `make mon-ps` — запущен ли контейнер экспортера, healthy ли он.
- `docker compose logs <сервис>` — почему упал.

**Что делать.**
- Контейнер остановлен/упал: `make mon-up` поднимет его заново.
- `no such host` — сервис не запущен или переименован в compose, а
  `prometheus.yml` смотрит на старое имя.
- `deadline exceeded` — экспортер отвечает дольше `scrape_timeout` (10 с):
  чаще всего тормозит то, что он опрашивает (БД, php-fpm).

**Воспроизвести.** `docker compose -f compose.yaml -f compose.monitoring.yaml stop nginx-exporter`.
