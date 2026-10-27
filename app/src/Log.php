<?php

declare(strict_types=1);

namespace App;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

final class Log
{
    private static Logger $logger;

    private static function getLogger(): Logger {
        if (!isset(self::$logger)) {
            $logger = new Logger('trello-clone');

            $logPath = __DIR__ . '/../out/logs';

            $handlerStderr = new StreamHandler('php://stderr');
            $handlerStderr->setFormatter(new JsonFormatter());

            $handlerFile = new RotatingFileHandler(
                $logPath . '/app.log',
                0,
                Logger::INFO,
                true,
                0664,
                false,
                'Y-m-d'
            );
            $handlerFile->setFormatter(new JsonFormatter());

            $handlerFile->pushProcessor(function (\Monolog\LogRecord $record): \Monolog\LogRecord {
                $record->extra['request_id'] = (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
                $record->extra['route'] = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
                return $record;
            });

            $logger->pushHandler($handlerStderr);
            $logger->pushHandler($handlerFile);
            self::$logger = $logger;
        }
        return self::$logger;
    }

    public static function requestId(): string {
        return (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
    }

    public static function info(string $message, array $context = []): void {
        self::getLogger()->info($message, $context);
    }

    public static function error(string $message, array $context = []): void {
        self::getLogger()->error($message, $context);
    }

    public static function debug(string $message, array $context = []): void {
        self::getLogger()->debug($message, $context);
    }

    public static function warning(string $message, array $context = []): void {
        self::getLogger()->warning($message, $context);
    }

    public static function critical(string $message, array $context = []): void {
        self::getLogger()->critical($message, $context);
    }

    public static function alert(string $message, array $context = []): void {
        self::getLogger()->alert($message, $context);
    }

    public static function emergency(string $message, array $context = []): void {
        self::getLogger()->emergency($message, $context);
    }
}
