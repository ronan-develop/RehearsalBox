<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\MysqlConversationMessageRepository;
use App\Repository\MysqlConversationMuteRepository;
use App\Repository\MysqlConversationPresenceRepository;
use App\Repository\MysqlConversationRepository;
use App\Tests\RepositoryTestCase;
use App\Tests\Support\MessagingScenario;
use PHPUnit\Framework\Attributes\Test;

/** Sourdine (#210) : un interrupteur personnel par conversation, stocké dans conversation_states. */
final class MysqlConversationMuteRepositoryTest extends RepositoryTestCase
{
    use MessagingScenario;

    private MysqlConversationRepository $conversations;
    private MysqlConversationMessageRepository $messages;
    private MysqlConversationPresenceRepository $presence;
    private MysqlConversationMuteRepository $mutes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        $this->conversations = new MysqlConversationRepository($this->pdo);
        $this->messages = new MysqlConversationMessageRepository($this->pdo);
        $this->presence = new MysqlConversationPresenceRepository($this->pdo);
        $this->mutes = new MysqlConversationMuteRepository($this->pdo);
    }

    #[Test]
    public function testAConversationIsNotMutedByDefault(): void
    {
        [, $bob, $a, $b] = $this->pair();
        $thread = $this->conversations->create($a->id(), $b->id(), 'Fil', $this->now);

        self::assertFalse($this->mutes->isMuted($thread->id(), $bob->id()));
    }

    #[Test]
    public function testMutingAndUnmutingTogglesOnlyThatPersonsSwitch(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->conversations->create($a->id(), $b->id(), 'Fil', $this->now);

        $this->mutes->setMuted($thread->id(), $bob->id(), true);
        self::assertTrue($this->mutes->isMuted($thread->id(), $bob->id()));
        self::assertFalse($this->mutes->isMuted($thread->id(), $alice->id()), 'personnelle : les autres ne sont pas touchés');

        $this->mutes->setMuted($thread->id(), $bob->id(), false);
        self::assertFalse($this->mutes->isMuted($thread->id(), $bob->id()));
    }

    #[Test]
    public function testMutingTwiceIsHarmless(): void
    {
        [, $bob, $a, $b] = $this->pair();
        $thread = $this->conversations->create($a->id(), $b->id(), 'Fil', $this->now);

        $this->mutes->setMuted($thread->id(), $bob->id(), true);
        $this->mutes->setMuted($thread->id(), $bob->id(), true);

        self::assertTrue($this->mutes->isMuted($thread->id(), $bob->id()));
    }

    #[Test]
    public function testMutingKeepsTheReadDateAndReadingKeepsTheMute(): void
    {
        [, $bob, $a, $b] = $this->pair();
        $thread = $this->conversations->create($a->id(), $b->id(), 'Fil', $this->now);
        $this->presence->markRead($thread->id(), $bob->id(), $this->at('+1 minute'));

        $this->mutes->setMuted($thread->id(), $bob->id(), true);
        self::assertEquals($this->at('+1 minute'), $this->presence->lastReadAt($thread->id(), $bob->id()));

        $this->presence->markRead($thread->id(), $bob->id(), $this->at('+2 minutes'));
        self::assertTrue($this->mutes->isMuted($thread->id(), $bob->id()));
    }

    #[Test]
    public function testAMutedConversationIsNotCountedAsUnread(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->conversations->create($a->id(), $b->id(), 'Fil', $this->now);
        $this->messages->addMessage($thread->id(), $alice->id(), 'Salut', $this->now);
        self::assertSame(1, $this->conversations->countUnreadFor($bob->id(), $this->cutoff));

        $this->mutes->setMuted($thread->id(), $bob->id(), true);

        self::assertSame(0, $this->conversations->countUnreadFor($bob->id(), $this->cutoff), 'sourdine = pas de comptage de non-lus');
    }
}
