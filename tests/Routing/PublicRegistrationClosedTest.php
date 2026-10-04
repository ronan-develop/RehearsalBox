<?php

declare(strict_types=1);

namespace App\Tests\Routing;

use App\Http\Request;
use App\Routing\Exception\MethodNotAllowedException;
use App\Routing\Exception\RouteNotFoundException;
use App\Routing\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #137 : les comptes sont créés par un admin (ou bin/create-user.php), jamais par
 * un visiteur : ni page ni endpoint d'inscription publique.
 */
final class PublicRegistrationClosedTest extends TestCase
{
    private function router(): Router
    {
        $groups = require __DIR__ . '/../../config/routes.php';
        $router = new Router();
        foreach ([...$groups['pages'], ...$groups['api']] as [$method, $pattern, $handler]) {
            $router->add($method, $pattern, $handler);
        }

        return $router;
    }

    #[Test]
    public function testRegisterPageIsNotServed(): void
    {
        $this->expectException(RouteNotFoundException::class);

        $this->router()->match(new Request('GET', '/register', [], [], []));
    }

    #[Test]
    public function testRegisterEndpointIsNotServed(): void
    {
        try {
            $this->router()->match(new Request('POST', '/api/auth/register', [], [], []));
            self::fail("L'endpoint d'inscription publique ne doit plus exister.");
        } catch (RouteNotFoundException|MethodNotAllowedException) {
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function testLoginPageDoesNotLinkToRegistration(): void
    {
        self::assertStringNotContainsString('/register', (string) file_get_contents(__DIR__ . '/../../templates/auth/login.php'));
    }
}
