<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\AppUrl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AppUrlTest extends TestCase
{
    #[Test]
    public function testAnHttpsApplicationUrlMeansSecureTransport(): void
    {
        self::assertTrue(AppUrl::isHttps('https://rehearsal.example.org'));
        self::assertTrue(AppUrl::isHttps('HTTPS://rehearsal.example.org/'));
    }

    #[Test]
    public function testAnythingElseIsNotConsideredSecure(): void
    {
        self::assertFalse(AppUrl::isHttps('http://localhost:8001'));
        self::assertFalse(AppUrl::isHttps(''), 'inconnu : pas de préfixe ni de HSTS trompeurs ; la redirection HTTPS du serveur reste en place');
        self::assertFalse(AppUrl::isHttps('https-fake://x'));
        self::assertFalse(AppUrl::isHttps('rehearsal.example.org'));
    }
}
