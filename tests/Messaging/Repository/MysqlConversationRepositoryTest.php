<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Repository;

use App\Account\Entity\User;
use App\Messaging\Repository\ConversationRepositoryInterface as Box;
use App\Messaging\Repository\MysqlConversationRepository;
use App\Messaging\Repository\MysqlConversationPresenceRepository;
use App\Messaging\Repository\MysqlConversationMessageRepository;
use App\Messaging\Repository\Participation\MysqlConversationTrashRepository;
use App\Tests\RepositoryTestCase;
use App\Tests\Support\MessagingScenario;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class MysqlConversationRepositoryTest extends RepositoryTestCase
{
    use MessagingScenario;

    private MysqlConversationRepository $repository;
    private MysqlConversationMessageRepository $messages;
    private MysqlConversationPresenceRepository $presence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        $this->repository = new MysqlConversationRepository($this->pdo);
        $this->messages = new MysqlConversationMessageRepository($this->pdo);
        $this->presence = new MysqlConversationPresenceRepository($this->pdo);
    }

    /** @return list<string> */
    private function titles(int $userId, string $box): array
    {
        return array_map(static fn ($s) => $s->displayTitle(), $this->repository->listFor($userId, $box, $this->cutoff));
    }

    // --- Création, titre, messages ----------------------------------------------------------------

    #[Test]
    public function testCreatesAConversationWithoutTitleAndItsMessagesInOrder(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();

        $conversation = $this->repository->create($a->id(), $b->id(), null, $this->now);
        $this->messages->addMessage($conversation->id(), $alice->id(), 'Salut', $this->at('+1 minute'));
        $this->messages->addMessage($conversation->id(), $bob->id(), 'Hello', $this->at('+2 minutes'));

        $found = $this->repository->findById($conversation->id());
        self::assertNotNull($found);
        self::assertNull($found->title());

        $messages = $this->messages->messagesOf($conversation->id());
        self::assertSame(['Salut', 'Hello'], array_map(static fn ($m) => $m->body(), $messages));
        self::assertSame(['Alice', 'Bob'], array_map(static fn ($m) => $m->authorName(), $messages));
    }

    #[Test]
    public function testUnknownConversationIsNull(): void
    {
        self::assertNull($this->repository->findById(999));
    }

    #[Test]
    public function testRenameSetsAndRemovesTheTitle(): void
    {
        [$alice, , $a, $b] = $this->pair();
        $conversation = $this->repository->create($a->id(), $b->id(), null, $this->now);
        $this->messages->addMessage($conversation->id(), $alice->id(), 'Salut', $this->now);

        self::assertSame(['Alpha ↔ Beta'], $this->titles($alice->id(), Box::BOX_ACTIVE), 'sans titre : label des deux groupes');

        $this->repository->rename($conversation->id(), 'Concert du 12');
        self::assertSame('Concert du 12', $this->repository->findById($conversation->id())?->title());
        self::assertSame(['Concert du 12'], $this->titles($alice->id(), Box::BOX_ACTIVE));

        $this->repository->rename($conversation->id(), null);
        self::assertNull($this->repository->findById($conversation->id())?->title());
    }

    // --- Visibilité ---------------------------------------------------------------------------

    #[Test]
    public function testListShowsThreadsOfBothGroupsOfTheMemberOnly(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $carol = $this->user('Carol');
        $c = $this->group('Gamma', $carol);
        $ab = $this->repository->create($a->id(), $b->id(), 'AB', $this->now);
        $this->messages->addMessage($ab->id(), $alice->id(), 'm', $this->now);
        $bc = $this->repository->create($b->id(), $c->id(), 'BC', $this->now);
        $this->messages->addMessage($bc->id(), $bob->id(), 'm', $this->now);

        self::assertSame(['AB'], $this->titles($alice->id(), Box::BOX_ACTIVE));
        self::assertEqualsCanonicalizing(['AB', 'BC'], $this->titles($bob->id(), Box::BOX_ACTIVE));
        self::assertSame([], $this->titles($this->user('Dave')->id(), Box::BOX_ACTIVE), 'un non-membre ne voit rien');
    }

    #[Test]
    public function testAUserOfBothGroupsSeesTheThreadOnlyOnce(): void
    {
        $alice = $this->user('Alice');
        $a = $this->group('Alpha', $alice);
        $b = $this->group('Beta', $alice);
        $thread = $this->repository->create($a->id(), $b->id(), 'Moi', $this->now);
        $this->messages->addMessage($thread->id(), $alice->id(), 'm', $this->now);

        self::assertCount(1, $this->repository->listFor($alice->id(), Box::BOX_ACTIVE, $this->cutoff));
    }

    #[Test]
    public function testListPreviewsTheLastMessageWhoeverWroteIt(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->repository->create($a->id(), $b->id(), null, $this->now);
        $this->messages->addMessage($thread->id(), $alice->id(), 'Premier', $this->at('+1 minute'));
        $this->messages->addMessage($thread->id(), $bob->id(), 'Dernier de Bob', $this->at('+2 minutes'));

        $summary = $this->repository->listFor($alice->id(), Box::BOX_ACTIVE, $this->cutoff)[0];

        self::assertSame('Dernier de Bob', $summary->lastMessage()->body());
        self::assertSame('Bob', $summary->lastMessage()->authorName());
    }

    #[Test]
    public function testMostRecentlyActiveThreadComesFirst(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $old = $this->repository->create($a->id(), $b->id(), 'Ancien', $this->now);
        $this->messages->addMessage($old->id(), $alice->id(), 'm', $this->at('+1 minute'));
        $recent = $this->repository->create($a->id(), $b->id(), 'Récent', $this->now);
        $this->messages->addMessage($recent->id(), $alice->id(), 'm', $this->at('+2 minutes'));
        $this->messages->addMessage($old->id(), $bob->id(), 'm', $this->at('+3 minutes'));

        self::assertSame(['Ancien', 'Récent'], $this->titles($alice->id(), Box::BOX_ACTIVE));
    }

    // --- Archivage dérivé de l'inactivité -------------------------------------------------------

    #[Test]
    public function testAThreadSilentSinceBeforeTheCutoffIsArchivedForEveryone(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $quiet = $this->repository->create($a->id(), $b->id(), 'Silencieux', $this->at('-60 days'));
        $this->messages->addMessage($quiet->id(), $alice->id(), 'vieux', $this->at('-31 days'));
        $lively = $this->repository->create($a->id(), $b->id(), 'Vivant', $this->at('-60 days'));
        $this->messages->addMessage($lively->id(), $alice->id(), 'récent', $this->at('-1 day'));

        foreach ([$alice, $bob] as $user) {
            self::assertSame(['Vivant'], $this->titles($user->id(), Box::BOX_ACTIVE));
            self::assertSame(['Silencieux'], $this->titles($user->id(), Box::BOX_ARCHIVED));
        }
    }

    #[Test]
    public function testTheCutoffItselfStillCountsAsActive(): void
    {
        [$alice, , $a, $b] = $this->pair();
        $thread = $this->repository->create($a->id(), $b->id(), 'Limite', $this->at('-40 days'));
        $this->messages->addMessage($thread->id(), $alice->id(), 'pile', $this->cutoff);

        self::assertSame(['Limite'], $this->titles($alice->id(), Box::BOX_ACTIVE));
        self::assertSame([], $this->titles($alice->id(), Box::BOX_ARCHIVED));
    }

    #[Test]
    public function testANewMessageBringsAnArchivedThreadBack(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->repository->create($a->id(), $b->id(), 'Réveillé', $this->at('-60 days'));
        $this->messages->addMessage($thread->id(), $alice->id(), 'vieux', $this->at('-45 days'));
        self::assertSame(['Réveillé'], $this->titles($bob->id(), Box::BOX_ARCHIVED));

        $this->messages->addMessage($thread->id(), $bob->id(), 'coucou', $this->now);

        self::assertSame(['Réveillé'], $this->titles($alice->id(), Box::BOX_ACTIVE));
        self::assertSame([], $this->titles($alice->id(), Box::BOX_ARCHIVED));
    }

    // --- Non lu -----------------------------------------------------------------------------------

    #[Test]
    public function testUnreadUntilReadAndAgainAfterANewMessageFromSomeoneElse(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->repository->create($a->id(), $b->id(), 'Fil', $this->now);
        $this->messages->addMessage($thread->id(), $alice->id(), 'Salut', $this->at('+1 minute'));

        self::assertTrue($this->repository->listFor($bob->id(), Box::BOX_ACTIVE, $this->cutoff)[0]->isUnread());
        self::assertFalse($this->repository->listFor($alice->id(), Box::BOX_ACTIVE, $this->cutoff)[0]->isUnread(), 'mon propre message n\'est pas non lu');
        self::assertSame(1, $this->repository->countUnreadFor($bob->id(), $this->cutoff));

        $this->presence->markRead($thread->id(), $bob->id(), $this->at('+2 minutes'));
        self::assertFalse($this->repository->listFor($bob->id(), Box::BOX_ACTIVE, $this->cutoff)[0]->isUnread());
        self::assertSame(0, $this->repository->countUnreadFor($bob->id(), $this->cutoff));

        $this->messages->addMessage($thread->id(), $alice->id(), 'Encore', $this->at('+3 minutes'));
        self::assertTrue($this->repository->listFor($bob->id(), Box::BOX_ACTIVE, $this->cutoff)[0]->isUnread());
    }

    #[Test]
    public function testUnreadCountCanBeLimitedToOneBox(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $old = $this->repository->create($a->id(), $b->id(), 'Archivé', $this->at('-60 days'));
        $this->messages->addMessage($old->id(), $alice->id(), 'vieux', $this->at('-45 days'));
        $current = $this->repository->create($a->id(), $b->id(), 'Actif', $this->now);
        $this->messages->addMessage($current->id(), $alice->id(), 'récent', $this->at('-1 day'));

        self::assertSame(2, $this->repository->countUnreadFor($bob->id(), $this->cutoff));
        self::assertSame(1, $this->repository->countUnreadFor($bob->id(), $this->cutoff, Box::BOX_ACTIVE));
        self::assertSame(1, $this->repository->countUnreadFor($bob->id(), $this->cutoff, Box::BOX_ARCHIVED));
    }

    // --- Vu par / écrit… ------------------------------------------------------------------------

    // --- Message système, dernier message de l'auteur, débit du signal « écrit… » ----------------

    // --- Autres --------------------------------------------------------------------------------

    #[Test]
    public function testThreadsDisappearWithTheirGroup(): void
    {
        $alice = $this->user('Alice');
        $a = $this->group('Alpha', $alice);
        $b = $this->group('Beta');
        $thread = $this->repository->create($a->id(), $b->id(), null, $this->now);
        $this->messages->addMessage($thread->id(), $alice->id(), 'm', $this->now);

        $this->groups->delete($b->id());

        self::assertNull($this->repository->findById($thread->id()));
    }

    // --- Propriétaire de la conversation (#190) --------------------------------------------------------

    #[Test]
    public function testTheCreatorIsRememberedAndTheThreadStartsOutsideTheTrash(): void
    {
        [$alice, , $a, $b] = $this->pair();

        $thread = $this->repository->create($a->id(), $b->id(), null, $this->now, $alice->id());

        $found = $this->repository->findById($thread->id());
        self::assertSame($alice->id(), $found->createdBy());
        self::assertNull($found->deletedAt());
    }

    #[Test]
    public function testAThreadWhoseCreatorIsDeletedKeepsExistingWithoutCreator(): void
    {
        [$alice, , $a, $b] = $this->pair();
        $thread = $this->repository->create($a->id(), $b->id(), null, $this->now, $alice->id());

        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$alice->id()]);

        self::assertNull($this->repository->findById($thread->id())->createdBy());
    }
}
