<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;

$dbHost = getenv('DB_HOST') ?: 'localhost';

$capsule = new Capsule();

$capsule->addConnection([
    'driver'   => 'pgsql',
    'host'     => $dbHost,
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

$filesystem = new Filesystem();

$resolver = $capsule->getDatabaseManager();
$repository = new DatabaseMigrationRepository($resolver, 'migrations');
$migrator = new \Illuminate\Database\Migrations\Migrator($repository, $resolver, $filesystem);

if (!$repository->repositoryExists()) {
    echo "Creating migrations table...\n";
    $repository->createRepository();
    echo "Migrations table created.\n";
}

$path = __DIR__ . '/../database/migrations';

echo "Running migrations from $path...\n";
$migrator->run($path);

echo "Migrations completed.\n";
