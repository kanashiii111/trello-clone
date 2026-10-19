# Учебное окружение: nginx + php-fpm + PostgreSQL

# Подтягиваем .env как переменные make (нужно, например, для `make psql`).
# Docker Compose сам читает .env для подстановки в compose.yaml — это отдельно, для Makefile.
ifneq (,$(wildcard .env))
include .env
export
endif

.DEFAULT_GOAL := help
.PHONY: help init build up down ps logs clean composer sh psql \
	mon-up mon-down mon-ps mon-check mon-reload pg-monitoring \
	logs-up logs-down

# Базовый стек — только compose.yaml. Мониторинг подключается вторым файлом через -f:
# compose склеивает их в одну модель, сервисы мониторинга попадают в ту же сеть
COMPOSE     = docker compose
COMPOSE_MON = docker compose -f compose.yaml -f compose.monitoring.yaml
# Логи — следующий уровень поверх мониторинга: база + мониторинг + Graylog
COMPOSE_LOG = $(COMPOSE_MON) -f compose.logging.yaml
# Все файлы и все профили сразу — для down/clean. Иначе `make down` после `make mon-up`
# оставил бы контейнеры мониторинга «сиротами» в сети, и удаление сети упало бы
COMPOSE_ALL = $(COMPOSE_LOG) --profile '*'

help:
	@echo "make init      — создать .env из .env.example (с UID/GID текущего пользователя)"
	@echo "make build     — собрать образы"
	@echo "make up        — поднять контейнеры (в фоне)"
	@echo "make down      — остановить и удалить контейнеры (включая мониторинг)"
	@echo "make ps        — статус контейнеров"
	@echo "make logs      — логи всех сервисов (follow)"
	@echo "make clean     — down -v: остановить всё и удалить volume'ы (данные БД и метрики будут потеряны)"
	@echo "make composer  — composer install внутри php-контейнера"
	@echo "make sh        — shell в php-контейнере"
	@echo "make psql      — консоль psql к postgres"
	@echo ""
	@echo "Мониторинг (Prometheus + экспортеры):"
	@echo "make mon-up    — поднять базу + мониторинг (cAdvisor: COMPOSE_PROFILES=cadvisor make mon-up)"
	@echo "make mon-down  — остановить мониторинг, база продолжит работать"
	@echo "make mon-ps    — статус контейнеров вместе с мониторингом"
	@echo "make mon-check — проверить конфиги Prometheus и правила (promtool), blackbox, Alertmanager (amtool)"
	@echo "make mon-reload — перечитать конфиги Prometheus и Alertmanager без рестарта"
	@echo "make pg-monitoring — применить настройки мониторинга к уже существующей БД"
	@echo ""
	@echo "Логи (Graylog, нужно ~3 ГБ RAM для Docker):"
	@echo "make logs-up   — база + мониторинг + Graylog, логи nginx/php/postgres уходят в Graylog"
	@echo "make logs-down — убрать Graylog, мониторинг и база остаются"

# Создаёт .env из примера и подставляет реальные UID/GID текущего пользователя,
# чтобы файлы, которые php-fpm пишет в bind mount (например app/vendor), на Linux-хосте
# принадлежали не root/произвольному системному uid, а текущему пользователю
init:
	@if [ -f .env ]; then \
		echo ".env уже существует, пропускаю"; \
	else \
		cp .env.example .env; \
		CURRENT_UID=$$(id -u); \
		CURRENT_GID=$$(id -g); \
		sed -i.bak "s/^UID=.*/UID=$$CURRENT_UID/" .env; \
		sed -i.bak "s/^GID=.*/GID=$$CURRENT_GID/" .env; \
		rm -f .env.bak; \
		echo ".env создан (UID=$$CURRENT_UID, GID=$$CURRENT_GID)"; \
	fi

build:
	docker compose build

up:
	docker compose up -d

down:
	$(COMPOSE_ALL) down --remove-orphans

ps:
	docker compose ps

