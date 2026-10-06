<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\FreeSlotBookingApiController;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\Weekday;
use App\Entity\Group;
use App\Entity\RecurringSlot;
use App\Entity\User;
use App\Http\Request;
use App\Repository\MysqlFreeSlotBookingRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlRecurringSlotRepository;
use App\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\Exception\AccessDeniedException;
use App\Security\Exception\UnauthenticatedException;
use App\Service\AuthService;
use App\Service\FreeSlotBookingPolicy;
use App\Service\FreeSlotBookingService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;
use App\Tests\Support\FastPasswordHasher;
use App\Tests\Support\KernelTranslation;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

/** #263 partie 2 : l'API des réservations libres. */
final class FreeSlotBookingApiControllerTest extends RepositoryTestCase
{
    private KernelTranslation $api;
    private AuthService $auth;
    private int $alphaId;
    private int $betaId;

    protected function setUp(): void
    {
        parent::setUp();
        $groups = new MysqlGroupRepository($this->pdo);
        $users = new MysqlUserRepository($this->pdo);
        $this->auth = new AuthService($users, new FastPasswordHasher(), new InMemorySession(), $groups);
        $alice = $this->user($users, 'alice', UserRole::Musicien);
        $this->user($users, 'carole', UserRole::Musicien);
        $this->user($users, 'admin', UserRole::Admin);
        $this->alphaId = $groups->save(new Group(0, 'Alpha', null, null, 'alpha@example.test'))->id();
        $this->betaId = $groups->save(new Group(0, 'Beta', null, null, 'beta@example.test'))->id();
        $groups->addMember($this->alphaId, $alice->id());
        $slots = new MysqlRecurringSlotRepository($this->pdo);
        $slots->save(new RecurringSlot(0, $this->betaId, Weekday::Wednesday, '18:30:00', '22:45:00', true));
        $service = new FreeSlotBookingService(new MysqlFreeSlotBookingRepository($this->pdo), new \App\Repository\MysqlBookingDateLock($this->pdo), $slots, $groups, new FreeSlotBookingPolicy(), new MockClock('2026-10-04 12:00:00'));
        $this->api = new KernelTranslation(new FreeSlotBookingApiController($service, new AuthGuard($this->auth)));
    }

    private function user(MysqlUserRepository $users, string $name, UserRole $role): User
    {
        return $users->save(new User(0, "{$name}@rehearsalbox.test", password_hash('password', PASSWORD_DEFAULT), ucfirst($name), $role, true, 0, null));
    }

    private function login(string $name): void
    {
        $this->auth->attempt("{$name}@rehearsalbox.test", 'password');
    }

    /** @param array<string, mixed> $body */
    private function book(array $body = []): \App\Http\Response
    {
        return $this->api->store(new Request('POST', '/api/bookings', [], array_replace([
            'groupId' => $this->alphaId, 'bookingDate' => '2026-10-07', 'startTime' => '09:00', 'endTime' => '12:00', 'reason' => 'Enregistrement',
        ], $body), []));
    }

    /** @return array<string, mixed> */
    private function json(\App\Http\Response $response): array
    {
        return json_decode($response->body(), true) ?? [];
    }

