<?php

declare(strict_types=1);

use App\Container\Container;
use App\Controller\Api\AuthApiController;
use App\Controller\Api\AvailabilityApiController;
use App\Controller\Api\GroupApiController;
use App\Controller\Api\SlotApiController;
use App\Controller\PageController;
use App\Database\ConnectionFactory;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Repository\Contract\RecurringSlotRepositoryInterface;
use App\Repository\Contract\SlotExceptionRepositoryInterface;
use App\Repository\Contract\UserRepositoryInterface;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlRecurringSlotRepository;
use App\Repository\MysqlSlotExceptionRepository;
use App\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Security\NativePasswordHasher;
use App\Security\NativeSession;
use App\Security\PasswordHasherInterface;
use App\Security\SessionInterface;
use App\Service\AuthService;
use App\Service\AvailabilityService;
use App\Service\Contract\AuthServiceInterface;
use App\Service\Contract\AvailabilityServiceInterface;
use App\Service\Contract\GroupServiceInterface;
use App\Service\Contract\SlotServiceInterface;
use App\Service\GroupService;
use App\Service\SlotService;
use App\View\PhpTemplateRenderer;
use App\View\TemplateRendererInterface;

/** @param array<string, mixed> $config */
return static function (array $config): Container {
    $container = new Container();

    $container->set(PDO::class, fn () => (new ConnectionFactory($config['db']))->create());

    $container->set(UserRepositoryInterface::class, fn ($c) => new MysqlUserRepository($c->get(PDO::class)));

    $container->set(PasswordHasherInterface::class, fn () => new NativePasswordHasher());
    $container->set(SessionInterface::class, function () {
        $session = new NativeSession();
        $session->start();

        return $session;
    });
    $container->set(CsrfTokenManager::class, fn ($c) => new CsrfTokenManager($c->get(SessionInterface::class)));

    $container->set(AuthServiceInterface::class, fn ($c) => new AuthService(
        $c->get(UserRepositoryInterface::class),
        $c->get(PasswordHasherInterface::class),
        $c->get(SessionInterface::class),
    ));

    $container->set(GroupRepositoryInterface::class, fn ($c) => new MysqlGroupRepository($c->get(PDO::class)));
    $container->set(SlotExceptionRepositoryInterface::class, fn ($c) => new MysqlSlotExceptionRepository($c->get(PDO::class)));
    $container->set(RecurringSlotRepositoryInterface::class, fn ($c) => new MysqlRecurringSlotRepository($c->get(PDO::class)));

    $container->set(AuthGuard::class, fn ($c) => new AuthGuard($c->get(AuthServiceInterface::class)));

    $container->set(AvailabilityServiceInterface::class, fn ($c) => new AvailabilityService(
        $c->get(SlotExceptionRepositoryInterface::class),
        $c->get(GroupRepositoryInterface::class),
        $c->get(RecurringSlotRepositoryInterface::class),
    ));

    $container->set(SlotServiceInterface::class, fn ($c) => new SlotService(
        $c->get(RecurringSlotRepositoryInterface::class),
        $c->get(GroupRepositoryInterface::class),
    ));

    $container->set(GroupServiceInterface::class, fn ($c) => new GroupService(
        $c->get(GroupRepositoryInterface::class),
        $c->get(UserRepositoryInterface::class),
    ));

    $container->set(TemplateRendererInterface::class, fn () => new PhpTemplateRenderer(__DIR__ . '/../templates'));

    $container->set(PageController::class, fn ($c) => new PageController(
        $c->get(TemplateRendererInterface::class),
        $c->get(CsrfTokenManager::class),
        $c->get(AuthGuard::class),
        $c->get(AvailabilityServiceInterface::class),
        $c->get(GroupRepositoryInterface::class),
        $c->get(SlotServiceInterface::class),
        $c->get(GroupServiceInterface::class),
    ));

    $container->set(AuthApiController::class, fn ($c) => new AuthApiController(
        $c->get(AuthServiceInterface::class),
        $c->get(UserRepositoryInterface::class),
        $c->get(PasswordHasherInterface::class),
    ));

    $container->set(AvailabilityApiController::class, fn ($c) => new AvailabilityApiController(
        $c->get(AvailabilityServiceInterface::class),
        $c->get(AuthGuard::class),
    ));

    $container->set(SlotApiController::class, fn ($c) => new SlotApiController(
        $c->get(SlotServiceInterface::class),
        $c->get(AuthGuard::class),
    ));

    $container->set(GroupApiController::class, fn ($c) => new GroupApiController(
        $c->get(GroupServiceInterface::class),
        $c->get(AuthGuard::class),
    ));

    return $container;
};
