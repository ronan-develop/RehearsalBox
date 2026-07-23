<?php

declare(strict_types=1);

namespace App\Routing;

use App\Http\Request;
use App\Routing\Exception\MethodNotAllowedException;
use App\Routing\Exception\RouteNotFoundException;

final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @param array{0: class-string, 1: string} $handler */
    public function add(string $method, string $pattern, array $handler): void
    {
        $this->routes[] = new Route(strtoupper($method), $pattern, $handler);
    }

    public function match(Request $request): MatchedRoute
    {
        $pathMatchedByOtherMethod = false;

        foreach ($this->routes as $route) {
            $params = $route->match($request->path());
            if ($params === null) {
                continue;
            }

            if ($route->method !== $request->method()) {
                $pathMatchedByOtherMethod = true;
                continue;
            }

            return new MatchedRoute($route->handler, $params);
        }

        if ($pathMatchedByOtherMethod) {
            throw new MethodNotAllowedException(
                "Méthode {$request->method()} non autorisée pour {$request->path()}"
            );
        }

        throw new RouteNotFoundException("Aucune route ne correspond à {$request->path()}");
    }
}
