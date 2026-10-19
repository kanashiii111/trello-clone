# Учебное окружение: nginx + php-fpm + PostgreSQL

Простой стек для практики Docker Compose: nginx как веб-сервер и
реверс-прокси к php-fpm 8.5 (чистый PHP, без фреймворков), PostgreSQL 18
как база данных. Все три образа собираются локально из Dockerfile в `docker/`.

## Быстрый старт

```bash
make init && make up && make composer
```

- `make init` создаёт `.env` из `.env.example` и подставляет в него UID/GID
  текущего пользователя (нужно, чтобы файлы, которые пишет php-контейнер,
  на хосте принадлежали вам, а не root).
- `make up` собирает (если ещё не собраны) и поднимает контейнеры.
- `make composer` ставит зависимости приложения в `app/vendor/`.

После этого:

- приложение — http://127.0.0.1:8080/
- health-check — http://127.0.0.1:8080/health.php
- PostgreSQL слушает `127.0.0.1:5432` — можно подключиться любым GUI-клиентом
  (логин/пароль/база — из `.env`), либо `make psql` для консоли.

Остальные команды — в `make help`.

## Когда пересобирать, а когда достаточно F5 или restart

- **Правки в `app/`** (PHP-код) — видны сразу, без пересборки и рестарта.
  `./app` смонтирован в контейнеры как volume, а opcache в dev-конфиге
  (`opcache.validate_timestamps = 1`, `opcache.revalidate_freq = 0`)
  проверяет изменения файла на каждый запрос. Просто обновите страницу.
- **Правки в конфигах** (`docker/nginx/conf.d/*.conf`, `php.ini`, `www.conf`,
  `postgresql.conf`) — тоже volume, но применяются не мгновенно: нужен
  `docker compose restart <сервис>`, потому что nginx/php-fpm/postgres
  читают их только при старте.
- **После обновления репозитория** (например, переключения на тег
  `monitoring-step-3`, где в образ php добавлен APCu) — тоже `make build`,
  `make up`, `make composer`.
- **Правки в `Dockerfile` или `app/composer.json`** (новое системное
  расширение PHP, системный пакет, PHP-зависимость) — нужен `make build`,
  затем `make up` (и `make composer`, если менялся `composer.json`).

## Ловушка: initdb выполняется только один раз

`docker/postgres/initdb/*.sql` применяется исключительно при инициализации
**пустого** тома `pgdata` — то есть один раз, при самом первом старте. Если
окружение уже поднималось и вы правите `01-init.sql`, он не выполнится
повторно сам по себе. Чтобы применить изменения — снести том и поднять
заново:

```bash
make clean && make up
```

Это удалит все данные в PostgreSQL.

То же касается мониторинга: `initdb/02-monitoring.sh` создаёт пользователя
для postgres-exporter и extension `pg_stat_statements` только на свежем томе.
Если окружение поднималось до появления мониторинга, данные терять не нужно —
есть идемпотентная цель:

```bash
make pg-monitoring
```

Она перезапускает postgres (`shared_preload_libraries` читается только при
старте сервера) и выполняет тот же скрипт. Повторный запуск безопасен.

## Мониторинг

Prometheus и экспортеры описаны в отдельном `compose.monitoring.yaml` и
подключаются поверх базового стека — `make up` без мониторинга остаётся лёгким.

```bash
make mon-up      # база + Prometheus и экспортеры
make mon-down    # убрать мониторинг, приложение продолжит работать
```

- Prometheus — http://127.0.0.1:9090/ (Status → Targets: все должны быть `UP`).
- Экспортеры наружу не публикуются: их опрашивает только Prometheus изнутри
  docker-сети. `stub_status` nginx живёт на внутреннем порту 8081, статус пула
  php-fpm (`/status`) читается напрямую по FastCGI.
- Black-box пробы (blackbox-exporter) раз в 15 секунд запрашивают
  `http://nginx/health.php` (ждём 200 и `"status":"ok"` в теле) и
  `http://nginx/nginx-health`. Результат — метрика `probe_success`: попробуйте
  `docker compose stop postgres` и запрос `probe_success` в Prometheus —
  health.php уйдёт в 0 (503), а nginx-health останется 1.

### Метрики приложения

