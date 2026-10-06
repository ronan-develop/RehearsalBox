<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\MysqlConversationNoticeRepository;
use App\Repository\MysqlConversationRepository;
use App\Repository\MysqlConversationPresenceRepository;
use App\Repository\MysqlConversationMessageRepository;
use App\Repository\MysqlConversationTrashRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class MysqlConversationNoticeRepositoryTest extends RepositoryTestCase
{
    private \DateTimeImmutable $now;
    private MysqlConversationNoticeRepository $notices;
    private int $conversationId;
    private int $groupA;
    private int $groupB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-10-06 12:00:00');
        $this->notices = new MysqlConversationNoticeRepository($this->pdo);
        $groups = new MysqlGroupRepository($this->pdo);
        $this->groupA = $groups->save(new Group(0, 'Alpha', null, null, 'alpha@rehearsalbox.test'))->id();
        $this->groupB = $groups->save(new Group(0, 'Beta', null, null, 'beta@rehearsalbox.test'))->id();
        $this->conversationId = (new MysqlConversationRepository($this->pdo))->create($this->groupA, $this->groupB, null, $this->now)->id();
    }

    #[Test]
    public function testTheFirstClaimWinsAndTheSecondOneIsRefused(): void
    {
        self::assertTrue($this->notices->claimInitial($this->conversationId, $this->groupB, $this->now));
        self::assertFalse($this->notices->claimInitial($this->conversationId, $this->groupB, $this->now->modify('+1 minute')), 'déjà prévenu : un seul e-mail');
        self::assertEquals($this->now, $this->notices->initialNotifiedAt($this->conversationId, $this->groupB), 'la date du premier envoi est conservée');
    }

    #[Test]
    public function testCountingInitialNoticesOfAGroupSinceADate(): void
    {
        $this->notices->claimInitial($this->conversationId, $this->groupB, $this->now);

        self::assertSame(1, $this->notices->countInitialSince($this->groupB, $this->now->modify('-1 hour')));
        self::assertSame(0, $this->notices->countInitialSince($this->groupB, $this->now->modify('+1 minute')), 'trop ancien');
        self::assertSame(0, $this->notices->countInitialSince($this->groupA, $this->now->modify('-1 hour')), 'un autre groupe');
    }

    #[Test]
    public function testEachGroupHasItsOwnClaim(): void
    {
        self::assertTrue($this->notices->claimInitial($this->conversationId, $this->groupA, $this->now));
        self::assertTrue($this->notices->claimInitial($this->conversationId, $this->groupB, $this->now));
    }

    #[Test]
    public function testReleasingAClaimAllowsANewAttempt(): void
    {
        $this->notices->claimInitial($this->conversationId, $this->groupB, $this->now);

        $this->notices->releaseInitial($this->conversationId, $this->groupB);

        self::assertNull($this->notices->initialNotifiedAt($this->conversationId, $this->groupB));
        self::assertTrue($this->notices->claimInitial($this->conversationId, $this->groupB, $this->now->modify('+5 minutes')));
    }

    #[Test]
    public function testNoticesDisappearWithTheirConversation(): void
    {
        $this->notices->claimInitial($this->conversationId, $this->groupB, $this->now);

        $this->pdo->exec('DELETE FROM conversations WHERE id = ' . $this->conversationId);

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM conversation_group_notices')->fetchColumn());
    }

    // --- Relances après 24 h sans lecture du groupe ----------------------------------------------------------

    /** @return array{User, User, User} alice (Alpha), bob et carol (Beta) */
    private function people(): array
    {
        $users = new MysqlUserRepository($this->pdo);
        $groups = new MysqlGroupRepository($this->pdo);
        $make = fn (string $n): User => $users->save(new User(0, "{$n}@rehearsalbox.test", 'hash', ucfirst($n), UserRole::Musicien, true, 0, null));
        [$alice, $bob, $carol] = [$make('alice'), $make('bob'), $make('carol')];
        $groups->addMember($this->groupA, $alice->id());
        $groups->addMember($this->groupB, $bob->id());
        $groups->addMember($this->groupB, $carol->id());

        return [$alice, $bob, $carol];
    }

    private function say(int $authorId, string $at, bool $system = false): int
    {
        return (new MysqlConversationMessageRepository($this->pdo))->addMessage($this->conversationId, $authorId, 'texte', new \DateTimeImmutable($at), $system)->id();
    }

    /** @return list<array{int, string}> [groupId, nom du groupe d'en face] */
    private function due(string $now = '2026-10-06 12:00:00'): array
    {
        $now = new \DateTimeImmutable($now);

        return array_map(
            static fn ($d): array => [$d->groupId(), $d->counterpartName()],
            $this->notices->findDueReminders($now->modify('-24 hours'), $now->modify('-7 days')),
        );
    }

    #[Test]
    public function testAMessageUnreadByTheOtherGroupForMoreThan24HoursIsDueForThatGroupOnly(): void
    {
        [$alice] = $this->people();
        $this->say($alice->id(), '2026-10-05 10:00:00');

        self::assertSame([[$this->groupB, 'Alpha']], $this->due(), 'Beta est relancé ; Alpha est l\'auteur du message');
    }

    #[Test]
    public function testATrashedConversationIsNeverReminded(): void
    {
        [$alice] = $this->people();
        $this->say($alice->id(), '2026-10-05 10:00:00');
        self::assertCount(1, $this->due());

        (new MysqlConversationTrashRepository($this->pdo))->moveToTrash($this->conversationId, $this->now);

        self::assertSame([], $this->due());
    }

    #[Test]
    public function testNothingIsDueBefore24HoursNorAfterSevenDays(): void
    {
        [$alice] = $this->people();
        $this->say($alice->id(), '2026-10-05 13:00:00');

        self::assertSame([], $this->due(), '23 h : trop tôt');
        self::assertSame([], $this->due('2026-10-14 12:00:00'), 'plus de 7 jours : trop tard (évite une relance tardive après une panne)');
    }

    #[Test]
    public function testOneMemberOfTheGroupReadingIsEnoughToCancelTheReminder(): void
    {
        [$alice, , $carol] = $this->people();
        $this->say($alice->id(), '2026-10-05 10:00:00');
        self::assertCount(1, $this->due());

        (new MysqlConversationPresenceRepository($this->pdo))->markRead($this->conversationId, $carol->id(), new \DateTimeImmutable('2026-10-05 15:00:00'));

        self::assertSame([], $this->due(), 'Carole a lu : le groupe a lu');
    }

    #[Test]
    public function testReadingBeforeTheMessageDoesNotCount(): void
    {
        [$alice, $bob] = $this->people();
        (new MysqlConversationPresenceRepository($this->pdo))->markRead($this->conversationId, $bob->id(), new \DateTimeImmutable('2026-10-05 09:00:00'));
        $this->say($alice->id(), '2026-10-05 10:00:00');

        self::assertCount(1, $this->due());
    }

    #[Test]
    public function testAReplyMarksTheRepliersGroupAsHavingRead(): void
    {
        [$alice, $bob] = $this->people();
        $this->say($alice->id(), '2026-10-05 08:00:00');
        $this->say($bob->id(), '2026-10-05 09:00:00');
        (new MysqlConversationPresenceRepository($this->pdo))->markRead($this->conversationId, $bob->id(), new \DateTimeImmutable('2026-10-05 09:00:00'));

        self::assertSame([[$this->groupA, 'Beta']], $this->due(), 'seul Alpha attend désormais la lecture de la réponse de Bob');
    }

    #[Test]
    public function testSystemMessagesAndMessagesOfAPersonInBothGroupsNeverTriggerAReminder(): void
    {
        [$alice, $bob] = $this->people();
        $this->say($alice->id(), '2026-10-05 08:00:00', true);
        (new MysqlGroupRepository($this->pdo))->addMember($this->groupA, $bob->id());
        $this->say($bob->id(), '2026-10-05 09:00:00');

        self::assertSame([], $this->due());
    }

    #[Test]
    public function testAMessageFromSomeoneWhoLeftTheirGroupTriggersNothing(): void
    {
        [$alice] = $this->people();
        $this->say($alice->id(), '2026-10-05 10:00:00');
        (new MysqlGroupRepository($this->pdo))->removeMember($this->groupA, $alice->id());

        self::assertSame([], $this->due());
    }

    #[Test]
    public function testClaimingAReminderSilencesItUntilANewerMessageAges24Hours(): void
    {
        [$alice] = $this->people();
        $this->say($alice->id(), '2026-10-05 10:00:00');
        $now = new \DateTimeImmutable('2026-10-06 12:00:00');

        self::assertTrue($this->notices->claimReminder($this->conversationId, $this->groupB, $now));
        self::assertSame([], $this->due(), 'déjà relancé pour ce message');

        $this->say($alice->id(), '2026-10-06 13:00:00');
        self::assertSame([], $this->due('2026-10-07 12:00:00'), 'le nouveau message n\'a que 23 h');
        self::assertCount(1, $this->due('2026-10-07 14:00:00'), 'plus de 24 h et plus récent que le dernier rappel');
    }

    #[Test]
    public function testTwoOverlappingRunsCannotBothClaim(): void
    {
        $now = new \DateTimeImmutable('2026-10-06 12:00:00');

        self::assertTrue($this->notices->claimReminder($this->conversationId, $this->groupB, $now));
        self::assertFalse($this->notices->claimReminder($this->conversationId, $this->groupB, $now->modify('+5 minutes')));
        self::assertTrue($this->notices->claimReminder($this->conversationId, $this->groupB, $now->modify('+2 hours')), 'une heure plus tard : possible');
    }

    #[Test]
    public function testRestoringAReminderPutsBackThePreviousDate(): void
    {
        $now = new \DateTimeImmutable('2026-10-06 12:00:00');
        $this->notices->claimReminder($this->conversationId, $this->groupB, $now);
        $this->notices->restoreReminder($this->conversationId, $this->groupB, null);
        self::assertNull($this->notices->remindedAt($this->conversationId, $this->groupB));

        $this->notices->claimReminder($this->conversationId, $this->groupB, $now);
        $this->notices->claimReminder($this->conversationId, $this->groupB, $now->modify('+3 hours'));
        $this->notices->restoreReminder($this->conversationId, $this->groupB, $now);
        self::assertEquals($now, $this->notices->remindedAt($this->conversationId, $this->groupB));
    }

    #[Test]
    public function testTheReminderCarriesTheContactAddressAndTheOldestUnreadDate(): void
    {
        [$alice] = $this->people();
        $this->say($alice->id(), '2026-10-05 10:00:00');
        $this->say($alice->id(), '2026-10-05 11:00:00');
        $now = new \DateTimeImmutable('2026-10-06 12:00:00');

        $due = $this->notices->findDueReminders($now->modify('-24 hours'), $now->modify('-7 days'));

        self::assertCount(1, $due, 'une seule relance par conversation et par groupe');
        self::assertSame('beta@rehearsalbox.test', $due[0]->contactEmail());
        self::assertSame('Beta', $due[0]->groupName());
        self::assertEquals(new \DateTimeImmutable('2026-10-05 10:00:00'), $due[0]->since());
    }
}