logs:
	docker compose logs -f

clean:
	$(COMPOSE_ALL) down -v --remove-orphans

# Ставим зависимости от имени www-data — тогда vendor/ на хосте получит
# владельца из UID/GID, заданных при `make init`, а не root
composer:
	docker compose exec -u www-data php composer install

sh:
	docker compose exec php sh

psql:
	docker compose exec postgres psql -U $(POSTGRES_USER) -d $(POSTGRES_DB)

mon-up:
	$(COMPOSE_MON) up -d

# Останавливаем только сервисы мониторинга, а не весь проект: база продолжает работать.
# Список сервисов не дублируем: «все сервисы» минус «сервисы базы» (Graylog, если
# поднят, тоже уходит — он уровнем выше мониторинга). Затем `up` базы: override-файлы
# дополняют и базовые сервисы (метрики в php, logging driver), и их надо пересоздать
# обратно в «базовом» виде
mon-down:
	$(COMPOSE_ALL) rm -sf $$($(COMPOSE_ALL) config --services | grep -vxF "$$($(COMPOSE) config --services)")
	$(COMPOSE) up -d

mon-ps:
	$(COMPOSE_MON) --profile '*' ps

# promtool запускаем из того же образа и с теми же volume'ами, что и Prometheus —
# проверяется ровно тот конфиг, который будет загружен
mon-check:
	$(COMPOSE_MON) run --rm --no-deps --entrypoint promtool prometheus check config /etc/prometheus/prometheus.yml
	$(COMPOSE_MON) run --rm --no-deps --entrypoint promtool prometheus check rules /etc/prometheus/rules/alerts.yml
	$(COMPOSE_MON) run --rm --no-deps blackbox-exporter --config.file=/etc/blackbox_exporter/blackbox.yml --config.check
	$(COMPOSE_MON) run --rm --no-deps --entrypoint amtool alertmanager check-config /etc/alertmanager/alertmanager.yml

mon-reload:
	curl -fsS -X POST http://127.0.0.1:$(or $(PROMETHEUS_PORT),9090)/-/reload && echo "Prometheus: конфиг и правила перечитаны"
	curl -fsS -X POST http://127.0.0.1:$(or $(ALERTMANAGER_PORT),9093)/-/reload && echo "Alertmanager: конфиг перечитан"

# initdb-скрипты выполняются только на пустом томе pgdata. Чтобы не делать `make clean`
# (и не терять данные), применяем мониторинговую часть вручную:
#  1) restart — shared_preload_libraries читается только при старте сервера;
#  2) --wait — ждём healthy, иначе psql не к чему подключаться;
#  3) тот же идемпотентный скрипт, что и при initdb
pg-monitoring:
	$(COMPOSE) restart postgres
	$(COMPOSE) up -d --wait postgres
	$(COMPOSE) exec postgres sh /docker-entrypoint-initdb.d/02-monitoring.sh
	@echo "Готово: пользователь экспортера и pg_stat_statements на месте"

# --wait: дождаться healthy всех сервисов (Graylog стартует 1–2 минуты).
# graylog-init — одноразовый, после настройки он завершается, поэтому его ждём отдельно
logs-up:
	$(COMPOSE_LOG) up -d --wait --wait-timeout 300 $$($(COMPOSE_LOG) config --services | grep -vx graylog-init)
	$(COMPOSE_LOG) up graylog-init --exit-code-from graylog-init 2>&1 | grep 'graylog-init-1' || true
	@echo "Graylog: http://127.0.0.1:$(or $(GRAYLOG_PORT),9000)/ (admin / пароль из .env)"

# Убираем только Graylog и его хранилища; nginx/php/postgres пересоздаются
# с обычным logging driver, мониторинг продолжает работать
logs-down:
	$(COMPOSE_LOG) rm -sf $$($(COMPOSE_LOG) config --services | grep -vxF "$$($(COMPOSE_MON) config --services)")
	$(COMPOSE_MON) up -d
