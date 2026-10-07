<?php

declare(strict_types=1);

namespace App\Tests\Planning\Controller;

use App\Planning\Controller\BookingPageController;
use App\Planning\Entity\FreeSlotBookingStatus;
use App\Account\Entity\UserRole;
use App\Group\Entity\Group;
use App\Account\Entity\User;
use App\Planning\Presenter\MemberBookingsView;
use App\Planning\Repository\MysqlBookingDateLock;
use App\Planning\Repository\MysqlFreeSlotBookingRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Planning\Repository\MysqlRecurringSlotRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Security\Exception\UnauthenticatedException;
use App\Account\Service\AuthService;
use App\Planning\Service\FreeSlotBookingPolicy;
use App\Planning\Service\FreeSlotBookingService;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\InMemorySession;
use App\Tests\Doubles\FastPasswordHasher;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

/** #263 partie 3b-2 : la page « Réserver le local ». */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class BookingPageControllerTest extends RepositoryTestCase
{
    private const PASSWORD = 'mot-de-passe-de-test'; // fixture factice : aucun compte réel

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
        $alice = $users->save(new User(0, 'alice@rehearsalbox.test', (new FastPasswordHasher())->hash(self::PASSWORD), 'Alice', UserRole::Musicien, true, 0, null));
        $users->save(new User(0, 'seul@rehearsalbox.test', (new FastPasswordHasher())->hash(self::PASSWORD), 'Seul', UserRole::Musicien, true, 0, null));
        $this->aliceId = $alice->id();
        $this->alphaId = $this->groups->save(new Group(0, 'Alpha <b>', null, null, 'alpha@example.test'))->id();
        $this->groups->addMember($this->alphaId, $alice->id());
        $this->bookings = new MysqlFreeSlotBookingRepository($this->pdo);
        $this->service = new FreeSlotBookingService($this->bookings, new MysqlBookingDateLock($this->pdo), new MysqlRecurringSlotRepository($this->pdo), $this->groups, new FreeSlotBookingPolicy(), new MockClock('2026-10-04 12:00:00'));
        $this->controller = new BookingPageController(
            new PhpTemplateRenderer(__DIR__ . '/../../../templates'),
            new CsrfTokenManager($session),
            new AuthGuard($this->auth),
            $this->groups,
            $this->service,
            new MemberBookingsView(),
        );
    }

    private function login(string $name): void
    {
        $this->auth->attempt("{$name}@rehearsalbox.test", self::PASSWORD);
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
        self::assertStringNotContainsString('type="time"', $html, 'le sélecteur natif propose toutes les minutes et reste blanc');
        self::assertSame(2, substr_count($html, '<rb-time-picker'), 'un sélecteur d\'horaire par champ');
        self::assertStringContainsString('<input type="hidden" name="start" value="">', $html);
        self::assertStringContainsString('<input type="hidden" name="end" value="">', $html);
        self::assertMatchesRegularExpression('/<select[^>]*id="booking-start"[^>]*data-time-hours/', $html, 'le libellé « De » vise la liste des heures');
        self::assertMatchesRegularExpression('/<select[^>]*id="booking-end"[^>]*data-time-hours/', $html);
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

    #[Test]
    public function testTheTimePickersChooseHoursThenQuarterHoursWithinTheBookableRange(): void
    {
        $this->login('alice');

        $html = $this->controller->index()->body();

        preg_match_all('/<rb-time-picker[^>]*>.*?<\/rb-time-picker>/s', $html, $pickers);
        self::assertCount(2, $pickers[0]);
        [$start, $end] = $pickers[0];
        self::assertStringContainsString('data-min="00:00" data-max="23:45"', $start);
        self::assertStringContainsString('data-min="00:15" data-max="23:30"', $end, 'la fin ne dépasse pas 23:30 et ne peut pas être minuit');
        foreach ([$start, $end] as $picker) {
            preg_match('/<select[^>]*data-time-hours.*?<\/select>/s', $picker, $hours);
            preg_match('/<select[^>]*data-time-minutes.*?<\/select>/s', $picker, $minutes);
            self::assertSame(24, preg_match_all('/<option value="\d\d"/', $hours[0]), 'de 00 à 23');
            self::assertSame(["00", "15", "30", "45"], self::values($minutes[0]));
            self::assertStringContainsString('<option value="">--</option>', $hours[0]);
        }
        self::assertStringContainsString('aria-label="Début : heures"', $start);
        self::assertStringContainsString('aria-label="Fin : minutes"', $end);
    }

    /** @return list<string> les valeurs non vides des options */
    private static function values(string $select): array
    {
        preg_match_all('/<option value="(\d\d)"/', $select, $found);

        return $found[1];
    }
}
