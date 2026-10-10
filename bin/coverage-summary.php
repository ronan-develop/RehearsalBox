<?php

declare(strict_types=1);

// Résumé de la couverture de tests (#129), en Markdown : php bin/coverage-summary.php [rapport Clover] [--min=<pourcentage>]
// Défaut : build/coverage/clover.xml, produit par `phpunit --coverage-clover=build/coverage/clover.xml` (extension PCOV requise).
// Avec --min (#365) : sortie 1 si la couverture TOTALE est inférieure (le résumé est affiché dans tous les cas). Le seuil est fixé
// à UN seul endroit, l'étape « Coverage summary » de .github/workflows/ci.yml. Sans --min : on mesure seulement.

require __DIR__ . '/../vendor/autoload.php';

use App\Tools\CoverageSummary;

$report = __DIR__ . '/../build/coverage/clover.xml';
$minimum = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--min=')) {
        try {
            $minimum = CoverageSummary::parseMinimum(substr($argument, 6));
        } catch (InvalidArgumentException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }
    } else {
        $report = $argument;
    }
}

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

$failure = $minimum === null ? null : $tool->minimumFailure($summary, $minimum);
if ($failure !== null) {
    fwrite(STDERR, $failure . "\n");
    exit(1);
}
