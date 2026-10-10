<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Repository\Notice;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Account\Repository\MysqlUserRepository;
use App\Group\Entity\Group;
use App\Group\Repository\MysqlGroupRepository;
use App\Messaging\Repository\MysqlConversationMessageRepository;
use App\Messaging\Repository\MysqlConversationPresenceRepository;
use App\Messaging\Repository\MysqlConversationRepository;
use App\Messaging\Repository\Notice\MysqlMentionNoticeRepository;
use App\Messaging\Repository\Participation\MysqlConversationMuteRepository;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * #372 : un message direct non lu est relancé UNE fois après 24 h, comme une mention, mais sans le désabonnement général
 * (règle des messages directs : seule la sourdine de la conversation coupe) et en nommant l'expéditeur.
 */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class MysqlDirectReminderTest extends RepositoryTestCase
{
    private \DateTimeImmutable $now;
    private MysqlMentionNoticeRepository $notices;
    private MysqlConversationPresenceRepository $presence;
    private int $directId;
    /** @var array<string, User> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-10-06 12:00:00');
        $this->notices = new MysqlMentionNoticeRepository($this->pdo);
        $this->presence = new MysqlConversationPresenceRepository($this->pdo);
        $users = new MysqlUserRepository($this->pdo);
        foreach (['alice', 'bob', 'carol'] as $name) {
            $this->people[$name] = $users->save(new User(0, "{$name}@rehearsalbox.test", 'hash', ucfirst($name), UserRole::Musicien, true, 0, null));
        }
        $conversations = new MysqlConversationRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make());
        $this->directId = $conversations->openDirect($this->id('alice'), $this->id('bob'), $this->now->modify('-3 days'))->id();
        $this->notices->claimNotice($this->directId, $this->id('bob'), $this->id('alice'), $this->now->modify('-25 hours'), $this->now->modify('-49 hours'));
    }

    private function id(string $name): int
    {
        return $this->people[$name]->id();
    }

    /** @return list<array{int, string, bool}> [personne relancée, expéditeur, message direct] */
    private function due(): array
    {
        return array_map(
            static fn ($d): array => [$d->userId(), $d->mentionerName(), $d->isDirect()],
            $this->notices->findDueReminders($this->now->modify('-24 hours'), $this->now->modify('-7 days')),
        );
    }

    #[Test]
    public function testAnUnreadDirectMessageIsDueAfterTwentyFourHoursAndNamesTheSender(): void
    {
        self::assertSame([[$this->id('bob'), 'Alice', true]], $this->due());
    }

    #[Test]
    public function testTheGeneralEmailOptOutDoesNotStopTheReminderOfADirectMessage(): void
    {
        $this->pdo->exec('UPDATE users SET email_notifications = 0 WHERE id = ' . $this->id('bob'));

        self::assertSame([[$this->id('bob'), 'Alice', true]], $this->due());
    }

    #[Test]
    public function testAMutedTrashedOrReadDirectConversationIsNotReminded(): void
    {
        (new MysqlConversationMuteRepository($this->pdo))->setMuted($this->directId, $this->id('bob'), true);
        self::assertSame([], $this->due(), 'sourdine');
        (new MysqlConversationMuteRepository($this->pdo))->setMuted($this->directId, $this->id('bob'), false);
        self::assertCount(1, $this->due());

        $this->presence->markRead($this->directId, $this->id('bob'), $this->now->modify('-1 hour'));
        self::assertSame([], $this->due(), 'lue depuis l\'e-mail');
    }

    #[Test]
    public function testADeletedConversationOrAnInactiveRecipientIsNotReminded(): void
    {
        $this->pdo->exec('UPDATE users SET is_active = 0 WHERE id = ' . $this->id('bob'));
        self::assertSame([], $this->due(), 'compte inactif');
        $this->pdo->exec('UPDATE users SET is_active = 1 WHERE id = ' . $this->id('bob'));

        $this->pdo->exec("UPDATE conversations SET deleted_at = '2026-10-06 08:00:00' WHERE id = " . $this->directId);
        self::assertSame([], $this->due(), 'conversation à la corbeille');
    }

    #[Test]
    public function testSomeoneWhoIsNotAParticipantOfTheDirectConversationIsNeverReminded(): void
    {
        $this->pdo->exec('DELETE FROM conversation_mention_notices');
        $this->notices->claimNotice($this->directId, $this->id('carol'), $this->id('alice'), $this->now->modify('-25 hours'), $this->now->modify('-49 hours'));

        self::assertSame([], $this->due());
    }

    #[Test]
    public function testTheGeneralOptOutStillStopsTheRemindersOfGroupMentions(): void
    {
        $groups = new MysqlGroupRepository($this->pdo);
        $alpha = $groups->save(new Group(0, 'Alpha', null, null, 'alpha@rehearsalbox.test'))->id();
        $beta = $groups->save(new Group(0, 'Beta', null, null, 'beta@rehearsalbox.test'))->id();
        $groups->addMember($alpha, $this->id('alice'));
        $groups->addMember($beta, $this->id('carol'));
        $conversation = (new MysqlConversationRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make()))->create($alpha, $beta, null, $this->now->modify('-3 days'), $this->id('alice'))->id();
        $this->notices->claimNotice($conversation, $this->id('carol'), $this->id('alice'), $this->now->modify('-25 hours'), $this->now->modify('-49 hours'));
        $this->pdo->exec('DELETE FROM conversation_mention_notices WHERE conversation_id = ' . $this->directId);
        self::assertCount(1, $this->due(), 'mention de groupe relancée');

        $this->pdo->exec('UPDATE users SET email_notifications = 0 WHERE id = ' . $this->id('carol'));
        self::assertSame([], $this->due(), 'le désabonnement général reste respecté pour les mentions');
    }
}
