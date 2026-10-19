<?php

declare(strict_types=1);

namespace App;

use Prometheus\CollectorRegistry;
use Prometheus\Storage\APCng;

/**
 * Единая точка доступа к метрикам приложения.
 *
 * Метрики включаются только вместе со стеком мониторинга (APP_METRICS_ENABLED=1
 * задаётся в compose.monitoring.yaml). В базовом окружении все методы — no-op,
 * поэтому код приложения (например, index.php) можно инструментировать, не думая,
 * запущен ли сейчас Prometheus.
 */
final class Metrics
{
    private static ?CollectorRegistry $registry = null;

    public static function enabled(): bool
    {
        return getenv('APP_METRICS_ENABLED') === '1'
            && extension_loaded('apcu')
            && apcu_enabled();
    }

    public static function registry(): CollectorRegistry
    {
        // APCng — хранилище в общей памяти APCu, общей для всех воркеров php-fpm
        // (зачем оно вообще нужно — см. комментарий в docker/php/Dockerfile).
        // Точность 6 знаков: суммы гистограмм хранятся как целые числа, и при
        // точности по умолчанию (3, т.е. миллисекунды) быстрые запросы округлялись бы в ноль.
        // Стандартную метрику php_info отключаем — она бы перезаписывалась на каждый запрос
        return self::$registry ??= new CollectorRegistry(new APCng(decimalPrecision: 6), false);
    }

    /**
     * Бизнес-метрика: сколько записей создали пользователи.
     * Технические метрики (RPS, ошибки, latency) говорят, жив ли сервис; бизнес-метрики —
     * делает ли он то, ради чего существует. «Ошибок нет, но записи перестали создаваться» —
     * поломка, которую видно только так.
     */
    public static function noteCreated(): void
    {
        self::safely(static function (): void {
            self::registry()
                ->getOrRegisterCounter('app', 'notes_created_total', 'Количество созданных записей')
                ->inc();
        });
    }

    /**
     * Метрики не должны ломать приложение: если хранилище недоступно или переполнено,
     * запрос пользователя всё равно должен отработать — теряем только точку на графике.
     */
    public static function safely(callable $fn): void
    {
        if (!self::enabled()) {
            return;
        }

        try {
            $fn();
        } catch (\Throwable $e) {
            error_log('metrics: ' . $e->getMessage());
        }
    }
}
