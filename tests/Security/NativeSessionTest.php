<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\NativeSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NativeSessionTest extends TestCase
{
    #[Test]
    public function testASecureApplicationGetsASecureHostLockedCookie(): void
    {
        $options = NativeSession::cookieOptions(secure: true);

        self::assertTrue($options['cookie_secure']);
        self::assertTrue($options['cookie_httponly']);
        self::assertSame('Lax', $options['cookie_samesite']);
        // « __Host- » : le navigateur refuse tout cookie de ce nom qui ne soit pas Secure, sans Domain et sur le chemin « / ».
        self::assertSame('__Host-rbsid', $options['name']);
    }

    #[Test]
    public function testALocalDevelopmentApplicationKeepsAPlainCookieName(): void
    {
        $options = NativeSession::cookieOptions(secure: false);

        self::assertFalse($options['cookie_secure']);
        self::assertTrue($options['cookie_httponly']);
        self::assertSame('rbsid', $options['name'], 'le préfixe « __Host- » exige Secure : pas en HTTP local');
    }

    #[Test]
    public function testAnIdentifierTheServerNeverIssuedIsNeverAdopted(): void
    {
        foreach ([true, false] as $secure) {
            self::assertTrue(NativeSession::cookieOptions($secure)['use_strict_mode'], 'mode strict : un identifiant inconnu est ignoré');
        }
    }

    #[Test]
    public function testTheSecureFlagComesFromTheConfigurationNeverFromTheRequest(): void
    {
        // Plus aucun en-tête du client (HTTPS, X-Forwarded-Proto) n'entre en compte : la signature ne les accepte pas.
        $parameters = (new \ReflectionMethod(NativeSession::class, 'cookieOptions'))->getParameters();

        self::assertCount(1, $parameters);
        self::assertSame('secure', $parameters[0]->getName());
        self::assertSame('bool', (string) $parameters[0]->getType());
    }
}
