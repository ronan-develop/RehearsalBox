<?php

declare(strict_types=1);

namespace App\Tests\Tools;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #433 : l'analyse de teinte signale bien une valeur de la requête qui arrive dans une requête SQL, du HTML ou une commande. */
final class PsalmTaintTest extends TestCase
{
    #[Test]
    public function testPlantedTaintedFlowsAreDetected(): void
    {
        $root = dirname(__DIR__, 2);
        $command = sprintf(
            '%s %s --config=%s --taint-analysis --no-cache --no-progress --output-format=json 2>/dev/null',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root . '/vendor/bin/psalm'),
            escapeshellarg($root . '/tests/fixtures/taint/psalm.xml'),
        );

        $issues = json_decode((string) shell_exec($command), true);

        self::assertIsArray($issues, 'Psalm n\'a pas répondu en JSON');
        $types = array_values(array_unique(array_column($issues, 'type')));
        foreach (['TaintedSql', 'TaintedHtml', 'TaintedShell'] as $expected) {
            self::assertContains($expected, $types, "{$expected} doit être détecté (Request::body/query sont des sources, PDO un puits)");
        }
    }
}
