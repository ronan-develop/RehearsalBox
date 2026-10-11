<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Container\Container;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Kernel;
use App\Routing\Router;
use App\Security\CsrfTokenManager;
use App\Security\SecurityHeaders;
use App\Tests\Doubles\InMemorySession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Défense en profondeur du CSRF (#426) : même avec un jeton valide, une mutation d'API venue d'un autre site est refusée. */
final class CrossSiteMutationTest extends TestCase
{
    private int $reached = 0;

    /** @param array<string, string> $headers */
    private function post(array $headers): int
    {
        $this->reached = 0;
        $router = new Router();
        $router->add('POST', '/api/thing', ['thing', 'act']);
        $container = new Container();
        $container->set('thing', fn () => new class ($this->reached) {
            public function __construct(private int &$reached)
            {
            }

            public function act(): JsonResponse
            {
                ++$this->reached;

                return new JsonResponse(['status' => 'ok']);
            }
        });
        $csrf = new CsrfTokenManager(new InMemorySession());
        $kernel = new Kernel($router, $container, $csrf, new SecurityHeaders());

        return $kernel->handle(new Request('POST', '/api/thing', [], [], ['X-CSRF-TOKEN' => $csrf->getToken()] + $headers))->statusCode();
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function crossSite(): iterable
    {
        yield 'Origin d\'un autre site' => [['Host' => 'rehearsal.example', 'Origin' => 'https://evil.example']];
        yield 'Origin « null »' => [['Host' => 'rehearsal.example', 'Origin' => 'null']];
        yield 'Origin avec un autre port' => [['Host' => 'rehearsal.example', 'Origin' => 'https://rehearsal.example:8443']];
        yield 'Sec-Fetch-Site cross-site' => [['Host' => 'rehearsal.example', 'Sec-Fetch-Site' => 'cross-site']];
        yield 'Origin correct mais Sec-Fetch-Site cross-site' => [['Host' => 'rehearsal.example', 'Origin' => 'https://rehearsal.example', 'Sec-Fetch-Site' => 'cross-site']];
    }

    /** @param array<string, string> $headers */
    #[Test]
    #[DataProvider('crossSite')]
    public function testACrossSiteMutationIsRefusedBeforeTheController(array $headers): void
    {
        self::assertSame(403, $this->post($headers));
        self::assertSame(0, $this->reached);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function sameSite(): iterable
    {
        yield 'sans Origin ni Sec-Fetch-Site (client non navigateur)' => [['Host' => 'rehearsal.example']];
        yield 'même origine' => [['Host' => 'rehearsal.example', 'Origin' => 'https://rehearsal.example', 'Sec-Fetch-Site' => 'same-origin']];
        yield 'port par défaut explicite dans Host' => [['Host' => 'rehearsal.example:443', 'Origin' => 'https://rehearsal.example']];
        yield 'casse différente' => [['Host' => 'Rehearsal.Example', 'Origin' => 'https://rehearsal.example']];
        yield 'développement local avec port' => [['Host' => 'localhost:8000', 'Origin' => 'http://localhost:8000']];
    }

    /** @param array<string, string> $headers */
    #[Test]
    #[DataProvider('sameSite')]
    public function testASameOriginMutationStillWorks(array $headers): void
    {
        self::assertSame(200, $this->post($headers));
        self::assertSame(1, $this->reached);
    }
}
