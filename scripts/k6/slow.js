// Сценарий 3: медленные запросы к БД.
//
// Несколько пользователей запрашивают /demo/slow.php?seconds=1.5 — PostgreSQL
// выполняет pg_sleep(1.5). Один и тот же запрос виден сразу в нескольких местах:
//   - Grafana: p95 по route /demo/slow.php, топ запросов pg_stat_statements;
//   - через ~3.5 минуты — алерт HighLatencyP95 (for: 3m) в Mailpit;
//   - Graylog: tag:*postgres* AND duration — slow-log (log_min_duration_statement = 500 мс),
//     а по request_id из лога nginx находится вся цепочка запроса.
// После нагрузки make demo-slow печатает топ из pg_stat_statements.
//
// Запуск: make demo-slow
import http from 'k6/http';
import { check } from 'k6';

const BASE = __ENV.BASE_URL || 'http://nginx';

export const options = {
  vus: Number(__ENV.VUS || 3),
  duration: __ENV.DURATION || '4m',
  summaryTrendStats: ['avg', 'p(50)', 'p(95)', 'p(99)', 'max'],
};

export default function () {
  const res = http.get(`${BASE}/demo/slow.php?seconds=1.5`, { tags: { name: 'slow.php' } });
  check(res, { 'status 200': (r) => r.status === 200 });
}
