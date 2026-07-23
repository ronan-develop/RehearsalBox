<?php

declare(strict_types=1);

namespace App\Tests;

use App\Container\Container;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Kernel;
use App\Routing\Router;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    public function testHandleDispatchesToResolvedControllerMethod(): void
    {
        $router = new Router();
        $router->add('GET', '/ping', ['ping_controller', 'index']);

        $container = new Container();
        $container->set('ping_controller', fn () => new class {
            public function index(): JsonResponse
            {
                return new JsonResponse(['status' => 'ok']);
            }
        });

        $kernel = new Kernel($router, $container);
        $response = $kernel->handle(new Request('GET', '/ping', [], [], []));

        self::assertSame(200, $response->statusCode());
        self::assertSame('{"status":"ok"}', $response->body());
    }

    public function testHandleReturnsJson404ForUnknownApiRoute(): void
    {
        $kernel = new Kernel(new Router(), new Container());

        $response = $kernel->handle(new Request('GET', '/api/inexistant', [], [], []));

        self::assertSame(404, $response->statusCode());
        self::assertStringContainsString('application/json', $response->headers()['Content-Type']);
    }

    public function testHandleReturnsHtml404ForUnknownPageRoute(): void
    {
        $kernel = new Kernel(new Router(), new Container());

        $response = $kernel->handle(new Request('GET', '/inexistant', [], [], []));

        self::assertSame(404, $response->statusCode());
        self::assertStringNotContainsString('application/json', $response->headers()['Content-Type'] ?? '');
    }

    public function testHandleReturnsJson405WhenMethodNotAllowedOnApiRoute(): void
    {
        $router = new Router();
        $router->add('GET', '/api/availability', ['availability_controller', 'index']);

        $kernel = new Kernel($router, new Container());
        $response = $kernel->handle(new Request('POST', '/api/availability', [], [], []));

        self::assertSame(405, $response->statusCode());
    }
}
