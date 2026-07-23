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

final class Kernel
{
    public function __construct(
        private readonly Router $router,
        private readonly ContainerInterface $container,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $matched = $this->router->match($request);
        } catch (RouteNotFoundException $e) {
            return $this->errorResponse($request, 404, 'Route non trouvée');
        } catch (MethodNotAllowedException $e) {
            return $this->errorResponse($request, 405, 'Méthode non autorisée');
        }

        [$serviceId, $method] = $matched->handler;
        $controller = $this->container->get($serviceId);

        return $controller->$method($request, ...array_values($matched->params));
    }

    private function errorResponse(Request $request, int $statusCode, string $message): Response
    {
        if (str_starts_with($request->path(), '/api/')) {
            return new JsonResponse(['error' => $message], $statusCode);
        }

        return new Response(body: $message, statusCode: $statusCode);
    }
}
