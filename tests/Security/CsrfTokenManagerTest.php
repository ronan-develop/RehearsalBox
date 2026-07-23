<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\CsrfTokenManager;
use PHPUnit\Framework\TestCase;

final class CsrfTokenManagerTest extends TestCase
{
    public function testGetTokenGeneratesAndPersistsATokenInSession(): void
    {
        $session = new InMemorySession();
        $manager = new CsrfTokenManager($session);

        $token = $manager->getToken();

        self::assertNotEmpty($token);
        self::assertSame($token, $session->get('csrf_token'));
    }

    public function testGetTokenReturnsSameTokenOnSecondCall(): void
    {
        $session = new InMemorySession();
        $manager = new CsrfTokenManager($session);

        $first = $manager->getToken();
        $second = $manager->getToken();

        self::assertSame($first, $second);
    }

    public function testIsValidReturnsTrueForMatchingToken(): void
    {
        $session = new InMemorySession();
        $manager = new CsrfTokenManager($session);
        $token = $manager->getToken();

        self::assertTrue($manager->isValid($token));
    }

    public function testIsValidReturnsFalseForWrongToken(): void
    {
        $session = new InMemorySession();
        $manager = new CsrfTokenManager($session);
        $manager->getToken();

        self::assertFalse($manager->isValid('token-invalide'));
    }

    public function testIsValidReturnsFalseWhenNoTokenInSession(): void
    {
        $session = new InMemorySession();
        $manager = new CsrfTokenManager($session);

        self::assertFalse($manager->isValid('nimporte-quoi'));
    }
}
