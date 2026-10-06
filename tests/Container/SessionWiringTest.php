<?php

declare(strict_types=1);

namespace App\Tests\Container;

use App\Security\SessionInterface;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #244 : le cookie de session dépend de l'URL publique de l'application, lue DANS le câblage du conteneur. Vérifié ici de bout
 * en bout (et non sur NativeSession::cookieOptions() seule) : une configuration non capturée donnait un cookie sans Secure.
 */
final class SessionWiringTest extends TestCase
{
    /** @return array<string, mixed> */
    private function config(string $baseUrl): array
    {
        $config = require __DIR__ . '/../../config/config.php';
        $config['app']['base_url'] = $baseUrl;

        return $config;
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheSessionCookieIsSecureAndHostPrefixedWhenThePublicUrlIsHttps(): void
    {
        $container = (require __DIR__ . '/../../config/services.php')($this->config('https://rehearsalbox.example'));

        $container->get(SessionInterface::class);

        self::assertSame('__Host-rbsid', session_name());
        self::assertTrue(session_get_cookie_params()['secure']);
        self::assertTrue(session_get_cookie_params()['httponly']);
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheSessionCookieKeepsTheDevelopmentNameOverPlainHttp(): void
    {
        $container = (require __DIR__ . '/../../config/services.php')($this->config('http://localhost:8001'));

        $container->get(SessionInterface::class);

        self::assertSame('rbsid', session_name());
        self::assertFalse(session_get_cookie_params()['secure']);
    }
}
