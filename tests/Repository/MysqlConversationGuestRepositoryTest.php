<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\Contract\ConversationRepositoryInterface as Box;
use App\Repository\MysqlConversationGuestRepository;
use App\Repository\MysqlConversationRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

/** #178 : un invité (membre du site extérieur aux deux groupes) accède à cette conversation seulement. */
final class MysqlConversationGuestRepositoryTest extends RepositoryTestCase
{
    private \DateTimeImmutable $now;
    private \DateTimeImmutable $cutoff;
    private MysqlConversationRepository $conversations;
    private MysqlConversationGuestRepository $guests;
    /** @var array<string, User> */
    private array $people = [];
    private int $conversationId;
    private int $otherConversationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-10-06 12:00:00');
        $this->cutoff = $this->now->modify('-30 days');
        $this->conversations = new MysqlConversationRepository($this->pdo);
        $this->guests = new MysqlConversationGuestRepository($this->pdo);
        $users = new MysqlUserRepository($this->pdo);
        $groups = new MysqlGroupRepository($this->pdo);
        foreach (['alice', 'bob', 'denis'] as $name) {
            $this->people[$name] = $users->save(new User(0, "{$name}@rehearsalbox.test", 'hash', ucfirst($name), UserRole::Musicien, true, 0, null));
        }
        $alpha = $groups->save(new Group(0, 'Alpha', null, null, 'alpha@rehearsalbox.test'))->id();
        $beta = $groups->save(new Group(0, 'Beta', null, null, 'beta@rehearsalbox.test'))->id();
        $carnage = $groups->save(new Group(0, 'Carnage', null, null, 'carnage@rehearsalbox.test'))->id();
        $groups->addMember($alpha, $this->people['alice']->id());
        $groups->addMember($beta, $this->people['bob']->id());
        $groups->addMember($carnage, $this->people['denis']->id());
        $this->conversationId = $this->conversations->create($alpha, $beta, 'Concert', $this->now, $this->people['alice']->id())->id();
        $this->conversations->addMessage($this->conversationId, $this->people['alice']->id(), 'Salut', $this->now);
        $this->otherConversationId = $this->conversations->create($alpha, $beta, 'Autre', $this->now, $this->people['alice']->id())->id();
        $this->conversations->addMessage($this->otherConversationId, $this->people['alice']->id(), 'Hello', $this->now);
    }

    private function id(string $name): int
    {
        return $this->people[$name]->id();
    }

    /** @return list<string> */
    private function titlesFor(string $name): array
    {
        return array_map(static fn ($s) => $s->displayTitle(), $this->conversations->listFor($this->id($name), Box::BOX_ACTIVE, $this->cutoff));
    }

    #[Test]
    public function testAddingIsIdempotentAndRemembersWhoAddedTheGuest(): void
    {
        self::assertTrue($this->guests->add($this->conversationId, $this->id('denis'), $this->id('alice'), $this->now));
        self::assertFalse($this->guests->add($this->conversationId, $this->id('denis'), $this->id('bob'), $this->now), 'déjà invité');

        self::assertTrue($this->guests->isGuest($this->conversationId, $this->id('denis')));
        self::assertFalse($this->guests->isGuest($this->otherConversationId, $this->id('denis')), "l'invitation ne vaut que pour cette conversation");
        self::assertSame($this->id('alice'), $this->guests->addedBy($this->conversationId, $this->id('denis')));
    }

    #[Test]
    public function testAGuestSeesOnlyTheConversationHeWasAddedTo(): void
    {
        self::assertSame([], $this->titlesFor('denis'));

        $this->guests->add($this->conversationId, $this->id('denis'), $this->id('alice'), $this->now);

        self::assertSame(['Concert'], $this->titlesFor('denis'));
        self::assertSame(1, $this->conversations->countUnreadFor($this->id('denis'), $this->cutoff));
    }

    #[Test]
    public function testRemovingTheGuestTakesTheConversationAway(): void
    {
        $this->guests->add($this->conversationId, $this->id('denis'), $this->id('alice'), $this->now);

        $this->guests->remove($this->conversationId, $this->id('denis'));

        self::assertSame([], $this->titlesFor('denis'));
        self::assertFalse($this->guests->isGuest($this->conversationId, $this->id('denis')));
    }

    #[Test]
    public function testAGuestCountsAsAParticipantAndAppearsInReadersAndTyping(): void
    {
        self::assertSame(2, $this->conversations->participantCount($this->conversationId));
        $this->guests->add($this->conversationId, $this->id('denis'), $this->id('alice'), $this->now);
        $this->conversations->markRead($this->conversationId, $this->id('denis'), $this->now);
        $this->conversations->setTyping($this->conversationId, $this->id('denis'), $this->now);

        self::assertSame(3, $this->conversations->participantCount($this->conversationId));
        self::assertSame(['Denis'], $this->conversations->readersOf($this->conversationId, $this->now->modify('-1 minute'), $this->id('alice')));
        self::assertSame(['Denis'], $this->conversations->typingNames($this->conversationId, $this->id('alice'), $this->now->modify('-5 seconds')));
    }

    #[Test]
    public function testAGuestIsRemovedWithTheConversationAndTheirAccount(): void
    {
        $this->guests->add($this->conversationId, $this->id('denis'), $this->id('alice'), $this->now);

        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->id('alice')]);

        self::assertNull($this->guests->addedBy($this->conversationId, $this->id('denis')), "celui qui l'a ajouté a disparu");
    }
}
