<?php

declare(strict_types=1);

use App\Container\Container;
use App\Controller\Api\PingApiController;
use App\Controller\PingController;
use App\Database\ConnectionFactory;

/** @param array<string, mixed> $config */
return static function (array $config): Container {
    $container = new Container();

    $container->set(PDO::class, fn () => (new ConnectionFactory($config['db']))->create());

    $container->set(PingController::class, fn () => new PingController());
    $container->set(PingApiController::class, fn () => new PingApiController());

    return $container;
};
