<?php

declare(strict_types=1);

// Résumé de la couverture de tests (#129), en Markdown : php bin/coverage-summary.php [rapport Clover]
// Défaut : build/coverage/clover.xml, produit par `phpunit --coverage-clover=build/coverage/clover.xml` (extension PCOV requise).
// Aucun seuil : on mesure d'abord (sortie 0 tant que le rapport est lisible).

require __DIR__ . '/../vendor/autoload.php';

use App\Tools\CoverageSummary;

$report = $argv[1] ?? __DIR__ . '/../build/coverage/clover.xml';
if (!is_file($report)) {
    fwrite(STDERR, "Rapport de couverture introuvable : {$report}\nLancer : ./vendor/bin/phpunit --coverage-clover=build/coverage/clover.xml (PCOV requis)\n");
    exit(1);
}

$tool = new CoverageSummary();

try {
    $summary = $tool->fromClover((string) file_get_contents($report), (string) realpath(__DIR__ . '/../src'));
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo $tool->toMarkdown($summary);
