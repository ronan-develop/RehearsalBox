<?php

declare(strict_types=1);

// État des lieux (#263), LECTURE SEULE : liste les créneaux fixes actifs qui se chevauchent (le local est exclusif depuis #263).
//   Usage : php bin/check-slot-overlaps.php
// Affiche le jour, les heures, l'identifiant du créneau et le nom du groupe ; aucune écriture, aucune donnée personnelle.
// Code de sortie : 0 = aucun chevauchement, 1 = au moins un.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

use App\Repository\Contract\GroupRepositoryInterface;
use App\Repository\Contract\RecurringSlotRepositoryInterface;
use App\Service\SlotOverlapAudit;

$config = require __DIR__ . '/../config/config.php';
$container = (require __DIR__ . '/../config/services.php')($config);
$groups = $container->get(GroupRepositoryInterface::class);

$describe = static function (App\Entity\RecurringSlot $slot) use ($groups): string {
    return sprintf('#%d %s %s–%s (%s)', $slot->id(), $slot->weekday()->name, substr($slot->startTime(), 0, 5), substr($slot->endTime(), 0, 5), $groups->findById($slot->groupId())?->name() ?? 'groupe inconnu');
};

$pairs = (new SlotOverlapAudit())->pairs($container->get(RecurringSlotRepositoryInterface::class)->findAllActive());
foreach ($pairs as [$first, $second]) {
    echo $describe($first), '  chevauche  ', $describe($second), "\n";
}
printf("%d chevauchement(s).\n", count($pairs));

exit($pairs === [] ? 0 : 1);
