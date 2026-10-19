// Сценарий 4: поток ошибок и роль `for:` в алерте HighErrorRate.
//
// Фон — обычный трафик на главную. Поверх него две фазы ошибок:
//   1. всплеск: 30 секунд одних ошибок. Условие алерта выполняется, алерт уходит
//      в pending (Prometheus → Alerts), но погасает раньше, чем истекут for: 2m —
//      письма НЕ будет. Короткий сбой не будит дежурного;
//   2. пауза 90 секунд — алерт возвращается в inactive;
//   3. устойчивые ошибки: 30% запросов к /demo/error.php падают 4 минуты. Через
//      ~2.5 минуты — firing и письмо в Mailpit, после окончания — RESOLVED.
//
// Запуск: make demo-errors
import http from 'k6/http';
import { sleep } from 'k6';

const BASE = __ENV.BASE_URL || 'http://nginx';

export const options = {
  scenarios: {
    // Фоновый «нормальный» трафик всё время сценария — без него доля ошибок
    // была бы 100% и не отражала бы реальность
    background: {
      executor: 'constant-arrival-rate',
      exec: 'background',
      rate: 3, timeUnit: '1s', duration: '6m10s',
      preAllocatedVUs: 5,
    },
    burst: {
      executor: 'constant-arrival-rate',
      exec: 'burst',
      rate: 5, timeUnit: '1s', duration: '30s',
      preAllocatedVUs: 5,
    },
    sustained: {
      executor: 'constant-arrival-rate',
      exec: 'sustained',
      rate: 5, timeUnit: '1s', duration: '4m',
      startTime: '2m', // 30 с всплеска + 90 с паузы
      preAllocatedVUs: 5,
    },
  },
};

export function background() {
  http.get(`${BASE}/`, { tags: { name: 'index' } });
}

export function burst() {
  http.get(`${BASE}/demo/error.php`, { tags: { name: 'error.php burst' } });
}

export function sustained() {
  http.get(`${BASE}/demo/error.php?rate=0.3`, { tags: { name: 'error.php 30%' } });
}
