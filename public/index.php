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
use App\Http\AfterResponseInterface;
use App\Http\Request;
use App\Kernel;
use App\Routing\Router;
use App\Security\AppUrl;
use App\Security\CsrfTokenManager;
use App\Security\SecurityHeaders;
use App\Metrics\MetricsRecorderInterface;
use Psr\Log\LoggerInterface;

$config = require __DIR__ . '/../config/config.php';

// Hors développement, aucune trace ni message d'erreur PHP n'est affiché au visiteur (#220) : tout va au journal du serveur.
// (`debug` vaut false dans la configuration générée pour la production.)
if (!($config['debug'] ?? false)) {
    ini_set('display_errors', '0');
}

$buildContainer = require __DIR__ . '/../config/services.php';
$routeGroups = require __DIR__ . '/../config/routes.php';

$container = $buildContainer($config);

$router = new Router();
foreach ([...$routeGroups['pages'], ...$routeGroups['api']] as [$method, $pattern, $handler]) {
    $router->add($method, $pattern, $handler);
}

// HSTS seulement si l'application est servie en HTTPS (jamais en développement local).
$hsts = AppUrl::isHttps((string) ($config['app']['base_url'] ?? ''));

$kernel = new Kernel($router, $container, $container->get(CsrfTokenManager::class), new SecurityHeaders(hsts: $hsts), logger: $container->get(LoggerInterface::class), metrics: $container->get(MetricsRecorderInterface::class));
$request = Request::fromGlobals();

// Marqueur opaque de la release (écrit par bin/deploy.sh) : permet de vérifier
// que la production sert bien la dernière release. Absent en développement.
$releaseMarker = ReleaseMarker::fromFile(__DIR__ . '/../RELEASE');
if ($releaseMarker !== null) {
    header('X-Release: ' . $releaseMarker);
}

$kernel->handle($request)->send();

// Le travail différé (ex. e-mail de réinitialisation) part une fois la réponse livrée : le client n'attend pas, et le temps
// de réponse ne révèle rien (#219).
$container->get(AfterResponseInterface::class)->run();

// Les mesures (#195) sont écrites ici, hors du chemin critique : la réponse est déjà partie.
$container->get(MetricsRecorderInterface::class)->flush();
