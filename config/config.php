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
