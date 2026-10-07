<?php

declare(strict_types=1);

use App\Container\Container;
use App\Logging\FileLogger;
use App\Metrics\Collection\HealthProbe;
use App\Metrics\Controller\MetricsPageController;
use App\Metrics\MetricsAccess;
use App\Metrics\Report\HealthReportBuilder;
use App\Metrics\Report\MetricsReaderInterface;
use App\Metrics\Report\MysqlMetricsReader;
use App\Metrics\Report\Thresholds;
use App\Metrics\Collection\IpPseudonymizer;
use App\Metrics\Collection\MetricsMaintenance;
use App\Metrics\Collection\MetricsRecorder;
use App\Metrics\Collection\MetricsRecorderInterface;
use App\Metrics\Collection\MetricsRepositoryInterface;
use App\Metrics\Collection\MysqlMetricsRepository;
use Psr\Log\LoggerInterface;
use App\Account\Controller\Api\AccountApiController;
use App\Account\Controller\Api\AuthApiController;
use App\Planning\Controller\Api\AvailabilityApiController;
use App\Planning\Controller\AdminBookingPageController;
use App\Group\Controller\AdminGroupPageController;
use App\Planning\Controller\BookingPageController;
use App\Account\Controller\AdminUserPageController;
use App\Messaging\Controller\Api\ConversationApiController;
use App\Messaging\Controller\Api\ConversationFeedApiController;
use App\Messaging\Controller\Api\ConversationMuteApiController;
use App\Planning\Controller\Api\FreeSlotBookingApiController;
use App\Messaging\Controller\Api\ConversationTrashApiController;
use App\Group\Controller\Api\GroupApiController;
use App\Account\Controller\Api\UserAdminApiController;
use App\Group\Controller\Api\GroupDocumentApiController;
use App\Group\Controller\Api\GroupSpaceApiController;
use App\Planning\Controller\Api\PlanningFragmentApiController;
use App\Planning\Controller\Api\SlotApiController;
use App\Messaging\Controller\MessagesPageController;
use App\Controller\PageController;
use App\Account\Controller\Api\PasswordResetApiController;
use App\Database\ConnectionFactory;
use App\Database\TransactionRunner;
use App\Group\Repository\GroupImpactRepositoryInterface;
use App\Group\Repository\GroupRepositoryInterface;
use App\Account\Repository\PasswordResetRepositoryInterface;
use App\Planning\Repository\RecurringSlotRepositoryInterface;
use App\Planning\Repository\SlotExceptionRepositoryInterface;
use App\Account\Repository\UserRepositoryInterface;
use App\Group\Repository\GroupDocumentRepositoryInterface;
use App\Group\Repository\MysqlGroupDocumentRepository;
use App\Group\Repository\MysqlGroupImpactRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Account\Repository\MysqlPasswordResetRepository;
use App\Planning\Repository\MysqlRecurringSlotRepository;
use App\Planning\Repository\MysqlSlotExceptionRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Account\Security\NativePasswordHasher;
use App\Security\AppUrl;
use App\Security\NativeSession;
use App\Account\Security\PasswordHasherInterface;
use App\Account\Security\PasswordPolicy;
use App\Security\SessionInterface;
use App\Account\Service\AccountSecurityService;
use App\Account\Service\AuthService;
use App\Planning\Service\AvailabilityService;
use App\Account\Service\AuthServiceInterface;
use App\Planning\Service\AvailabilityServiceInterface;
use App\Group\Service\GroupServiceInterface;
use App\Http\AfterResponseInterface;
use App\Http\DeferredAfterResponse;
use App\Mail\Mailbox;
use App\Mail\MailRenderer;
use App\Account\Service\UserAdminServiceInterface;
use App\Account\Service\UserAdminService;
use App\Planning\Service\SlotServiceInterface;
use App\Planning\Presenter\AdminBookingsView;
use App\Planning\Presenter\MemberBookingsView;
use App\Messaging\Presenter\ConversationFormatter;
use App\Messaging\Presenter\ConversationListView;
use App\Messaging\Presenter\ConversationPresenter;
use App\Messaging\Presenter\ConversationTimeline;
use App\Messaging\Presenter\ConversationUpdates;
use App\Messaging\Presenter\MessagesPageView;
use App\Planning\Presenter\PlanningDays;
use App\Dashboard\DashboardBookings;
use App\Dashboard\DashboardView;
use App\Planning\Presenter\ExceptionalPlanningFragment;
use App\Planning\Presenter\PlanningView;
use App\Messaging\Repository\Participation\ConversationAlertRepositoryInterface;
use App\Messaging\Repository\Notice\ConversationNoticeRepositoryInterface;
use App\Account\Repository\ThrottleEventRepositoryInterface;
use App\Messaging\Repository\Participation\MysqlConversationAlertRepository;
use App\Messaging\Repository\Notice\MysqlConversationNoticeRepository;
use App\Account\Repository\MysqlThrottleEventRepository;
use App\Messaging\Repository\Participation\ConversationGuestRepositoryInterface;
use App\Messaging\Repository\Mention\ConversationMentionRepositoryInterface;
use App\Planning\Repository\BookingDateLockInterface;
use App\Messaging\Repository\Participation\ConversationMuteRepositoryInterface;
use App\Planning\Repository\FreeSlotBookingRepositoryInterface;
use App\Messaging\Repository\Mention\MemberDirectoryInterface;
use App\Messaging\Repository\Notice\MentionNoticeRepositoryInterface;
use App\Messaging\Repository\Notice\MysqlMentionNoticeRepository;
use App\Messaging\Notification\MentionNotifier;
use App\Messaging\Service\MessageEditService;
use App\Messaging\Presenter\EditedMessageFragments;
use App\Messaging\Controller\Api\MessageApiController;
use App\Messaging\Notification\MentionReminderService;
use App\Messaging\Service\MessageVersionPurge;
use App\Account\Repository\NotificationPreferenceRepositoryInterface;
use App\Account\Repository\MysqlNotificationPreferenceRepository;
use App\Messaging\Repository\Participation\MysqlConversationGuestRepository;
use App\Messaging\Repository\Mention\MysqlConversationMentionRepository;
use App\Planning\Repository\MysqlBookingDateLock;
use App\Messaging\Repository\Participation\MysqlConversationMuteRepository;
use App\Planning\Repository\MysqlFreeSlotBookingRepository;
use App\Messaging\Repository\Mention\MysqlMemberDirectory;
use App\Messaging\Service\ConversationAccess;
use App\Messaging\Service\ConversationGuestService;
use App\Messaging\Service\ConversationMuteService;
use App\Planning\Service\BookingNotifier;
use App\Planning\Service\BookingPlanner;
use App\Planning\Service\BookingNotifierInterface;
use App\Planning\Service\FreeSlotBookingPolicy;
use App\Planning\Service\FreeSlotBookingService;
use App\Messaging\Service\Mention\ConversationMentionService;
use App\Messaging\Notification\ConversationNotifier;
use App\Messaging\Service\ConversationTrashService;
use App\Messaging\Service\Mention\MemberSearchService;
use App\Messaging\Controller\Api\MemberApiController;
use App\Messaging\Notification\ConversationReminderService;
use App\Messaging\Service\ConversationService;
use App\Messaging\Service\ConversationReader;
use App\Messaging\Service\ConversationThreadBuilder;
use App\Messaging\Repository\ConversationMessageRepositoryInterface;
use App\Messaging\Repository\MessageVersionRepositoryInterface;
use App\Messaging\Repository\MysqlMessageVersionRepository;
use App\Messaging\Repository\ConversationPresenceRepositoryInterface;
use App\Messaging\Repository\ConversationRepositoryInterface;
use App\Messaging\Repository\Participation\ConversationTrashRepositoryInterface;
use App\Messaging\Repository\MysqlConversationMessageRepository;
use App\Messaging\Repository\MysqlConversationPresenceRepository;
use App\Messaging\Repository\MysqlConversationRepository;
use App\Messaging\Repository\Participation\MysqlConversationTrashRepository;
use App\Group\Service\GroupDocumentService;
use App\Group\Service\GroupService;
use App\Account\Service\IpThrottle;
use App\Account\Service\PasswordChangeService;
use App\Account\Repository\EmailChangeRepositoryInterface;
use App\Account\Repository\MysqlEmailChangeRepository;
use App\Account\Service\EmailChangeService;
use App\Account\Service\ProfileService;
use App\Account\Service\PasswordResetService;
use App\Planning\Service\SlotService;
use App\Account\Service\UserProvisioningService;
use App\View\PhpTemplateRenderer;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use App\View\TemplateRendererInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;

