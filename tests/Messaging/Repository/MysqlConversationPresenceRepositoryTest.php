<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Repository;

use App\Account\Entity\User;
use App\Messaging\Repository\MysqlConversationMessageRepository;
use App\Messaging\Repository\MysqlConversationPresenceRepository;
use App\Messaging\Repository\MysqlConversationRepository;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Scenarios\MessagingScenario;
use PHPUnit\Framework\Attributes\Test;

/** Présence : dernière lecture, « en train d'écrire » et « vu par ». */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class MysqlConversationPresenceRepositoryTest extends RepositoryTestCase
{
    use MessagingScenario;

    private MysqlConversationRepository $conversations;
    private MysqlConversationMessageRepository $messages;
    private MysqlConversationPresenceRepository $presence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        $this->conversations = new MysqlConversationRepository($this->pdo);
        $this->messages = new MysqlConversationMessageRepository($this->pdo);
        $this->presence = new MysqlConversationPresenceRepository($this->pdo);
    }

    #[Test]
    public function testMarkReadTwiceIsHarmless(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->conversations->create($a->id(), $b->id(), 'Fil', $this->now);
        $this->messages->addMessage($thread->id(), $alice->id(), 'Salut', $this->now);

        $this->presence->markRead($thread->id(), $bob->id(), $this->at('+1 minute'));
        $this->presence->markRead($thread->id(), $bob->id(), $this->at('+2 minutes'));

        self::assertSame(0, $this->conversations->countUnreadFor($bob->id(), $this->cutoff));
    }

    #[Test]
    public function testLastReadAtIsNullUntilReadThenTheReadDate(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->conversations->create($a->id(), $b->id(), null, $this->now);
        $this->messages->addMessage($thread->id(), $alice->id(), 'Salut', $this->now);

        self::assertNull($this->presence->lastReadAt($thread->id(), $bob->id()));

        $this->presence->setTyping($thread->id(), $bob->id(), $this->at('+10 seconds'));
        self::assertNull($this->presence->lastReadAt($thread->id(), $bob->id()), "écrire n'est pas lire");

        $this->presence->markRead($thread->id(), $bob->id(), $this->at('+1 minute'));
        self::assertEquals($this->at('+1 minute'), $this->presence->lastReadAt($thread->id(), $bob->id()));
    }

    #[Test]
    public function testReadersAreTheOtherMembersWhoReadAtOrAfterTheMessage(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $zoe = $this->user('Zoe');
        $this->groups->addMember($b->id(), $zoe->id());
        $thread = $this->conversations->create($a->id(), $b->id(), null, $this->now);
        $message = $this->messages->addMessage($thread->id(), $alice->id(), 'Salut', $this->at('+1 minute'));

        $this->presence->markRead($thread->id(), $bob->id(), $this->at('+2 minutes'));
        $this->presence->markRead($thread->id(), $zoe->id(), $this->at('+30 seconds'));
        $this->presence->markRead($thread->id(), $alice->id(), $this->at('+5 minutes'));

        self::assertSame(['Bob'], $this->presence->readersOf($thread->id(), $message->createdAt(), $alice->id()), 'Zoé a lu avant le message ; Alice est l\'auteure');
        self::assertSame(3, $this->conversations->participantCount($thread->id()));
    }

    #[Test]
    public function testAMemberWhoLeftIsNoLongerAReaderNorAParticipant(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->conversations->create($a->id(), $b->id(), null, $this->now);
        $message = $this->messages->addMessage($thread->id(), $alice->id(), 'Salut', $this->now);
        $this->presence->markRead($thread->id(), $bob->id(), $this->at('+1 minute'));

        $this->groups->removeMember($b->id(), $bob->id());

        self::assertSame([], $this->presence->readersOf($thread->id(), $message->createdAt(), $alice->id()));
        self::assertSame(1, $this->conversations->participantCount($thread->id()));
    }

    #[Test]
    public function testTypingIsVisibleToOthersForAShortWindowAndNeverToTheTyperThemself(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->conversations->create($a->id(), $b->id(), null, $this->now);
        $this->messages->addMessage($thread->id(), $alice->id(), 'Salut', $this->now);

        $this->presence->setTyping($thread->id(), $bob->id(), $this->at('+10 seconds'));

        self::assertSame(['Bob'], $this->presence->typingNames($thread->id(), $alice->id(), $this->at('+7 seconds')));
        self::assertSame([], $this->presence->typingNames($thread->id(), $bob->id(), $this->at('+7 seconds')), 'jamais pour soi-même');
        self::assertSame([], $this->presence->typingNames($thread->id(), $alice->id(), $this->at('+11 seconds')), 'signal expiré');
    }

    #[Test]
    public function testTypingDoesNotDisturbTheReadStateAndRevealsNothingToOutsiders(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->conversations->create($a->id(), $b->id(), null, $this->now);
        $this->messages->addMessage($thread->id(), $alice->id(), 'Salut', $this->at('+1 minute'));
        $this->presence->setTyping($thread->id(), $bob->id(), $this->at('+2 minutes'));

        self::assertSame(1, $this->conversations->countUnreadFor($bob->id(), $this->cutoff), 'écrire n\'est pas lire');
        $this->presence->markRead($thread->id(), $bob->id(), $this->at('+3 minutes'));
        self::assertSame(['Bob'], $this->presence->typingNames($thread->id(), $alice->id(), $this->at('+90 seconds')), 'lire ne supprime pas le signal');
    }

    #[Test]
    public function testTypingSignalsAreThrottledToOneEveryTwoSeconds(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->conversations->create($a->id(), $b->id(), null, $this->now);
        $this->messages->addMessage($thread->id(), $alice->id(), 'Salut', $this->now);

        $this->presence->setTyping($thread->id(), $bob->id(), $this->at('+10 seconds'));
        $this->presence->setTyping($thread->id(), $bob->id(), $this->at('+11 seconds'));
        self::assertSame([], $this->presence->typingNames($thread->id(), $alice->id(), $this->at('+11 seconds')), 'le second signal (1 s plus tard) est ignoré');
        self::assertSame(['Bob'], $this->presence->typingNames($thread->id(), $alice->id(), $this->at('+10 seconds')));

        $this->presence->setTyping($thread->id(), $bob->id(), $this->at('+13 seconds'));
        self::assertSame(['Bob'], $this->presence->typingNames($thread->id(), $alice->id(), $this->at('+13 seconds')), 'au-delà de 2 s le signal est pris en compte');
    }
}
