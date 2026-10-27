<?php

declare(strict_types=1);

namespace App;

use Illuminate\Database\Capsule\Manager as Capsule;

final class Database
{
    private static ?Capsule $capsule = null;

    public static function boot(): \Illuminate\Database\Capsule\Manager
    {
        if (self::$capsule instanceof \Illuminate\Database\Capsule\Manager) {
            return self::$capsule;
        }

        $capsule = new \Illuminate\Database\Capsule\Manager();

        $capsule->addConnection([
            'driver'   => 'pgsql',
            'host'     => getenv('DB_HOST') ?: 'db',
            'port'     => getenv('DB_PORT') ?: '5432',
            'database' => getenv('DB_NAME') ?: 'webl',
            'username' => getenv('DB_USER') ?: 'webl',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset'  => 'utf8',
            'prefix'   => '',
            'schema'   => 'public',
            'options'  => [
                \PDO::ATTR_TIMEOUT => 3,
            ],
        ]);

        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$capsule = $capsule;

        return self::$capsule;
    }

    public static function connection(): \Illuminate\Database\ConnectionInterface
    {
        return self::boot()->getConnection();
    }
}