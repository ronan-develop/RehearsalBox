<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Migration\Migrator;

$config = require __DIR__ . '/../config/config.php';
$db = $config['db'];

$pdo = new PDO(
    "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
    $db['user'],
    $db['password'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$migrator = new Migrator($pdo, __DIR__ . '/../database/migrations');
$applied = $migrator->run();

if ($applied === []) {
    echo "Aucune migration à appliquer.\n";
    exit(0);
}

foreach ($applied as $migration) {
    echo "Appliquée : {$migration}\n";
}
