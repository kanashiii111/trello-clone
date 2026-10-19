"""
Идемпотентная настройка Graylog через REST API (запускается init-контейнером graylog-init).

Graylog хранит inputs и pipelines в MongoDB, а не в конфиг-файлах, поэтому
«конфигурация как код» здесь — это скрипт, который приводит Graylog к нужному
состоянию. Идемпотентность: каждый объект ищется по названию и создаётся, только
если его нет, — скрипт можно запускать сколько угодно раз (при каждом `make logs-up`).

Что создаётся:
  1. GELF UDP input на :12201 — сюда шлёт логи Docker (logging driver gelf).
  2. Pipeline-правило, которое разбирает JSON-строки логов (access log nginx,
     логи приложения) на отдельные поля: status, request_time, request_id...
     Без него вся строка — одно поле message, и искать по полям нельзя.

Только стандартная библиотека Python — без pip install и доступа в интернет.
"""

import base64
import json
import os
import sys
import time
import urllib.error
import urllib.request

API = os.environ.get("GRAYLOG_API", "http://graylog:9000/api")
USER = os.environ.get("GRAYLOG_USER", "admin")
PASSWORD = os.environ["GRAYLOG_PASSWORD"]

INPUT_TITLE = "GELF UDP (Docker)"
RULE_TITLE = "Разбор JSON-строк логов"
PIPELINE_TITLE = "Логи контейнеров"
# У потока «Default Stream» фиксированный id во всех установках Graylog —
# в него попадают все сообщения, если не настроены свои потоки
DEFAULT_STREAM_ID = "000000000000000000000001"

# Правило на языке pipeline-правил Graylog. Срабатывает только для строк,
# начинающихся с «{», остальные (текстовые логи postgres, служебные строки php-fpm)
# остаются как есть — их по-прежнему можно искать полнотекстово
RULE_SOURCE = f'''rule "{RULE_TITLE}"
when
  starts_with(to_string($message.message), "{{")
then
  let parsed = parse_json(to_string($message.message));
  set_fields(to_map(parsed));
end'''

PIPELINE_SOURCE = f'''pipeline "{PIPELINE_TITLE}"
stage 0 match either
  rule "{RULE_TITLE}";
end'''


def api(method, path, body=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(API + path, data=data, method=method)
    token = base64.b64encode(f"{USER}:{PASSWORD}".encode()).decode()
    req.add_header("Authorization", "Basic " + token)
    req.add_header("Content-Type", "application/json")
    req.add_header("Accept", "application/json")
    # Без этого заголовка Graylog отклоняет изменяющие запросы (защита от CSRF)
    req.add_header("X-Requested-By", "graylog-init")
    with urllib.request.urlopen(req, timeout=10) as resp:
        raw = resp.read()
        return json.loads(raw) if raw else None


def wait_for_graylog(timeout=300):
    # Graylog стартует долго (JVM, миграции, подключение к OpenSearch) —
    # healthcheck в compose это учитывает, но подстрахуемся и здесь
    # Ждём именно lifecycle=running: API может уже отвечать, пока узел ещё стартует.
    # (Не /system/lbstatus — он отдаёт text/plain, а мы просим JSON и получили бы 406)
    deadline = time.time() + timeout
    last_error = None
    while time.time() < deadline:
        try:
            if api("GET", "/system").get("lifecycle") == "running":
                return
        except (urllib.error.URLError, ConnectionError, TimeoutError) as e:
            last_error = e
        time.sleep(5)
    sys.exit(f"Graylog API не готов за {timeout} с: {last_error}")


def ensure_input():
    for item in api("GET", "/system/inputs")["inputs"]:
        if item["title"] == INPUT_TITLE:
            print(f"input «{INPUT_TITLE}» уже есть ({item['id']})")
            return
    created = api("POST", "/system/inputs", {
        "title": INPUT_TITLE,
        "type": "org.graylog2.inputs.gelf.udp.GELFUDPInput",
        # global — input запускается на всех узлах кластера (у нас узел один)
        "global": True,
        "configuration": {
            "bind_address": "0.0.0.0",
            "port": 12201,
            "recv_buffer_size": 262144,
            "decompress_size_limit": 8388608,
        },
    })
    print(f"input «{INPUT_TITLE}» создан ({created['id']})")


def ensure_rule():
    for rule in api("GET", "/system/pipelines/rule"):
        if rule["title"] == RULE_TITLE:
            print(f"правило «{RULE_TITLE}» уже есть")
            return
    api("POST", "/system/pipelines/rule", {
        "title": RULE_TITLE,
        "description": "Создано docker/graylog/init.py",
        "source": RULE_SOURCE,
    })
    print(f"правило «{RULE_TITLE}» создано")


def ensure_pipeline():
    pipeline_id = None
    for pipeline in api("GET", "/system/pipelines/pipeline"):
        if pipeline["title"] == PIPELINE_TITLE:
            pipeline_id = pipeline["id"]
            print(f"pipeline «{PIPELINE_TITLE}» уже есть")
    if pipeline_id is None:
        pipeline_id = api("POST", "/system/pipelines/pipeline", {
            "title": PIPELINE_TITLE,
            "description": "Создано docker/graylog/init.py",
            "source": PIPELINE_SOURCE,
        })["id"]
        print(f"pipeline «{PIPELINE_TITLE}» создан")

    # Pipeline работает, только когда подключён к потоку сообщений
    try:
        connected = api("GET", f"/system/pipelines/connections/{DEFAULT_STREAM_ID}")
    except urllib.error.HTTPError as e:
        if e.code != 404:
            raise
        connected = {}  # у потока ещё нет ни одного подключённого pipeline
    if pipeline_id in connected.get("pipeline_ids", []):
        print("pipeline уже подключён к Default Stream")
        return
    api("POST", "/system/pipelines/connections/to_stream", {
        "stream_id": DEFAULT_STREAM_ID,
        "pipeline_ids": sorted(set(connected.get("pipeline_ids", [])) | {pipeline_id}),
    })
    print("pipeline подключён к Default Stream")


def main():
    wait_for_graylog()
    ensure_input()
    ensure_rule()
    ensure_pipeline()
    print("Graylog настроен")


if __name__ == "__main__":
    try:
        main()
    except urllib.error.HTTPError as e:
        sys.exit(f"Graylog API: {e.code} {e.reason}: {e.read().decode(errors='replace')}")
