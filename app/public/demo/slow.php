<?php

declare(strict_types=1);

/**
 * Демо для лекции: медленный запрос к БД.
 *
 *   /demo/slow.php?seconds=2
 *
 * Тормозит именно PostgreSQL (pg_sleep), а не PHP (sleep) — так один запрос виден
 * сразу в трёх местах: в latency приложения (http_request_duration_seconds),
 * в slow-log PostgreSQL (log_min_duration_statement = 500 мс) и в pg_stat_statements.
 * Пока запрос висит, он держит воркер php-fpm и соединение с БД — пачка таких
 * запросов исчерпает пул.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Log;

// Верхний лимит обязателен: иначе ?seconds=100000 — это готовый способ занять
// все воркеры и соединения одним curl'ом. Даже в учебном коде
const MAX_SECONDS = 10.0;

$seconds = (float) ($_GET['seconds'] ?? 1);
$seconds = max(0.0, min($seconds, MAX_SECONDS));

header('Content-Type: application/json; charset=utf-8');

try {
    $stmt = Database::connect()->prepare('SELECT pg_sleep(:seconds)');
    $stmt->execute(['seconds' => $seconds]);
} catch (\Throwable $e) {
    Log::error('slow query failed', ['error' => $e->getMessage()]);
    http_response_code(503);
    echo json_encode(['status' => 'fail', 'error' => 'database unavailable'], JSON_UNESCAPED_UNICODE);
    exit;
}

Log::info('slow query finished', ['seconds' => $seconds]);

echo json_encode(['status' => 'ok', 'slept_seconds' => $seconds], JSON_UNESCAPED_UNICODE);
