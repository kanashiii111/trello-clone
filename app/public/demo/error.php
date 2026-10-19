<?php

declare(strict_types=1);

/**
 * Демо для лекции: ошибка сервера.
 *
 *   /demo/error.php            — всегда 500
 *   /demo/error.php?rate=0.3   — 500 примерно в 30% случаев, иначе 200
 *
 * Частичный отказ (rate) ближе к жизни, чем «всё сломалось»: так видно,
 * зачем алерту нужна доля ошибок, а не их абсолютное число, и зачем `for:` —
 * короткий всплеск не должен будить дежурного.
 */

header('Content-Type: application/json; charset=utf-8');

$rate = (float) ($_GET['rate'] ?? 1);
$rate = max(0.0, min($rate, 1.0));

if (mt_rand() / mt_getrandmax() < $rate) {
    http_response_code(500);
    // error_log уходит в stderr php-fpm — пригодится, когда будем искать ошибку в логах
    error_log('demo/error.php: simulated failure');
    echo json_encode(['status' => 'fail', 'error' => 'simulated failure'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['status' => 'ok'], JSON_UNESCAPED_UNICODE);
