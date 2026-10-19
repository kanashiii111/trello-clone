<?php

declare(strict_types=1);

/**
 * Демо для лекции: нагрузка на CPU воркера.
 *
 *   /demo/cpu.php?ms=300
 *
 * В отличие от slow.php, здесь воркер php-fpm не ждёт, а реально жжёт процессор.
 * Под параллельной нагрузкой все pm.max_children (5 в www.conf) быстро заняты,
 * новые запросы встают в очередь (listen queue) — растут p99 и phpfpm_max_children_reached.
 */

// Верхний лимит — та же защита, что и в slow.php
const MAX_MS = 2000;

$ms = (int) ($_GET['ms'] ?? 200);
$ms = max(0, min($ms, MAX_MS));

$deadline = hrtime(true) + $ms * 1_000_000;
$iterations = 0;
$hash = '';

while (hrtime(true) < $deadline) {
    $hash = hash('sha256', $hash . $iterations);
    $iterations++;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['status' => 'ok', 'burned_ms' => $ms, 'iterations' => $iterations], JSON_UNESCAPED_UNICODE);
