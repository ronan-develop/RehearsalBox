<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Controller;

use App\Messaging\Controller\MessagesPageController;
use App\Messaging\Presenter\ConversationFormatter;
use App\Messaging\Presenter\ConversationListView;
use App\Messaging\Presenter\ConversationTimeline;
use App\Messaging\Presenter\MessagesPageView;
use App\Database\TransactionRunner;
use App\Account\Entity\UserRole;
use App\Group\Entity\Group;
use App\Account\Entity\User;
use App\Messaging\Repository\MysqlConversationRepository;
use App\Messaging\Repository\MysqlConversationPresenceRepository;
use App\Messaging\Repository\MysqlConversationMessageRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Security\Exception\AccessDeniedException;
use App\Security\Exception\UnauthenticatedException;
use App\Tests\Doubles\FastPasswordHasher;
use App\Account\Service\AuthService;
use App\Messaging\Service\ConversationService;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\InMemorySession;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class MessagesPageControllerTest extends RepositoryTestCase
{
    private const PASSWORD = 'mot-de-passe-de-test';

    private MessagesPageController $controller;
    private MockClock $clock;
    private ConversationService $service;
    private \App\Messaging\Service\ConversationReader $reader;
    private \App\Messaging\Service\ConversationTrashService $trash;
    private MysqlGroupRepository $groups;
    private MysqlUserRepository $users;
    private AuthService $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $session = new InMemorySession();
        $this->groups = new MysqlGroupRepository($this->pdo);
        $this->users = new MysqlUserRepository($this->pdo);
        $this->auth = new AuthService($this->users, new FastPasswordHasher(), $session, $this->groups);
        $this->clock = new MockClock('2026-10-04 12:00:00');
        $this->service = new ConversationService(new MysqlConversationRepository($this->pdo), new MysqlConversationMessageRepository($this->pdo), new MysqlConversationPresenceRepository($this->pdo), $this->groups, new TransactionRunner($this->pdo), $this->clock);
        $conversations = new MysqlConversationRepository($this->pdo);
        $messages = new MysqlConversationMessageRepository($this->pdo);
        $presence = new MysqlConversationPresenceRepository($this->pdo);
        $this->reader = new \App\Messaging\Service\ConversationReader(
            new \App\Messaging\Service\ConversationAccess($conversations, $this->groups),
            new \App\Messaging\Service\ConversationThreadBuilder($conversations, $messages, $presence, $this->groups, $this->clock),
            $conversations,
            $messages,
            $presence,
            $this->clock,
        );
        $this->trash = new \App\Messaging\Service\ConversationTrashService(new \App\Messaging\Service\ConversationAccess(new MysqlConversationRepository($this->pdo), $this->groups), new \App\Messaging\Repository\Participation\MysqlConversationTrashRepository($this->pdo), new TransactionRunner($this->pdo), $this->clock, new \App\Messaging\Repository\Participation\MysqlConversationAlertRepository($this->pdo));
        $formatter = new ConversationFormatter(new \DateTimeZone('Europe/Paris'));
        $this->controller = new MessagesPageController(
            new PhpTemplateRenderer(__DIR__ . '/../../../templates'),
            new CsrfTokenManager($session),
            new AuthGuard($this->auth),
            $this->reader,
            $this->groups,
            new MessagesPageView($this->reader, new ConversationListView($formatter), new ConversationTimeline($formatter), $formatter, $this->clock, $this->trash),
        );
    }

    private function user(string $name): User
    {
        return $this->users->save(new User(0, strtolower($name) . '@rehearsalbox.test', (new FastPasswordHasher())->hash(self::PASSWORD), $name, UserRole::Musicien, true, 0, null));
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
        foreach ([fn () => $this->controller->list($this->request()), fn () => $this->controller->show($this->request(), '1')] as $call) {
            try {
                $call();
                self::fail('connexion exigée');
            } catch (UnauthenticatedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function testListPageRendersTheConversationsOnTheServerWithNoOpenThread(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $this->service->start($alice->id(), $this->group('Alpha', $alice)->id(), $this->group('Beta', $bob)->id(), 'Salut <b>Bob</b>', 'Concert du 12');
        $this->loginAs($bob);

        $response = $this->controller->list($this->request());
        $body = $response->body();

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('data-chat', $body);
        self::assertStringContainsString('Concert du 12', $body, 'le titre est rendu par le serveur (aucun appel XHR pour le premier affichage)');
        self::assertStringContainsString('Alice : Salut &lt;b&gt;Bob&lt;/b&gt;', $body, 'aperçu échappé');
        self::assertStringContainsString('rb-chat-item--unread', $body);
        self::assertStringContainsString('data-view="list"', $body);
        self::assertStringContainsString('data-active-id=""', $body);
        self::assertStringContainsString('href="/messages/archives"', $body);
    }

    #[Test]
    public function testListPageForSomeoneWithoutConversationShowsTheEmptyMessage(): void
    {
        $this->loginAs($this->user('Zoe'));

        $body = $this->controller->list($this->request())->body();

        self::assertDoesNotMatchRegularExpression('/data-chat-empty hidden/', $body);
        self::assertStringContainsString('Aucune conversation', $body);
    }

    #[Test]
    public function testArchivesPageListsSilentConversationsAndLinksBack(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $this->service->start($alice->id(), $this->group('Alpha', $alice)->id(), $this->group('Beta', $bob)->id(), 'Vieux fil', 'Ancien');
        $this->clock->modify('+31 days');
        $this->loginAs($bob);

        $archives = $this->controller->archives($this->request())->body();
        $active = $this->controller->list($this->request())->body();

        self::assertStringContainsString('Ancien', $archives);
        self::assertStringContainsString('<h1 data-chat-list-title>Archivées</h1>', $archives);
        self::assertStringContainsString('href="/messages"', $archives);
        self::assertStringNotContainsString('Vieux fil', $active, 'plus dans la liste active');
        self::assertStringContainsString('data-chat-archives-unread>1<', $active, 'le badge compte les non lus des archives');
    }

    #[Test]
    public function testShowPageIsRenderedByTheServerAndMarksTheConversationRead(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $conversation = $this->service->start($alice->id(), $this->group('Alpha', $alice)->id(), $this->group('Beta', $bob)->id(), 'Message <i>confidentiel</i>', 'Titre du fil');
        $this->loginAs($bob);
        self::assertSame(1, $this->reader->unreadCount($bob->id()));

        $body = $this->controller->show($this->request(), (string) $conversation->id())->body();

        self::assertStringContainsString('data-active-id="' . $conversation->id() . '"', $body);
        self::assertStringContainsString('data-view="thread"', $body);
        self::assertStringContainsString('>Titre du fil</button>', $body);
        self::assertStringContainsString('Alpha ↔ Beta', $body, 'le label des deux groupes sous le titre');
        self::assertStringContainsString('Message &lt;i&gt;confidentiel&lt;/i&gt;', $body, 'le message est dessiné par le serveur, échappé');
        self::assertStringContainsString('Messages non lus', $body);
        self::assertStringContainsString('rb-chat-item--active', $body);
        self::assertStringContainsString('data-last-id="', $body);
        self::assertSame(0, $this->reader->unreadCount($bob->id()), 'ouvrir la page lit la conversation');
    }

    #[Test]
    public function testAPrefetchedPageDoesNotMarkTheConversationReadUntilItIsReallyOpened(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $conversation = $this->service->start($alice->id(), $this->group('Alpha', $alice)->id(), $this->group('Beta', $bob)->id(), 'Bonjour', 'Titre');
        $this->loginAs($bob);

        foreach ([['SEC-PURPOSE' => 'prefetch'], ['PURPOSE' => 'prefetch'], ['X-MOZ' => 'prefetch'], ['SEC-PURPOSE' => 'prefetch;prerender']] as $headers) {
            $prefetch = new \App\Http\Request('GET', '/messages/' . $conversation->id(), [], [], $headers);
            $this->controller->show($prefetch, (string) $conversation->id());
            self::assertSame(1, $this->reader->unreadCount($bob->id()), 'un préchargement du navigateur ne vaut pas lecture');
        }

        $this->controller->show($this->request(), (string) $conversation->id());
        self::assertSame(0, $this->reader->unreadCount($bob->id()), 'l\'ouverture réelle la marque lue');
    }

    #[Test]
    public function testShowPageOfAnArchivedConversationShowsTheArchivesInTheSidebar(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $conversation = $this->service->start($alice->id(), $this->group('Alpha', $alice)->id(), $this->group('Beta', $bob)->id(), 'Ancien', 'Fil ancien');
        $this->clock->modify('+31 days');
        $this->loginAs($bob);

        $body = $this->controller->show($this->request(), (string) $conversation->id())->body();

        self::assertStringContainsString('<h1 data-chat-list-title>Archivées</h1>', $body);
        self::assertStringContainsString('rb-chat-item--active', $body);
    }

    #[Test]
    public function testShowPageNeverLeaksTheConversationToAnOutsider(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $outsider = $this->user('Carol');
        $this->group('Gamma', $outsider);
        $conversation = $this->service->start($alice->id(), $this->group('Alpha', $alice)->id(), $this->group('Beta', $bob)->id(), 'Message confidentiel', 'Titre secret');
        $this->loginAs($outsider);

        try {
            $this->controller->show($this->request(), (string) $conversation->id());
            self::fail('refus attendu');
        } catch (AccessDeniedException) {
            $this->addToAssertionCount(1);
        }
        self::assertStringNotContainsString('Titre secret', $this->controller->list($this->request())->body(), 'ni dans sa liste');
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
        self::assertSame([], $this->reader->listFor($alice->id(), 'active'), 'ouvrir la page ne crée rien : la conversation naît au premier message');
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

    // --- Corbeille et avis (#190) ----------------------------------------------------------------------

    /** @return array{User, User, int} Alice (Alpha, initiatrice), Bob (Beta) et l'identifiant de la conversation */
    private function conversationFromAlice(): array
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $id = $this->service->start($alice->id(), $this->group('Alpha', $alice)->id(), $this->group('Beta', $bob)->id(), 'Salut', 'Concert du 12')->id();

        return [$alice, $bob, $id];
    }

    #[Test]
    public function testTheThreadPageHasAHiddenNewMessagesIndicatorBetweenTheFeedAndTheStatusLine(): void
    {
        [$alice, , $id] = $this->conversationFromAlice();
        $this->loginAs($alice);

        $body = $this->controller->show($this->request(), (string) $id)->body();

        self::assertMatchesRegularExpression('#</rb-message-list>.*data-chat-new-messages hidden.*data-chat-status#s', $body);
    }

    #[Test]
    public function testTheComposerAsksTheMobileKeyboardForASendKey(): void
    {
        [$alice, , $id] = $this->conversationFromAlice();
        $this->loginAs($alice);

        self::assertStringContainsString('enterkeyhint="send"', $this->controller->show($this->request(), (string) $id)->body());
    }

    #[Test]
    public function testTheAuthorSeesTheEditButtonOnRecentOwnMessagesOnlyAndThePageCarriesTheCorrectionCursor(): void
    {
        [$alice, $bob, $id] = $this->conversationFromAlice();

        $this->loginAs($alice);
        $mine = $this->controller->show($this->request(), (string) $id)->body();
        self::assertStringContainsString('data-edit-message', $mine);
        self::assertStringContainsString('data-composer-edit', $mine, 'bandeau de modification dans la saisie');
        self::assertStringContainsString('data-edited-at="0"', $mine);

        $this->loginAs($bob);
        self::assertStringNotContainsString('data-edit-message', $this->withoutActionsTemplate($this->controller->show($this->request(), (string) $id)->body()), 'jamais sur les messages des autres');

        $this->clock->modify('+16 minutes');
        $this->loginAs($alice);
        self::assertStringNotContainsString('data-edit-message', $this->withoutActionsTemplate($this->controller->show($this->request(), (string) $id)->body()), 'plus de bouton après 15 minutes');
    }

    /** Le modèle des actions (#257) contient toujours les deux boutons : les assertions « absent » portent sur les lignes du fil. */
    private function withoutActionsTemplate(string $html): string
    {
        return preg_replace('#<template data-message-actions>.*?</template>#s', '', $html) ?? $html;
    }

    #[Test]
    public function testThePageCarriesOneTemplateOfTheTapActionsWithBothButtons(): void
    {
        [$alice, , $id] = $this->conversationFromAlice();
        $this->loginAs($alice);

        $body = $this->controller->show($this->request(), (string) $id)->body();

        self::assertSame(1, substr_count($body, '<template data-message-actions>'), 'une seule définition des boutons');
        preg_match('#<template data-message-actions>(.*?)</template>#s', $body, $template);
        self::assertStringNotContainsString('<rb-message-actions', $template[1], 'le modèle ne contient que les boutons : le composant, lui, s\'insère au tap');
        self::assertStringContainsString('data-quote-message', $template[1]);
        self::assertStringContainsString('data-edit-message', $template[1]);
    }

    #[Test]
    public function testThePageCarriesTheViewersIdSoDraftsAreKeptPerUser(): void
    {
        [$alice, $bob] = $this->conversationFromAlice();

        $this->loginAs($alice);
        self::assertStringContainsString('data-user-id="' . $alice->id() . '"', $this->controller->list($this->request())->body());

        $this->loginAs($bob);
        $body = $this->controller->list($this->request())->body();
        self::assertStringContainsString('data-user-id="' . $bob->id() . '"', $body);
        self::assertStringNotContainsString('data-user-id="' . $alice->id() . '"', $body);
    }

    #[Test]
    public function testOnlyTheInitiatorSeesTheDeleteButton(): void
    {
        [$alice, $bob, $id] = $this->conversationFromAlice();

        $this->loginAs($alice);
        $body = $this->controller->show($this->request(), (string) $id)->body();
        self::assertStringContainsString('data-trash-action="delete"', $body);
        self::assertMatchesRegularExpression('/<button[^>]*class="rb-btn rb-btn-danger rb-chat-delete"[^>]*data-trash-action="delete"/', $body, 'un vrai bouton, plus un lien discret');
        self::assertMatchesRegularExpression('/<header class="rb-chat-thread-head">.*data-trash-action="delete".*<\/header>/s', $body, 'dans l\'en-tête du fil');

        $this->loginAs($bob);
        self::assertStringNotContainsString('data-trash-action', $this->controller->show($this->request(), (string) $id)->body());
    }

    #[Test]
    public function testTheRecipientSeesADismissibleAlertAndNobodyElseDoes(): void
    {
        [$alice, $bob, $id] = $this->conversationFromAlice();
        $this->trash->delete($alice->id(), $id);

        $this->loginAs($bob);
        $body = $this->controller->list($this->request())->body();
        self::assertStringContainsString('Alpha ↔ Beta', $body);
        self::assertStringContainsString('a été supprimée', $body);
        self::assertStringContainsString('data-trash-action="dismiss"', $body);
        self::assertStringNotContainsString('Concert du 12', $body, 'le titre n\'apparaît jamais dans un avis');

        $this->loginAs($alice);
        self::assertStringNotContainsString('data-trash-action="dismiss"', $this->controller->list($this->request())->body());
    }

    #[Test]
    public function testTheTrashPageListsTheInitiatorsConversationsWithRestoreAndPurgeButtons(): void
    {
        [$alice, $bob, $id] = $this->conversationFromAlice();
        $this->trash->delete($alice->id(), $id);

        $this->loginAs($alice);
        $body = $this->controller->trash($this->request())->body();
        self::assertStringContainsString('Concert du 12', $body);
        self::assertStringContainsString('data-trash-action="restore"', $body);
        self::assertStringContainsString('data-trash-action="purge"', $body);
        self::assertStringContainsString('jours', $body, 'durée restante avant la suppression définitive');
        self::assertStringContainsString('href="/messages/trash"', $this->controller->list($this->request())->body(), 'lien Corbeille dans la liste');

        $this->loginAs($bob);
        $other = $this->controller->trash($this->request())->body();
        self::assertStringNotContainsString('Concert du 12', $other);
        self::assertStringNotContainsString('href="/messages/trash"', $this->controller->list($this->request())->body(), 'pas de lien sans rien à y voir');
    }

    #[Test]
    public function testTheTrashPageRequiresALoginAndATrashedConversationIsGoneFromItsUrl(): void
    {
        try {
            $this->controller->trash($this->request());
            self::fail('connexion exigée');
        } catch (UnauthenticatedException) {
            $this->addToAssertionCount(1);
        }

        [$alice, , $id] = $this->conversationFromAlice();
        $this->trash->delete($alice->id(), $id);
        $this->loginAs($alice);
        $this->expectException(\App\Security\Exception\AccessDeniedException::class);
        $this->controller->show($this->request(), (string) $id);
    }
}
