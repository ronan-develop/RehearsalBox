<?php

declare(strict_types=1);

use App\Container\Container;
use App\Controller\Api\AccountApiController;
use App\Controller\Api\AuthApiController;
use App\Controller\Api\AvailabilityApiController;
use App\Controller\AdminUserPageController;
use App\Controller\Api\ConversationApiController;
use App\Controller\Api\GroupApiController;
use App\Controller\Api\UserAdminApiController;
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
use App\Mail\MailRenderer;
use App\Service\Contract\UserAdminServiceInterface;
use App\Service\UserAdminService;
use App\Service\Contract\SlotServiceInterface;
use App\Presenter\ConversationPresenter;
use App\Service\ConversationService;
use App\Repository\Contract\ConversationRepositoryInterface;
use App\Repository\MysqlConversationRepository;
use App\Service\GroupDocumentService;
use App\Service\GroupService;
use App\Service\PasswordChangeService;
use App\Repository\Contract\EmailChangeRepositoryInterface;
use App\Repository\MysqlEmailChangeRepository;
use App\Service\EmailChangeService;
use App\Service\ProfileService;
use App\Service\PasswordResetService;
use App\Service\SlotService;
use App\Service\UserProvisioningService;
use App\View\PhpTemplateRenderer;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
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

    $container->set(MailRenderer::class, fn ($c) => new MailRenderer($c->get(TemplateRendererInterface::class)));

    $container->set(MailerInterface::class, fn () => new \Symfony\Component\Mailer\Mailer(
        Transport::fromDsn($config['mailer']['dsn']),
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

    // Le temps est un port (PSR-20) : le métier dépend de ClockInterface, les tests posent un MockClock.
    $container->set(ClockInterface::class, static fn () => new Clock());

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
        $c->get(MailRenderer::class),
    ));

    $container->set(AccountSecurityService::class, fn ($c) => new AccountSecurityService(
        $c->get(UserRepositoryInterface::class),
        $c->get(PasswordResetRepositoryInterface::class),
        $c->get(MailerInterface::class),
        $c->get(TransactionRunner::class),
        $c->get(PasswordResetService::class),
        $config['mailer']['from'],
        $config['app']['base_url'],
        $c->get(MailRenderer::class),
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
        $c->get(ProfileService::class),
        $c->get(EmailChangeService::class),
    ));

    $container->set(EmailChangeRepositoryInterface::class, fn ($c) => new MysqlEmailChangeRepository($c->get(PDO::class)));

    $container->set(EmailChangeService::class, fn ($c) => new EmailChangeService(
        $c->get(UserRepositoryInterface::class),
        $c->get(EmailChangeRepositoryInterface::class),
        $c->get(PasswordHasherInterface::class),
        $c->get(MailerInterface::class),
        $c->get(TransactionRunner::class),
        $config['mailer']['from'],
        $config['app']['base_url'],
        $c->get(MailRenderer::class),
    ));

    $container->set(ProfileService::class, fn ($c) => new ProfileService($c->get(UserRepositoryInterface::class)));

    $container->set(PasswordResetApiController::class, fn ($c) => new PasswordResetApiController(
        $c->get(PasswordResetService::class),
    ));

    $container->set(AuthApiController::class, fn ($c) => new AuthApiController(
        $c->get(AuthServiceInterface::class),
    ));

    $container->set(AvailabilityApiController::class, fn ($c) => new AvailabilityApiController(
        $c->get(AvailabilityServiceInterface::class),
        $c->get(AuthGuard::class),
    ));

    $container->set(SlotApiController::class, fn ($c) => new SlotApiController(
        $c->get(SlotServiceInterface::class),
        $c->get(AuthGuard::class),
    ));

    $container->set(UserAdminServiceInterface::class, fn ($c) => new UserAdminService(
        $c->get(UserRepositoryInterface::class),
        $c->get(GroupRepositoryInterface::class),
        $c->get(UserProvisioningService::class),
    ));

    $container->set(AdminUserPageController::class, fn ($c) => new AdminUserPageController(
        $c->get(TemplateRendererInterface::class),
        $c->get(CsrfTokenManager::class),
        $c->get(AuthGuard::class),
        $c->get(UserAdminServiceInterface::class),
        $c->get(GroupServiceInterface::class),
    ));

    $container->set(UserAdminApiController::class, fn ($c) => new UserAdminApiController(
        $c->get(UserAdminServiceInterface::class),
        $c->get(AuthGuard::class),
    ));

    $container->set(GroupApiController::class, fn ($c) => new GroupApiController(
        $c->get(GroupServiceInterface::class),
        $c->get(AuthGuard::class),
    ));

    $container->set(ConversationRepositoryInterface::class, fn ($c) => new MysqlConversationRepository($c->get(PDO::class)));

    $container->set(ConversationService::class, fn ($c) => new ConversationService(
        $c->get(ConversationRepositoryInterface::class),
        $c->get(GroupRepositoryInterface::class),
        $c->get(TransactionRunner::class),
        $c->get(ClockInterface::class),
    ));

    $container->set(ConversationPresenter::class, static fn () => new ConversationPresenter());

    $container->set(ConversationApiController::class, fn ($c) => new ConversationApiController(
        $c->get(ConversationService::class),
        $c->get(ConversationPresenter::class),
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
