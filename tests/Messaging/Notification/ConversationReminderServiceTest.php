<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Notification;

use App\Account\Entity\UserRole;
use App\Group\Entity\Group;
use App\Account\Entity\User;
use App\Messaging\Repository\Notice\MysqlConversationNoticeRepository;
use App\Messaging\Repository\MysqlConversationRepository;
use App\Messaging\Repository\MysqlConversationPresenceRepository;
use App\Messaging\Repository\MysqlConversationMessageRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Messaging\Notification\ConversationReminderService;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\FailingMailer;
use App\Tests\Doubles\RecordingMailer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\MailerInterface;
use App\Tests\Scenarios\TestMailbox;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class ConversationReminderServiceTest extends RepositoryTestCase
{
    private MysqlConversationRepository $conversations;
    private MysqlConversationMessageRepository $messages;
    private MysqlConversationPresenceRepository $presence;
    private MysqlConversationNoticeRepository $notices;
    private Group $alpha;
    private Group $beta;
    private User $alice;
    private int $conversationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conversations = new MysqlConversationRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make());
        $this->messages = new MysqlConversationMessageRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make());
        $this->presence = new MysqlConversationPresenceRepository($this->pdo);
        $this->notices = new MysqlConversationNoticeRepository($this->pdo);
        $users = new MysqlUserRepository($this->pdo);
        $groups = new MysqlGroupRepository($this->pdo);
        $this->alice = $users->save(new User(0, 'alice@rehearsalbox.test', 'hash', 'Alice', UserRole::Musicien, true, 0, null));
        $bob = $users->save(new User(0, 'bob@rehearsalbox.test', 'hash', 'Bob', UserRole::Musicien, true, 0, null));
        $this->alpha = $groups->save(new Group(0, 'Alpha', null, null, 'contact-alpha@rehearsalbox.test'));
        $this->beta = $groups->save(new Group(0, 'Beta', null, null, 'contact-beta@rehearsalbox.test'));
        $groups->addMember($this->alpha->id(), $this->alice->id());
        $groups->addMember($this->beta->id(), $bob->id());
        $this->conversationId = $this->conversations->create($this->alpha->id(), $this->beta->id(), 'Titre secret', new \DateTimeImmutable('2026-10-05 08:00:00'))->id();
        $this->messages->addMessage($this->conversationId, $this->alice->id(), 'Texte secret', new \DateTimeImmutable('2026-10-05 08:00:00'));
    }

    private function service(MailerInterface $mailer, string $now): ConversationReminderService
    {
        return new ConversationReminderService(
            $this->notices,
            TestMailbox::of($mailer),
            new MockClock($now),
            new \DateTimeZone('Europe/Paris'),
        );
    }

    #[Test]
    public function testAReminderGoesToTheContactOfTheGroupThatHasNotReadAfter24Hours(): void
    {
        $mailer = new RecordingMailer();

        $report = $this->service($mailer, '2026-10-06 10:30:00')->sendDue(); // 12 h 30 à Paris, 26 h 30 après le message

        self::assertSame(1, $report->sent());
        self::assertCount(1, $mailer->sent);
        self::assertSame(['contact-beta@rehearsalbox.test'], array_map(static fn ($a) => $a->getAddress(), $mailer->sent[0]->getTo()));
        $body = (string) $mailer->sent[0]->getTextBody();
        self::assertStringContainsString('Alpha', $body);
        self::assertStringContainsString('/messages/' . $this->conversationId, $body);
        self::assertStringContainsStringIgnoringCase('rappel', (string) $mailer->sent[0]->getSubject());
        self::assertStringNotContainsString('secret', $body . $mailer->sent[0]->getHtmlBody() . $mailer->sent[0]->getSubject(), 'aucun contenu du message');
    }

    #[Test]
    public function testNothingIsSentBefore24Hours(): void
    {
        $mailer = new RecordingMailer();

        $report = $this->service($mailer, '2026-10-06 05:00:00')->sendDue(); // 07 h Paris : 23 h après, et avant la plage

        self::assertSame(0, $report->sent());
        self::assertSame([], $mailer->sent);
    }

    #[Test]
    public function testRemindersOnlyLeaveInDaytimeHoursLocalTime(): void
    {
        $this->pdo->exec("UPDATE conversation_messages SET created_at = '2026-10-04 20:00:00'"); // due à toutes les heures testées
        $mailer = new RecordingMailer();

        $night = $this->service($mailer, '2026-10-06 02:00:00')->sendDue(); // 04 h Paris
        $evening = $this->service($mailer, '2026-10-06 18:00:00')->sendDue(); // 20 h Paris : fin de plage exclue
        self::assertTrue($night->outsideWindow());
        self::assertTrue($evening->outsideWindow());
        self::assertSame([], $mailer->sent, 'rien la nuit ni le soir');

        $morning = $this->service($mailer, '2026-10-06 07:00:00')->sendDue(); // 09 h Paris : début de plage inclus
        self::assertFalse($morning->outsideWindow());
        self::assertCount(1, $mailer->sent, 'les relances dues partent le matin');
    }

    #[Test]
    public function testTheSameMessageIsRemindedOnlyOnce(): void
    {
        $mailer = new RecordingMailer();

        $this->service($mailer, '2026-10-06 10:00:00')->sendDue();
        $this->service($mailer, '2026-10-06 12:00:00')->sendDue();
        $this->service($mailer, '2026-10-07 10:00:00')->sendDue();

        self::assertCount(1, $mailer->sent);
    }

    #[Test]
    public function testAMemberOfTheGroupHavingReadCancelsTheReminder(): void
    {
        $bob = (new MysqlUserRepository($this->pdo))->findByEmail('bob@rehearsalbox.test');
        $this->presence->markRead($this->conversationId, $bob->id(), new \DateTimeImmutable('2026-10-05 20:00:00'));
        $mailer = new RecordingMailer();

        $report = $this->service($mailer, '2026-10-06 10:30:00')->sendDue();

        self::assertSame(0, $report->sent());
        self::assertSame([], $mailer->sent);
    }

    #[Test]
    public function testAFailedSendIsReportedWithoutThrowingAndStaysRetryable(): void
    {
        $failed = $this->service(new FailingMailer(), '2026-10-06 10:30:00')->sendDue();

        self::assertSame(1, $failed->failed());
        self::assertSame(0, $failed->sent());
        self::assertNull($this->notices->remindedAt($this->conversationId, $this->beta->id()), 'rien de réservé : nouvel essai possible');

        $mailer = new RecordingMailer();
        $retry = $this->service($mailer, '2026-10-06 12:00:00')->sendDue();
        self::assertSame(1, $retry->sent());
    }

    #[Test]
    public function testAnInvalidContactAddressIsSkippedAndCounted(): void
    {
        $this->pdo->exec("UPDATE `groups` SET contact_email = 'pas-une-adresse' WHERE id = " . $this->beta->id());
        $mailer = new RecordingMailer();

        $report = $this->service($mailer, '2026-10-06 10:30:00')->sendDue();

        self::assertSame(1, $report->skipped());
        self::assertSame([], $mailer->sent);
    }

    #[Test]
    public function testTheReplyDirectionIsRemindedToo(): void
    {
        $bob = (new MysqlUserRepository($this->pdo))->findByEmail('bob@rehearsalbox.test');
        $this->messages->addMessage($this->conversationId, $bob->id(), 'Réponse', new \DateTimeImmutable('2026-10-05 09:00:00'));
        $this->presence->markRead($this->conversationId, $bob->id(), new \DateTimeImmutable('2026-10-05 09:00:00'));
        $mailer = new RecordingMailer();

        $this->service($mailer, '2026-10-06 10:30:00')->sendDue();

        self::assertSame(['contact-alpha@rehearsalbox.test'], array_map(static fn ($a) => $a->getAddress(), $mailer->sent[0]->getTo()), 'Alpha est relancé pour la réponse de Beta');
        self::assertCount(1, $mailer->sent, 'Beta a lu (sa réponse) : seul Alpha est relancé');
    }
}
