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
            $this->groups,
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

    // --- Démarrer une conversation : page vide à la Signal (#181) -----------------------------------------------

    #[Test]
    public function testComposeRequiresALogin(): void
    {
        $this->expectException(UnauthenticatedException::class);
        $this->controller->compose($this->request(), '1');
    }

    #[Test]
    public function testComposeRendersAnEmptyThreadAddressedToTheTargetGroup(): void
    {
        $alice = $this->user('Alice');
        $mine = $this->group('Alpha', $alice);
        $target = $this->group('Beta Rockers');
        $this->loginAs($alice);

        $response = $this->controller->compose($this->request(), (string) $target->id());
        $body = $response->body();

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('data-draft-target-id="' . $target->id() . '"', $body);
        self::assertStringContainsString('Nouvelle conversation avec Beta Rockers', $body);
        self::assertStringContainsString('data-chat-form', $body);
        self::assertStringContainsString('data-view="thread"', $body);
        self::assertStringNotContainsString('data-draft-blocked', $body);
        self::assertStringContainsString('<option value="' . $mine->id() . '">Alpha</option>', $body);
        self::assertSame([], $this->service->listFor($alice->id(), 'active'), 'ouvrir la page ne crée rien : la conversation naît au premier message');
    }

    #[Test]
    public function testComposeOffersTheSenderChoiceOnlyWhenSeveralGroupsCanWrite(): void
    {
        $alice = $this->user('Alice');
        $this->group('Alpha', $alice);
        $this->group('Gamma', $alice);
        $target = $this->group('Beta');
        $this->loginAs($alice);
        $several = $this->controller->compose($this->request(), (string) $target->id())->body();

        $bob = $this->user('Bob');
        $this->group('Delta', $bob);
        $this->loginAs($bob);
        $single = $this->controller->compose($this->request(), (string) $target->id())->body();

        self::assertDoesNotMatchRegularExpression('/<select[^>]*data-chat-sender[^>]*hidden/', $several, 'plusieurs groupes : la liste est visible');
        self::assertMatchesRegularExpression('/<select[^>]*data-chat-sender[^>]*hidden/', $single, 'un seul groupe : présélectionné, liste masquée');
    }

    #[Test]
    public function testComposeNeverProposesTheTargetGroupAsSender(): void
    {
        $alice = $this->user('Alice');
        $this->group('Alpha', $alice);
        $target = $this->group('Beta', $alice);
        $this->loginAs($alice);

        $body = $this->controller->compose($this->request(), (string) $target->id())->body();

        self::assertStringContainsString('>Alpha</option>', $body);
        self::assertStringNotContainsString('>Beta</option>', $body, 'un groupe ne s\'écrit pas à lui-même');
    }

    #[Test]
    public function testComposeExplainsWhenThePersonHasNoGroupToWriteFrom(): void
    {
        $nobody = $this->user('Zoe');
        $target = $this->group('Beta');
        $this->loginAs($nobody);

        $body = $this->controller->compose($this->request(), (string) $target->id())->body();

        self::assertStringContainsString('data-draft-blocked', $body);
        self::assertStringContainsString('Vous devez appartenir à un autre groupe', $body);
        self::assertMatchesRegularExpression('/<form[^>]*data-chat-form[^>]*hidden/', $body, 'pas de zone de saisie');
    }

    #[Test]
    public function testComposeEscapesTheGroupNameAndRefusesUnknownOrMalformedTargets(): void
    {
        $alice = $this->user('Alice');
        $this->group('Alpha', $alice);
        $target = $this->group('<script>alert(1)</script>');
        $this->loginAs($alice);

        $body = $this->controller->compose($this->request(), (string) $target->id())->body();
        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);

        $messages = [];
        foreach (['9999', 'abc', '0', '-1', '1.5', '1 OR 1=1'] as $id) {
            try {
                $this->controller->compose($this->request(), $id);
                self::fail("refus attendu pour {$id}");
            } catch (AccessDeniedException $e) {
                $messages[] = $e->getMessage();
            }
        }
        self::assertCount(1, array_unique($messages));
    }
}
