<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\ConversationApiController;
use App\Database\TransactionRunner;
use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Http\Request;
use App\Presenter\ConversationPresenter;
use App\Repository\MysqlConversationRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\Exception\AccessDeniedException;
use App\Security\Exception\UnauthenticatedException;
use App\Security\NativePasswordHasher;
use App\Service\AuthService;
use App\Service\ConversationService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

final class ConversationApiControllerTest extends RepositoryTestCase
{
    private const PASSWORD = 'mot-de-passe-de-test';

    private ConversationApiController $controller;
    private MysqlGroupRepository $groups;
    private MysqlUserRepository $users;
    private AuthService $auth;
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-04 12:00:00');
        $this->groups = new MysqlGroupRepository($this->pdo);
        $this->users = new MysqlUserRepository($this->pdo);
        $this->auth = new AuthService($this->users, new NativePasswordHasher(), new InMemorySession(), $this->groups);
        $this->controller = new ConversationApiController(
            new ConversationService(new MysqlConversationRepository($this->pdo), $this->groups, new TransactionRunner($this->pdo), $this->clock),
            new ConversationPresenter(),
            new AuthGuard($this->auth),
        );
    }

    private function user(string $name): User
    {
        return $this->users->save(new User(0, strtolower($name) . '@rehearsalbox.test', (new NativePasswordHasher())->hash(self::PASSWORD), $name, UserRole::Musicien, true, 0, null));
    }

    private function group(string $name, User ...$members): Group
    {
        $group = $this->groups->save(new Group(0, $name, null, '#aa0000', strtolower($name) . '@rehearsalbox.test'));
        foreach ($members as $member) {
            $this->groups->addMember($group->id(), $member->id());
        }

        return $group;
    }

    private function loginAs(User $user): void
    {
        $this->auth->attempt($user->email(), self::PASSWORD);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     *
     * @return array{int, array<string, mixed>}
     */
    private function call(string $action, array $query = [], array $body = [], string ...$args): array
    {
        $request = new Request('POST', '/api/conversations', $query, $body, []);
        $response = $this->controller->{$action}($request, ...$args);

        return [$response->statusCode(), json_decode($response->body(), true) ?? []];
    }

    /** @return array{User, User, Group, Group} */
    private function world(): array
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');

        return [$alice, $bob, $this->group('Alpha', $alice), $this->group('Beta', $bob)];
    }

    /** @return string identifiant de la conversation créée par Alice */
    private function startAsAlice(User $alice, Group $a, Group $b, array $extra = []): string
    {
        $this->loginAs($alice);
        [, $json] = $this->call('start', [], ['groupId' => $a->id(), 'targetGroupId' => $b->id(), 'message' => 'Salut'] + $extra);

        return (string) $json['id'];
    }

    #[Test]
    public function testEveryRouteRequiresALogin(): void
    {
        foreach ([['index'], ['start'], ['show', [], [], '1'], ['reply', [], [], '1'], ['rename', [], [], '1'], ['typing', [], [], '1']] as $call) {
            try {
                $this->call(...$call);
                self::fail('connexion exigée : ' . $call[0]);
            } catch (UnauthenticatedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function testStartWithoutTitleThenBothSidesSeeItAndCanReply(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $id = $this->startAsAlice($alice, $a, $b);

        [, $mine] = $this->call('index');
        self::assertSame('Alpha ↔ Beta', $mine['conversations'][0]['displayTitle']);
        self::assertNull($mine['conversations'][0]['title']);
        self::assertFalse($mine['conversations'][0]['unread']);

        $this->loginAs($bob);
        [, $received] = $this->call('index');
        self::assertCount(1, $received['conversations']);
        self::assertTrue($received['conversations'][0]['unread']);
        self::assertSame(1, $received['unread']['total']);

        $this->clock->sleep(30);
        [$status] = $this->call('reply', [], ['message' => 'Réponse'], $id);
        self::assertSame(201, $status);
        [, $thread] = $this->call('show', [], [], $id);
        self::assertSame(['Salut', 'Réponse'], array_column($thread['messages'], 'body'));
        self::assertSame([false, true], array_column($thread['messages'], 'mine'));
        self::assertSame(['Alpha', 'Beta'], array_column($thread['messages'], 'groupName'));
    }

    #[Test]
    public function testStartWithATitleAndRenameLater(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $id = $this->startAsAlice($alice, $a, $b, ['title' => 'Concert']);
        $this->loginAs($bob);

        [, $thread] = $this->call('show', [], [], $id);
        self::assertSame('Concert', $thread['displayTitle']);

        [$status] = $this->call('rename', [], ['title' => 'Concert du 12'], $id);
        self::assertSame(200, $status);
        [, $thread] = $this->call('show', [], [], $id);
        self::assertSame('Concert du 12', $thread['title']);
        self::assertTrue(end($thread['messages'])['system']);

        $this->call('rename', [], ['title' => null], $id);
        [, $thread] = $this->call('show', [], [], $id);
        self::assertSame('Alpha ↔ Beta', $thread['displayTitle']);
    }

    #[Test]
    public function testPollReturnsOnlyNewMessagesTypingAndSeen(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $id = $this->startAsAlice($alice, $a, $b);
        [, $first] = $this->call('show', [], [], $id);
        $firstId = $first['messages'][0]['id'];

        $this->loginAs($bob);
        $this->clock->sleep(10);
        $this->call('show', [], [], $id);
        [$status] = $this->call('typing', [], [], $id);
        self::assertSame(200, $status);

        $this->loginAs($alice);
        $this->clock->sleep(2);
        [, $update] = $this->call('show', ['after' => (string) $firstId], [], $id);

        self::assertSame([], $update['messages']);
        self::assertSame(['Bob'], $update['typing']);
        self::assertSame(['Bob'], $update['seen']['names']);
        self::assertSame(1, $update['seen']['total']);
    }

    #[Test]
    public function testForbiddenMissingAndMalformedThreadsAreIndistinguishable(): void
    {
        [$alice, , $a, $b] = $this->world();
        $outsider = $this->user('Carol');
        $this->group('Gamma', $outsider);
        $id = $this->startAsAlice($alice, $a, $b);

        $this->loginAs($outsider);
        $messages = [];
        foreach ([
            ['show', [], [], $id], ['show', [], [], '9999'], ['show', [], [], 'abc'], ['show', ['after' => '0'], [], $id],
            ['reply', [], ['message' => 'x'], $id], ['rename', [], ['title' => 'Piraté'], $id], ['typing', [], [], $id],
            ['start', [], ['groupId' => $a->id(), 'targetGroupId' => $b->id(), 'message' => 'Usurpation']],
        ] as $call) {
            try {
                $this->call(...$call);
                self::fail('refus attendu : ' . $call[0]);
            } catch (AccessDeniedException $e) {
                $messages[] = $e->getMessage();
            }
        }
        self::assertCount(1, array_unique($messages));
    }

    #[Test]
    public function testClientCannotChooseTheAuthorAndBadIdsAreRefused(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $id = $this->startAsAlice($alice, $a, $b, ['authorId' => $bob->id(), 'userId' => $bob->id()]);

        [, $thread] = $this->call('show', [], [], $id);
        self::assertSame('Alice', $thread['messages'][0]['authorName']);

        foreach ([['groupId' => '1 OR 1=1', 'targetGroupId' => $b->id()], ['groupId' => [$a->id()], 'targetGroupId' => $b->id()], ['groupId' => $a->id(), 'targetGroupId' => null]] as $ids) {
            try {
                $this->call('start', [], $ids + ['message' => 'M']);
                self::fail('ids invalides');
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function testValidationErrorsAnswer422WithFields(): void
    {
        [$alice, , $a, $b] = $this->world();
        $this->loginAs($alice);

        [$status, $json] = $this->call('start', [], ['groupId' => $a->id(), 'targetGroupId' => $b->id(), 'message' => '', 'title' => str_repeat('x', 151)]);

        self::assertSame(422, $status);
        self::assertArrayHasKey('message', $json['fields']);
        self::assertArrayHasKey('title', $json['fields']);
    }

    #[Test]
    public function testMalformedParametersAnswer422(): void
    {
        [$alice, , $a, $b] = $this->world();
        $id = $this->startAsAlice($alice, $a, $b);

        self::assertSame(422, $this->call('index', ['box' => 'secret'])[0]);
        self::assertSame(422, $this->call('show', ['after' => 'abc'], [], $id)[0]);
        self::assertSame(422, $this->call('show', ['after' => '-1'], [], $id)[0]);
        self::assertSame(422, $this->call('rename', [], ['title' => ['x']], $id)[0]);
        self::assertSame(422, $this->call('rename', [], [], $id)[0], 'champ title absent');
    }

    #[Test]
    public function testRateLimitAnswers429(): void
    {
        [$alice, , $a, $b] = $this->world();
        $id = $this->startAsAlice($alice, $a, $b);

        for ($i = 1; $i < ConversationService::MAX_MESSAGES_PER_HOUR; $i++) {
            $this->call('reply', [], ['message' => "m{$i}"], $id);
        }
        [$status] = $this->call('reply', [], ['message' => 'de trop'], $id);

        self::assertSame(429, $status);
    }

    #[Test]
    public function testArchivedBoxListsSilentConversations(): void
    {
        [$alice, , $a, $b] = $this->world();
        $this->startAsAlice($alice, $a, $b);
        $this->clock->modify('+31 days');

        [, $active] = $this->call('index', ['box' => 'active']);
        [, $archived] = $this->call('index', ['box' => 'archived']);

        self::assertCount(0, $active['conversations']);
        self::assertCount(1, $archived['conversations']);
    }
}
