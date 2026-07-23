<?php

declare(strict_types=1);

namespace App\Tests\Routing;

use App\Http\Request;
use App\Routing\Exception\MethodNotAllowedException;
use App\Routing\Exception\RouteNotFoundException;
use App\Routing\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function request(string $method, string $path): Request
    {
        return new Request($method, $path, [], [], []);
    }

    public function testMatchNominalCaseReturnsHandlerAndEmptyParams(): void
    {
        $router = new Router();
        $router->add('GET', '/', ['DashboardController', 'index']);

        $matched = $router->match($this->request('GET', '/'));

        self::assertSame(['DashboardController', 'index'], $matched->handler);
        self::assertSame([], $matched->params);
    }

    public function testMatchExtractsNamedParams(): void
    {
        $router = new Router();
        $router->add('GET', '/groups/{id}', ['GroupController', 'show']);

        $matched = $router->match($this->request('GET', '/groups/42'));

        self::assertSame(['GroupController', 'show'], $matched->handler);
        self::assertSame(['id' => '42'], $matched->params);
    }

    public function testMatchThrowsRouteNotFoundWhenNoPatternMatches(): void
    {
        $router = new Router();
        $router->add('GET', '/', ['DashboardController', 'index']);

        $this->expectException(RouteNotFoundException::class);

        $router->match($this->request('GET', '/inexistant'));
    }

    public function testMatchThrowsMethodNotAllowedWhenPathMatchesButNotMethod(): void
    {
        $router = new Router();
        $router->add('GET', '/groups/{id}', ['GroupController', 'show']);

        $this->expectException(MethodNotAllowedException::class);

        $router->match($this->request('POST', '/groups/42'));
    }

    public function testMatchDistinguishesMultipleNamedParams(): void
    {
        $router = new Router();
        $router->add('DELETE', '/api/admin/groups/{id}/members/{userId}', ['GroupApiController', 'removeMember']);

        $matched = $router->match($this->request('DELETE', '/api/admin/groups/7/members/12'));

        self::assertSame(['id' => '7', 'userId' => '12'], $matched->params);
    }
}
