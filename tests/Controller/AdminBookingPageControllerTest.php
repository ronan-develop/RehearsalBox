<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AdminBookingPageController;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\Weekday;
use App\Group\Entity\Group;
use App\Entity\RecurringSlot;
use App\Entity\User;
use App\Presenter\AdminBookingsView;
use App\Repository\MysqlBookingDateLock;
use App\Repository\MysqlFreeSlotBookingRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Repository\MysqlRecurringSlotRepository;
use App\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Security\Exception\AccessDeniedException;
use App\Service\AuthService;
use App\Service\FreeSlotBookingPolicy;
use App\Service\FreeSlotBookingService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;
use App\Tests\Support\FastPasswordHasher;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

/** #263 partie 3a : la page « Réservations » des administrateurs. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class AdminBookingPageControllerTest extends RepositoryTestCase
{
    private AdminBookingPageController $controller;
    private FreeSlotBookingService $service;
    private AuthService $auth;
    private int $aliceId;
    private int $groupId;

    protected function setUp(): void
    {
        parent::setUp();
        $groups = new MysqlGroupRepository($this->pdo);
        $users = new MysqlUserRepository($this->pdo);
        $session = new InMemorySession();
        $this->auth = new AuthService($users, new FastPasswordHasher(), $session, $groups);
        $alice = $users->save(new User(0, 'alice@rehearsalbox.test', password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]), 'Alice', UserRole::Musicien, true, 0, null));
        $users->save(new User(0, 'admin@rehearsalbox.test', password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]), 'Admin', UserRole::Admin, true, 0, null));
        $this->aliceId = $alice->id();
        $this->groupId = $groups->save(new Group(0, 'Groupe <Alpha>', null, null, 'alpha@example.test'))->id();
        $groups->addMember($this->groupId, $alice->id());
        $this->service = new FreeSlotBookingService(new MysqlFreeSlotBookingRepository($this->pdo), new MysqlBookingDateLock($this->pdo), new MysqlRecurringSlotRepository($this->pdo), $groups, new FreeSlotBookingPolicy(), new MockClock('2026-10-04 12:00:00'));
        $this->controller = new AdminBookingPageController(
            new PhpTemplateRenderer(__DIR__ . '/../../templates'),
            new CsrfTokenManager($session),
            new AuthGuard($this->auth),
            $this->service,
            new AdminBookingsView($groups),
        );
    }

    private function login(string $name): void
    {
        $this->auth->attempt("{$name}@rehearsalbox.test", 'password');
    }

    #[Test]
    public function testOnlyAnAdministratorMayOpenThePage(): void
    {
        $this->login('alice');

        $this->expectException(AccessDeniedException::class);
        $this->controller->index();
    }

    #[Test]
    public function testItListsThePendingBookingsWithEscapedTextAndTheDecisionControls(): void
    {
        $booking = $this->service->request($this->aliceId, $this->groupId, new \DateTimeImmutable('2026-10-07'), '09:00', '14:00', 'Enregistrement <b>live</b>');
        $this->login('admin');

        $html = $this->controller->index()->body();

        self::assertStringContainsString('name="csrf-token"', $html);
        self::assertStringContainsString('<rb-booking-card', $html);
        self::assertStringContainsString('data-id="' . $booking->id() . '"', $html);
        self::assertStringContainsString('Groupe &lt;Alpha&gt;', $html);
        self::assertStringNotContainsString('Groupe <Alpha>', $html);
        self::assertStringContainsString('mercredi 7 octobre 2026', $html);
        self::assertStringContainsString('09:00 – 14:00', $html);
        self::assertStringContainsString('Enregistrement &lt;b&gt;live&lt;/b&gt;', $html);
        self::assertStringContainsString('data-booking-approve', $html);
        self::assertStringContainsString('data-booking-refuse', $html);
        self::assertStringContainsString('name="note"', $html);
        self::assertStringContainsString('maxlength="255"', $html, 'le motif de refus tient dans la colonne');
        self::assertSame(1, substr_count($html, '<rb-booking-card'));
    }

    #[Test]
    public function testWithoutAnythingToValidateItSaysSoAndKeepsTheListContainer(): void
    {
        $this->login('admin');

        $html = $this->controller->index()->body();

        self::assertStringContainsString('Aucune réservation à valider.', $html);
        self::assertStringContainsString('data-booking-list', $html, 'le conteneur reste, pour que la page se vide proprement');
        self::assertStringNotContainsString('<rb-booking-card', $html);
    }
}
