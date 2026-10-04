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
    private MysqlConversationRepository $repository;
    private MysqlGroupRepository $groups;
    private MysqlUserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-10-04 12:00:00');
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

    #[Test]
    public function testCreatesAConversationAndItsMessagesInOrder(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $a = $this->group('Alpha', $alice);
        $b = $this->group('Beta', $bob);

        $conversation = $this->repository->create($a->id(), $b->id(), 'Créneau du jeudi', $this->now);
        $this->repository->addMessage($conversation->id(), $alice->id(), 'Salut', $this->at('+1 minute'));
        $this->repository->addMessage($conversation->id(), $bob->id(), 'Hello', $this->at('+2 minutes'));

        $found = $this->repository->findById($conversation->id());
        self::assertNotNull($found);
        self::assertSame('Créneau du jeudi', $found->subject());
        self::assertTrue($found->involvesGroup($a->id()));
        self::assertTrue($found->involvesGroup($b->id()));
        self::assertFalse($found->involvesGroup($b->id() + 100));

        $messages = $this->repository->messagesOf($conversation->id());
        self::assertSame(['Salut', 'Hello'], array_map(static fn ($m) => $m->body(), $messages));
        self::assertSame(['Alice', 'Bob'], array_map(static fn ($m) => $m->authorName(), $messages));
    }

    #[Test]
    public function testUnknownConversationIsNull(): void
    {
        self::assertNull($this->repository->findById(999));
    }

    #[Test]
    public function testReceivedBoxShowsThreadsOfBothGroupsOfTheMemberOnly(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $carol = $this->user('Carol');
        $a = $this->group('Alpha', $alice);
        $b = $this->group('Beta', $bob);
        $c = $this->group('Gamma', $carol);
        $ab = $this->repository->create($a->id(), $b->id(), 'AB', $this->now);
        $this->repository->addMessage($ab->id(), $alice->id(), 'm', $this->now);
        $bc = $this->repository->create($b->id(), $c->id(), 'BC', $this->now);
        $this->repository->addMessage($bc->id(), $bob->id(), 'm', $this->now);

        $aliceBox = $this->repository->listFor($alice->id(), Box::BOX_RECEIVED);
        $bobBox = $this->repository->listFor($bob->id(), Box::BOX_RECEIVED);

        self::assertSame(['AB'], array_map(static fn ($s) => $s->conversation()->subject(), $aliceBox));
        self::assertEqualsCanonicalizing(['AB', 'BC'], array_map(static fn ($s) => $s->conversation()->subject(), $bobBox));
        self::assertSame('Alpha ↔ Beta', $aliceBox[0]->label());
        self::assertSame([], $this->repository->listFor($this->user('Dave')->id(), Box::BOX_RECEIVED), 'un non-membre ne voit rien');
    }

    #[Test]
    public function testAUserOfBothGroupsSeesTheThreadOnlyOnce(): void
    {
        $alice = $this->user('Alice');
        $a = $this->group('Alpha', $alice);
        $b = $this->group('Beta', $alice);
        $thread = $this->repository->create($a->id(), $b->id(), 'Moi', $this->now);
        $this->repository->addMessage($thread->id(), $alice->id(), 'm', $this->now);

        self::assertCount(1, $this->repository->listFor($alice->id(), Box::BOX_RECEIVED));
    }

    #[Test]
    public function testSentBoxKeepsThreadsWhereThePersonWroteWithTheirOwnLastMessage(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $a = $this->group('Alpha', $alice);
        $b = $this->group('Beta', $bob);
        $thread = $this->repository->create($a->id(), $b->id(), 'Fil', $this->now);
        $this->repository->addMessage($thread->id(), $alice->id(), 'Premier de moi', $this->at('+1 minute'));
        $this->repository->addMessage($thread->id(), $alice->id(), 'Dernier de moi', $this->at('+2 minutes'));
        $this->repository->addMessage($thread->id(), $bob->id(), 'Réponse de Bob', $this->at('+3 minutes'));

        $aliceSent = $this->repository->listFor($alice->id(), Box::BOX_SENT);
        $bobSent = $this->repository->listFor($bob->id(), Box::BOX_SENT);

        self::assertCount(1, $aliceSent);
        self::assertSame('Dernier de moi', $aliceSent[0]->myLastMessage()?->body());
        self::assertSame('Réponse de Bob', $aliceSent[0]->lastMessage()->body());
        self::assertSame('Réponse de Bob', $bobSent[0]->myLastMessage()?->body());
    }

    #[Test]
    public function testAThreadWhereThePersonNeverWroteIsNotInSent(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $a = $this->group('Alpha', $alice);
        $b = $this->group('Beta', $bob);
        $thread = $this->repository->create($a->id(), $b->id(), 'Fil', $this->now);
        $this->repository->addMessage($thread->id(), $alice->id(), 'Salut', $this->now);

        self::assertSame([], $this->repository->listFor($bob->id(), Box::BOX_SENT));
        self::assertNull($this->repository->listFor($bob->id(), Box::BOX_RECEIVED)[0]->myLastMessage());
    }

    #[Test]
    public function testArchivingIsPerPersonAndMovesTheThreadToArchived(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $a = $this->group('Alpha', $alice);
        $b = $this->group('Beta', $bob);
        $thread = $this->repository->create($a->id(), $b->id(), 'Fil', $this->now);
        $this->repository->addMessage($thread->id(), $alice->id(), 'Salut', $this->now);

        $this->repository->setArchived($thread->id(), $alice->id(), true);

        self::assertSame([], $this->repository->listFor($alice->id(), Box::BOX_RECEIVED));
        self::assertSame([], $this->repository->listFor($alice->id(), Box::BOX_SENT));
        self::assertCount(1, $this->repository->listFor($alice->id(), Box::BOX_ARCHIVED));
        self::assertCount(1, $this->repository->listFor($bob->id(), Box::BOX_RECEIVED), 'Bob n\'est pas concerné');
        self::assertSame([], $this->repository->listFor($bob->id(), Box::BOX_ARCHIVED));

        $this->repository->setArchived($thread->id(), $alice->id(), false);
        self::assertCount(1, $this->repository->listFor($alice->id(), Box::BOX_RECEIVED));
    }

    #[Test]
    public function testUnreadUntilReadAndAgainAfterANewMessageFromSomeoneElse(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $a = $this->group('Alpha', $alice);
        $b = $this->group('Beta', $bob);
        $thread = $this->repository->create($a->id(), $b->id(), 'Fil', $this->now);
        $this->repository->addMessage($thread->id(), $alice->id(), 'Salut', $this->at('+1 minute'));

        self::assertTrue($this->repository->listFor($bob->id(), Box::BOX_RECEIVED)[0]->isUnread());
        self::assertFalse($this->repository->listFor($alice->id(), Box::BOX_RECEIVED)[0]->isUnread(), 'mon propre message n\'est pas non lu');
        self::assertSame(1, $this->repository->countUnreadFor($bob->id()));

        $this->repository->markRead($thread->id(), $bob->id(), $this->at('+2 minutes'));
        self::assertFalse($this->repository->listFor($bob->id(), Box::BOX_RECEIVED)[0]->isUnread());
        self::assertSame(0, $this->repository->countUnreadFor($bob->id()));

        $this->repository->addMessage($thread->id(), $alice->id(), 'Encore', $this->at('+3 minutes'));
        self::assertTrue($this->repository->listFor($bob->id(), Box::BOX_RECEIVED)[0]->isUnread());
    }

    #[Test]
    public function testMarkReadTwiceIsHarmlessAndKeepsTheArchiveState(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $a = $this->group('Alpha', $alice);
        $b = $this->group('Beta', $bob);
        $thread = $this->repository->create($a->id(), $b->id(), 'Fil', $this->now);
        $this->repository->addMessage($thread->id(), $alice->id(), 'Salut', $this->now);

        $this->repository->setArchived($thread->id(), $bob->id(), true);
        $this->repository->markRead($thread->id(), $bob->id(), $this->at('+1 minute'));
        $this->repository->markRead($thread->id(), $bob->id(), $this->at('+2 minutes'));

        self::assertCount(1, $this->repository->listFor($bob->id(), Box::BOX_ARCHIVED));
    }

    #[Test]
    public function testMostRecentlyActiveThreadComesFirst(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $a = $this->group('Alpha', $alice);
        $b = $this->group('Beta', $bob);
        $old = $this->repository->create($a->id(), $b->id(), 'Ancien', $this->now);
        $this->repository->addMessage($old->id(), $alice->id(), 'm', $this->at('+1 minute'));
        $recent = $this->repository->create($a->id(), $b->id(), 'Récent', $this->now);
        $this->repository->addMessage($recent->id(), $alice->id(), 'm', $this->at('+2 minutes'));
        $this->repository->addMessage($old->id(), $bob->id(), 'm', $this->at('+3 minutes'));

        $subjects = array_map(static fn ($s) => $s->conversation()->subject(), $this->repository->listFor($alice->id(), Box::BOX_RECEIVED));

        self::assertSame(['Ancien', 'Récent'], $subjects);
    }

    #[Test]
    public function testCountMessagesBySinceCountsOnlyRecentMessagesOfTheAuthor(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $a = $this->group('Alpha', $alice);
        $b = $this->group('Beta', $bob);
        $thread = $this->repository->create($a->id(), $b->id(), 'Fil', $this->now);
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
        $thread = $this->repository->create($a->id(), $b->id(), 'Fil', $this->now);
        $this->repository->addMessage($thread->id(), $alice->id(), 'm', $this->now);

        $this->groups->delete($b->id());

        self::assertNull($this->repository->findById($thread->id()));
    }
}