    #[Test]
    public function testEveryRouteRequiresALoginAndTheAdminRoutesAnAdmin(): void
    {
        foreach ([['store', []], ['destroy', ['1']], ['index', []], ['pending', []], ['approve', ['1']], ['refuse', ['1']]] as [$action, $args]) {
            try {
                $this->api->{$action}(new Request('GET', '/x', ['groupId' => (string) $this->alphaId], [], []), ...$args);
                self::fail("connexion exigée : {$action}");
            } catch (UnauthenticatedException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->login('alice');
        foreach (['pending', 'approve', 'refuse'] as $action) {
            try {
                $this->api->{$action}(new Request('GET', '/x', [], [], []), ...($action === 'pending' ? [] : ['1']));
                self::fail("administrateur exigé : {$action}");
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function testAMemberBooksAndSeesTheirGroupsBookings(): void
    {
        $this->login('alice');

        $created = $this->book();
        self::assertSame(201, $created->statusCode());
        $booking = $this->json($created)['booking'];
        self::assertSame(['en_attente', '2026-10-07', '09:00', '12:00'], [$booking['status'], $booking['bookingDate'], $booking['startTime'], $booking['endTime']]);
        self::assertSame($this->alphaId, $booking['groupId']);

        $list = $this->api->index(new Request('GET', '/api/bookings', ['groupId' => (string) $this->alphaId], [], []));
        self::assertSame([$booking['id']], array_column($this->json($list)['bookings'], 'id'));
    }

    #[Test]
    public function testBadInputIsRefusedPerFieldAndATakenRangeIsAConflict(): void
    {
        $this->login('alice');

        $invalid = $this->book(['startTime' => '09:10']);
        self::assertSame(422, $invalid->statusCode());
        self::assertArrayHasKey('startTime', $this->json($invalid)['fields']);
        $badDate = $this->book(['bookingDate' => '07/10/2026']);
        self::assertSame(422, $badDate->statusCode());
        self::assertArrayHasKey('bookingDate', $this->json($badDate)['fields']);

        $fixed = $this->book(['startTime' => '18:00', 'endTime' => '19:00']);
        self::assertSame(409, $fixed->statusCode(), 'chevauche un créneau fixe');
        self::assertStringNotContainsString('Beta', $fixed->body());
    }

    #[Test]
    public function testABookingForAnotherGroupOrAMalformedGroupIsRefusedLikeAnUnknownOne(): void
    {
        $this->login('alice');

        foreach ([$this->betaId, 999999, 'abc', null] as $group) {
            try {
                $this->book(['groupId' => $group]);
                self::fail('accès refusé attendu');
            } catch (AccessDeniedException $e) {
                self::assertSame('Accès refusé.', $e->getMessage());
            }
        }
    }

    #[Test]
    public function testAnAdminListsPendingBookingsAndApprovesOrRefusesOnlyOnce(): void
    {
        $this->login('alice');
        $first = $this->json($this->book())['booking']['id'];
        $second = $this->json($this->book(['startTime' => '13:00', 'endTime' => '14:00']))['booking']['id'];

        $this->login('admin');
        self::assertSame([$first, $second], array_column($this->json($this->api->pending(new Request('GET', '/api/admin/bookings', [], [], [])))['bookings'], 'id'));

        $approved = $this->api->approve(new Request('POST', '/x', [], [], []), (string) $first);
        self::assertSame('validee', $this->json($approved)['booking']['status']);
        self::assertSame(409, $this->api->refuse(new Request('POST', '/x', [], ['note' => 'trop tard'], []), (string) $first)->statusCode(), 'le second perd');

        $refused = $this->api->refuse(new Request('POST', '/x', [], ['note' => 'Local fermé'], []), (string) $second);
        self::assertSame(['refusee', 'Local fermé'], [$this->json($refused)['booking']['status'], $this->json($refused)['booking']['decisionNote']]);
    }

    #[Test]
    public function testAMemberCancelsTheirBookingAndAStrangerIsRefused(): void
    {
        $this->login('alice');
        $id = $this->json($this->book())['booking']['id'];

        $this->login('carole');
        try {
            $this->api->destroy(new Request('DELETE', '/x', [], [], []), (string) $id);
            self::fail('accès refusé attendu');
        } catch (AccessDeniedException) {
            $this->addToAssertionCount(1);
        }

        $this->login('alice');
        self::assertSame(200, $this->api->destroy(new Request('DELETE', '/x', [], [], []), (string) $id)->statusCode());
        self::assertSame(409, $this->api->destroy(new Request('DELETE', '/x', [], [], []), (string) $id)->statusCode(), 'déjà annulée');
    }
}
