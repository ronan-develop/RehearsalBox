<?php

declare(strict_types=1);

use App\Container\Container;
use App\Controller\Api\AccountApiController;
use App\Controller\Api\AuthApiController;
use App\Controller\Api\AvailabilityApiController;
use App\Controller\AdminBookingPageController;
use App\Controller\AdminGroupPageController;
use App\Controller\AdminUserPageController;
use App\Controller\Api\ConversationApiController;
use App\Controller\Api\ConversationFeedApiController;
use App\Controller\Api\ConversationMuteApiController;
use App\Controller\Api\FreeSlotBookingApiController;
use App\Controller\Api\ConversationTrashApiController;
use App\Controller\Api\GroupApiController;
use App\Controller\Api\UserAdminApiController;
use App\Controller\Api\GroupDocumentApiController;
use App\Controller\Api\GroupSpaceApiController;
use App\Controller\Api\SlotApiController;
use App\Controller\MessagesPageController;
use App\Controller\PageController;
use App\Controller\Api\PasswordResetApiController;
use App\Database\ConnectionFactory;
use App\Database\TransactionRunner;
use App\Repository\Contract\GroupImpactRepositoryInterface;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Repository\Contract\PasswordResetRepositoryInterface;
use App\Repository\Contract\RecurringSlotRepositoryInterface;
use App\Repository\Contract\SlotExceptionRepositoryInterface;
use App\Repository\Contract\UserRepositoryInterface;
use App\Repository\Contract\GroupDocumentRepositoryInterface;
use App\Repository\MysqlGroupDocumentRepository;
use App\Repository\MysqlGroupImpactRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlPasswordResetRepository;
use App\Repository\MysqlRecurringSlotRepository;
use App\Repository\MysqlSlotExceptionRepository;
use App\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Security\NativePasswordHasher;
use App\Security\AppUrl;
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
use App\Http\AfterResponseInterface;
use App\Http\DeferredAfterResponse;
use App\Mail\MailRenderer;
use App\Service\Contract\UserAdminServiceInterface;
use App\Service\UserAdminService;
use App\Service\Contract\SlotServiceInterface;
use App\Presenter\AdminBookingsView;
use App\Presenter\ConversationFormatter;
use App\Presenter\ConversationListView;
use App\Presenter\ConversationPresenter;
use App\Presenter\ConversationTimeline;
use App\Presenter\ConversationUpdates;
use App\Presenter\MessagesPageView;
use App\Presenter\PlanningDays;
use App\Presenter\PlanningView;
use App\Repository\Contract\ConversationAlertRepositoryInterface;
use App\Repository\Contract\ConversationNoticeRepositoryInterface;
use App\Repository\Contract\ThrottleEventRepositoryInterface;
use App\Repository\MysqlConversationAlertRepository;
use App\Repository\MysqlConversationNoticeRepository;
use App\Repository\MysqlThrottleEventRepository;
use App\Repository\Contract\ConversationGuestRepositoryInterface;
use App\Repository\Contract\ConversationMentionRepositoryInterface;
use App\Repository\Contract\BookingDateLockInterface;
use App\Repository\Contract\ConversationMuteRepositoryInterface;
use App\Repository\Contract\FreeSlotBookingRepositoryInterface;
use App\Repository\Contract\MemberDirectoryInterface;
use App\Repository\Contract\MentionNoticeRepositoryInterface;
use App\Repository\MysqlMentionNoticeRepository;
use App\Service\MentionNotifier;
use App\Service\MessageEditService;
use App\Presenter\EditedMessageFragments;
use App\Controller\Api\MessageApiController;
use App\Service\MentionReminderService;
use App\Service\MessageVersionPurge;
use App\Repository\Contract\NotificationPreferenceRepositoryInterface;
use App\Repository\MysqlNotificationPreferenceRepository;
use App\Repository\MysqlConversationGuestRepository;
use App\Repository\MysqlConversationMentionRepository;
use App\Repository\MysqlBookingDateLock;
use App\Repository\MysqlConversationMuteRepository;
use App\Repository\MysqlFreeSlotBookingRepository;
use App\Repository\MysqlMemberDirectory;
use App\Service\ConversationAccess;
use App\Service\ConversationGuestService;
use App\Service\ConversationMuteService;
use App\Service\BookingNotifier;
use App\Service\Contract\BookingNotifierInterface;
use App\Service\FreeSlotBookingPolicy;
use App\Service\FreeSlotBookingService;
use App\Service\ConversationMentionService;
use App\Service\ConversationNotifier;
use App\Service\ConversationTrashService;
use App\Service\MemberSearchService;
use App\Controller\Api\MemberApiController;
use App\Service\ConversationReminderService;
use App\Service\ConversationService;
use App\Service\ConversationReader;
use App\Service\ConversationThreadBuilder;
use App\Repository\Contract\ConversationMessageRepositoryInterface;
use App\Repository\Contract\MessageVersionRepositoryInterface;
use App\Repository\MysqlMessageVersionRepository;
use App\Repository\Contract\ConversationPresenceRepositoryInterface;
use App\Repository\Contract\ConversationRepositoryInterface;
use App\Repository\Contract\ConversationTrashRepositoryInterface;
use App\Repository\MysqlConversationMessageRepository;
use App\Repository\MysqlConversationPresenceRepository;
use App\Repository\MysqlConversationRepository;
use App\Repository\MysqlConversationTrashRepository;
use App\Service\GroupDocumentService;
use App\Service\GroupService;
use App\Service\IpThrottle;
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
    // `use ($config)` : sans lui, l'URL publique est lue vide et le cookie perd Secure et le préfixe __Host- (#244).
    $container->set(SessionInterface::class, function () use ($config) {
        $session = new NativeSession(AppUrl::isHttps((string) $config['app']['base_url']));
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

    $container->set(FreeSlotBookingRepositoryInterface::class, fn ($c) => new MysqlFreeSlotBookingRepository($c->get(PDO::class)));

    $container->set(BookingDateLockInterface::class, fn ($c) => new MysqlBookingDateLock($c->get(PDO::class)));

    $container->set(BookingNotifierInterface::class, fn ($c) => new BookingNotifier(
        $c->get(MailerInterface::class),
        $c->get(UserRepositoryInterface::class),
        $c->get(GroupRepositoryInterface::class),
        $c->get(AfterResponseInterface::class),
        $config['mailer']['from'],
        $config['app']['base_url'],
        $c->get(MailRenderer::class),
    ));

    $container->set(FreeSlotBookingService::class, fn ($c) => new FreeSlotBookingService(
        $c->get(FreeSlotBookingRepositoryInterface::class),
        $c->get(BookingDateLockInterface::class),
        $c->get(RecurringSlotRepositoryInterface::class),
        $c->get(GroupRepositoryInterface::class),
        new FreeSlotBookingPolicy(),
        $c->get(ClockInterface::class),
        $c->get(BookingNotifierInterface::class),
    ));

    $container->set(FreeSlotBookingApiController::class, fn ($c) => new FreeSlotBookingApiController(
        $c->get(FreeSlotBookingService::class),
        $c->get(AuthGuard::class),
    ));

    $container->set(SlotServiceInterface::class, fn ($c) => new SlotService(
        $c->get(RecurringSlotRepositoryInterface::class),
        $c->get(GroupRepositoryInterface::class),
        $c->get(SlotExceptionRepositoryInterface::class),
        $c->get(FreeSlotBookingRepositoryInterface::class),
    ));

    $container->set(GroupServiceInterface::class, fn ($c) => new GroupService(
        $c->get(GroupRepositoryInterface::class),
        $c->get(UserRepositoryInterface::class),
        $c->get(GroupDocumentService::class),
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
        $c->get(PlanningView::class),
        $c->get(NotificationPreferenceRepositoryInterface::class),
    ));

    $container->set(PlanningView::class, fn ($c) => new PlanningView(
        $c->get(SlotServiceInterface::class),
        new PlanningDays(),
        $c->get(ClockInterface::class),
        new \DateTimeZone($config['app']['timezone']),
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
        $c->get(AfterResponseInterface::class),
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
        $c->get(PasswordResetRepositoryInterface::class),
        $c->get(PasswordHasherInterface::class),
        $c->get(MailerInterface::class),
        $c->get(TransactionRunner::class),
        $config['mailer']['from'],
        $config['app']['base_url'],
        $c->get(MailRenderer::class),
    ));

    $container->set(NotificationPreferenceRepositoryInterface::class, fn ($c) => new MysqlNotificationPreferenceRepository($c->get(PDO::class)));
    $container->set(ProfileService::class, fn ($c) => new ProfileService($c->get(UserRepositoryInterface::class), $c->get(NotificationPreferenceRepositoryInterface::class)));

    $container->set(PasswordResetApiController::class, fn ($c) => new PasswordResetApiController(
        $c->get(PasswordResetService::class),
        $c->get('throttle.password-reset'),
    ));

    // Limites par adresse : une instance par route sensible, chacune avec son étiquette (#218, #219).
    $container->set(ThrottleEventRepositoryInterface::class, fn ($c) => new MysqlThrottleEventRepository($c->get(PDO::class)));
    $container->set('throttle.login', fn ($c) => new IpThrottle($c->get(ThrottleEventRepositoryInterface::class), 'login', 20, '-15 minutes'));
    $container->set('throttle.password-reset', fn ($c) => new IpThrottle($c->get(ThrottleEventRepositoryInterface::class), 'password-reset', 10, '-1 hour'));

    // Travail fait APRÈS l'envoi de la réponse (le front controller appelle run()) : sa durée ne dépend plus du compte (#219).
    $container->set(AfterResponseInterface::class, static fn () => new DeferredAfterResponse(DeferredAfterResponse::finishRequest(...)));

    $container->set(AuthApiController::class, fn ($c) => new AuthApiController(
        $c->get(AuthServiceInterface::class),
        $c->get('throttle.login'),
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

    $container->set(GroupImpactRepositoryInterface::class, fn ($c) => new MysqlGroupImpactRepository($c->get(PDO::class)));

    $container->set(AdminGroupPageController::class, fn ($c) => new AdminGroupPageController(
        $c->get(TemplateRendererInterface::class),
        $c->get(CsrfTokenManager::class),
        $c->get(AuthGuard::class),
        $c->get(GroupServiceInterface::class),
        $c->get(GroupImpactRepositoryInterface::class),
    ));

    $container->set(AdminBookingPageController::class, fn ($c) => new AdminBookingPageController(
        $c->get(TemplateRendererInterface::class),
        $c->get(CsrfTokenManager::class),
        $c->get(AuthGuard::class),
        $c->get(FreeSlotBookingService::class),
        new AdminBookingsView($c->get(GroupRepositoryInterface::class)),
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
    $container->set(MessageVersionRepositoryInterface::class, fn ($c) => new MysqlMessageVersionRepository($c->get(PDO::class)));
    $container->set(ConversationMessageRepositoryInterface::class, fn ($c) => new MysqlConversationMessageRepository($c->get(PDO::class)));
    $container->set(ConversationPresenceRepositoryInterface::class, fn ($c) => new MysqlConversationPresenceRepository($c->get(PDO::class)));
    $container->set(ConversationTrashRepositoryInterface::class, fn ($c) => new MysqlConversationTrashRepository($c->get(PDO::class)));

    $container->set(ConversationNoticeRepositoryInterface::class, fn ($c) => new MysqlConversationNoticeRepository($c->get(PDO::class)));

    $container->set(ConversationAlertRepositoryInterface::class, fn ($c) => new MysqlConversationAlertRepository($c->get(PDO::class)));

    $container->set(ConversationNotifier::class, fn ($c) => new ConversationNotifier(
        $c->get(MailerInterface::class),
        $c->get(ConversationNoticeRepositoryInterface::class),
        $config['mailer']['from'],
        $config['app']['base_url'],
        $c->get(MailRenderer::class),
    ));

    $container->set(ConversationReminderService::class, fn ($c) => new ConversationReminderService(
        $c->get(ConversationNoticeRepositoryInterface::class),
        $c->get(MailerInterface::class),
        $c->get(ClockInterface::class),
        new \DateTimeZone($config['app']['timezone']),
        $config['mailer']['from'],
        $config['app']['base_url'],
        $c->get(MailRenderer::class),
    ));

    $container->set(ConversationGuestRepositoryInterface::class, fn ($c) => new MysqlConversationGuestRepository($c->get(PDO::class)));
    $container->set(ConversationMentionRepositoryInterface::class, fn ($c) => new MysqlConversationMentionRepository($c->get(PDO::class)));
    $container->set(MemberDirectoryInterface::class, fn ($c) => new MysqlMemberDirectory($c->get(PDO::class)));

    // Règle d'accès unique de la messagerie (membre d'un des deux groupes ou invité) puis un service par responsabilité.
    $container->set(ConversationAccess::class, fn ($c) => new ConversationAccess(
        $c->get(ConversationRepositoryInterface::class),
        $c->get(GroupRepositoryInterface::class),
        $c->get(ConversationGuestRepositoryInterface::class),
    ));

    $container->set(ConversationMuteRepositoryInterface::class, fn ($c) => new MysqlConversationMuteRepository($c->get(PDO::class)));

    $container->set(MentionNoticeRepositoryInterface::class, fn ($c) => new MysqlMentionNoticeRepository($c->get(PDO::class)));

    $container->set(MentionNotifier::class, fn ($c) => new MentionNotifier(
        $c->get(MailerInterface::class),
        $c->get(MentionNoticeRepositoryInterface::class),
        $c->get(UserRepositoryInterface::class),
        $c->get(NotificationPreferenceRepositoryInterface::class),
        $c->get(ConversationMuteRepositoryInterface::class),
        $config['mailer']['from'],
        $config['app']['base_url'],
        $c->get(MailRenderer::class),
    ));

    $container->set(ConversationMentionService::class, fn ($c) => new ConversationMentionService(
        $c->get(UserRepositoryInterface::class),
        $c->get(GroupRepositoryInterface::class),
        $c->get(ConversationGuestRepositoryInterface::class),
        $c->get(ConversationMentionRepositoryInterface::class),
        $c->get(ConversationMessageRepositoryInterface::class),
        $c->get(MentionNotifier::class),
    ));

    $container->set(MentionReminderService::class, fn ($c) => new MentionReminderService(
        $c->get(MentionNoticeRepositoryInterface::class),
        $c->get(MailerInterface::class),
        $c->get(ClockInterface::class),
        new \DateTimeZone($config['app']['timezone']),
        $config['mailer']['from'],
        $config['app']['base_url'],
        $c->get(MailRenderer::class),
    ));

    $container->set(MessageVersionPurge::class, fn ($c) => new MessageVersionPurge(
        $c->get(MessageVersionRepositoryInterface::class),
        $c->get(ClockInterface::class),
    ));

    $container->set(ConversationService::class, fn ($c) => new ConversationService(
        $c->get(ConversationRepositoryInterface::class),
        $c->get(ConversationMessageRepositoryInterface::class),
        $c->get(ConversationPresenceRepositoryInterface::class),
        $c->get(GroupRepositoryInterface::class),
        $c->get(TransactionRunner::class),
        $c->get(ClockInterface::class),
        notifier: $c->get(ConversationNotifier::class),
        mentions: $c->get(ConversationMentionService::class),
        access: $c->get(ConversationAccess::class),
    ));

    $container->set(ConversationThreadBuilder::class, fn ($c) => new ConversationThreadBuilder(
        $c->get(ConversationRepositoryInterface::class),
        $c->get(ConversationMessageRepositoryInterface::class),
        $c->get(ConversationPresenceRepositoryInterface::class),
        $c->get(GroupRepositoryInterface::class),
        $c->get(ClockInterface::class),
        $c->get(ConversationMentionService::class),
    ));

    $container->set(ConversationReader::class, fn ($c) => new ConversationReader(
        $c->get(ConversationAccess::class),
        $c->get(ConversationThreadBuilder::class),
        $c->get(ConversationRepositoryInterface::class),
        $c->get(ConversationMessageRepositoryInterface::class),
        $c->get(ConversationPresenceRepositoryInterface::class),
        $c->get(ClockInterface::class),
        $c->get(ConversationMentionService::class),
    ));

    $container->set(ConversationTrashService::class, fn ($c) => new ConversationTrashService(
        $c->get(ConversationAccess::class),
        $c->get(ConversationTrashRepositoryInterface::class),
        $c->get(TransactionRunner::class),
        $c->get(ClockInterface::class),
        $c->get(ConversationAlertRepositoryInterface::class),
    ));

    $container->set(ConversationMuteService::class, fn ($c) => new ConversationMuteService(
        $c->get(ConversationAccess::class),
        $c->get(ConversationMuteRepositoryInterface::class),
    ));

    $container->set(ConversationGuestService::class, fn ($c) => new ConversationGuestService(
        $c->get(ConversationAccess::class),
        $c->get(ConversationGuestRepositoryInterface::class),
        $c->get(ConversationMessageRepositoryInterface::class),
        $c->get(UserRepositoryInterface::class),
        $c->get(TransactionRunner::class),
        $c->get(ClockInterface::class),
    ));

    $container->set(ConversationPresenter::class, static fn () => new ConversationPresenter());

    // Affichage de la messagerie rendu par le serveur (#183) : heures et jours dans le fuseau de l'application.
    $container->set(ConversationFormatter::class, static fn () => new ConversationFormatter(new \DateTimeZone($config['app']['timezone'])));
    $container->set(ConversationTimeline::class, fn ($c) => new ConversationTimeline($c->get(ConversationFormatter::class)));
    $container->set(ConversationListView::class, fn ($c) => new ConversationListView($c->get(ConversationFormatter::class)));
    $container->set(MessagesPageView::class, fn ($c) => new MessagesPageView(
        $c->get(ConversationReader::class),
        $c->get(ConversationListView::class),
        $c->get(ConversationTimeline::class),
        $c->get(ConversationFormatter::class),
        $c->get(ClockInterface::class),
        $c->get(ConversationTrashService::class),
    ));

    $container->set(MessagesPageController::class, fn ($c) => new MessagesPageController(
        $c->get(TemplateRendererInterface::class),
        $c->get(CsrfTokenManager::class),
        $c->get(AuthGuard::class),
        $c->get(ConversationReader::class),
        $c->get(GroupRepositoryInterface::class),
        $c->get(MessagesPageView::class),
    ));

    $container->set(ConversationUpdates::class, fn ($c) => new ConversationUpdates(
        $c->get(ConversationReader::class),
        $c->get(MessagesPageView::class),
        $c->get(TemplateRendererInterface::class),
        $c->get(EditedMessageFragments::class),
    ));

    $container->set(ConversationApiController::class, fn ($c) => new ConversationApiController(
        $c->get(ConversationService::class),
        $c->get(ConversationUpdates::class),
        $c->get(AuthGuard::class),
        $c->get(ConversationGuestService::class),
    ));

    $container->set(ConversationFeedApiController::class, fn ($c) => new ConversationFeedApiController(
        $c->get(ConversationReader::class),
        $c->get(ConversationUpdates::class),
        $c->get(ConversationPresenter::class),
        $c->get(AuthGuard::class),
        $c->get(MessagesPageView::class),
        $c->get(TemplateRendererInterface::class),
        $c->get(ConversationTrashService::class),
    ));

    $container->set(MessageEditService::class, fn ($c) => new MessageEditService(
        $c->get(ConversationAccess::class),
        $c->get(ConversationMessageRepositoryInterface::class),
        $c->get(ConversationMentionService::class),
        $c->get(TransactionRunner::class),
        $c->get(ClockInterface::class),
    ));

    $container->set(EditedMessageFragments::class, fn ($c) => new EditedMessageFragments(
        $c->get(TemplateRendererInterface::class),
        $c->get(MessagesPageView::class),
    ));

    $container->set(MessageApiController::class, fn ($c) => new MessageApiController(
        $c->get(MessageEditService::class),
        $c->get(AuthGuard::class),
        $c->get(EditedMessageFragments::class),
        $c->get(ConversationReader::class),
    ));

    $container->set(ConversationTrashApiController::class, fn ($c) => new ConversationTrashApiController(
        $c->get(ConversationTrashService::class),
        $c->get(AuthGuard::class),
    ));

    $container->set(ConversationMuteApiController::class, fn ($c) => new ConversationMuteApiController(
        $c->get(ConversationMuteService::class),
        $c->get(AuthGuard::class),
    ));

    $container->set(MemberSearchService::class, fn ($c) => new MemberSearchService(
        $c->get(MemberDirectoryInterface::class),
        $c->get(ConversationAccess::class),
        $c->get(GroupRepositoryInterface::class),
        $c->get(ConversationGuestRepositoryInterface::class),
    ));

    $container->set(MemberApiController::class, fn ($c) => new MemberApiController(
        $c->get(MemberSearchService::class),
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
        $c->get(TransactionRunner::class),
        $config['storage']['group_documents_path'],
        20,
    ));

    $container->set(GroupDocumentApiController::class, fn ($c) => new GroupDocumentApiController(
        $c->get(GroupDocumentService::class),
        $c->get(AuthGuard::class),
    ));

    return $container;
};