/** @param array<string, mixed> $config */
return static function (array $config): Container {
    $container = new Container();

    // Journal applicatif (#193) : un fichier hors de la racine web, rotation par taille, niveau réglable dans config.local.php.
    $container->set(LoggerInterface::class, fn ($c) => new FileLogger(
        $config['logging']['path'],
        $c->get(ClockInterface::class),
        $config['logging']['level'],
        $config['logging']['max_bytes'],
        $config['logging']['keep'],
    ));
    // Journal du cron : niveau « info » pour prouver, à chaque passage, que la tâche a tourné.
    $container->set('logger.cron', fn ($c) => new FileLogger(
        $config['logging']['cron_path'],
        $c->get(ClockInterface::class),
        'info',
        $config['logging']['max_bytes'],
        $config['logging']['keep'],
    ));

    // Mesures (#195) : un enregistreur par requête (tampon en mémoire), vidé une fois la réponse livrée (public/index.php).
    $container->set(MetricsRepositoryInterface::class, fn ($c) => new MysqlMetricsRepository($c->get(PDO::class)));
    $container->set(MetricsRecorderInterface::class, fn ($c) => new MetricsRecorder(
        $c->get(MetricsRepositoryInterface::class),
        new IpPseudonymizer($config['metrics']['secret']),
        $c->get(ClockInterface::class),
        $c->get(LoggerInterface::class),
    ));
    $container->set(MetricsReaderInterface::class, fn ($c) => new MysqlMetricsReader($c->get(PDO::class)));
    $container->set(MetricsPageController::class, fn ($c) => new MetricsPageController(
        $c->get(TemplateRendererInterface::class),
        $c->get(AuthGuard::class),
        new MetricsAccess((string) $config['metrics']['viewer_email']),
        new HealthReportBuilder(
            $c->get(MetricsReaderInterface::class),
            new Thresholds($config['metrics']['thresholds']),
            $c->get(ClockInterface::class),
            new \DateTimeZone($config['app']['timezone']),
        ),
    ));
    $container->set(MetricsMaintenance::class, fn ($c) => new MetricsMaintenance(
        $c->get(MetricsRepositoryInterface::class),
        new HealthProbe(
            $c->get(MetricsRepositoryInterface::class),
            $c->get(ClockInterface::class),
            __DIR__ . '/../storage',
            $config['metrics']['backup_dir'],
            $config['logging']['cron_path'],
            __DIR__ . '/../RELEASE',
        ),
        $c->get(ClockInterface::class),
    ));

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
        $c->get(Mailbox::class),
        $c->get(UserRepositoryInterface::class),
        $c->get(GroupRepositoryInterface::class),
        $c->get(AfterResponseInterface::class),
        $c->get(LoggerInterface::class),
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

    $container->set(BookingPlanner::class, fn ($c) => new BookingPlanner(
        $c->get(RecurringSlotRepositoryInterface::class),
        $c->get(GroupRepositoryInterface::class),
        $c->get(FreeSlotBookingRepositoryInterface::class),
        new FreeSlotBookingPolicy(),
        $c->get(ClockInterface::class),
    ));

    $container->set(FreeSlotBookingApiController::class, fn ($c) => new FreeSlotBookingApiController(
        $c->get(FreeSlotBookingService::class),
        $c->get(BookingPlanner::class),
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
    $container->set(Mailbox::class, fn ($c) => new Mailbox(
        $c->get(MailerInterface::class),
        $config['mailer']['from'],
        $config['app']['base_url'],
        $c->get(MailRenderer::class),
        $c->get(LoggerInterface::class),
        $c->get(MetricsRecorderInterface::class),
    ));

    $container->set(MailerInterface::class, fn () => new \Symfony\Component\Mailer\Mailer(
        Transport::fromDsn($config['mailer']['dsn']),
    ));

    $container->set(TemplateRendererInterface::class, fn () => new PhpTemplateRenderer(__DIR__ . '/../templates'));

    $container->set(PageController::class, fn ($c) => new PageController(
        $c->get(TemplateRendererInterface::class),
        $c->get(CsrfTokenManager::class),
        $c->get(AuthGuard::class),
        $c->get(GroupRepositoryInterface::class),
        $c->get(SlotServiceInterface::class),
        $c->get(GroupServiceInterface::class),
        $c->get(GroupDocumentRepositoryInterface::class),
        $c->get(NotificationPreferenceRepositoryInterface::class),
        new DashboardView(
            $c->get(AvailabilityServiceInterface::class),
            $c->get(GroupRepositoryInterface::class),
            $c->get(SlotServiceInterface::class),
            $c->get(PlanningView::class),
            new DashboardBookings($c->get(FreeSlotBookingService::class)),
        ),
    ));

    $container->set(PlanningFragmentApiController::class, fn ($c) => new PlanningFragmentApiController(
        $c->get(AuthGuard::class),
        new ExceptionalPlanningFragment($c->get(SlotServiceInterface::class), $c->get(TemplateRendererInterface::class)),
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
        $c->get(Mailbox::class),
        $c->get(TransactionRunner::class),
        $c->get(AfterResponseInterface::class),
    ));

    $container->set(AccountSecurityService::class, fn ($c) => new AccountSecurityService(
        $c->get(UserRepositoryInterface::class),
        $c->get(PasswordResetRepositoryInterface::class),
        $c->get(Mailbox::class),
        $c->get(TransactionRunner::class),
        $c->get(PasswordResetService::class),
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
        $c->get(Mailbox::class),
        $c->get(TransactionRunner::class),
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
    $container->set(AfterResponseInterface::class, static fn ($c) => new DeferredAfterResponse(DeferredAfterResponse::finishRequest(...), $c->get(LoggerInterface::class)));

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

    $container->set(BookingPageController::class, fn ($c) => new BookingPageController(
        $c->get(TemplateRendererInterface::class),
        $c->get(CsrfTokenManager::class),
        $c->get(AuthGuard::class),
        $c->get(GroupRepositoryInterface::class),
        $c->get(FreeSlotBookingService::class),
        new MemberBookingsView(),
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
        $c->get(Mailbox::class),
        $c->get(ConversationNoticeRepositoryInterface::class),
        $c->get(LoggerInterface::class),
    ));

    $container->set(ConversationReminderService::class, fn ($c) => new ConversationReminderService(
        $c->get(ConversationNoticeRepositoryInterface::class),
        $c->get(Mailbox::class),
        $c->get(ClockInterface::class),
        new \DateTimeZone($config['app']['timezone']),
        $c->get(LoggerInterface::class),
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
        $c->get(Mailbox::class),
        $c->get(MentionNoticeRepositoryInterface::class),
        $c->get(UserRepositoryInterface::class),
        $c->get(NotificationPreferenceRepositoryInterface::class),
        $c->get(ConversationMuteRepositoryInterface::class),
        $c->get(LoggerInterface::class),
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
        $c->get(Mailbox::class),
        $c->get(ClockInterface::class),
        new \DateTimeZone($config['app']['timezone']),
        $c->get(LoggerInterface::class),
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
        $c->get(LoggerInterface::class),
    ));

    return $container;
};