Под мониторингом php получает `APP_METRICS_ENABLED=1` и `auto_prepend_file`
(`docker/php/monitoring.ini`): файл `app/instrumentation/prepend.php`
выполняется перед каждым скриптом и в конце запроса пишет в APCu
`http_requests_total{method,route,status}` и гистограмму
`http_request_duration_seconds{method,route}`. Бизнес-метрика
`app_notes_created_total` считается в `index.php`. Без мониторинга всё это
выключено, `App\Metrics` превращается в no-op.

- Метрики отдаёт `/metrics.php`, но только через внутренний порт nginx 8081
  (Prometheus, job `app`). Снаружи http://127.0.0.1:8080/metrics.php — 404.
  Посмотреть руками:
  `docker compose exec nginx curl -s http://localhost:8081/metrics.php`.
- `route` — путь PHP-скрипта, а не URL: метки с неограниченным числом значений
  (query string, id) взрывают кардинальность Prometheus.
- Счётчики живут в памяти APCu и обнуляются при рестарте php — это нормально,
  `rate()`/`increase()` в Prometheus корректно обрабатывают сброс счётчика.

Демо-эндпоинты для экспериментов (работают и без мониторинга):

| URL | Что делает |
|---|---|
| `/demo/slow.php?seconds=2` | `pg_sleep` в PostgreSQL (максимум 10 с) — видно в latency, slow-log и `pg_stat_statements` |
| `/demo/error.php?rate=0.3` | отдаёт 500 с заданной вероятностью (по умолчанию всегда) |
| `/demo/cpu.php?ms=300` | жжёт CPU воркера php-fpm (максимум 2000 мс) |

### cAdvisor (необязательно)

Метрики CPU/памяти/сети по каждому контейнеру. Включается профилем:

```bash
COMPOSE_PROFILES=cadvisor make mon-up
```

UI — http://127.0.0.1:8088/. На Linux работает полноценно. На Docker Desktop
(macOS, Windows/WSL2) Docker живёт внутри виртуальной машины, и cAdvisor видит
её, а не ваш компьютер: метрики «хоста» — это метрики VM, а часть метрик
(диски, файловые системы) может отсутствовать. Метрики по контейнерам при этом
есть. Без профиля Prometheus просто не находит cAdvisor и не считает его упавшим.

## Linux / macOS / Windows (WSL2)

`make init` подставляет в `.env` реальные UID/GID текущего пользователя, а
Dockerfile php пересоздаёт `www-data` с этими же ID (`groupmod`/`usermod -o`,
флаг `-o` — потому что нужный GID нередко уже занят какой-то системной группой
в образе, например `dialout`). За счёт этого владелец файлов, которые
php-fpm пишет в bind mount (`app/vendor` и т.п.), на хосте совпадает с вами,
а не с root — проверено на Linux, macOS и Windows/WSL2.

- **Linux** — работает «из коробки»: bind mount напрямую транслирует UID/GID
  между хостом и контейнером.
- **macOS** (Docker Desktop) — тоже напрямую: virtiofs корректно пробрасывает
  владельца файлов.
- **Windows + WSL2** — запускайте `make`/`docker compose` из терминала
  WSL2-дистрибутива (не из PowerShell/cmd) и держите проект **внутри
  файловой системы WSL2** (например `~/projects/...`), а не на `/mnt/c/...`.
  Диски Windows подключены в WSL2 через DrvFs, которая не хранит настоящие
  Unix-права — владелец файлов там не будет соответствовать UID/GID из
  `.env`, независимо от настроек Dockerfile. Также проверьте, что в Docker
  Desktop включена WSL-интеграция для вашего дистрибутива (Settings →
  Resources → WSL Integration).
- **Переносы строк** — `.gitattributes` форсирует LF для всех файлов
  репозитория, чтобы `git clone` на Windows с `core.autocrlf=true` не
  проставил CRLF в Dockerfile/Makefile/конфигах (это бы сломало их разбор
  внутри Linux-контейнеров).

## Структура

```
app/            — код приложения (PSR-4 автозагрузка App\ → src/)
docker/nginx/   — Dockerfile + конфиг nginx
docker/php/     — Dockerfile + php.ini + пул php-fpm (www.conf)
docker/postgres/— Dockerfile + postgresql.conf + init-скрипты
docker/prometheus/ — конфиг Prometheus
docker/blackbox/ — модули black-box проб
docker/nginx-exporter/ — тонкая обёртка над образом экспортера (wget для healthcheck)
compose.yaml    — оркестрация; значения по умолчанию — в .env.example
compose.monitoring.yaml — стек мониторинга, подключается через -f
```
