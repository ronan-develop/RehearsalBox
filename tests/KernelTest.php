<?php

declare(strict_types=1);

namespace App\Tests;

use App\Container\Container;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Response;
use App\Kernel;
use App\Routing\Router;
use App\Security\CsrfTokenManager;
use App\Security\Exception\AccessDeniedException;
use App\Security\Exception\UnauthenticatedException;
use App\Security\SecurityHeaders;
use App\Tests\Security\InMemorySession;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class KernelTest extends TestCase
{
    private function kernel(Router $router, Container $container): Kernel
    {
        return new Kernel($router, $container, new CsrfTokenManager(new InMemorySession()), new SecurityHeaders());
    }

    #[Test]

    public function testHandleDispatchesToResolvedControllerMethod(): void
    {
        $router = new Router();
        $router->add('GET', '/ping', ['ping_controller', 'index']);

        $container = new Container();
        $container->set('ping_controller', fn () => new class () {
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

    #[Test]

    public function testHandleReturnsJson404ForUnknownApiRoute(): void
    {
        $kernel = $this->kernel(new Router(), new Container());

        $response = $kernel->handle(new Request('GET', '/api/inexistant', [], [], []));

        self::assertSame(404, $response->statusCode());
        self::assertStringContainsString('application/json', $response->headers()['Content-Type']);
    }

    #[Test]

    public function testHandleReturnsHtml404ForUnknownPageRoute(): void
    {
        $kernel = $this->kernel(new Router(), new Container());

        $response = $kernel->handle(new Request('GET', '/inexistant', [], [], []));

        self::assertSame(404, $response->statusCode());
        self::assertStringNotContainsString('application/json', $response->headers()['Content-Type'] ?? '');
    }

    #[Test]

    public function testHandleReturnsJson405WhenMethodNotAllowedOnApiRoute(): void
    {
        $router = new Router();
        $router->add('GET', '/api/availability', ['availability_controller', 'index']);

        $kernel = $this->kernel($router, new Container());
        $response = $kernel->handle(new Request('POST', '/api/availability', [], [], []));

        self::assertSame(405, $response->statusCode());
    }

    #[Test]

    public function testHandleReturnsJson403WhenControllerThrowsAccessDeniedOnApiRoute(): void
    {
        $router = new Router();
        $router->add('GET', '/api/admin/slots', ['admin_controller', 'index']);

        $container = new Container();
        $container->set('admin_controller', fn () => new class () {
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

    #[Test]

    public function testHandleReturnsHtml403WhenControllerThrowsAccessDeniedOnPageRoute(): void
    {
        $router = new Router();
        $router->add('GET', '/admin/slots', ['admin_controller', 'index']);

        $container = new Container();
        $container->set('admin_controller', fn () => new class () {
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

    #[Test]

    public function testHandleRedirectsToLoginWhenControllerThrowsUnauthenticatedOnPageRoute(): void
    {
        $router = new Router();
        $router->add('GET', '/', ['dashboard_controller', 'index']);

        $container = new Container();
        $container->set('dashboard_controller', fn () => new class () {
            public function index(): never
            {
                throw new UnauthenticatedException('Connexion requise.');
            }
        });

        $kernel = $this->kernel($router, $container);
        $response = $kernel->handle(new Request('GET', '/', [], [], []));

        self::assertSame(302, $response->statusCode());
        self::assertSame('/login', $response->headers()['Location']);
    }

    #[Test]
    public function testAnonymousVisitorOfAMessagingPageIsSentBackThereAfterLogin(): void
    {
        $router = new Router();
        $router->add('GET', '/messages/{id}', ['messages_controller', 'show']);
        $router->add('GET', '/account/password', ['messages_controller', 'account']);

        $container = new Container();
        $container->set('messages_controller', fn () => new class () {
            public function show(): never
            {
                throw new UnauthenticatedException('Connexion requise.');
            }

            public function account(): never
            {
                throw new UnauthenticatedException('Connexion requise.');
            }
        });
        $kernel = $this->kernel($router, $container);

        $messaging = $kernel->handle(new Request('GET', '/messages/12', ['x' => '1'], [], []));
        $other = $kernel->handle(new Request('GET', '/account/password', [], [], []));

        self::assertSame('/login?next=%2Fmessages%2F12', $messaging->headers()['Location'], 'retour dans la conversation, sans les paramètres');
        self::assertSame('/login', $other->headers()['Location'], 'les autres pages gardent le comportement habituel');
    }

    #[Test]

    public function testHandleReturnsJson401WhenControllerThrowsUnauthenticatedOnApiRoute(): void
    {
        $router = new Router();
        $router->add('GET', '/api/availability', ['availability_controller', 'index']);

        $container = new Container();
        $container->set('availability_controller', fn () => new class () {
            public function index(): never
            {
                throw new UnauthenticatedException('Connexion requise.');
            }
        });

        $kernel = $this->kernel($router, $container);
        $response = $kernel->handle(new Request('GET', '/api/availability', [], [], []));

        self::assertSame(401, $response->statusCode());
        self::assertStringContainsString('application/json', $response->headers()['Content-Type']);
    }

    #[Test]

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

    #[Test]

    public function testHandleAcceptsMutatingApiRequestWithValidCsrfToken(): void
    {
        $router = new Router();
        $router->add('POST', '/api/availability/1/claim', ['availability_controller', 'claim']);

        $container = new Container();
        $container->set('availability_controller', fn () => new class () {
            public function claim(): JsonResponse
            {
                return new JsonResponse(['status' => 'ok']);
            }
        });

        $session = new InMemorySession();
        $csrf = new CsrfTokenManager($session);
        $token = $csrf->getToken();

        $kernel = new Kernel($router, $container, $csrf, new SecurityHeaders());
        $response = $kernel->handle(new Request('POST', '/api/availability/1/claim', [], [], ['X-CSRF-TOKEN' => $token]));

        self::assertSame(200, $response->statusCode());
    }

    #[Test]

    public function testHandleDoesNotCheckCsrfOnGetApiRequests(): void
    {
        $router = new Router();
        $router->add('GET', '/api/availability', ['availability_controller', 'index']);

        $container = new Container();
        $container->set('availability_controller', fn () => new class () {
            public function index(): JsonResponse
            {
                return new JsonResponse(['status' => 'ok']);
            }
        });

        $kernel = $this->kernel($router, $container);
        $response = $kernel->handle(new Request('GET', '/api/availability', [], [], []));

        self::assertSame(200, $response->statusCode());
    }

    #[Test]
    public function testEveryKindOfResponseCarriesTheSecurityHeaders(): void
    {
        $router = new Router();
        $router->add('GET', '/page', ['c', 'page']);
        $router->add('GET', '/private', ['c', 'private']);
        $router->add('POST', '/api/thing', ['c', 'thing']);
        $container = new Container();
        $container->set('c', fn () => new class () {
            public function page(): Response
            {
                return new Response('<p>ok</p>');
            }

            public function private(): Response
            {
                throw new UnauthenticatedException('non connecté');
            }

            public function thing(): JsonResponse
            {
                return new JsonResponse(['status' => 'ok']);
            }
        });
        $kernel = $this->kernel($router, $container);

        $responses = [
            'page' => $kernel->handle(new Request('GET', '/page', [], [], [])),
            'redirection vers la connexion' => $kernel->handle(new Request('GET', '/private', [], [], [])),
            'API refusée (CSRF)' => $kernel->handle(new Request('POST', '/api/thing', [], [], [])),
            '404 page' => $kernel->handle(new Request('GET', '/nulle-part', [], [], [])),
            '404 API' => $kernel->handle(new Request('GET', '/api/nulle-part', [], [], [])),
            '405 méthode non autorisée' => $kernel->handle(new Request('DELETE', '/page', [], [], [])),
        ];

        foreach ($responses as $label => $response) {
            self::assertSame("frame-ancestors 'none'", $this->directive($response, 'frame-ancestors'), $label);
            self::assertSame('DENY', $response->headers()['X-Frame-Options'] ?? null, $label);
            self::assertSame('nosniff', $response->headers()['X-Content-Type-Options'] ?? null, $label);
            self::assertSame('private, no-store', $response->headers()['Cache-Control'] ?? null, $label);
        }
    }

    #[Test]
    public function testAControllerHeaderIsKeptByTheKernel(): void
    {
        $router = new Router();
        $router->add('GET', '/reset', ['c', 'reset']);
        $container = new Container();
        $container->set('c', fn () => new class () {
            public function reset(): Response
            {
                return new Response('ok', 200, ['Referrer-Policy' => 'no-referrer']);
            }
        });

        $response = $this->kernel($router, $container)->handle(new Request('GET', '/reset', [], [], []));

        self::assertSame('no-referrer', $response->headers()['Referrer-Policy']);
    }

    private function directive(Response $response, string $name): ?string
    {
        foreach (explode(';', $response->headers()['Content-Security-Policy'] ?? '') as $part) {
            if (str_starts_with(trim($part), $name)) {
                return trim($part);
            }
        }

        return null;
    }

    // --- Exceptions inattendues (#220) ----------------------------------------------------------------

    /** @return array{Request, \App\Http\Response, string} la réponse du Kernel et ce qui a été journalisé */
    private function crash(string $method, string $path, \Throwable $error): array
    {
        $router = new Router();
        $router->add($method, $path, ['boom', 'run']);
        $container = new Container();
        $container->set('boom', fn () => new class ($error) {
            public function __construct(private readonly \Throwable $error)
            {
            }

            public function run(): Response
            {
                throw $this->error;
            }
        });
        $request = new Request($method, $path, [], [], []);

        $file = tempnam(sys_get_temp_dir(), 'errlog');
        $previous = ini_set('error_log', $file);
        try {
            $response = $this->kernel($router, $container)->handle($request);
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $logged = (string) file_get_contents($file);
        unlink($file);

        return [$request, $response, $logged];
    }

    #[Test]
    public function testAnUnexpectedErrorOnTheApiIsAGenericJson500WithoutAnyLeak(): void
    {
        [, $response, $logged] = $this->crash('GET', '/api/boom', new \RuntimeException('SQLSTATE alice@rehearsalbox.test jeton-secret /var/www/chemin'));

        self::assertSame(500, $response->statusCode());
        self::assertSame('{"error":"Erreur interne."}', $response->body());
        self::assertStringContainsString('application/json', $response->headers()['Content-Type']);
        self::assertStringNotContainsString('alice', $response->body() . $logged);
        self::assertStringNotContainsString('jeton-secret', $response->body() . $logged);
        self::assertStringNotContainsString('SQLSTATE', $response->body() . $logged);
        self::assertStringContainsString('RuntimeException', $logged, 'la classe de l’erreur suffit au diagnostic');
        self::assertStringContainsString('GET /api/boom', $logged);
    }

    #[Test]
    public function testAnUnexpectedErrorOnAPageIsAGenericPageWithoutAnyLeak(): void
    {
        [, $response, $logged] = $this->crash('GET', '/boom', new \PDOException('Access denied for user root@localhost password=secret'));

        self::assertSame(500, $response->statusCode());
        self::assertStringContainsString('<h1>Erreur interne</h1>', $response->body());
        self::assertStringNotContainsString('secret', $response->body() . $logged);
        self::assertStringContainsString('PDOException', $logged);
    }

    #[Test]
    public function testPhpErrorsAreCaughtToo(): void
    {
        [, $response] = $this->crash('GET', '/api/boom', new \ValueError('3 is not a valid backing value'));

        self::assertSame(500, $response->statusCode());
        self::assertSame('{"error":"Erreur interne."}', $response->body());
    }

    #[Test]
    public function testTheGenericErrorStillCarriesTheSecurityHeaders(): void
    {
        [, $response] = $this->crash('GET', '/boom', new \RuntimeException('x'));

        self::assertSame('DENY', $response->headers()['X-Frame-Options']);
        self::assertSame('private, no-store', $response->headers()['Cache-Control']);
    }

    #[Test]
    public function testAServiceThatCannotBeBuiltIsAlsoAGenericError(): void
    {
        $router = new Router();
        $router->add('GET', '/boom', ['inconnu', 'run']);

        $file = tempnam(sys_get_temp_dir(), 'errlog');
        $previous = ini_set('error_log', $file);
        try {
            $response = $this->kernel($router, new Container())->handle(new Request('GET', '/boom', [], [], []));
        } finally {
            ini_set('error_log', (string) $previous);
            unlink($file);
        }

        self::assertSame(500, $response->statusCode());
    }

    #[Test]
    public function testABusinessExceptionBubblingUpFromAControllerBecomesItsResponse(): void
    {
        foreach ([
            [new \App\Account\Exception\UserValidationException(['email' => 'Invalide.']), 422, '{"error":"Validation échouée","fields":{"email":"Invalide."}}'],
            [new \App\Planning\Exception\RequestAlreadyRespondedException('Déjà traitée.'), 409, '{"error":"Déjà traitée."}'],
            [new \App\Service\Exception\ConversationRateLimitException('Trop de messages.'), 429, '{"error":"Trop de messages."}'],
            [new \App\Account\Exception\UserNotFoundException('Utilisateur 42 introuvable.'), 404, '{"error":"Utilisateur introuvable."}'],
        ] as [$error, $status, $body]) {
            [, $response, $logged] = $this->crash('GET', '/api/boom', $error);

            self::assertSame($status, $response->statusCode(), $error::class);
            self::assertSame($body, $response->body(), $error::class);
            self::assertSame('', $logged, 'une erreur métier attendue n\'est pas une « erreur non gérée »');
            self::assertSame('DENY', $response->headers()['X-Frame-Options'], 'les en-têtes de sécurité restent posés');
        }
    }

    #[Test]
    public function testAPageRouteThatIsUnknownGetsTheStyledErrorPageNotBarePlainText(): void
    {
        $response = $this->kernel(new Router(), new Container())->handle(new Request('GET', '/messages/3', [], [], []));

        self::assertSame(404, $response->statusCode());
        self::assertStringContainsString('text/html', $response->headers()['Content-Type']);
        self::assertStringContainsString('<h1>Page introuvable</h1>', $response->body());
    }

    #[Test]
    public function testADeniedPageGetsTheStyledErrorPageWithoutTheInternalMessage(): void
    {
        $router = new Router();
        $router->add('GET', '/messages/3', ['c', 'show']);
        $container = new Container();
        $container->set('c', fn () => new class () {
            public function show(): Response
            {
                throw new AccessDeniedException('Conversation 3 : pas membre');
            }
        });

        $response = $this->kernel($router, $container)->handle(new Request('GET', '/messages/3', [], [], []));

        self::assertSame(403, $response->statusCode());
        self::assertStringContainsString('<h1>Accès refusé</h1>', $response->body());
        self::assertStringNotContainsString('pas membre', $response->body());
    }
}
