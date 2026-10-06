<?php

declare(strict_types=1);

namespace App\Tests\Tools;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #298 : le coût de bcrypt de production (≈ 190 ms par hachage) dominait la durée de la suite. Les tests fabriquent leurs mots de
 * passe avec un coût minimal ; seul NativePasswordHasherTest garde le vrai coût, pour protéger la force du hacheur.
 */
final class TestSuiteSpeedTest extends TestCase
{
    #[Test]
    public function testNoTestHashesAPasswordWithTheProductionCost(): void
    {
        $offenders = [];
        $forbidden = 'PASSWORD_' . 'DEFAULT';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/..', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!str_ends_with((string) $file, 'Test.php') && !str_ends_with((string) $file, 'World.php') && !str_ends_with((string) $file, 'Scenario.php')) {
                continue;
            }
            if (basename((string) $file) === 'TestSuiteSpeedTest.php' || basename((string) $file) === 'NativePasswordHasherTest.php') {
                continue;
            }
            if (str_contains((string) file_get_contents((string) $file), $forbidden)) {
                $offenders[] = substr((string) $file, strlen(__DIR__ . '/../'));
            }
        }

        self::assertSame([], $offenders, 'utiliser FastPasswordHasher (ou bcrypt au coût 4) dans les tests');
    }
}
