# Runbook-и алертов

На каждый алерт из `docker/prometheus/rules/alerts.yml` — короткая инструкция:
что значит, как проверить, что делать. Ссылка на runbook приходит в письме
(аннотация `runbook_url`), чтобы дежурный не начинал разбор с нуля.

Хороший runbook короткий и проверяемый: команды, которые можно скопировать,
и критерий «починили». Если по алерту нечего делать — это не алерт, а шум.

| Алерт | Severity |
|---|---|
| [TargetDown](TargetDown.md) | warning |
| [HealthCheckFailing](HealthCheckFailing.md) | critical |
| [HighErrorRate](HighErrorRate.md) | critical |
| [HighLatencyP95](HighLatencyP95.md) | warning |
| [PhpFpmPoolSaturated](PhpFpmPoolSaturated.md) | warning |
| [PostgresDown](PostgresDown.md) | critical |
| [PostgresTooManyConnections](PostgresTooManyConnections.md) | warning |
