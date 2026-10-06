<?php

declare(strict_types=1);

namespace App\Tests\Deploy;

use App\Deploy\SeedGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SeedGuardTest extends TestCase
{
    #[Test]
    #[DataProvider('localUrls')]
    public function testItAllowsTheSeedOnACommandLineOnALocalApplicationWithTheExplicitFlag(string $baseUrl): void
    {
        SeedGuard::assertSafe('cli', $baseUrl, ['database/seed.php', '--force-local']);

        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function localUrls(): iterable
    {
        yield 'localhost avec port' => ['http://localhost:8001'];
        yield 'localhost sans port' => ['http://localhost'];
        yield 'boucle locale IPv4' => ['http://127.0.0.1:8001'];
        yield 'boucle locale IPv6' => ['http://[::1]:8001'];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function testItRefusesEverythingElseWithoutTouchingAnything(string $sapi, string $baseUrl, array $argv, string $reason): void
    {
        try {
            SeedGuard::assertSafe($sapi, $baseUrl, $argv);
            self::fail('le seed aurait dû être refusé : ' . $reason);
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Seed refusé', $e->getMessage(), $reason);
        }
    }

    /** @return iterable<string, array{string, string, list<string>, string}> */
    public static function refusals(): iterable
    {
        $flag = ['database/seed.php', '--force-local'];
        yield 'depuis le web' => ['fpm-fcgi', 'http://localhost:8001', $flag, 'le seed ne tourne jamais depuis le web'];
        yield 'application en ligne' => ['cli', 'https://rehearsal.example.org', $flag, 'une URL publique = environnement réel'];
        yield 'nom de domaine commençant par localhost' => ['cli', 'https://localhost.attaquant.example', $flag, 'seul l\'hôte exact compte'];
        yield 'URL vide' => ['cli', '', $flag, 'environnement inconnu = refus'];
        yield 'drapeau absent' => ['cli', 'http://localhost:8001', ['database/seed.php'], 'il faut l\'accord explicite'];
        yield 'drapeau proche' => ['cli', 'http://localhost:8001', ['database/seed.php', '--force'], 'le drapeau exact est exigé'];
    }

    #[Test]
    public function testTheRefusalNeverShowsTheUrl(): void
    {
        try {
            SeedGuard::assertSafe('cli', 'https://secret-prod.example.org', ['database/seed.php', '--force-local']);
            self::fail('refus attendu');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString('secret-prod', $e->getMessage());
        }
    }
}
