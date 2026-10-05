<?php

declare(strict_types=1);

// Relances de la messagerie (#180) : à lancer TOUTES LES HEURES par une tâche planifiée (cron du cPanel).
//   Usage : php bin/send-reminders.php
// Envoie, à l'adresse de contact d'un groupe, UNE relance quand un message de l'autre côté est resté sans lecture plus de
// 24 h par ce groupe (jamais le contenu du message), seulement dans la plage de jour (heure locale) ; hors plage, ne fait
// rien et les relances dues partent le matin. Idempotent : peut être relancé sans doublon.
// Code de sortie : 0 = succès (même s'il n'y a rien à envoyer), 1 = au moins un échec d'envoi (réessayé à la prochaine
// exécution). Le bilan ne contient ni adresse ni contenu.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

use App\Service\ConversationReminderService;

$config = require __DIR__ . '/../config/config.php';
$container = (require __DIR__ . '/../config/services.php')($config);

$report = $container->get(ConversationReminderService::class)->sendDue();

if ($report->outsideWindow()) {
    echo "Relances : hors de la plage de jour, rien à faire.\n";
    exit(0);
}

printf("Relances : %d envoyée(s), %d échec(s), %d ignorée(s).\n", $report->sent(), $report->failed(), $report->skipped());
exit($report->failed() > 0 ? 1 : 0);
