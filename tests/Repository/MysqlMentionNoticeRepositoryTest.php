<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\MysqlConversationGuestRepository;
use App\Repository\MysqlConversationMentionRepository;
use App\Repository\MysqlConversationRepository;
use App\Repository\MysqlConversationPresenceRepository;
use App\Repository\MysqlConversationMessageRepository;
use App\Repository\MysqlConversationTrashRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlMentionNoticeRepository;
use App\Repository\MysqlNotificationPreferenceRepository;
use App\Repository\MysqlUserRepository;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

final class MysqlMentionNoticeRepositoryTest extends RepositoryTestCase
{
    private \DateTimeImmutable $now;
    private MysqlMentionNoticeRepository $notices;
    private MysqlConversationRepository $conversations;
    private MysqlConversationMessageRepository $messages;
    private MysqlConversationPresenceRepository $presence;
    private MysqlConversationMentionRepository $mentions;
    /** @var array<string, User> */
    private array $people = [];
    private int $conversationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-10-06 12:00:00');
        $this->notices = new MysqlMentionNoticeRepository($this->pdo);
        $this->conversations = new MysqlConversationRepository($this->pdo);
        $this->messages = new MysqlConversationMessageRepository($this->pdo);
        $this->presence = new MysqlConversationPresenceRepository($this->pdo);
        $this->mentions = new MysqlConversationMentionRepository($this->pdo);
        $users = new MysqlUserRepository($this->pdo);
        $groups = new MysqlGroupRepository($this->pdo);
        foreach (['alice', 'bob', 'denis'] as $name) {
            $this->people[$name] = $users->save(new User(0, "{$name}@rehearsalbox.test", 'hash', ucfirst($name), UserRole::Musicien, true, 0, null));
        }
        $alpha = $groups->save(new Group(0, 'Alpha', null, null, 'alpha@rehearsalbox.test'))->id();
        $beta = $groups->save(new Group(0, 'Beta', null, null, 'beta@rehearsalbox.test'))->id();
        $groups->addMember($alpha, $this->id('alice'));
        $groups->addMember($beta, $this->id('bob'));
        $this->conversationId = $this->conversations->create($alpha, $beta, null, $this->now->modify('-3 days'), $this->id('alice'))->id();
        (new MysqlConversationGuestRepository($this->pdo))->add($this->conversationId, $this->id('denis'), $this->id('alice'), $this->now->modify('-3 days'));
    }

    private function id(string $name): int
    {
        return $this->people[$name]->id();
    }

    private function notBefore(): \DateTimeImmutable
    {
        return $this->now->modify('-24 hours');
    }

    /** Message d'Alice qui mentionne Denis, écrit à la date indiquée. */
    private function tagDenis(string $at): int
    {
        $message = $this->messages->addMessage($this->conversationId, $this->id('alice'), '@Denis', new \DateTimeImmutable($at));
        $this->mentions->record($message->id(), [$this->id('denis') => '@Denis']);

        return $message->id();
    }

    /** @return list<array{int, string}> [personne relancée, auteur de la mention] */
    private function due(string $now = '2026-10-06 12:00:00'): array
    {
        $now = new \DateTimeImmutable($now);

        return array_map(
            static fn ($d): array => [$d->userId(), $d->mentionerName()],
            $this->notices->findDueReminders($now->modify('-24 hours'), $now->modify('-7 days')),
        );
    }

    #[Test]
    public function testTheFirstMentionIsClaimedAndAnotherWithinTwentyFourHoursIsRefused(): void
    {
        self::assertTrue($this->notices->claimNotice($this->conversationId, $this->id('denis'), $this->id('alice'), $this->now, $this->notBefore()));
        self::assertFalse($this->notices->claimNotice($this->conversationId, $this->id('denis'), $this->id('alice'), $this->now->modify('+2 hours'), $this->now->modify('+2 hours')->modify('-24 hours')), 'moins de 24 h : pas de second e-mail');
        self::assertEquals($this->now, $this->notices->find($this->conversationId, $this->id('denis'))->notifiedAt());
    }

    #[Test]
    public function testAfterTwentyFourHoursANewMentionIsClaimedAgainAndRestartsTheReminder(): void
    {
        $this->notices->claimNotice($this->conversationId, $this->id('denis'), $this->id('alice'), $this->now, $this->notBefore());
        $this->notices->claimReminder($this->conversationId, $this->id('denis'), $this->now->modify('+1 day'));
        $later = $this->now->modify('+2 days');

        self::assertTrue($this->notices->claimNotice($this->conversationId, $this->id('denis'), $this->id('bob'), $later, $later->modify('-24 hours')));

        $notice = $this->notices->find($this->conversationId, $this->id('denis'));
        self::assertEquals($later, $notice->notifiedAt());
        self::assertSame($this->id('bob'), $notice->notifiedBy());
        self::assertNull($notice->remindedAt(), 'un nouvel e-mail relance le compte à rebours de la relance');
    }

    #[Test]
    public function testEachConversationAndEachPersonHasTheirOwnClock(): void
    {
        self::assertTrue($this->notices->claimNotice($this->conversationId, $this->id('denis'), $this->id('alice'), $this->now, $this->notBefore()));
        self::assertTrue($this->notices->claimNotice($this->conversationId, $this->id('bob'), $this->id('alice'), $this->now, $this->notBefore()));
    }

    #[Test]
    public function testRestoringAfterAFailedSendLeavesNoTraceOrPutsTheOldStateBack(): void
    {
        $this->notices->claimNotice($this->conversationId, $this->id('denis'), $this->id('alice'), $this->now, $this->notBefore());
        $this->notices->restoreNotice($this->conversationId, $this->id('denis'), null);
        self::assertNull($this->notices->find($this->conversationId, $this->id('denis')));

        $first = $this->now->modify('-3 days');
        $this->notices->claimNotice($this->conversationId, $this->id('denis'), $this->id('alice'), $first, $first->modify('-24 hours'));
        $previous = $this->notices->find($this->conversationId, $this->id('denis'));
        $this->notices->claimNotice($this->conversationId, $this->id('denis'), $this->id('alice'), $this->now, $this->notBefore());

        $this->notices->restoreNotice($this->conversationId, $this->id('denis'), $previous);

        self::assertEquals($first, $this->notices->find($this->conversationId, $this->id('denis'))->notifiedAt());
    }

    #[Test]
    public function testTheHourlyCapCountsTheEmailsSentByAnAuthor(): void
    {
        $this->notices->claimNotice($this->conversationId, $this->id('denis'), $this->id('alice'), $this->now->modify('-30 minutes'), $this->notBefore());
        $this->notices->claimNotice($this->conversationId, $this->id('bob'), $this->id('alice'), $this->now->modify('-3 hours'), $this->notBefore());

        self::assertSame(1, $this->notices->countSentBy($this->id('alice'), $this->now->modify('-1 hour')));
        self::assertSame(2, $this->notices->countSentBy($this->id('alice'), $this->now->modify('-1 day')));
        self::assertSame(0, $this->notices->countSentBy($this->id('bob'), $this->now->modify('-1 day')));
    }

    #[Test]
    public function testAReminderIsDueTwentyFourHoursAfterTheEmailIfTheMentionIsStillUnread(): void
    {
        $this->tagDenis('2026-10-05 10:00:00');
        $this->notices->claimNotice($this->conversationId, $this->id('denis'), $this->id('alice'), new \DateTimeImmutable('2026-10-05 10:00:00'), new \DateTimeImmutable('2026-10-04 10:00:00'));

        self::assertSame([[$this->id('denis'), 'Alice']], $this->due());
        self::assertSame([], $this->due('2026-10-06 09:59:00'), 'avant 24 h : trop tôt');
        self::assertSame([], $this->due('2026-10-13 12:00:00'), 'après 7 jours : trop tard');
    }

    #[Test]
    public function testReadingTheConversationCancelsTheReminder(): void
    {
        $this->tagDenis('2026-10-05 10:00:00');
        $this->notices->claimNotice($this->conversationId, $this->id('denis'), $this->id('alice'), new \DateTimeImmutable('2026-10-05 10:00:00'), new \DateTimeImmutable('2026-10-04 10:00:00'));

        $this->presence->markRead($this->conversationId, $this->id('denis'), new \DateTimeImmutable('2026-10-05 20:00:00'));

        self::assertSame([], $this->due());
    }

    #[Test]
    public function testOnlyOneReminderPerEmailAndItCanBeRestoredAfterAFailure(): void
    {
        $this->tagDenis('2026-10-05 10:00:00');
        $this->notices->claimNotice($this->conversationId, $this->id('denis'), $this->id('alice'), new \DateTimeImmutable('2026-10-05 10:00:00'), new \DateTimeImmutable('2026-10-04 10:00:00'));

        self::assertTrue($this->notices->claimReminder($this->conversationId, $this->id('denis'), $this->now));
        self::assertFalse($this->notices->claimReminder($this->conversationId, $this->id('denis'), $this->now), 'une seule relance');
        self::assertSame([], $this->due());

        $this->notices->restoreReminder($this->conversationId, $this->id('denis'));
        self::assertSame([[$this->id('denis'), 'Alice']], $this->due(), 'échec d\'envoi : réessayable');
    }

    #[Test]
    public function testMutingTheConversationCancelsThePendingReminderAndUnmutingBringsItBack(): void
    {
        $this->tagDenis('2026-10-05 10:00:00');
        $this->notices->claimNotice($this->conversationId, $this->id('denis'), $this->id('alice'), new \DateTimeImmutable('2026-10-05 10:00:00'), new \DateTimeImmutable('2026-10-04 10:00:00'));
        $mutes = new \App\Repository\MysqlConversationMuteRepository($this->pdo);

        $mutes->setMuted($this->conversationId, $this->id('denis'), true);
        self::assertSame([], $this->due(), 'sourdine (#210) : aucune relance');

        $mutes->setMuted($this->conversationId, $this->id('denis'), false);
        self::assertSame([[$this->id('denis'), 'Alice']], $this->due(), 'sourdine levée : la relance reste due');
    }

    #[Test]
    public function testNoReminderForUnsubscribedPeopleTrashedConversationsOrRemovedGuests(): void
    {
        $this->tagDenis('2026-10-05 10:00:00');
        $this->notices->claimNotice($this->conversationId, $this->id('denis'), $this->id('alice'), new \DateTimeImmutable('2026-10-05 10:00:00'), new \DateTimeImmutable('2026-10-04 10:00:00'));
        self::assertCount(1, $this->due());

        (new MysqlNotificationPreferenceRepository($this->pdo))->setEmailEnabled($this->id('denis'), false);
        self::assertSame([], $this->due(), 'désinscrit : aucune relance');
        (new MysqlNotificationPreferenceRepository($this->pdo))->setEmailEnabled($this->id('denis'), true);

        (new MysqlConversationTrashRepository($this->pdo))->moveToTrash($this->conversationId, $this->now);
        self::assertSame([], $this->due(), 'conversation à la corbeille');
        (new MysqlConversationTrashRepository($this->pdo))->restore($this->conversationId);

        (new MysqlConversationGuestRepository($this->pdo))->remove($this->conversationId, $this->id('denis'));
        self::assertSame([], $this->due(), 'plus participant');
    }

    #[Test]
    public function testNoticesDisappearWithTheirConversation(): void
    {
        $this->notices->claimNotice($this->conversationId, $this->id('denis'), $this->id('alice'), $this->now, $this->notBefore());

        (new MysqlConversationTrashRepository($this->pdo))->delete($this->conversationId);

        self::assertNull($this->notices->find($this->conversationId, $this->id('denis')));
    }
}
