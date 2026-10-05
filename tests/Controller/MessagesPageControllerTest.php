<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\MessagesPageController;
use App\Database\TransactionRunner;
use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\MysqlConversationRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Security\Exception\AccessDeniedException;
use App\Security\Exception\UnauthenticatedException;
use App\Security\NativePasswordHasher;
use App\Service\AuthService;
use App\Service\ConversationService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

final class MessagesPageControllerTest extends RepositoryTestCase
{
    private const PASSWORD = 'mot-de-passe-de-test';

    private MessagesPageController $controller;
    private ConversationService $service;
    private MysqlGroupRepository $groups;
    private MysqlUserRepository $users;
    private AuthService $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $session = new InMemorySession();
        $this->groups = new MysqlGroupRepository($this->pdo);
        $this->users = new MysqlUserRepository($this->pdo);
        $this->auth = new AuthService($this->users, new NativePasswordHasher(), $session, $this->groups);
        $this->service = new ConversationService(new MysqlConversationRepository($this->pdo), $this->groups, new TransactionRunner($this->pdo), new MockClock('2026-10-04 12:00:00'));
        $this->controller = new MessagesPageController(
            new PhpTemplateRenderer(__DIR__ . '/../../templates'),
            new CsrfTokenManager($session),
            new AuthGuard($this->auth),
            $this->service,
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

    private function request(): \App\Http\Request
    {
        return new \App\Http\Request('GET', '/messages', [], [], []);
    }

    private function loginAs(User $user): void
    {
        $this->auth->attempt($user->email(), self::PASSWORD);
    }

    #[Test]
    public function testBothPagesRequireALogin(): void
    {
        foreach ([fn () => $this->controller->list(), fn () => $this->controller->show($this->request(), '1')] as $call) {
            try {
                $call();
                self::fail('connexion exigée');
            } catch (UnauthenticatedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function testListPageRendersTheChatShellWithNoActiveConversation(): void
    {
        $this->loginAs($this->user('Alice'));

        $response = $this->controller->list();
        $body = $response->body();

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('data-chat', $body);
        self::assertStringContainsString('data-chat-list', $body);
        self::assertStringContainsString('data-chat-messages', $body);
        self::assertStringContainsString('data-chat-form', $body);
        self::assertStringContainsString('data-chat-archives', $body);
        self::assertStringContainsString('name="csrf-token"', $body);
        self::assertStringContainsString('data-active-id=""', $body);
    }

    #[Test]
    public function testShowPageForAMemberExposesTheActiveConversationWithoutReadingIt(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $conversation = $this->service->start($alice->id(), $this->group('Alpha', $alice)->id(), $this->group('Beta', $bob)->id(), 'Message confidentiel', 'Titre secret');
        $this->loginAs($bob);

        $body = $this->controller->show($this->request(), (string) $conversation->id())->body();

        self::assertStringContainsString('data-active-id="' . $conversation->id() . '"', $body);
        self::assertStringNotContainsString('Message confidentiel', $body, 'le contenu n\'est servi que par l\'API');
        self::assertStringNotContainsString('Titre secret', $body);
        self::assertSame(1, $this->service->unreadCount($bob->id()), 'la page ne marque rien comme lu (c\'est le JS qui ouvre le fil)');
    }

    #[Test]
    public function testForbiddenMissingAndMalformedConversationsAreRefusedIdentically(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $outsider = $this->user('Carol');
        $this->group('Gamma', $outsider);
        $conversation = $this->service->start($alice->id(), $this->group('Alpha', $alice)->id(), $this->group('Beta', $bob)->id(), 'Salut');
        $this->loginAs($outsider);

        $messages = [];
        foreach ([(string) $conversation->id(), '9999', 'abc', '0', '-1', '1.5'] as $id) {
            try {
                $this->controller->show($this->request(), $id);
                self::fail("refus attendu pour {$id}");
            } catch (AccessDeniedException $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertCount(1, array_unique($messages));
    }
}
