<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\BookingPageController;
use App\Entity\Enum\FreeSlotBookingStatus;
use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Presenter\MemberBookingsView;
use App\Repository\MysqlBookingDateLock;
use App\Repository\MysqlFreeSlotBookingRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlRecurringSlotRepository;
use App\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Security\Exception\UnauthenticatedException;
use App\Service\AuthService;
use App\Service\FreeSlotBookingPolicy;
use App\Service\FreeSlotBookingService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;
use App\Tests\Support\FastPasswordHasher;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

/** #263 partie 3b-2 : la page « Réserver le local ». */
final class BookingPageControllerTest extends RepositoryTestCase
{
    private BookingPageController $controller;
    private FreeSlotBookingService $service;
    private MysqlFreeSlotBookingRepository $bookings;
    private AuthService $auth;
    private MysqlGroupRepository $groups;
    private int $aliceId;
    private int $alphaId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->groups = new MysqlGroupRepository($this->pdo);
        $users = new MysqlUserRepository($this->pdo);
        $session = new InMemorySession();
        $this->auth = new AuthService($users, new FastPasswordHasher(), $session, $this->groups);
        $alice = $users->save(new User(0, 'alice@rehearsalbox.test', password_hash('password', PASSWORD_DEFAULT), 'Alice', UserRole::Musicien, true, 0, null));
        $users->save(new User(0, 'seul@rehearsalbox.test', password_hash('password', PASSWORD_DEFAULT), 'Seul', UserRole::Musicien, true, 0, null));
        $this->aliceId = $alice->id();
        $this->alphaId = $this->groups->save(new Group(0, 'Alpha <b>', null, null, 'alpha@example.test'))->id();
        $this->groups->addMember($this->alphaId, $alice->id());
        $this->bookings = new MysqlFreeSlotBookingRepository($this->pdo);
        $this->service = new FreeSlotBookingService($this->bookings, new MysqlBookingDateLock($this->pdo), new MysqlRecurringSlotRepository($this->pdo), $this->groups, new FreeSlotBookingPolicy(), new MockClock('2026-10-04 12:00:00'));
        $this->controller = new BookingPageController(
            new PhpTemplateRenderer(__DIR__ . '/../../templates'),
            new CsrfTokenManager($session),
            new AuthGuard($this->auth),
            $this->groups,
            $this->service,
            new MemberBookingsView(),
        );
    }

    private function login(string $name): void
    {
        $this->auth->attempt("{$name}@rehearsalbox.test", 'password');
    }

    #[Test]
    public function testItRequiresALogin(): void
    {
        $this->expectException(UnauthenticatedException::class);

        $this->controller->index();
    }

    #[Test]
    public function testAMemberSeesTheFormForTheirGroupWithLabelledFieldsAndTheQuarterHourStep(): void
    {
        $this->login('alice');

        $html = $this->controller->index()->body();

        self::assertStringContainsString('name="csrf-token"', $html);
        self::assertStringContainsString('<rb-booking-form', $html);
        self::assertStringContainsString('<input type="hidden" name="groupId" value="' . $this->alphaId . '">', $html, 'un seul groupe : pas de liste à choisir');
        self::assertStringContainsString('Alpha &lt;b&gt;', $html);
        self::assertStringNotContainsString('Alpha <b>', $html);
        foreach (['for="booking-date"', 'for="booking-start"', 'for="booking-end"', 'for="booking-reason"'] as $label) {
            self::assertStringContainsString($label, $html, 'un libellé visible par champ');
        }
        self::assertStringContainsString('type="date"', $html);
        self::assertSame(2, substr_count($html, 'step="900"'), 'début et fin sur le quart d\'heure');
        self::assertStringContainsString('maxlength="255"', $html);
        self::assertStringContainsString('data-booking-plan', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringContainsString('data-booking-submit', $html);
    }

    #[Test]
    public function testAMemberOfSeveralGroupsChoosesWhichOneBooks(): void
    {
        $beta = $this->groups->save(new Group(0, 'Beta', null, null, 'beta@example.test'));
        $this->groups->addMember($beta->id(), $this->aliceId);
        $this->login('alice');

        $html = $this->controller->index()->body();

        self::assertStringContainsString('<select', $html);
        self::assertStringContainsString('<option value="' . $beta->id() . '">Beta</option>', $html);
        self::assertStringNotContainsString('type="hidden" name="groupId"', $html);
    }

    #[Test]
    public function testTheGroupsBookingsAreListedWithTheirStateTheNoteAndACancelButtonOnlyWhenCancellable(): void
    {
        $pending = $this->service->request($this->aliceId, $this->alphaId, new \DateTimeImmutable('2026-10-07'), '09:00', '12:00', 'Enregistrement <i>x</i>');
        $refused = $this->service->request($this->aliceId, $this->alphaId, new \DateTimeImmutable('2026-10-08'), '14:00', '15:00', null);
        $adminId = (new MysqlUserRepository($this->pdo))->save(new User(0, 'admin@rehearsalbox.test', 'hash', 'Admin', UserRole::Admin, true, 0, null))->id();
        $this->bookings->decide($refused->id(), FreeSlotBookingStatus::Refusee, $adminId, 'Local fermé', new \DateTimeImmutable('2026-10-04 12:00:00'));
        $this->login('alice');

        $html = $this->controller->index()->body();

        self::assertSame(2, substr_count($html, '<rb-booking-item'));
        self::assertStringContainsString('mercredi 7 octobre 2026', $html);
        self::assertStringContainsString('09:00 – 12:00', $html);
        self::assertStringContainsString('En attente de validation', $html);
        self::assertStringContainsString('Refusée', $html);
        self::assertStringContainsString('Local fermé', $html);
        self::assertStringContainsString('Enregistrement &lt;i&gt;x&lt;/i&gt;', $html);
        self::assertStringNotContainsString('<i>x</i>', $html);
        self::assertSame(1, substr_count($html, 'data-booking-cancel'), 'seule la réservation en attente s\'annule');
        self::assertStringContainsString('data-id="' . $pending->id() . '"', $html);
    }

    #[Test]
    public function testWithoutAGroupThePageExplainsItAndOffersNoForm(): void
    {
        $this->login('seul');

        $html = $this->controller->index()->body();

        self::assertStringContainsString('appartenir à un groupe', $html);
        self::assertStringNotContainsString('<rb-booking-form', $html);
    }
}
