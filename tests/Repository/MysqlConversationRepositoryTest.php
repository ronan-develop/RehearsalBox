<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\Contract\ConversationRepositoryInterface as Box;
use App\Repository\MysqlConversationRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

final class MysqlConversationRepositoryTest extends RepositoryTestCase
{
    private \DateTimeImmutable $now;
    private \DateTimeImmutable $cutoff;
    private MysqlConversationRepository $repository;
    private MysqlGroupRepository $groups;
    private MysqlUserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-10-04 12:00:00');
        $this->cutoff = $this->now->modify('-30 days');
        $this->repository = new MysqlConversationRepository($this->pdo);
        $this->groups = new MysqlGroupRepository($this->pdo);
        $this->users = new MysqlUserRepository($this->pdo);
    }

    private function user(string $name): User
    {
        return $this->users->save(new User(0, strtolower($name) . '@rehearsalbox.test', 'hash', $name, UserRole::Musicien, true, 0, null));
    }

    private function group(string $name, User ...$members): Group
    {
        $group = $this->groups->save(new Group(0, $name, null, null, strtolower($name) . '@rehearsalbox.test'));
        foreach ($members as $member) {
            $this->groups->addMember($group->id(), $member->id());
        }

        return $group;
    }

    private function at(string $modifier): \DateTimeImmutable
    {
        return $this->now->modify($modifier);
    }

    /** @return array{User, User, Group, Group} Alice (Alpha) et Bob (Beta) */
    private function pair(): array
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');

        return [$alice, $bob, $this->group('Alpha', $alice), $this->group('Beta', $bob)];
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
        $this->repository->addMessage($conversation->id(), $alice->id(), 'Salut', $this->at('+1 minute'));
        $this->repository->addMessage($conversation->id(), $bob->id(), 'Hello', $this->at('+2 minutes'));

        $found = $this->repository->findById($conversation->id());
        self::assertNotNull($found);
        self::assertNull($found->title());
        self::assertTrue($found->involvesGroup($a->id()));
        self::assertTrue($found->involvesGroup($b->id()));
        self::assertFalse($found->involvesGroup($b->id() + 100));

        $messages = $this->repository->messagesOf($conversation->id());
        self::assertSame(['Salut', 'Hello'], array_map(static fn ($m) => $m->body(), $messages));
        self::assertSame(['Alice', 'Bob'], array_map(static fn ($m) => $m->authorName(), $messages));
    }

    #[Test]
    public function testAddMessageReturnsTheRealIncreasingIdOfTheStoredMessage(): void
    {
        [$alice, , $a, $b] = $this->pair();
        $conversation = $this->repository->create($a->id(), $b->id(), null, $this->now);

        $first = $this->repository->addMessage($conversation->id(), $alice->id(), 'un', $this->now);
        $second = $this->repository->addMessage($conversation->id(), $alice->id(), 'deux', $this->now);

        self::assertGreaterThan(0, $first->id());
        self::assertGreaterThan($first->id(), $second->id());
        self::assertSame([$first->id(), $second->id()], array_map(static fn ($m) => $m->id(), $this->repository->messagesOf($conversation->id())));
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
        $this->repository->addMessage($conversation->id(), $alice->id(), 'Salut', $this->now);

        self::assertSame(['Alpha ↔ Beta'], $this->titles($alice->id(), Box::BOX_ACTIVE), 'sans titre : label des deux groupes');

        $this->repository->rename($conversation->id(), 'Concert du 12');
        self::assertSame('Concert du 12', $this->repository->findById($conversation->id())?->title());
        self::assertSame(['Concert du 12'], $this->titles($alice->id(), Box::BOX_ACTIVE));

        $this->repository->rename($conversation->id(), null);
        self::assertNull($this->repository->findById($conversation->id())?->title());
    }

    #[Test]
    public function testMessagesOfIsIncrementalWithAfterId(): void
    {
        [$alice, , $a, $b] = $this->pair();
        $conversation = $this->repository->create($a->id(), $b->id(), null, $this->now);
        $first = $this->repository->addMessage($conversation->id(), $alice->id(), 'un', $this->at('+1 minute'));
        $second = $this->repository->addMessage($conversation->id(), $alice->id(), 'deux', $this->at('+2 minutes'));
        $this->repository->addMessage($conversation->id(), $alice->id(), 'trois', $this->at('+3 minutes'));

        self::assertSame(['trois'], array_map(static fn ($m) => $m->body(), $this->repository->messagesOf($conversation->id(), $second->id())));
        self::assertSame(['deux', 'trois'], array_map(static fn ($m) => $m->body(), $this->repository->messagesOf($conversation->id(), $first->id())));
        self::assertSame([], $this->repository->messagesOf($conversation->id(), 99999));
    }

    // --- Visibilité ---------------------------------------------------------------------------

    #[Test]
    public function testListShowsThreadsOfBothGroupsOfTheMemberOnly(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $carol = $this->user('Carol');
        $c = $this->group('Gamma', $carol);
        $ab = $this->repository->create($a->id(), $b->id(), 'AB', $this->now);
        $this->repository->addMessage($ab->id(), $alice->id(), 'm', $this->now);
        $bc = $this->repository->create($b->id(), $c->id(), 'BC', $this->now);
        $this->repository->addMessage($bc->id(), $bob->id(), 'm', $this->now);

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
        $this->repository->addMessage($thread->id(), $alice->id(), 'm', $this->now);

        self::assertCount(1, $this->repository->listFor($alice->id(), Box::BOX_ACTIVE, $this->cutoff));
    }

    #[Test]
    public function testListPreviewsTheLastMessageWhoeverWroteIt(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->repository->create($a->id(), $b->id(), null, $this->now);
        $this->repository->addMessage($thread->id(), $alice->id(), 'Premier', $this->at('+1 minute'));
        $this->repository->addMessage($thread->id(), $bob->id(), 'Dernier de Bob', $this->at('+2 minutes'));

        $summary = $this->repository->listFor($alice->id(), Box::BOX_ACTIVE, $this->cutoff)[0];

        self::assertSame('Dernier de Bob', $summary->lastMessage()->body());
        self::assertSame('Bob', $summary->lastMessage()->authorName());
    }

    #[Test]
    public function testMostRecentlyActiveThreadComesFirst(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $old = $this->repository->create($a->id(), $b->id(), 'Ancien', $this->now);
        $this->repository->addMessage($old->id(), $alice->id(), 'm', $this->at('+1 minute'));
        $recent = $this->repository->create($a->id(), $b->id(), 'Récent', $this->now);
        $this->repository->addMessage($recent->id(), $alice->id(), 'm', $this->at('+2 minutes'));
        $this->repository->addMessage($old->id(), $bob->id(), 'm', $this->at('+3 minutes'));

        self::assertSame(['Ancien', 'Récent'], $this->titles($alice->id(), Box::BOX_ACTIVE));
    }

    // --- Archivage dérivé de l'inactivité -------------------------------------------------------

    #[Test]
    public function testAThreadSilentSinceBeforeTheCutoffIsArchivedForEveryone(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $quiet = $this->repository->create($a->id(), $b->id(), 'Silencieux', $this->at('-60 days'));
        $this->repository->addMessage($quiet->id(), $alice->id(), 'vieux', $this->at('-31 days'));
        $lively = $this->repository->create($a->id(), $b->id(), 'Vivant', $this->at('-60 days'));
        $this->repository->addMessage($lively->id(), $alice->id(), 'récent', $this->at('-1 day'));

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
        $this->repository->addMessage($thread->id(), $alice->id(), 'pile', $this->cutoff);

        self::assertSame(['Limite'], $this->titles($alice->id(), Box::BOX_ACTIVE));
        self::assertSame([], $this->titles($alice->id(), Box::BOX_ARCHIVED));
    }

    #[Test]
    public function testANewMessageBringsAnArchivedThreadBack(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->repository->create($a->id(), $b->id(), 'Réveillé', $this->at('-60 days'));
        $this->repository->addMessage($thread->id(), $alice->id(), 'vieux', $this->at('-45 days'));
        self::assertSame(['Réveillé'], $this->titles($bob->id(), Box::BOX_ARCHIVED));

        $this->repository->addMessage($thread->id(), $bob->id(), 'coucou', $this->now);

        self::assertSame(['Réveillé'], $this->titles($alice->id(), Box::BOX_ACTIVE));
        self::assertSame([], $this->titles($alice->id(), Box::BOX_ARCHIVED));
    }

    // --- Non lu -----------------------------------------------------------------------------------

    #[Test]
    public function testUnreadUntilReadAndAgainAfterANewMessageFromSomeoneElse(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->repository->create($a->id(), $b->id(), 'Fil', $this->now);
        $this->repository->addMessage($thread->id(), $alice->id(), 'Salut', $this->at('+1 minute'));

        self::assertTrue($this->repository->listFor($bob->id(), Box::BOX_ACTIVE, $this->cutoff)[0]->isUnread());
        self::assertFalse($this->repository->listFor($alice->id(), Box::BOX_ACTIVE, $this->cutoff)[0]->isUnread(), 'mon propre message n\'est pas non lu');
        self::assertSame(1, $this->repository->countUnreadFor($bob->id(), $this->cutoff));

        $this->repository->markRead($thread->id(), $bob->id(), $this->at('+2 minutes'));
        self::assertFalse($this->repository->listFor($bob->id(), Box::BOX_ACTIVE, $this->cutoff)[0]->isUnread());
        self::assertSame(0, $this->repository->countUnreadFor($bob->id(), $this->cutoff));

        $this->repository->addMessage($thread->id(), $alice->id(), 'Encore', $this->at('+3 minutes'));
        self::assertTrue($this->repository->listFor($bob->id(), Box::BOX_ACTIVE, $this->cutoff)[0]->isUnread());
    }

    #[Test]
    public function testUnreadCountCanBeLimitedToOneBox(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $old = $this->repository->create($a->id(), $b->id(), 'Archivé', $this->at('-60 days'));
        $this->repository->addMessage($old->id(), $alice->id(), 'vieux', $this->at('-45 days'));
        $current = $this->repository->create($a->id(), $b->id(), 'Actif', $this->now);
        $this->repository->addMessage($current->id(), $alice->id(), 'récent', $this->at('-1 day'));

        self::assertSame(2, $this->repository->countUnreadFor($bob->id(), $this->cutoff));
        self::assertSame(1, $this->repository->countUnreadFor($bob->id(), $this->cutoff, Box::BOX_ACTIVE));
        self::assertSame(1, $this->repository->countUnreadFor($bob->id(), $this->cutoff, Box::BOX_ARCHIVED));
    }

    #[Test]
    public function testMarkReadTwiceIsHarmless(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->repository->create($a->id(), $b->id(), 'Fil', $this->now);
        $this->repository->addMessage($thread->id(), $alice->id(), 'Salut', $this->now);

        $this->repository->markRead($thread->id(), $bob->id(), $this->at('+1 minute'));
        $this->repository->markRead($thread->id(), $bob->id(), $this->at('+2 minutes'));

        self::assertSame(0, $this->repository->countUnreadFor($bob->id(), $this->cutoff));
    }

    // --- Vu par / écrit… ------------------------------------------------------------------------

    #[Test]
    public function testReadersAreTheOtherMembersWhoReadAtOrAfterTheMessage(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $zoe = $this->user('Zoe');
        $this->groups->addMember($b->id(), $zoe->id());
        $thread = $this->repository->create($a->id(), $b->id(), null, $this->now);
        $message = $this->repository->addMessage($thread->id(), $alice->id(), 'Salut', $this->at('+1 minute'));

        $this->repository->markRead($thread->id(), $bob->id(), $this->at('+2 minutes'));
        $this->repository->markRead($thread->id(), $zoe->id(), $this->at('+30 seconds'));
        $this->repository->markRead($thread->id(), $alice->id(), $this->at('+5 minutes'));

        self::assertSame(['Bob'], $this->repository->readersOf($thread->id(), $message->createdAt(), $alice->id()), 'Zoé a lu avant le message ; Alice est l\'auteure');
        self::assertSame(3, $this->repository->participantCount($thread->id()));
    }

    #[Test]
    public function testAMemberWhoLeftIsNoLongerAReaderNorAParticipant(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->repository->create($a->id(), $b->id(), null, $this->now);
        $message = $this->repository->addMessage($thread->id(), $alice->id(), 'Salut', $this->now);
        $this->repository->markRead($thread->id(), $bob->id(), $this->at('+1 minute'));

        $this->groups->removeMember($b->id(), $bob->id());

        self::assertSame([], $this->repository->readersOf($thread->id(), $message->createdAt(), $alice->id()));
        self::assertSame(1, $this->repository->participantCount($thread->id()));
    }

    #[Test]
    public function testTypingIsVisibleToOthersForAShortWindowAndNeverToTheTyperThemself(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->repository->create($a->id(), $b->id(), null, $this->now);
        $this->repository->addMessage($thread->id(), $alice->id(), 'Salut', $this->now);

        $this->repository->setTyping($thread->id(), $bob->id(), $this->at('+10 seconds'));

        self::assertSame(['Bob'], $this->repository->typingNames($thread->id(), $alice->id(), $this->at('+7 seconds')));
        self::assertSame([], $this->repository->typingNames($thread->id(), $bob->id(), $this->at('+7 seconds')), 'jamais pour soi-même');
        self::assertSame([], $this->repository->typingNames($thread->id(), $alice->id(), $this->at('+11 seconds')), 'signal expiré');
    }

    #[Test]
    public function testTypingDoesNotDisturbTheReadStateAndRevealsNothingToOutsiders(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->repository->create($a->id(), $b->id(), null, $this->now);
        $this->repository->addMessage($thread->id(), $alice->id(), 'Salut', $this->at('+1 minute'));
        $this->repository->setTyping($thread->id(), $bob->id(), $this->at('+2 minutes'));

        self::assertSame(1, $this->repository->countUnreadFor($bob->id(), $this->cutoff), 'écrire n\'est pas lire');
        $this->repository->markRead($thread->id(), $bob->id(), $this->at('+3 minutes'));
        self::assertSame(['Bob'], $this->repository->typingNames($thread->id(), $alice->id(), $this->at('+90 seconds')), 'lire ne supprime pas le signal');
    }

    // --- Autres --------------------------------------------------------------------------------

    #[Test]
    public function testCountMessagesBySinceCountsOnlyRecentMessagesOfTheAuthor(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->repository->create($a->id(), $b->id(), null, $this->now);
        $this->repository->addMessage($thread->id(), $alice->id(), 'vieux', $this->at('-2 hours'));
        $this->repository->addMessage($thread->id(), $alice->id(), 'récent', $this->at('-10 minutes'));
        $this->repository->addMessage($thread->id(), $bob->id(), 'autre', $this->now);

        self::assertSame(1, $this->repository->countMessagesBySince($alice->id(), $this->at('-1 hour')));
    }

    #[Test]
    public function testThreadsDisappearWithTheirGroup(): void
    {
        $alice = $this->user('Alice');
        $a = $this->group('Alpha', $alice);
        $b = $this->group('Beta');
        $thread = $this->repository->create($a->id(), $b->id(), null, $this->now);
        $this->repository->addMessage($thread->id(), $alice->id(), 'm', $this->now);

        $this->groups->delete($b->id());

        self::assertNull($this->repository->findById($thread->id()));
    }
}
