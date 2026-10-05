<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\MysqlConversationMentionRepository;
use App\Repository\MysqlConversationRepository;
use App\Repository\MysqlConversationTrashRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

final class MysqlConversationMentionRepositoryTest extends RepositoryTestCase
{
    private \DateTimeImmutable $now;
    private MysqlConversationMentionRepository $mentions;
    private MysqlConversationRepository $conversations;
    /** @var array<string, User> */
    private array $people = [];
    private int $conversationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-10-06 12:00:00');
        $this->mentions = new MysqlConversationMentionRepository($this->pdo);
        $this->conversations = new MysqlConversationRepository($this->pdo);
        $users = new MysqlUserRepository($this->pdo);
        $groups = new MysqlGroupRepository($this->pdo);
        foreach (['alice', 'bob', 'denis'] as $name) {
            $this->people[$name] = $users->save(new User(0, "{$name}@rehearsalbox.test", 'hash', ucfirst($name), UserRole::Musicien, true, 0, null));
        }
        $alpha = $groups->save(new Group(0, 'Alpha', null, null, 'alpha@rehearsalbox.test'))->id();
        $beta = $groups->save(new Group(0, 'Beta', null, null, 'beta@rehearsalbox.test'))->id();
        $groups->addMember($alpha, $this->people['alice']->id());
        $groups->addMember($beta, $this->people['bob']->id());
        $this->conversationId = $this->conversations->create($alpha, $beta, null, $this->now, $this->people['alice']->id())->id();
    }

    private function say(string $text): int
    {
        return $this->conversations->addMessage($this->conversationId, $this->people['alice']->id(), $text, $this->now)->id();
    }

    #[Test]
    public function testMentionsAreRecordedByUserIdWithTheirLabelAndReadBackPerMessage(): void
    {
        $first = $this->say('Salut @Denis');
        $second = $this->say('Rien ici');
        $third = $this->say('@Bob et @Denis');

        $this->mentions->record($first, [$this->people['denis']->id() => '@Denis']);
        $this->mentions->record($third, [$this->people['bob']->id() => '@Bob', $this->people['denis']->id() => '@Denis']);

        $found = $this->mentions->forMessages([$first, $second, $third]);

        self::assertSame([$this->people['denis']->id() => '@Denis'], $found[$first]);
        self::assertArrayNotHasKey($second, $found, 'un message sans mention est absent du résultat');
        self::assertEqualsCanonicalizing(['@Bob', '@Denis'], array_values($found[$third]));
    }

    #[Test]
    public function testRecordingTwiceForTheSameMessageReplacesThePreviousMentions(): void
    {
        $id = $this->say('@Bob');
        $this->mentions->record($id, [$this->people['bob']->id() => '@Bob']);

        $this->mentions->record($id, [$this->people['denis']->id() => '@Denis']);

        self::assertSame([$this->people['denis']->id() => '@Denis'], $this->mentions->forMessages([$id])[$id]);
    }

    #[Test]
    public function testAnEmptyListOfMessagesGivesAnEmptyResult(): void
    {
        self::assertSame([], $this->mentions->forMessages([]));
    }

    #[Test]
    public function testMentionsDisappearWithTheirMessage(): void
    {
        $id = $this->say('@Bob');
        $this->mentions->record($id, [$this->people['bob']->id() => '@Bob']);

        (new MysqlConversationTrashRepository($this->pdo))->delete($this->conversationId);

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM message_mentions')->fetchColumn());
    }

    #[Test]
    public function testAnUnreadMentionMarksTheConversationInTheListUntilItIsRead(): void
    {
        $cutoff = $this->now->modify('-30 days');
        $id = $this->say('Salut @Bob');
        $this->mentions->record($id, [$this->people['bob']->id() => '@Bob']);
        $mentioned = fn (string $who): bool => $this->conversations->listFor($this->people[$who]->id(), 'active', $cutoff)[0]->isMentioned();

        self::assertTrue($mentioned('bob'), 'Bob est tagué et n\'a pas lu');
        self::assertFalse($mentioned('alice'), 'Alice a écrit le message, elle n\'est pas taguée');

        $this->conversations->markRead($this->conversationId, $this->people['bob']->id(), $this->now->modify('+1 minute'));

        self::assertFalse($mentioned('bob'), 'lu : le marqueur disparaît');
    }
}
