<?php

declare(strict_types=1);

/**
 * Автоматическая инструментация всех PHP-скриптов (RED-метрики: Rate, Errors, Duration).
 *
 * Файл подключается через auto_prepend_file (docker/php/monitoring.ini) — PHP выполняет
 * его перед каждым скриптом. Так метрики собираются для всех эндпоинтов сразу, без правки
 * каждого файла и без риска забыть новый. register_shutdown_function срабатывает в конце
 * запроса — даже после exit, редиректа и фатальной ошибки.
 *
 * Включается только вместе с мониторингом: monitoring.ini монтируется лишь в
 * compose.monitoring.yaml, а без APP_METRICS_ENABLED=1 файл сразу выходит.
 */

use App\Metrics;

// Сам эндпоинт метрик не считаем: иначе каждый скрейп Prometheus раз в 15 секунд
// выглядел бы как пользовательский трафик и искажал RPS и latency
if (($_SERVER['SCRIPT_NAME'] ?? '') === '/metrics.php') {
    return;
}

// Без vendor/ (ещё не сделали `make composer`) инструментация молча выключается,
// а не роняет каждый запрос фатальной ошибкой
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    return;
}
require_once $autoload;

if (!Metrics::enabled()) {
    return;
}

// Время считаем от старта PHP-скрипта. Важно понимать, что сюда НЕ входит ожидание
// в очереди php-fpm (listen queue), когда все воркеры заняты: запрос висит в очереди,
// а PHP о нём ещё не знает. Это время видно только снаружи — в nginx
// ($upstream_response_time) и в black-box пробах. Хороший повод мерить с нескольких сторон
$appRequestStart = hrtime(true);

register_shutdown_function(static function () use ($appRequestStart): void {
    $duration = (hrtime(true) - $appRequestStart) / 1e9;

    // !!! КАРДИНАЛЬНОСТЬ. Каждое уникальное сочетание значений меток — отдельный
    // временной ряд в Prometheus, со своей памятью и диском. Метка с неограниченным
    // числом значений (полный URL, query string, id записи, user id, текст ошибки)
    // за день превращает 10 рядов в миллион и кладёт Prometheus.
    // Поэтому route — это путь PHP-скрипта, а не REQUEST_URI: /index.php, /demo/slow.php.
    // Запросы вида /notes/123?page=2 через try_files приходят в /index.php — и их
    // число значений ограничено числом файлов в public/, а не фантазией клиентов
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $route = preg_match('#^/[A-Za-z0-9_\-/]+\.php$#', $script) === 1 ? $script : 'other';

    // Метод тоже приходит от клиента: `curl -X FOOBAR1`, `-X FOOBAR2`... — и вот новые ряды.
    // Всё нестандартное складываем в OTHER
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
    if (!in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
        $method = 'OTHER';
    }

    $status = http_response_code();
    $status = $status === false ? 200 : $status;

    // Фатальная ошибка = неуспешный запрос. В проде (display_errors = Off) PHP сам
    // выставил бы 500, а в нашем дев-режиме клиент получит 200 с текстом ошибки —
    // метрика должна честно показать сбой в любом случае
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $status = 500;
    }

    Metrics::safely(static function () use ($method, $route, $status, $duration): void {
        $registry = Metrics::registry();

        // status — точный код (200, 302, 500...), а не класс 2xx/5xx: значений немного,
        // а долю ошибок удобно считать как status=~"5.."
        $registry
            ->getOrRegisterCounter('', 'http_requests_total', 'Количество HTTP-запросов, обработанных PHP', ['method', 'route', 'status'])
            ->inc([$method, $route, (string) $status]);

        // Гистограмма без status: каждое сочетание меток — это сразу 17 рядов
        // (14 бакетов + «+Inf» + _sum + _count),
        // поэтому в гистограмму кладём только то, по чему действительно будем резать latency.
        // Бакеты покрывают диапазон от «быстрой страницы» (5 мс) до лимита demo/slow.php (10 с) —
        // перцентиль внутри бакета оценивается линейно, так что границы должны быть
        // гуще там, где проходит порог SLO
        $registry
            ->getOrRegisterHistogram(
                '',
                'http_request_duration_seconds',
                'Время обработки запроса в PHP, секунды',
                ['method', 'route'],
                [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 0.75, 1.0, 1.5, 2.5, 5.0, 7.5, 10.0],
            )
            ->observe($duration, [$method, $route]);
    });
});
