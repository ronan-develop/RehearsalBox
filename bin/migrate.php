<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Database\ConnectionFactory;
use App\Migration\Migrator;

$config = require __DIR__ . '/../config/config.php';

$pdo = (new ConnectionFactory($config['db']))->create();

$migrator = new Migrator($pdo, __DIR__ . '/../database/migrations');
$applied = $migrator->run();

if ($applied === []) {
    echo "Aucune migration à appliquer.\n";
    exit(0);
}

foreach ($applied as $migration) {
    echo "Appliquée : {$migration}\n";
}
