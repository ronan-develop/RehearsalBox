<?php

declare(strict_types=1);

namespace App\Tests;

use App\Container\Container;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Kernel;
use App\Routing\Router;
use App\Security\CsrfTokenManager;
use App\Security\Exception\AccessDeniedException;
use App\Tests\Security\InMemorySession;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    private function kernel(Router $router, Container $container): Kernel
    {
        return new Kernel($router, $container, new CsrfTokenManager(new InMemorySession()));
    }

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

        $kernel = $this->kernel($router, $container);
        $response = $kernel->handle(new Request('GET', '/ping', [], [], []));

        self::assertSame(200, $response->statusCode());
        self::assertSame('{"status":"ok"}', $response->body());
    }

    public function testHandleReturnsJson404ForUnknownApiRoute(): void
    {
        $kernel = $this->kernel(new Router(), new Container());

        $response = $kernel->handle(new Request('GET', '/api/inexistant', [], [], []));

        self::assertSame(404, $response->statusCode());
        self::assertStringContainsString('application/json', $response->headers()['Content-Type']);
    }

    public function testHandleReturnsHtml404ForUnknownPageRoute(): void
    {
        $kernel = $this->kernel(new Router(), new Container());

        $response = $kernel->handle(new Request('GET', '/inexistant', [], [], []));

        self::assertSame(404, $response->statusCode());
        self::assertStringNotContainsString('application/json', $response->headers()['Content-Type'] ?? '');
    }

    public function testHandleReturnsJson405WhenMethodNotAllowedOnApiRoute(): void
    {
        $router = new Router();
        $router->add('GET', '/api/availability', ['availability_controller', 'index']);

        $kernel = $this->kernel($router, new Container());
        $response = $kernel->handle(new Request('POST', '/api/availability', [], [], []));

        self::assertSame(405, $response->statusCode());
    }

    public function testHandleReturnsJson403WhenControllerThrowsAccessDeniedOnApiRoute(): void
    {
        $router = new Router();
        $router->add('GET', '/api/admin/slots', ['admin_controller', 'index']);

        $container = new Container();
        $container->set('admin_controller', fn () => new class {
            public function index(): never
            {
                throw new AccessDeniedException('Rôle admin requis.');
            }
        });

        $kernel = $this->kernel($router, $container);
        $response = $kernel->handle(new Request('GET', '/api/admin/slots', [], [], []));

        self::assertSame(403, $response->statusCode());
        self::assertStringContainsString('application/json', $response->headers()['Content-Type']);
    }

    public function testHandleReturnsHtml403WhenControllerThrowsAccessDeniedOnPageRoute(): void
    {
        $router = new Router();
        $router->add('GET', '/admin/slots', ['admin_controller', 'index']);

        $container = new Container();
        $container->set('admin_controller', fn () => new class {
            public function index(): never
            {
                throw new AccessDeniedException('Rôle admin requis.');
            }
        });

        $kernel = $this->kernel($router, $container);
        $response = $kernel->handle(new Request('GET', '/admin/slots', [], [], []));

        self::assertSame(403, $response->statusCode());
        self::assertStringNotContainsString('application/json', $response->headers()['Content-Type'] ?? '');
    }

    public function testHandleRejectsMutatingApiRequestWithoutCsrfTokenBeforeReachingController(): void
    {
        $router = new Router();
        $router->add('POST', '/api/availability/1/claim', ['availability_controller', 'claim']);

        $reached = false;
        $container = new Container();
        $container->set('availability_controller', function () use (&$reached) {
            return new class ($reached) {
                public function __construct(private bool &$reached)
                {
                }

                public function claim(): JsonResponse
                {
                    $this->reached = true;

                    return new JsonResponse(['status' => 'ok']);
                }
            };
        });

        $kernel = $this->kernel($router, $container);
        $response = $kernel->handle(new Request('POST', '/api/availability/1/claim', [], [], []));

        self::assertSame(403, $response->statusCode());
        self::assertFalse($reached, 'le controller ne doit jamais être atteint sans token CSRF valide');
    }

    public function testHandleAcceptsMutatingApiRequestWithValidCsrfToken(): void
    {
        $router = new Router();
        $router->add('POST', '/api/availability/1/claim', ['availability_controller', 'claim']);

        $container = new Container();
        $container->set('availability_controller', fn () => new class {
            public function claim(): JsonResponse
            {
                return new JsonResponse(['status' => 'ok']);
            }
        });

        $session = new InMemorySession();
        $csrf = new CsrfTokenManager($session);
        $token = $csrf->getToken();

        $kernel = new Kernel($router, $container, $csrf);
        $response = $kernel->handle(new Request('POST', '/api/availability/1/claim', [], [], ['X-CSRF-TOKEN' => $token]));

        self::assertSame(200, $response->statusCode());
    }

    public function testHandleDoesNotCheckCsrfOnGetApiRequests(): void
    {
        $router = new Router();
        $router->add('GET', '/api/availability', ['availability_controller', 'index']);

        $container = new Container();
        $container->set('availability_controller', fn () => new class {
            public function index(): JsonResponse
            {
                return new JsonResponse(['status' => 'ok']);
            }
        });

        $kernel = $this->kernel($router, $container);
        $response = $kernel->handle(new Request('GET', '/api/availability', [], [], []));

        self::assertSame(200, $response->statusCode());
    }
}
