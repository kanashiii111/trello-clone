# Учебное окружение: nginx + php-fpm + PostgreSQL

# Подтягиваем .env как переменные make (нужно, например, для `make psql`).
# Docker Compose сам читает .env для подстановки в compose.yaml — это отдельно, для Makefile.
ifneq (,$(wildcard .env))
include .env
export
endif

.DEFAULT_GOAL := help
.PHONY: help init build up down ps logs clean composer sh psql \
	mon-up mon-down mon-ps mon-check mon-reload pg-monitoring

# Базовый стек — только compose.yaml. Мониторинг подключается вторым файлом через -f:
# compose склеивает их в одну модель, сервисы мониторинга попадают в ту же сеть
COMPOSE     = docker compose
COMPOSE_MON = docker compose -f compose.yaml -f compose.monitoring.yaml
# Все файлы и все профили сразу — для down/clean. Иначе `make down` после `make mon-up`
# оставил бы контейнеры мониторинга «сиротами» в сети, и удаление сети упало бы
COMPOSE_ALL = $(COMPOSE_MON) --profile '*'

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
	@echo "make mon-check — проверить конфиги Prometheus (promtool) и blackbox"
	@echo "make mon-reload — перечитать конфиг Prometheus без рестарта"
	@echo "make pg-monitoring — применить настройки мониторинга к уже существующей БД"

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
# Список сервисов не дублируем: «все сервисы с мониторингом» минус «сервисы базы»
mon-down:
	$(COMPOSE_ALL) rm -sf $$($(COMPOSE_ALL) config --services | grep -vxF "$$($(COMPOSE) config --services)")

mon-ps:
	$(COMPOSE_MON) --profile '*' ps

# promtool запускаем из того же образа и с теми же volume'ами, что и Prometheus —
# проверяется ровно тот конфиг, который будет загружен
mon-check:
	$(COMPOSE_MON) run --rm --no-deps --entrypoint promtool prometheus check config /etc/prometheus/prometheus.yml
	$(COMPOSE_MON) run --rm --no-deps blackbox-exporter --config.file=/etc/blackbox_exporter/blackbox.yml --config.check

mon-reload:
	curl -fsS -X POST http://127.0.0.1:$(or $(PROMETHEUS_PORT),9090)/-/reload && echo "Prometheus: конфиг перечитан"

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
