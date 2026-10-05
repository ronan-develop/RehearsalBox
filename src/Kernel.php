<?php

declare(strict_types=1);

namespace App;

use App\Container\ContainerInterface;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Response;
use App\Routing\Exception\MethodNotAllowedException;
use App\Routing\Exception\RouteNotFoundException;
use App\Routing\Router;
use App\Security\CsrfTokenManager;
use App\Security\Exception\AccessDeniedException;
use App\Security\SafeRedirect;
use App\Security\SecurityHeaders;
use App\Security\Exception\UnauthenticatedException;

final class Kernel
{
    private const MUTATING_METHODS = ['POST', 'PATCH', 'DELETE', 'PUT'];

    public function __construct(
        private readonly Router $router,
        private readonly ContainerInterface $container,
        private readonly CsrfTokenManager $csrfTokenManager,
        private readonly SecurityHeaders $securityHeaders,
    ) {
    }

    /** Point de sortie unique : toute réponse (page, API, erreur, redirection) reçoit les en-têtes de sécurité. */
    public function handle(Request $request): Response
    {
        return $this->securityHeaders->applyTo($this->dispatch($request));
    }

    private function dispatch(Request $request): Response
    {
        try {
            $matched = $this->router->match($request);
        } catch (RouteNotFoundException $e) {
            return $this->errorResponse($request, 404, 'Route non trouvée');
        } catch (MethodNotAllowedException $e) {
            return $this->errorResponse($request, 405, 'Méthode non autorisée');
        }

        // Vérifié en tout premier, avant toute logique métier (cf. plan §5/§10.3) —
        // le controller n'est jamais atteint sans token valide sur une mutation API.
        if ($this->isMutatingApiRequest($request) && !$this->csrfTokenManager->isValid((string) $request->header('X-CSRF-Token', ''))) {
            return $this->errorResponse($request, 403, 'Token CSRF invalide ou manquant.');
        }

        [$serviceId, $method] = $matched->handler;
        $controller = $this->container->get($serviceId);

        try {
            return $controller->$method($request, ...array_values($matched->params));
        } catch (UnauthenticatedException $e) {
            if (str_starts_with($request->path(), '/api/')) {
                return $this->errorResponse($request, 401, $e->getMessage());
            }

            // Une page de la messagerie ouverte depuis un e-mail : on y revient après la connexion (liste blanche stricte).
            $next = $request->method() === 'GET' ? SafeRedirect::afterLogin($request->path()) : null;

            return new Response(statusCode: 302, headers: ['Location' => $next === null ? '/login' : '/login?next=' . rawurlencode($next)]);
        } catch (AccessDeniedException $e) {
            return $this->errorResponse($request, 403, $e->getMessage());
        }
    }

    private function isMutatingApiRequest(Request $request): bool
    {
        return str_starts_with($request->path(), '/api/')
            && in_array($request->method(), self::MUTATING_METHODS, true);
    }

    private function errorResponse(Request $request, int $statusCode, string $message): Response
    {
        if (str_starts_with($request->path(), '/api/')) {
            return new JsonResponse(['error' => $message], $statusCode);
        }

        return new Response(body: $message, statusCode: $statusCode);
    }
}
