<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $requestedPath = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($requestedPath !== __DIR__ . '/index.php' && is_file($requestedPath)) {
        return false;
    }
}

require __DIR__ . '/../vendor/autoload.php';

use App\Deploy\ReleaseMarker;
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

// Marqueur opaque de la release (écrit par bin/deploy.sh) : permet de vérifier
// que la production sert bien la dernière release. Absent en développement.
$releaseMarker = ReleaseMarker::fromFile(__DIR__ . '/../RELEASE');
if ($releaseMarker !== null) {
    header('X-Release: ' . $releaseMarker);
}

$kernel->handle($request)->send();
