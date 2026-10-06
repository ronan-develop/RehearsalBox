<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\MysqlConversationGuestRepository;
use App\Repository\MysqlConversationMentionRepository;
use App\Repository\MysqlConversationRepository;
use App\Repository\MysqlConversationPresenceRepository;
use App\Repository\MysqlConversationMessageRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlMentionNoticeRepository;
use App\Repository\MysqlNotificationPreferenceRepository;
use App\Repository\MysqlUserRepository;
use App\Service\MentionReminderService;
use App\Tests\RepositoryTestCase;
use App\Tests\Support\FailingMailer;
use App\Tests\Support\RecordingMailer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\MailerInterface;

/** #178 : une seule relance, 24 h après l'e-mail de mention, si la mention n'est toujours pas lue (plage de jour, cron horaire). */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class MentionReminderServiceTest extends RepositoryTestCase
{
    private MysqlMentionNoticeRepository $notices;
    private MysqlConversationRepository $conversations;
    private MysqlConversationMessageRepository $messages;
    private MysqlConversationPresenceRepository $presence;
    private MysqlUserRepository $users;
    /** @var array<string, User> */
    private array $people = [];
    private int $conversationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notices = new MysqlMentionNoticeRepository($this->pdo);
        $this->conversations = new MysqlConversationRepository($this->pdo);
        $this->messages = new MysqlConversationMessageRepository($this->pdo);
        $this->presence = new MysqlConversationPresenceRepository($this->pdo);
        $this->users = new MysqlUserRepository($this->pdo);
        $groups = new MysqlGroupRepository($this->pdo);
        foreach (['alice', 'bob', 'denis'] as $name) {
            $this->people[$name] = $this->users->save(new User(0, "{$name}.perso@rehearsalbox.test", 'hash', ucfirst($name), UserRole::Musicien, true, 0, null));
        }
        $alpha = $groups->save(new Group(0, 'Alpha', null, null, 'contact-alpha@rehearsalbox.test'))->id();
        $beta = $groups->save(new Group(0, 'Beta', null, null, 'contact-beta@rehearsalbox.test'))->id();
        $groups->addMember($alpha, $this->id('alice'));
        $groups->addMember($beta, $this->id('bob'));
        $this->conversationId = $this->conversations->create($alpha, $beta, 'Titre secret', new \DateTimeImmutable('2026-10-05 08:00:00'), $this->id('alice'))->id();
        (new MysqlConversationGuestRepository($this->pdo))->add($this->conversationId, $this->id('denis'), $this->id('alice'), new \DateTimeImmutable('2026-10-05 08:00:00'));
        // Alice mentionne Denis le 5 à 10:00 (UTC) ; l'e-mail de mention part à ce moment-là.
        $message = $this->messages->addMessage($this->conversationId, $this->id('alice'), 'Texte secret @Denis', new \DateTimeImmutable('2026-10-05 10:00:00'));
        (new MysqlConversationMentionRepository($this->pdo))->record($message->id(), [$this->id('denis') => '@Denis']);
        $this->notices->claimNotice($this->conversationId, $this->id('denis'), $this->id('alice'), new \DateTimeImmutable('2026-10-05 10:00:00'), new \DateTimeImmutable('2026-10-04 10:00:00'));
    }

    private function id(string $name): int
    {
        return $this->people[$name]->id();
    }

    private function service(MailerInterface $mailer, string $now): MentionReminderService
    {
        return new MentionReminderService(
            $this->notices,
            $mailer,
            new MockClock($now),
            new \DateTimeZone('Europe/Paris'),
            'no-reply@rehearsalbox.example',
            'https://rehearsalbox.example',
        );
    }

    #[Test]
    public function testTheTaggedPersonGetsOneReminderWithoutAnyContent(): void
    {
        $mailer = new RecordingMailer();

        $report = $this->service($mailer, '2026-10-06 10:30:00')->sendDue();

        self::assertSame([1, 0, 0], [$report->sent(), $report->failed(), $report->skipped()]);
        $email = $mailer->sent[0];
        self::assertSame('denis.perso@rehearsalbox.test', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('Alice', (string) $email->getSubject());
        $text = (string) $email->getTextBody() . (string) $email->getHtmlBody() . (string) $email->getSubject();
        self::assertStringContainsString('/messages/' . $this->conversationId, $text);
        self::assertStringContainsString('/account/password', $text);
        self::assertStringNotContainsString('Titre secret', $text);
        self::assertStringNotContainsString('Texte secret', $text);
    }

    #[Test]
    public function testOnlyOneReminderIsEverSentForTheSameEmail(): void
    {
        $mailer = new RecordingMailer();
        $this->service($mailer, '2026-10-06 10:30:00')->sendDue();

        $again = $this->service($mailer, '2026-10-06 11:30:00')->sendDue();

        self::assertSame(0, $again->sent());
        self::assertCount(1, $mailer->sent);
    }

    #[Test]
    public function testNothingLeavesAtNightAndTheReminderGoesOutTheNextMorning(): void
    {
        $mailer = new RecordingMailer();

        $night = $this->service($mailer, '2026-10-06 21:30:00')->sendDue(); // 23:30 à Paris
        self::assertTrue($night->outsideWindow());
        self::assertSame([], $mailer->sent);

        $morning = $this->service($mailer, '2026-10-07 07:30:00')->sendDue(); // 09:30 à Paris
        self::assertSame(1, $morning->sent());
    }

    #[Test]
    public function testNothingBeforeTwentyFourHoursNorAfterSevenDays(): void
    {
        $mailer = new RecordingMailer();

        self::assertSame(0, $this->service($mailer, '2026-10-06 09:30:00')->sendDue()->sent(), '23 h 30 : trop tôt');
        self::assertSame(0, $this->service($mailer, '2026-10-13 10:30:00')->sendDue()->sent(), 'plus de 7 jours : trop tard');
    }

    #[Test]
    public function testReadingTheConversationOrUnsubscribingCancelsTheReminder(): void
    {
        $this->presence->markRead($this->conversationId, $this->id('denis'), new \DateTimeImmutable('2026-10-05 20:00:00'));
        $mailer = new RecordingMailer();
        self::assertSame(0, $this->service($mailer, '2026-10-06 10:30:00')->sendDue()->sent(), 'lu : pas de relance');

        $this->pdo->exec('DELETE FROM conversation_states');
        (new MysqlNotificationPreferenceRepository($this->pdo))->setEmailEnabled($this->id('denis'), false);
        self::assertSame(0, $this->service($mailer, '2026-10-06 10:30:00')->sendDue()->sent(), 'désinscrit : pas de relance');
    }

    #[Test]
    public function testAFailureIsCountedAndTheReminderStaysRetryable(): void
    {
        $failed = $this->service(new FailingMailer(), '2026-10-06 10:30:00')->sendDue();
        self::assertSame([0, 1], [$failed->sent(), $failed->failed()]);

        $mailer = new RecordingMailer();
        self::assertSame(1, $this->service($mailer, '2026-10-06 11:30:00')->sendDue()->sent(), 'réessayée à l\'exécution suivante');
    }

    #[Test]
    public function testAnInvalidAccountAddressIsSkippedWithoutReservingTheReminder(): void
    {
        $this->pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute(['pas-une-adresse', $this->id('denis')]);
        $mailer = new RecordingMailer();

        $report = $this->service($mailer, '2026-10-06 10:30:00')->sendDue();

        self::assertSame([0, 0, 1], [$report->sent(), $report->failed(), $report->skipped()]);
        self::assertSame([], $mailer->sent);
    }
}
