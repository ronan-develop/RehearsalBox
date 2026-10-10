<?php

declare(strict_types=1);

$defaults = [
    'debug' => false,
    'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'rehearsalbox',
        'user' => 'root',
        'password' => '',
    ],
    'mailer' => [
        'dsn' => 'smtp://127.0.0.1:1025',
        'from' => 'no-reply@rehearsalbox.local',
    ],
    'app' => [
        // URL publique, utilisée pour les liens envoyés par e-mail (jamais dérivée de la requête).
        'base_url' => 'http://localhost:8001',
        // Fuseau d'affichage des heures et des jours (les dates sont stockées en UTC).
        'timezone' => 'Europe/Paris',
    ],
    'logging' => [
        // Hors de la racine web (storage/ est un lien vers shared/storage en production). Jamais de donnée personnelle dedans.
        'path' => __DIR__ . '/../storage/logs/app.log',
        'cron_path' => __DIR__ . '/../storage/logs/cron.log',
        // Journal de la collecte des mesures : distinct du précédent, dont la date de modification prouve que le cron des relances tourne.
        'collect_path' => __DIR__ . '/../storage/logs/collect.log',
        // debug, info, notice, warning, error, critical, alert, emergency
        'level' => 'warning',
        'max_bytes' => 1_000_000,
        'keep' => 5,
    ],
    'metrics' => [
        // Secret du serveur pour l'empreinte des adresses IP (config.local.php). Vide : aucune adresse n'est conservée, même sous forme d'empreinte.
        'secret' => '',
        // Dossier des sauvegardes de la base (pour l'âge de la dernière), null si inconnu.
        'backup_dir' => null,
        // Seul compte autorisé à voir le tableau de bord (config.local.php). Vide : la page n'existe pour personne.
        'viewer_email' => '',
        // Surcharge des seuils vert / orange / rouge des cartes d'état : [orange, rouge] par mesure (voir Report\Thresholds::DEFAULTS).
        'thresholds' => [],
        // Seuils de détection des anomalies (voir Report\Security\AnomalyDetector) : scanner_hits, burst_events, burst_minutes, stuffing_failures, stuffing_minutes.
        'anomalies' => [],
        // Alertes par e-mail au propriétaire du tableau de bord (viewer_email) : un résumé, pas avant min_gap_hours entre deux alertes d'un même type.
        'alerts' => ['enabled' => true, 'min_gap_hours' => 12],
    ],
    'messages' => [
        // Clés de chiffrement du texte de la messagerie (#171) : hors dépôt et hors base, créé UNE fois par `php bin/message-keys.php init`.
        // En production, config/message-keys.json est un lien vers shared/message-keys.json (jamais régénéré par un déploiement).
        'key_file' => __DIR__ . '/message-keys.json',
    ],
    'storage' => [
        'group_documents_path' => __DIR__ . '/../storage/group-documents',
    ],
];

$localConfigFile = __DIR__ . '/config.local.php';
if (is_file($localConfigFile)) {
    $local = require $localConfigFile;

    return array_replace_recursive($defaults, $local);
}

return $defaults;
