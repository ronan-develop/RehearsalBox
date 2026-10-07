<?php

declare(strict_types=1);

namespace App\Tests\Metrics\Collection;

use App\Container\Container;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Kernel;
use App\Routing\Router;
use App\Security\CsrfTokenManager;
use App\Security\SecurityHeaders;
use App\Tests\Doubles\InMemorySession;
use App\Tests\Doubles\RecordingMetrics;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KernelMetricsTest extends TestCase
{
    private RecordingMetrics $metrics;

    protected function setUp(): void
    {
        $this->metrics = new RecordingMetrics();
    }

    private function handle(Request $request, ?\Closure $controller = null): void
    {
        $router = new Router();
        $router->add('GET', '/items/{id}', ['items', 'show']);
        $router->add('POST', '/api/items', ['items', 'create']);
        $router->add('POST', '/api/auth/login', ['items', 'create']);
        $container = new Container();
        $container->set('items', fn () => new class ($controller) {
            public function __construct(private readonly ?\Closure $controller)
            {
            }

            public function show(): JsonResponse
            {
                return ($this->controller ?? static fn () => new JsonResponse(['ok' => true]))();
            }

            public function create(): JsonResponse
            {
                return ($this->controller ?? static fn () => new JsonResponse(['ok' => true], 201))();
            }
        });

        (new Kernel($router, $container, new CsrfTokenManager(new InMemorySession()), new SecurityHeaders(), metrics: $this->metrics))->handle($request);
    }

    #[Test]
    public function testARequestIsCountedUnderItsRoutePatternNeverItsIdentifier(): void
    {
        $this->handle(new Request('GET', '/items/42', [], [], []));

        self::assertSame([['/items/{id}', 200]], $this->metrics->requests);
        self::assertSame([], $this->metrics->events, 'une réponse normale n\'est pas un évènement');
    }

    #[Test]
    public function testAnUnknownPathIsCountedAsUnknownAndKeepsItsPathAsA404Event(): void
    {
        $this->handle(new Request('GET', '/.env', [], [], [], clientIp: '203.0.113.7'));

        self::assertSame([['(inconnue)', 404]], $this->metrics->requests);
        self::assertSame([['not_found', '/.env', 404, '203.0.113.7']], $this->metrics->events);
    }

    #[Test]
    public function testAMissingCsrfTokenIsOneCsrfEventNotAnAccessDeniedToo(): void
    {
        $this->handle(new Request('POST', '/api/items', [], [], []));

        self::assertSame(['csrf_failed'], $this->metrics->eventTypes());
        self::assertSame('/api/items', $this->metrics->events[0][1]);
    }

    #[Test]
    public function testAnUnexpectedErrorIsAServerErrorEvent(): void
    {
        $this->handle(new Request('GET', '/items/1', [], [], []), static function (): never {
            throw new \RuntimeException('boom');
        });

        self::assertSame(['server_error'], $this->metrics->eventTypes());
        self::assertSame([['/items/{id}', 500]], $this->metrics->requests);
    }

    #[Test]
    public function testEveryRequestIsMeasuredEvenWhenItIsRefused(): void
    {
        $this->handle(new Request('GET', '/nope', [], [], []));
        $this->handle(new Request('GET', '/items/1', [], [], []));

        self::assertCount(2, $this->metrics->requests);
    }
}
