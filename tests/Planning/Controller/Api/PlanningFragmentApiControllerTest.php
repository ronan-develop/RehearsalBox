<?php

declare(strict_types=1);

namespace App\Tests\Planning\Controller\Api;

use App\Planning\Controller\Api\PlanningFragmentApiController;
use App\Account\Entity\UserRole;
use App\Planning\Entity\Weekday;
use App\Group\Entity\Group;
use App\Account\Entity\User;
use App\Http\Request;
use App\Planning\Presenter\ExceptionalPlanningFragment;
use App\Group\Repository\MysqlGroupRepository;
use App\Planning\Repository\MysqlRecurringSlotRepository;
use App\Planning\Repository\MysqlSlotExceptionRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\Exception\UnauthenticatedException;
use App\Account\Service\AuthService;
use App\Planning\Service\SlotService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;
use App\Tests\Support\FastPasswordHasher;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;

/** #243 : les cartes « créneau exceptionnel » sont dessinées par le serveur (même gabarit que la page), le navigateur ne fait que les insérer. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class PlanningFragmentApiControllerTest extends RepositoryTestCase
{
    private PlanningFragmentApiController $controller;
    private AuthService $auth;
    private MysqlGroupRepository $groups;
    private MysqlSlotExceptionRepository $exceptions;
    private SlotService $slotService;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->groups = new MysqlGroupRepository($this->pdo);
        $this->exceptions = new MysqlSlotExceptionRepository($this->pdo);
        $users = new MysqlUserRepository($this->pdo);
        $hasher = new FastPasswordHasher();
        $this->auth = new AuthService($users, $hasher, new InMemorySession(), $this->groups);
        $this->user = $users->save(new User(0, 'alice@rehearsalbox.test', $hasher->hash('fixture-secret'), 'Alice', UserRole::Musicien, true, 0, null));
        $this->slotService = new SlotService(new MysqlRecurringSlotRepository($this->pdo), $this->groups, $this->exceptions);
        $this->controller = new PlanningFragmentApiController(
            new AuthGuard($this->auth),
            new ExceptionalPlanningFragment($this->slotService, new PhpTemplateRenderer(__DIR__ . '/../../../../templates')),
        );
    }

    private function acceptExceptionFor(string $requestingGroupName): void
    {
        $holder = $this->groups->save(new Group(0, 'Titulaire', null, null, 'holder@example.test'));
        $this->groups->addMember($holder->id(), $this->user->id());
        $slot = $this->slotService->create($holder->id(), Weekday::Tuesday, '18:00:00', '20:00:00');
        $requesting = $this->groups->save(new Group(0, $requestingGroupName, null, null, 'req@example.test'));
        $date = (new \DateTimeImmutable('today'))->modify('tuesday this week');
        $exception = $this->exceptions->createRequest($slot->id(), $date, $requesting->id(), $this->user->id(), null);
        $this->exceptions->respond($exception->id(), true, $this->user->id());
    }

    #[Test]
    public function testItRequiresALogin(): void
    {
        $this->expectException(UnauthenticatedException::class);

        $this->controller->exceptional(new Request('GET', '/api/planning/exceptional', [], [], []));
    }

    #[Test]
    public function testItReturnsTheServerRenderedCardsAndTheirCount(): void
    {
        $this->acceptExceptionFor('Groupe Demandeur');
        $this->auth->attempt('alice@rehearsalbox.test', 'fixture-secret');

        $response = $this->controller->exceptional(new Request('GET', '/api/planning/exceptional', [], [], []));

        $body = json_decode($response->body(), true);
        self::assertSame(200, $response->statusCode());
        self::assertSame(1, $body['count']);
        self::assertStringContainsString('rb-planning-card--exceptional', $body['html']);
        self::assertStringContainsString('Groupe Demandeur', $body['html']);
        self::assertStringContainsString('18:00 – 20:00', $body['html']);
        self::assertStringNotContainsString('role="button"', $body['html'], 'les cartes occasionnelles ne sont pas cliquables (#81)');
    }

    #[Test]
    public function testTheGroupNameIsEscaped(): void
    {
        $this->acceptExceptionFor('<script>alert(1)</script>');
        $this->auth->attempt('alice@rehearsalbox.test', 'fixture-secret');

        $body = json_decode($this->controller->exceptional(new Request('GET', '/api/planning/exceptional', [], [], []))->body(), true);

        self::assertStringNotContainsString('<script>', $body['html']);
        self::assertStringContainsString('&lt;script&gt;', $body['html']);
    }

    #[Test]
    public function testNoExceptionalSlotGivesAnEmptyFragment(): void
    {
        $this->auth->attempt('alice@rehearsalbox.test', 'fixture-secret');

        $body = json_decode($this->controller->exceptional(new Request('GET', '/api/planning/exceptional', [], [], []))->body(), true);

        self::assertSame(0, $body['count']);
        self::assertSame('', trim($body['html']));
    }
}
