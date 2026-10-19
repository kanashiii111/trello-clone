// Сценарий 2: нагрузка упирается в пул php-fpm.
//
// 20 виртуальных пользователей параллельно бьют в /demo/cpu.php (300 мс CPU на запрос),
// а воркеров php-fpm всего 5 (pm.max_children в www.conf). Лишние запросы ждут в очереди:
// растут phpfpm_listen_queue и время ответа глазами клиента (p95/p99 в итоге k6 и
// probe_duration_seconds), а через 2 минуты срабатывает PhpFpmPoolSaturated.
//
// Обратите внимание: http_request_duration_seconds из PHP почти не изменится (~0.3 с) —
// время в очереди PHP не видит. Поэтому латентность меряют с нескольких сторон.
//
// Запуск: make demo-load   (VUS=40 DURATION=5m make demo-load — подкрутить)
import http from 'k6/http';
import { check } from 'k6';

const BASE = __ENV.BASE_URL || 'http://nginx';

export const options = {
  scenarios: {
    load: {
      executor: 'ramping-vus',
      stages: [
        { duration: '30s', target: Number(__ENV.VUS || 20) }, // плавный разгон
        { duration: __ENV.DURATION || '4m', target: Number(__ENV.VUS || 20) },
        { duration: '10s', target: 0 },
      ],
    },
  },
  // В итоговой таблице — перцентили, которые обсуждаем на лекции
  summaryTrendStats: ['avg', 'p(50)', 'p(95)', 'p(99)', 'max'],
};

export default function () {
  const res = http.get(`${BASE}/demo/cpu.php?ms=300`, { tags: { name: 'cpu.php' } });
  check(res, { 'status 200': (r) => r.status === 200 });
}
