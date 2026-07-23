<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Http\Request;
use App\Kernel;
use App\Routing\Router;
use App\Security\CsrfTokenManager;

$config = require __DIR__ . '/../config/config.php';
$buildContainer = require __DIR__ . '/../config/services.php';
$routeGroups = require __DIR__ . '/../config/routes.php';

$container = $buildContainer($config);

$router = new Router();
foreach ([...$routeGroups['pages'], ...$routeGroups['api']] as [$method, $pattern, $handler]) {
    $router->add($method, $pattern, $handler);
}

$kernel = new Kernel($router, $container, $container->get(CsrfTokenManager::class));
$request = Request::fromGlobals();

$kernel->handle($request)->send();
