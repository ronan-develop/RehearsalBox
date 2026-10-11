<?php

declare(strict_types=1);

// Annonce « l'appli est ouverte » (#444) : un e-mail à chaque compte actif. À lancer À LA MAIN, une fois.
//   php bin/send-announcement.php                       simulation : compte les destinataires, n'envoie RIEN
//   php bin/send-announcement.php --test=moi@exemple.fr envoi d'essai à UNE adresse (sans mémoire)
//   php bin/send-announcement.php --send                envoi réel ; chaque compte servi est noté dans storage/announcement-sent.txt,
//                                                       relancer ne renvoie qu'aux comptes en échec ou ajoutés depuis
// Code de sortie : 0 = succès, 1 = au moins un échec d'envoi, 2 = option inconnue. Le bilan ne contient aucune adresse.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

use App\Account\Service\Announcement\AnnouncementService;

$options = getopt('', ['send', 'test:']);
foreach (array_slice($argv, 1) as $arg) {
    if ($arg !== '--send' && !str_starts_with($arg, '--test=')) {
        fwrite(STDERR, "Option inconnue. Voir l'en-tête de bin/send-announcement.php.\n");
        exit(2);
    }
}

$config = require __DIR__ . '/../config/config.php';
$service = (require __DIR__ . '/../config/services.php')($config)->get(AnnouncementService::class);

if (isset($options['test'])) {
    $report = $service->sendTest((string) $options['test']);
    echo $report->sent() === 1 ? "Essai envoyé.\n" : "Essai en échec (voir le journal).\n";
    exit($report->failed() > 0 ? 1 : 0);
}

if (!isset($options['send'])) {
    $report = $service->dryRun();
    printf("Simulation : %d destinataire(s) à servir, %d déjà servi(s). Rien n'a été envoyé.\n", $report->pending(), $report->alreadySent());
    exit(0);
}

$report = $service->sendToAll();
printf("%d envoyé(s), %d échec(s), %d déjà servi(s).\n", $report->sent(), $report->failed(), $report->alreadySent());
exit($report->failed() > 0 ? 1 : 0);
