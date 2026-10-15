<?php

declare(strict_types=1);

namespace App\Tests\Planning\Controller;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Account\Repository\MysqlUserRepository;
use App\Account\Service\AuthService;
use App\Group\Entity\Group;
use App\Group\Repository\MysqlGroupRepository;
use App\Planning\Controller\ExchangePageController;
use App\Planning\Entity\RecurringSlot;
use App\Planning\Entity\SlotException;
use App\Planning\Entity\Weekday;
use App\Planning\Repository\MysqlRecurringSlotRepository;
use App\Planning\Repository\MysqlSlotExceptionRepository;
use App\Planning\Service\AvailabilityService;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Security\Exception\UnauthenticatedException;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\FastPasswordHasher;
use App\Tests\Doubles\InMemorySession;
use App\Tests\Scenarios\TestDashboard;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/** #462 : la page d'une demande d'échange, ouverte depuis l'e-mail — titulaire et administrateurs seulement, 404 uniforme pour le reste. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class ExchangePageControllerTest extends RepositoryTestCase
{
    private ExchangePageController $controller;
    private AuthService $auth;
    private MysqlSlotExceptionRepository $exceptions;
    private SlotException $exception;
    private int $aliceId;

    protected function setUp(): void
    {
        parent::setUp();
        $groups = new MysqlGroupRepository($this->pdo);
        $slots = new MysqlRecurringSlotRepository($this->pdo);
        $this->exceptions = new MysqlSlotExceptionRepository($this->pdo);
        $users = new MysqlUserRepository($this->pdo);
        $session = new InMemorySession();
        $this->auth = new AuthService($users, new FastPasswordHasher(), $session, $groups);

        $holder = $groups->save(new Group(0, 'Titulaires <Rock>', null, null, 'holder@example.test'));
        $requester = $groups->save(new Group(0, 'Demandeurs', null, null, 'requester@example.test'));
        $other = $groups->save(new Group(0, 'Autres', null, null, 'other@example.test'));
        $slot = $slots->save(new RecurringSlot(0, $holder->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));
        $this->aliceId = $this->user($users, 'alice', UserRole::Musicien, $holder->id(), $groups);
        $bobId = $this->user($users, 'bob', UserRole::Musicien, $requester->id(), $groups);
        $this->user($users, 'carol', UserRole::Musicien, $other->id(), $groups);
        $this->user($users, 'admin', UserRole::Admin, null, $groups);
        $this->exception = $this->exceptions->createRequest($slot->id(), new \DateTimeImmutable('+7 days'), $requester->id(), $bobId, 'Motif <i>secret</i>');

        $this->controller = new ExchangePageController(
            new PhpTemplateRenderer(__DIR__ . '/../../../templates'),
            new CsrfTokenManager($session),
            new AuthGuard($this->auth),
            new AvailabilityService($this->exceptions, $groups, $slots),
            TestDashboard::view($this->pdo),
        );
    }

    private function user(MysqlUserRepository $users, string $name, UserRole $role, ?int $groupId, MysqlGroupRepository $groups): int
    {
        $user = $users->save(new User(0, "{$name}@rehearsalbox.test", password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]), ucfirst($name), $role, true, 0, null));
        if ($groupId !== null) {
            $groups->addMember($groupId, $user->id());
        }

        return $user->id();
    }

    private function login(string $name): void
    {
        $this->auth->attempt("{$name}@rehearsalbox.test", 'password');
    }

    private function open(string $id): \App\Http\Response
    {
        return $this->controller->show(new \App\Http\Request('GET', "/exchanges/{$id}", [], [], []), $id);
    }

    #[Test]
    public function testAnonymousVisitorIsSentToTheLogin(): void
    {
        $this->expectException(UnauthenticatedException::class);

        $this->open((string) $this->exception->id());
    }

    #[Test]
    public function testAMemberOfTheHolderGroupSeesTheRequestWithTheAnswerButtonsAndEscapedText(): void
    {
        $this->login('alice');

        $response = $this->open((string) $this->exception->id());
        $html = $response->body();

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('name="csrf-token"', $html);
        self::assertSame(1, substr_count($html, '<rb-request-card'));
        self::assertStringContainsString('exception-id="' . $this->exception->id() . '"', $html);
        self::assertStringContainsString('data-accepted="true"', $html);
        self::assertStringContainsString('data-accepted="false"', $html);
        self::assertStringContainsString('Titulaires &lt;Rock&gt;', $html);
        self::assertStringNotContainsString('Titulaires <Rock>', $html);
        self::assertStringContainsString('Retour au tableau de bord', $html);
    }

    #[Test]
    public function testAnAdministratorSeesItEvenOutsideBothGroups(): void
    {
        $this->login('admin');

        $response = $this->open((string) $this->exception->id());

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('data-accepted="true"', $response->body());
    }

    /** @return iterable<string, array{0: string}> */
    public static function forbiddenViewersProvider(): iterable
    {
        yield 'groupe demandeur seul' => ['bob'];
        yield 'autre groupe' => ['carol'];
    }

    #[Test]
    #[DataProvider('forbiddenViewersProvider')]
    public function testEveryoneElseGetsTheSame404AsForAnUnknownRequest(string $viewer): void
    {
        $this->login($viewer);

        $forbidden = $this->open((string) $this->exception->id());
        $unknown = $this->open('999999');

        self::assertSame(404, $forbidden->statusCode());
        self::assertSame($unknown->body(), $forbidden->body());
        self::assertStringNotContainsString('Titulaires', $forbidden->body());
    }

    /** @return iterable<string, array{0: string}> */
    public static function malformedIdsProvider(): iterable
    {
        foreach (['0', '-1', '1e0', 'abc', '007', '1.5', '99999999999'] as $id) {
            yield $id => [$id];
        }
    }

    #[Test]
    #[DataProvider('malformedIdsProvider')]
    public function testAMalformedIdentifierIsAnUnknownRequestEvenForAnAdministrator(string $id): void
    {
        $this->login('admin');

        self::assertSame(404, $this->open($id)->statusCode());
    }

    #[Test]
    public function testAnAlreadyAnsweredRequestShowsItsStatusWithoutButtons(): void
    {
        $this->exceptions->respond($this->exception->id(), true, $this->aliceId);
        $this->login('alice');

        $html = $this->open((string) $this->exception->id())->body();

        self::assertStringContainsString('a déjà reçu une réponse', $html);
        self::assertStringNotContainsString('data-action="respond"', $html);
        self::assertStringContainsString('rb-badge', $html);
    }
}
