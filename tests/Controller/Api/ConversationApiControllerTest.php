<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\ConversationApiController;
use App\Database\TransactionRunner;
use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Http\Request;
use App\Presenter\ConversationFormatter;
use App\Presenter\ConversationListView;
use App\Presenter\ConversationPresenter;
use App\Presenter\ConversationTimeline;
use App\Presenter\MessagesPageView;
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
use App\View\PhpTemplateRenderer;
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
        $service = new ConversationService(new MysqlConversationRepository($this->pdo), $this->groups, new TransactionRunner($this->pdo), $this->clock);
        $access = new \App\Service\ConversationAccess(new MysqlConversationRepository($this->pdo), $this->groups, new \App\Repository\MysqlConversationGuestRepository($this->pdo));
        $trash = new \App\Service\ConversationTrashService($access, new MysqlConversationRepository($this->pdo), new TransactionRunner($this->pdo), $this->clock, new \App\Repository\MysqlConversationAlertRepository($this->pdo));
        $guestService = new \App\Service\ConversationGuestService($access, new \App\Repository\MysqlConversationGuestRepository($this->pdo), new MysqlConversationRepository($this->pdo), $this->users, new TransactionRunner($this->pdo), $this->clock);
        $formatter = new ConversationFormatter(new \DateTimeZone('Europe/Paris'));
        $this->controller = new ConversationApiController(
            $service,
            new ConversationPresenter(),
            new AuthGuard($this->auth),
            new MessagesPageView($service, new ConversationListView($formatter), new ConversationTimeline($formatter), $formatter, $this->clock, $trash),
            new PhpTemplateRenderer(__DIR__ . '/../../../templates'),
            $trash,
            $guestService,
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

    /** Fil complet (messages depuis le début) tel que le sert le polling. @return array<string, mixed> */
    private function thread(string $id): array
    {
        [, $json] = $this->call('updates', ['after' => '0'], [], $id);

        return $json;
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
        foreach ([['index'], ['start'], ['updates', [], [], '1'], ['listFragment'], ['reply', [], [], '1'], ['rename', [], [], '1'], ['typing', [], [], '1']] as $call) {
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
        [$status, $reply] = $this->call('reply', [], ['message' => 'Réponse', 'after' => '0'], $id);
        self::assertSame(201, $status);
        self::assertStringContainsString('Réponse', $reply['html']);
        self::assertStringContainsString('rb-chat-message--mine', $reply['html']);
        self::assertTrue($reply['hasNew']);

        $thread = $this->thread($id);
        self::assertStringContainsString('Salut', $thread['html']);
        self::assertStringContainsString('Réponse', $thread['html']);
        self::assertStringContainsString('title="Alpha"', $thread['html'], 'pastille : groupe de l\'auteur');
        self::assertSame(2, substr_count($thread['html'], 'data-message-id='));
        self::assertSame($thread['lastId'], $reply['lastId']);
    }

    #[Test]
    public function testReplyReturnsOnlyMessagesAfterTheClientsLastKnownOne(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $id = $this->startAsAlice($alice, $a, $b);
        $known = $this->thread($id)['lastId'];
        $this->loginAs($bob);
        $this->clock->sleep(5);
        $this->call('reply', [], ['message' => 'Un autre message'], $id);
        $this->loginAs($alice);
        $this->clock->sleep(5);

        [, $reply] = $this->call('reply', [], ['message' => 'Ma réponse', 'after' => (string) $known], $id);

        self::assertStringContainsString('Un autre message', $reply['html'], 'les messages reçus entre-temps arrivent avec le mien');
        self::assertStringContainsString('Ma réponse', $reply['html']);
        self::assertStringNotContainsString('Salut', $reply['html'], 'déjà affiché côté client');
    }

    #[Test]
    public function testStartWithATitleAndRenameLater(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $id = $this->startAsAlice($alice, $a, $b, ['title' => 'Concert']);
        $this->loginAs($bob);

        self::assertSame('Concert', $this->thread($id)['displayTitle']);

        [$status] = $this->call('rename', [], ['title' => 'Concert du 12'], $id);
        self::assertSame(200, $status);
        $thread = $this->thread($id);
        self::assertSame('Concert du 12', $thread['title']);
        self::assertStringContainsString('Vous avez renommé la conversation « Concert du 12 »', $thread['html']);
        self::assertStringContainsString('rb-chat-system', $thread['html']);

        $this->call('rename', [], ['title' => null], $id);
        self::assertSame('Alpha ↔ Beta', $this->thread($id)['displayTitle']);
    }

    #[Test]
    public function testPollReturnsOnlyNewMessagesTypingAndSeen(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $id = $this->startAsAlice($alice, $a, $b);
        $firstId = $this->thread($id)['lastId'];

        $this->loginAs($bob);
        $this->clock->sleep(10);
        $this->thread($id);
        [$status] = $this->call('typing', [], [], $id);
        self::assertSame(200, $status);

        $this->loginAs($alice);
        $this->clock->sleep(2);
        [, $update] = $this->call('updates', ['after' => (string) $firstId], [], $id);

        self::assertSame('', trim($update['html']), 'aucun nouveau message');
        self::assertFalse($update['hasNew']);
        self::assertSame('Bob écrit…', $update['status']);
        self::assertTrue($update['typing']);
        self::assertSame($firstId, $update['lastId'] === 0 ? $firstId : $update['lastId']);
    }

    #[Test]
    public function testSeenByIsInTheStatusWhenNobodyIsWriting(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $id = $this->startAsAlice($alice, $a, $b);
        $this->loginAs($bob);
        $this->clock->sleep(10);
        $this->thread($id);
        $this->loginAs($alice);
        $this->clock->sleep(2);

        $update = $this->thread($id);

        self::assertSame('Vu par Bob', $update['status']);
        self::assertFalse($update['typing']);
    }

    #[Test]
    public function testTheListFragmentRendersTheUsersConversationsAndTheEmptyFlag(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $this->startAsAlice($alice, $a, $b, ['title' => 'Concert <b>du 12</b>']);
        $this->loginAs($bob);

        [$status, $json] = $this->call('listFragment', ['box' => 'active']);
        [, $archived] = $this->call('listFragment', ['box' => 'archived']);

        self::assertSame(200, $status);
        self::assertFalse($json['empty']);
        self::assertStringContainsString('&lt;b&gt;du 12&lt;/b&gt;', $json['html']);
        self::assertStringNotContainsString('<b>du 12</b>', $json['html']);
        self::assertStringContainsString('rb-chat-item--unread', $json['html']);
        self::assertTrue($archived['empty']);
        self::assertSame(422, $this->call('listFragment', ['box' => 'secret'])[0]);
    }

    #[Test]
    public function testTheFragmentsEscapeWhatUsersWrite(): void
    {
        [$alice, , $a, $b] = $this->world();
        $this->loginAs($alice);
        [, $json] = $this->call('start', [], ['groupId' => $a->id(), 'targetGroupId' => $b->id(), 'message' => '<script>alert(1)</script>']);

        $html = $this->thread((string) $json['id'])['html'];

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
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
            ['updates', [], [], $id], ['updates', [], [], '9999'], ['updates', [], [], 'abc'], ['updates', ['after' => '0'], [], $id],
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

        $thread = $this->thread($id);
        self::assertStringContainsString('Salut', $thread['html']);
        self::assertSame(1, substr_count($thread['html'], 'rb-chat-message--mine'), 'le message est le mien : l\'auteur vient de la session');
        self::assertStringNotContainsString('rb-chat-author', $thread['html']);

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
        self::assertSame(422, $this->call('updates', ['after' => 'abc'], [], $id)[0]);
        self::assertSame(422, $this->call('updates', ['after' => '-1'], [], $id)[0]);
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

    // --- Corbeille et avis (#190) ----------------------------------------------------------------------

    #[Test]
    public function testTheInitiatorTrashesRestoresAndDeletesForGood(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $id = $this->startAsAlice($alice, $a, $b);

        [$status] = $this->call('destroy', [], [], $id);
        self::assertSame(200, $status);
        [, $list] = $this->call('index');
        self::assertSame([], $list['conversations']);

        $this->loginAs($bob);
        try {
            $this->call('updates', ['after' => '0'], [], $id);
            self::fail('la conversation a disparu chez le destinataire');
        } catch (AccessDeniedException) {
            $this->addToAssertionCount(1);
        }

        $this->loginAs($alice);
        self::assertSame(200, $this->call('restore', [], [], $id)[0]);
        self::assertCount(1, $this->call('index')[1]['conversations']);

        $this->call('destroy', [], [], $id);
        self::assertSame(200, $this->call('destroyPermanently', [], [], $id)[0]);
        $this->loginAs($bob);
        self::assertSame([], $this->call('index')[1]['conversations']);
    }

    #[Test]
    public function testOnlyTheInitiatorMayTrashRestoreOrDeleteForGood(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $id = $this->startAsAlice($alice, $a, $b);
        $outsider = $this->user('Erin');

        foreach ([$bob, $outsider] as $intruder) {
            $this->loginAs($intruder);
            foreach (['destroy', 'restore', 'destroyPermanently'] as $action) {
                try {
                    $this->call($action, [], [], $id);
                    self::fail('refus attendu : ' . $action);
                } catch (AccessDeniedException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        $this->loginAs($alice);
        self::assertCount(1, $this->call('index')[1]['conversations'], "rien n'a bougé");
    }

    #[Test]
    public function testTheRecipientSeesAnAlertCountedInTheBadgeAndCanDismissIt(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $id = $this->startAsAlice($alice, $a, $b);
        $this->call('destroy', [], [], $id);

        $this->loginAs($bob);
        [, $list] = $this->call('index');
        self::assertSame(1, $list['unread']['total'], 'l\'avis compte dans la pastille');
        self::assertSame(1, $list['unread']['alerts']);
        $alertId = (string) $this->pdo->query('SELECT id FROM conversation_alerts LIMIT 1')->fetchColumn();

        self::assertSame(200, $this->call('dismissAlert', [], [], $alertId)[0]);

        self::assertSame(0, $this->call('index')[1]['unread']['alerts']);
    }
}
