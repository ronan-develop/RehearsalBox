<?php

declare(strict_types=1);

// Relances de la messagerie (#180, #178) : à lancer TOUTES LES HEURES par une tâche planifiée (cron).
//   Usage : php bin/send-reminders.php
// 1. Relance de GROUPE (#180) : à l'adresse de contact d'un groupe, quand un message de l'autre côté est resté sans lecture
//    plus de 24 h par ce groupe.
// 2. Relance de MENTION (#178) : à l'adresse du compte d'une personne taguée qui n'a pas lu la conversation 24 h après l'e-mail
//    de mention (une seule relance par e-mail de mention).
// 3. Oubli des anciennes versions de messages corrigés (#225) : au-delà de 30 jours, elles sont supprimées.
// Jamais le contenu d'un message. Seulement dans la plage de jour (heure locale) ; hors plage, ne fait rien et les relances
// dues partent le matin. Idempotent : peut être relancé sans doublon.
// Journal : storage/logs/cron.log reçoit un bilan à chaque passage (niveau « info »), même quand il n'y a rien à envoyer.
// Code de sortie : 0 = succès (même s'il n'y a rien à envoyer), 1 = au moins un échec d'envoi (réessayé à la prochaine
// exécution). Le bilan ne contient ni adresse ni contenu.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

use App\Messaging\Notification\ConversationReminderService;
use App\Messaging\Notification\MentionReminderService;
use App\Messaging\Service\MessageVersionPurge;

$config = require __DIR__ . '/../config/config.php';
$container = (require __DIR__ . '/../config/services.php')($config);

$log = $container->get('logger.cron');
$failed = 0;
foreach ([
    'Relances' => ConversationReminderService::class,
    'Relances de mention' => MentionReminderService::class,
] as $label => $service) {
    $report = $container->get($service)->sendDue();

    if ($report->outsideWindow()) {
        echo "{$label} : hors de la plage de jour, rien à faire.\n";
        $log->info($label . ' : hors de la plage de jour');
        continue;
    }

    printf("%s : %d envoyée(s), %d échec(s), %d ignorée(s).\n", $label, $report->sent(), $report->failed(), $report->skipped());
    $log->{$report->failed() > 0 ? 'error' : 'info'}($label . ' : bilan', ['sent' => $report->sent(), 'failed' => $report->failed(), 'skipped' => $report->skipped()]);
    $failed += $report->failed();
}

$purged = $container->get(MessageVersionPurge::class)->purge();
printf("Anciennes versions de messages : %d supprimée(s).\n", $purged);
$log->info('Anciennes versions de messages : purge', ['deleted' => $purged]);
// Une ligne à CHAQUE passage, même à vide : c'est la preuve que le cron tourne (#193).
$log->info('Passage du cron terminé', ['failed' => $failed]);

exit($failed > 0 ? 1 : 0);
