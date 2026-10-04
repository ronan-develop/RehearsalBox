<?php

declare(strict_types=1);

use App\Container\Container;
use App\Controller\Api\AccountApiController;
use App\Controller\Api\AuthApiController;
use App\Controller\Api\AvailabilityApiController;
use App\Controller\Api\GroupApiController;
use App\Controller\Api\GroupContactApiController;
use App\Controller\Api\GroupDocumentApiController;
use App\Controller\Api\GroupSpaceApiController;
use App\Controller\Api\SlotApiController;
use App\Controller\PageController;
use App\Controller\Api\PasswordResetApiController;
use App\Database\ConnectionFactory;
use App\Database\TransactionRunner;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Repository\Contract\PasswordResetRepositoryInterface;
use App\Repository\Contract\RecurringSlotRepositoryInterface;
use App\Repository\Contract\SlotExceptionRepositoryInterface;
use App\Repository\Contract\UserRepositoryInterface;
use App\Repository\Contract\GroupDocumentRepositoryInterface;
use App\Repository\MysqlGroupDocumentRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlPasswordResetRepository;
use App\Repository\MysqlRecurringSlotRepository;
use App\Repository\MysqlSlotExceptionRepository;
use App\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Security\NativePasswordHasher;
use App\Security\NativeSession;
use App\Security\PasswordHasherInterface;
use App\Security\PasswordPolicy;
use App\Security\SessionInterface;
use App\Service\AccountSecurityService;
use App\Service\AuthService;
use App\Service\AvailabilityService;
use App\Service\Contract\AuthServiceInterface;
use App\Service\Contract\AvailabilityServiceInterface;
use App\Service\Contract\GroupServiceInterface;
use App\Service\Contract\SlotServiceInterface;
use App\Service\GroupContactService;
use App\Service\GroupDocumentService;
use App\Service\GroupService;
use App\Service\PasswordChangeService;
use App\Service\PasswordResetService;
use App\Service\SlotService;
use App\Service\UserProvisioningService;
use App\View\PhpTemplateRenderer;
use App\View\TemplateRendererInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;

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

    $container->set(GroupRepositoryInterface::class, fn ($c) => new MysqlGroupRepository($c->get(PDO::class)));
    $container->set(SlotExceptionRepositoryInterface::class, fn ($c) => new MysqlSlotExceptionRepository($c->get(PDO::class)));
    $container->set(RecurringSlotRepositoryInterface::class, fn ($c) => new MysqlRecurringSlotRepository($c->get(PDO::class)));

    $container->set(AuthServiceInterface::class, fn ($c) => new AuthService(
        $c->get(UserRepositoryInterface::class),
        $c->get(PasswordHasherInterface::class),
        $c->get(SessionInterface::class),
        $c->get(GroupRepositoryInterface::class),
    ));

    $container->set(AuthGuard::class, fn ($c) => new AuthGuard($c->get(AuthServiceInterface::class)));

    $container->set(AvailabilityServiceInterface::class, fn ($c) => new AvailabilityService(
        $c->get(SlotExceptionRepositoryInterface::class),
        $c->get(GroupRepositoryInterface::class),
        $c->get(RecurringSlotRepositoryInterface::class),
    ));

    $container->set(SlotServiceInterface::class, fn ($c) => new SlotService(
        $c->get(RecurringSlotRepositoryInterface::class),
        $c->get(GroupRepositoryInterface::class),
        $c->get(SlotExceptionRepositoryInterface::class),
    ));

    $container->set(GroupServiceInterface::class, fn ($c) => new GroupService(
        $c->get(GroupRepositoryInterface::class),
        $c->get(UserRepositoryInterface::class),
    ));

    $container->set(MailerInterface::class, fn () => new \Symfony\Component\Mailer\Mailer(
        Transport::fromDsn($config['mailer']['dsn']),
    ));

    $container->set(GroupContactService::class, fn ($c) => new GroupContactService(
        $c->get(MailerInterface::class),
        $c->get(GroupRepositoryInterface::class),
        $config['mailer']['from'],
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
        $c->get(GroupDocumentRepositoryInterface::class),
    ));

    $container->set(PasswordPolicy::class, fn () => new PasswordPolicy());

    $container->set(TransactionRunner::class, fn ($c) => new TransactionRunner($c->get(PDO::class)));
    $container->set(PasswordResetRepositoryInterface::class, fn ($c) => new MysqlPasswordResetRepository($c->get(PDO::class)));

    $container->set(UserProvisioningService::class, fn ($c) => new UserProvisioningService(
        $c->get(UserRepositoryInterface::class),
        $c->get(PasswordHasherInterface::class),
        $c->get(PasswordPolicy::class),
    ));

    $container->set(PasswordResetService::class, fn ($c) => new PasswordResetService(
        $c->get(UserRepositoryInterface::class),
        $c->get(PasswordResetRepositoryInterface::class),
        $c->get(PasswordHasherInterface::class),
        $c->get(PasswordPolicy::class),
        $c->get(MailerInterface::class),
        $c->get(TransactionRunner::class),
        $config['mailer']['from'],
        $config['app']['base_url'],
    ));

    $container->set(AccountSecurityService::class, fn ($c) => new AccountSecurityService(
        $c->get(UserRepositoryInterface::class),
        $c->get(PasswordResetRepositoryInterface::class),
        $c->get(MailerInterface::class),
        $c->get(TransactionRunner::class),
        $c->get(PasswordResetService::class),
        $config['mailer']['from'],
        $config['app']['base_url'],
    ));

    $container->set(PasswordChangeService::class, fn ($c) => new PasswordChangeService(
        $c->get(UserRepositoryInterface::class),
        $c->get(PasswordHasherInterface::class),
        $c->get(PasswordPolicy::class),
        $c->get(AccountSecurityService::class),
    ));

    $container->set(AccountApiController::class, fn ($c) => new AccountApiController(
        $c->get(AuthGuard::class),
        $c->get(AuthServiceInterface::class),
        $c->get(PasswordChangeService::class),
        $c->get(AccountSecurityService::class),
    ));

    $container->set(PasswordResetApiController::class, fn ($c) => new PasswordResetApiController(
        $c->get(PasswordResetService::class),
    ));

    $container->set(AuthApiController::class, fn ($c) => new AuthApiController(
        $c->get(AuthServiceInterface::class),
        $c->get(UserProvisioningService::class),
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

    $container->set(GroupContactApiController::class, fn ($c) => new GroupContactApiController(
        $c->get(GroupContactService::class),
        $c->get(AuthGuard::class),
    ));

    $container->set(GroupSpaceApiController::class, fn ($c) => new GroupSpaceApiController(
        $c->get(GroupServiceInterface::class),
        $c->get(GroupRepositoryInterface::class),
        $c->get(AuthGuard::class),
    ));

    $container->set(GroupDocumentRepositoryInterface::class, fn ($c) => new MysqlGroupDocumentRepository($c->get(PDO::class)));

    $container->set(GroupDocumentService::class, fn ($c) => new GroupDocumentService(
        $c->get(GroupDocumentRepositoryInterface::class),
        $c->get(GroupRepositoryInterface::class),
        $config['storage']['group_documents_path'],
        20,
    ));

    $container->set(GroupDocumentApiController::class, fn ($c) => new GroupDocumentApiController(
        $c->get(GroupDocumentService::class),
        $c->get(AuthGuard::class),
    ));

    return $container;
};
