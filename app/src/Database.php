<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    public static function connect(): PDO
    {
        $host = getenv('DB_HOST') ?: 'postgres';
        $port = getenv('DB_PORT') ?: '5432';
        $name = getenv('DB_NAME') ?: 'app';
        $user = getenv('DB_USER') ?: 'app';
        $password = getenv('DB_PASSWORD') ?: '';

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name);

        // application_name видно в pg_stat_activity и в логах PostgreSQL (%a в log_line_prefix).
        // Кладём туда request_id от nginx: медленный запрос из slow-log сразу связывается
        // с конкретным HTTP-запросом. Имя ограничено 63 символами — id nginx укладывается
        $requestId = Log::requestId();
        if ($requestId !== '' && preg_match('/^[A-Za-z0-9-]{1,63}$/', $requestId) === 1) {
            $dsn .= ';application_name=' . $requestId;
        }

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Не ждём дольше 3 секунд, если БД недоступна
            PDO::ATTR_TIMEOUT => 3,
        ]);
    }
}
