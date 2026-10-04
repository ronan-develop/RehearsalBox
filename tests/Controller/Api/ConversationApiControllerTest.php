<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\ConversationApiController;
use App\Database\TransactionRunner;
use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Http\Request;
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

final class ConversationApiControllerTest extends RepositoryTestCase
{
    private const PASSWORD = 'mot-de-passe-de-test';

    private ConversationApiController $controller;
    private MysqlGroupRepository $groups;
    private MysqlUserRepository $users;
    private AuthService $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->groups = new MysqlGroupRepository($this->pdo);
        $this->users = new MysqlUserRepository($this->pdo);
        $this->auth = new AuthService($this->users, new NativePasswordHasher(), new InMemorySession(), $this->groups);
        $this->controller = new ConversationApiController(
            new ConversationService(new MysqlConversationRepository($this->pdo), $this->groups, new TransactionRunner($this->pdo)),
            new AuthGuard($this->auth),
        );
    }

    private function user(string $name): User
    {
        return $this->users->save(new User(0, strtolower($name) . '@rehearsalbox.test', (new NativePasswordHasher())->hash(self::PASSWORD), $name, UserRole::Musicien, true, 0, null));
    }

    private function group(string $name, User ...$members): Group
    {
        $group = $this->groups->save(new Group(0, $name, null, null, strtolower($name) . '@rehearsalbox.test'));
        foreach ($members as $member) {
            $this->groups->addMember($group->id(), $member->id());
        }

        return $group;
    }

    private function loginAs(User $user): void
    {
        $this->auth->attempt($user->email(), self::PASSWORD);
    }

    /** @param array<string, mixed> $body @return array{int, array<string, mixed>} */
    private function call(string $method, array $query = [], array $body = [], string ...$args): array
    {
        $request = new Request(strtoupper($method === 'archive' ? 'PATCH' : 'POST'), '/api/conversations', $query, $body, []);
        $response = $this->controller->{$method}($request, ...$args);

        return [$response->statusCode(), json_decode($response->body(), true)];
    }

    /** @return array{User, User, Group, Group} */
    private function world(): array
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');

        return [$alice, $bob, $this->group('Alpha', $alice), $this->group('Beta', $bob)];
    }

    #[Test]
    public function testEveryRouteRequiresALogin(): void
    {
        foreach ([['index'], ['start'], ['show', [], [], '1'], ['reply', [], [], '1'], ['archive', [], [], '1']] as $call) {
            try {
                $this->call(...$call);
                self::fail('connexion exigée : ' . $call[0]);
            } catch (UnauthenticatedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function testStartThenBothSidesSeeItInTheirBoxesAndCanReply(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $this->loginAs($alice);

        [$status, $json] = $this->call('start', [], ['groupId' => $a->id(), 'targetGroupId' => $b->id(), 'subject' => 'Jeudi', 'message' => 'Salut']);
        self::assertSame(201, $status);
        $id = (string) $json['id'];

        [, $sent] = $this->call('index', ['box' => 'sent']);
        self::assertSame('Alpha ↔ Beta', $sent['conversations'][0]['label']);
        self::assertSame('Salut', $sent['conversations'][0]['myLastMessage']['body']);

        $this->loginAs($bob);
        [, $received] = $this->call('index', ['box' => 'received']);
        self::assertCount(1, $received['conversations']);
        self::assertTrue($received['conversations'][0]['unread']);
        self::assertSame(1, $received['unread']);

        [$status] = $this->call('reply', [], ['message' => 'Réponse'], $id);
        self::assertSame(201, $status);
        [, $thread] = $this->call('show', [], [], $id);
        self::assertSame(['Salut', 'Réponse'], array_column($thread['messages'], 'body'));
        self::assertSame([false, true], array_column($thread['messages'], 'mine'));
        self::assertSame('Jeudi', $thread['subject']);
    }

    #[Test]
    public function testForbiddenAndMissingThreadsAreIndistinguishable(): void
    {
        [$alice, , $a, $b] = $this->world();
        $outsider = $this->user('Carol');
        $this->group('Gamma', $outsider);
        $this->loginAs($alice);
        [, $json] = $this->call('start', [], ['groupId' => $a->id(), 'targetGroupId' => $b->id(), 'subject' => 'S', 'message' => 'M']);
        $id = (string) $json['id'];

        $this->loginAs($outsider);
        $messages = [];
        foreach ([['show', [], [], $id], ['show', [], [], '9999'], ['show', [], [], 'abc'], ['reply', [], ['message' => 'x'], $id], ['archive', [], ['archived' => true], $id],
                  ['start', [], ['groupId' => $a->id(), 'targetGroupId' => $b->id(), 'subject' => 'S', 'message' => 'M']]] as $call) {
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
        $this->loginAs($alice);

        [, $json] = $this->call('start', [], ['groupId' => $a->id(), 'targetGroupId' => $b->id(), 'subject' => 'S', 'message' => 'M', 'authorId' => $bob->id(), 'userId' => $bob->id()]);
        [, $thread] = $this->call('show', [], [], (string) $json['id']);
        self::assertSame('Alice', $thread['messages'][0]['authorName']);

        foreach ([['groupId' => '1 OR 1=1', 'targetGroupId' => $b->id()], ['groupId' => [$a->id()], 'targetGroupId' => $b->id()], ['groupId' => $a->id(), 'targetGroupId' => null]] as $ids) {
            try {
                $this->call('start', [], $ids + ['subject' => 'S', 'message' => 'M']);
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

        [$status, $json] = $this->call('start', [], ['groupId' => $a->id(), 'targetGroupId' => $b->id(), 'subject' => '', 'message' => '']);

        self::assertSame(422, $status);
        self::assertArrayHasKey('subject', $json['fields']);
        self::assertArrayHasKey('message', $json['fields']);
    }

    #[Test]
    public function testRateLimitAnswers429(): void
    {
        [$alice, , $a, $b] = $this->world();
        $this->loginAs($alice);
        [, $json] = $this->call('start', [], ['groupId' => $a->id(), 'targetGroupId' => $b->id(), 'subject' => 'S', 'message' => 'M']);

        for ($i = 1; $i < ConversationService::MAX_MESSAGES_PER_HOUR; $i++) {
            $this->call('reply', [], ['message' => "m{$i}"], (string) $json['id']);
        }
        [$status] = $this->call('reply', [], ['message' => 'de trop'], (string) $json['id']);

        self::assertSame(429, $status);
    }

    #[Test]
    public function testArchiveAndInvalidBox(): void
    {
        [$alice, , $a, $b] = $this->world();
        $this->loginAs($alice);
        [, $json] = $this->call('start', [], ['groupId' => $a->id(), 'targetGroupId' => $b->id(), 'subject' => 'S', 'message' => 'M']);

        [$status] = $this->call('archive', [], ['archived' => true], (string) $json['id']);
        self::assertSame(200, $status);
        [, $archived] = $this->call('index', ['box' => 'archived']);
        self::assertCount(1, $archived['conversations']);

        [$status] = $this->call('archive', [], ['archived' => 'oui'], (string) $json['id']);
        self::assertSame(422, $status);
        [$status] = $this->call('index', ['box' => 'secret']);
        self::assertSame(422, $status);
    }
}
