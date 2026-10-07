<?php

declare(strict_types=1);

// Collecte horaire des mesures (#195) : à lancer TOUTES LES HEURES par une tâche planifiée (cron).
//   Usage : php bin/collect-metrics.php
// Relève l'état du serveur (disque, base, sauvegarde, cron, version, charge), l'enregistre, puis purge les mesures trop
// anciennes (évènements 30 jours, agrégats et instantanés 90 jours). Idempotent. Ne contient aucune donnée personnelle.
// Alertes (#199) : si un seuil critique est franchi, un e-mail de synthèse part au propriétaire du tableau de bord (anti-bruit :
// pas avant plusieurs heures entre deux alertes d'un même type). Une panne d'envoi n'a aucun effet : la prochaine collecte réessaie.
// Journal : storage/logs/collect.log reçoit une ligne par passage (pas cron.log : sa date prouve que le cron des relances tourne).

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

use App\Metrics\Alert\MetricsAlerter;
use App\Metrics\Collection\MetricsMaintenance;

$config = require __DIR__ . '/../config/config.php';
$container = (require __DIR__ . '/../config/services.php')($config);
$log = $container->get('logger.collect');

try {
    $purged = $container->get(MetricsMaintenance::class)->run();
    $alerts = $container->get(MetricsAlerter::class)->run();
} catch (Throwable $e) {
    $log->error('Collecte des mesures : échec', ['exception' => $e::class]);
    fwrite(STDERR, 'Collecte des mesures : échec (' . $e::class . ").\n");
    exit(1);
}

$log->info('Collecte des mesures : terminée', ['purged' => $purged, 'alerts' => $alerts]);
printf("Mesures collectées, %d ligne(s) purgée(s), %d alerte(s) envoyée(s).\n", $purged, $alerts);
