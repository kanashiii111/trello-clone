<?php

declare(strict_types=1);

namespace App;

/**
 * Минимальный структурный логгер приложения.
 *
 * Пишет одну JSON-строку на событие в stderr php-fpm, а оттуда — в `docker compose logs`
 * и (под compose.logging.yaml) в Graylog. JSON, а не свободный текст, — чтобы Graylog
 * разобрал строку на поля и можно было искать по request_id, level, route.
 *
 * В каждую запись попадает request_id из nginx (заголовок X-Request-Id ответа):
 * по нему одна и та же история запроса находится в access log nginx, в логах
 * приложения и в slow-log PostgreSQL.
 *
 * В проде для этого берут Monolog — здесь достаточно десятка строк.
 */
final class Log
{
    public static function requestId(): string
    {
        return (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $record = [
            'time' => date(DATE_ATOM),
            // Не «level»: в GELF уже есть числовое поле level (уровень syslog), и строка
            // «error» в том же поле сломала бы индексацию в Graylog/OpenSearch
            'log_level' => $level,
            'message' => $message,
            'request_id' => self::requestId(),
            'route' => (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
        ] + $context;

        // Прямо в stderr, а не через error_log(): error_log() добавил бы свой префикс
        // («PHP message: ...»), и строка перестала бы быть валидным JSON.
        // Выход воркеров попадает в лог контейнера благодаря catch_workers_output
        // в php-fpm.d/docker.conf официального образа
        file_put_contents('php://stderr', json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
