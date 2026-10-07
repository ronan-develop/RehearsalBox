<?php

declare(strict_types=1);

// Contrôle du nombre de classes par dossier de src/ (CI et local) : php bin/check-folders.php (#287)
// Les exceptions sont des dossiers déjà trop pleins, figés à leur effectif ; les vider en déplaçant par domaine (cf. .claude/audit-architecture.md).

require __DIR__ . '/../vendor/autoload.php';

use App\Tools\FolderBudget;

$exceptions = require __DIR__ . '/../config/folder-budget-exceptions.php';

$problems = (new FolderBudget(maxClasses: 12, exceptions: $exceptions))->check(__DIR__ . '/../src');

if ($problems === []) {
    echo "Plafond de classes par dossier respecté.\n";
    exit(0);
}

fwrite(STDERR, implode("\n", $problems) . "\n");
exit(1);
