<?php

declare(strict_types=1);

namespace App\Tests\Deploy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Ce que bin/deploy.sh envoie en production (#220). */
final class DeployArchiveTest extends TestCase
{
    private function script(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../bin/deploy.sh');
    }

    #[Test]
    public function testTheDestructiveSeedIsNeverShippedToProduction(): void
    {
        self::assertMatchesRegularExpression(
            '/git archive[^\n]*database[^\n]*\':\(exclude\)database\/seed\.php\'/',
            $this->script(),
            'le seed vide la base et recrée des comptes au mot de passe connu : il est exclu de l\'archive',
        );
    }

    #[Test]
    public function testTheArchiveStillCarriesTheMigrationsAndTheApplication(): void
    {
        preg_match('/git archive[^\n]*/', $this->script(), $line);

        foreach (['bin', 'config', 'database', 'public', 'src', 'templates', 'composer.json', 'composer.lock'] as $path) {
            self::assertStringContainsString($path, $line[0], "{$path} doit rester livré");
        }
    }

    #[Test]
    public function testTheSeedItselfRefusesToRunWithoutItsGuard(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../database/seed.php');

        self::assertStringContainsString('SeedGuard::assertSafe', $script);
        self::assertLessThan(strpos($script, 'DELETE FROM'), strpos($script, 'SeedGuard::assertSafe'), 'le garde-fou passe AVANT toute écriture');
    }
}
