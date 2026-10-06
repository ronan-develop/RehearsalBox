<?php

declare(strict_types=1);

namespace App\Tests\Tools;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #301 : `composer test:quick` exclut le groupe « db » pour la boucle de travail. PHPUnit n'hérite pas d'un groupe posé sur la classe
 * parente : chaque classe de test qui touche la base doit donc porter #[\PHPUnit\Framework\Attributes\Group('db')] elle-même (nom complet : certains tests importent déjà App\Entity\Group), sinon elle fausserait le test rapide.
 */
final class DbGroupTest extends TestCase
{
    #[Test]
    public function testEveryTestClassUsingTheDatabaseIsInTheDbGroup(): void
    {
        $missing = [];
        $marker = 'extends Repository' . 'TestCase';
        $direct = 'TestDatabase' . '::';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/..', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = (string) $file;
            if (!str_ends_with($path, 'Test.php') || basename($path) === 'DbGroupTest.php') {
                continue;
            }
            $code = (string) file_get_contents($path);
            if ((str_contains($code, $marker) || str_contains($code, $direct)) && !str_contains($code, "#[\\PHPUnit\\Framework\\Attributes\\Group('db')]")) {
                $missing[] = substr($path, strlen(__DIR__ . '/../'));
            }
        }
        sort($missing);

        self::assertSame([], $missing, "ajouter #[\\PHPUnit\\Framework\\Attributes\\Group('db')] avant la classe");
    }
}
