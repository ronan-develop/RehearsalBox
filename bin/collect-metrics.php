<?php

declare(strict_types=1);

// Collecte horaire des mesures (#195) : à lancer TOUTES LES HEURES par une tâche planifiée (cron).
//   Usage : php bin/collect-metrics.php
// Relève l'état du serveur (disque, base, sauvegarde, cron, version, charge), l'enregistre, puis purge les mesures trop
// anciennes (évènements 30 jours, agrégats et instantanés 90 jours). Idempotent. Ne contient aucune donnée personnelle.
// Journal : storage/logs/cron.log reçoit une ligne par passage.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

use App\Metrics\MetricsMaintenance;

$config = require __DIR__ . '/../config/config.php';
$container = (require __DIR__ . '/../config/services.php')($config);
$log = $container->get('logger.cron');

try {
    $purged = $container->get(MetricsMaintenance::class)->run();
} catch (Throwable $e) {
    $log->error('Collecte des mesures : échec', ['exception' => $e::class]);
    fwrite(STDERR, 'Collecte des mesures : échec (' . $e::class . ").\n");
    exit(1);
}

$log->info('Collecte des mesures : terminée', ['purged' => $purged]);
printf("Mesures collectées, %d ligne(s) purgée(s).\n", $purged);
