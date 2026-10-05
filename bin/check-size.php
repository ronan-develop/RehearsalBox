<?php

declare(strict_types=1);

// Contrôle du budget de taille des classes de src/ (CI et local) : php bin/check-size.php
// Les exceptions sont des classes déjà trop grosses, figées à leur taille actuelle ; les retirer en les découpant.

require __DIR__ . '/../vendor/autoload.php';

use App\Tools\SizeBudget;

$exceptions = require __DIR__ . '/../config/size-budget-exceptions.php';

$problems = (new SizeBudget(maxLines: 250, maxPublicMethods: 10, exceptions: $exceptions))->check(__DIR__ . '/../src');

if ($problems === []) {
    echo "Budget de taille respecté.\n";
    exit(0);
}

fwrite(STDERR, implode("\n", $problems) . "\n");
exit(1);
