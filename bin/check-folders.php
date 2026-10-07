<?php

declare(strict_types=1);

// Contrôle du nombre de classes par dossier de src/ et de tests/ (CI et local) : php bin/check-folders.php (#287)
// Les exceptions sont des dossiers déjà trop pleins, figés à leur effectif ; les vider en déplaçant par domaine (cf. .claude/audit-architecture.md).

require __DIR__ . '/../vendor/autoload.php';

use App\Tools\FolderBudget;

$exceptions = require __DIR__ . '/../config/folder-budget-exceptions.php';

$budget = new FolderBudget(maxClasses: 12, exceptions: $exceptions);
// src/ et tests/ : les tests suivent le même rangement par domaine (#287). Les exceptions ne portent que sur src/.
$problems = [
    ...$budget->check(__DIR__ . '/../src'),
    ...array_map(static fn (string $problem): string => 'tests/' . $problem, (new FolderBudget(maxClasses: 12))->check(__DIR__ . '/../tests')),
];

if ($problems === []) {
    echo "Plafond de classes par dossier respecté.\n";
    exit(0);
}

fwrite(STDERR, implode("\n", $problems) . "\n");
exit(1);
