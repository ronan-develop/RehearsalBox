<?php

declare(strict_types=1);

namespace App\Tests\Dashboard;

use App\Controller\PageController;
use App\Planning\Entity\FreeSlotBookingStatus;
use App\Account\Entity\UserRole;
use App\Planning\Entity\Weekday;
use App\Group\Entity\Group;
use App\Account\Entity\User;
use App\Dashboard\DashboardBookings;
use App\Planning\Presenter\PlanningDays;
use App\Planning\Presenter\PlanningView;
use App\Planning\Repository\MysqlBookingDateLock;
use App\Planning\Repository\MysqlFreeSlotBookingRepository;
use App\Group\Repository\MysqlGroupDocumentRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Planning\Repository\MysqlRecurringSlotRepository;
use App\Planning\Repository\MysqlSlotExceptionRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Account\Service\AuthService;
use App\Planning\Service\AvailabilityService;
use App\Planning\Service\FreeSlotBookingPolicy;
use App\Planning\Service\FreeSlotBookingService;
use App\Group\Service\GroupService;
use App\Planning\Service\SlotService;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\InMemorySession;
use App\Tests\Doubles\FastPasswordHasher;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;
use App\Tests\Scenarios\TestDashboard;

/** #292 : le bloc « Demandes de créneau » suit à la fois les échanges entre groupes et les réservations libres, et les distingue d'un coup d'œil. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class DashboardRequestsTest extends RepositoryTestCase
{
    private const PASSWORD = 'fixture-secret';

    private PageController $controller;
    private AuthService $auth;
    private MysqlGroupRepository $groups;
    private MysqlRecurringSlotRepository $slots;
    private MysqlSlotExceptionRepository $exceptions;
    private MysqlFreeSlotBookingRepository $bookings;
    private FreeSlotBookingService $service;
    private SlotService $slotService;
    private int $aliceId;
    private int $bobId;
    private int $alphaId;
    private int $betaId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->groups = new MysqlGroupRepository($this->pdo);
        $this->slots = new MysqlRecurringSlotRepository($this->pdo);
        $this->exceptions = new MysqlSlotExceptionRepository($this->pdo);
        $this->bookings = new MysqlFreeSlotBookingRepository($this->pdo);
        $users = new MysqlUserRepository($this->pdo);
        $session = new InMemorySession();
        $hasher = new FastPasswordHasher();
        $this->auth = new AuthService($users, $hasher, $session, $this->groups);
        $this->aliceId = $users->save(new User(0, 'alice@rehearsalbox.test', $hasher->hash(self::PASSWORD), 'Alice', UserRole::Musicien, true, 0, null))->id();
        $this->bobId = $users->save(new User(0, 'bob@rehearsalbox.test', $hasher->hash(self::PASSWORD), 'Bob', UserRole::Musicien, true, 0, null))->id();
        $this->alphaId = $this->groups->save(new Group(0, 'Alpha', null, null, 'alpha@example.test'))->id();
        $this->betaId = $this->groups->save(new Group(0, 'Beta', null, null, 'beta@example.test'))->id();
        $this->groups->addMember($this->alphaId, $this->aliceId);
        $this->groups->addMember($this->betaId, $this->bobId);

        $clock = new MockClock('2026-10-04 12:00:00');
        $this->service = new FreeSlotBookingService($this->bookings, new MysqlBookingDateLock($this->pdo), $this->slots, $this->groups, new FreeSlotBookingPolicy(), $clock);
        $this->slotService = $slotService = new SlotService($this->slots, $this->groups, $this->exceptions);
        $this->controller = new PageController(
            new PhpTemplateRenderer(__DIR__ . '/../../templates'),
            new CsrfTokenManager($session),
            new AuthGuard($this->auth),
            $this->groups,
            $slotService,
            new GroupService($this->groups, $users),
            new MysqlGroupDocumentRepository($this->pdo),
            new \App\Account\Repository\MysqlNotificationPreferenceRepository($this->pdo),
            TestDashboard::view($this->pdo),
        );
    }

    private function login(string $name): void
    {
        $this->auth->attempt("{$name}@rehearsalbox.test", self::PASSWORD);
    }

    /** Le contenu d'un des trois paquets de cartes du bloc (received, sent, archived). */
    private function deck(string $name): string
    {
        $html = $this->controller->dashboard()->body();
        $start = (int) strpos($html, 'data-deck="' . $name . '"');
        $next = strpos($html, 'data-deck="', $start + 10);

        return substr($html, $start, $next === false ? null : $next - $start);
    }

    #[Test]
    public function testAPendingFreeBookingShowsInTheSentColumnAsAReservationWaitingForTheAdmins(): void
    {
        $this->service->request($this->aliceId, $this->alphaId, new \DateTimeImmutable('2026-10-14'), '19:00', '22:00', 'Enregistrement <i>x</i>');
        $this->login('alice');

        $sent = $this->deck('sent');

        self::assertStringContainsString('data-booking-id', $sent);
        self::assertStringContainsString('Réservation', $sent);
        self::assertStringContainsString('À valider par les admins', $sent);
        self::assertStringContainsString('Mercredi 14/10/2026', $sent, 'même présentation de la date que les échanges');
        self::assertStringContainsString('19:00 – 22:00', $sent);
        self::assertStringContainsString('Enregistrement &lt;i&gt;x&lt;/i&gt;', $sent);
        self::assertStringNotContainsString('Enregistrement <i>', $sent);
        self::assertStringContainsString('data-booking-cancel', $sent, 'une réservation en attente s\'annule depuis le bloc');
    }

    #[Test]
    public function testDecidedAndCancelledBookingsMoveToTheArchivedColumnWithTheAnswerAndNoCancelButton(): void
    {
        $refused = $this->service->request($this->aliceId, $this->alphaId, new \DateTimeImmutable('2026-10-14'), '19:00', '20:00', null);
        $validated = $this->service->request($this->aliceId, $this->alphaId, new \DateTimeImmutable('2026-10-15'), '19:00', '20:00', null);
        $now = new \DateTimeImmutable('2026-10-04 12:00:00');
        $this->bookings->decide($refused->id(), FreeSlotBookingStatus::Refusee, $this->bobId, 'Local fermé', $now);
        $this->bookings->decide($validated->id(), FreeSlotBookingStatus::Validee, $this->bobId, null, $now);
        $this->login('alice');

        self::assertStringNotContainsString('data-booking-id', $this->deck('sent'));
        $archived = $this->deck('archived');
        self::assertSame(2, substr_count($archived, 'data-booking-id'));
        self::assertStringContainsString('Refusée', $archived);
        self::assertStringContainsString('Validée', $archived);
        self::assertStringContainsString('Local fermé', $archived);
        self::assertStringNotContainsString('À valider par', $archived, 'une réponse est déjà donnée');
    }

    #[Test]
    public function testFreeBookingsNeverShowInTheReceivedColumnNorForAnotherGroup(): void
    {
        $this->service->request($this->aliceId, $this->alphaId, new \DateTimeImmutable('2026-10-14'), '19:00', '20:00', null);

        $this->login('bob');

        $html = $this->controller->dashboard()->body();
        self::assertStringNotContainsString('data-booking-id', $html, 'les réservations d\'un autre groupe restent invisibles');
        $this->login('alice');
        self::assertStringNotContainsString('data-booking-id', $this->deck('received'));
    }

    #[Test]
    public function testAGroupExchangeIsLabelledAsAnExchangeAndNamesTheGroupThatMustValidate(): void
    {
        $slot = $this->slotService->create($this->betaId, Weekday::Wednesday, '18:00:00', '22:00:00');
        $this->exceptions->createRequest($slot->id(), new \DateTimeImmutable('2026-10-14'), $this->alphaId, $this->aliceId, null);
        $this->login('alice');

        $sent = $this->deck('sent');

        self::assertStringContainsString('Échange', $sent);
        self::assertStringContainsString('À valider par Beta', $sent);
        self::assertStringNotContainsString('data-booking-id', $sent);

        $this->login('bob');
        $received = $this->deck('received');
        self::assertStringContainsString('Échange', $received);
        self::assertStringContainsString('À valider par Beta', $received);
    }

    #[Test]
    public function testBookingsAndExchangesAreMixedByMostRecentFirstInTheSameColumn(): void
    {
        $slot = $this->slotService->create($this->betaId, Weekday::Wednesday, '18:00:00', '22:00:00');
        $this->exceptions->createRequest($slot->id(), new \DateTimeImmutable('2026-10-14'), $this->alphaId, $this->aliceId, null);
        $this->service->request($this->aliceId, $this->alphaId, new \DateTimeImmutable('2026-10-20'), '19:00', '20:00', null);
        $this->login('alice');

        $sent = $this->deck('sent');

        self::assertLessThan(strpos($sent, 'Échange'), strpos($sent, 'Réservation'), 'la plus récente (la réservation) en premier');
    }
}
