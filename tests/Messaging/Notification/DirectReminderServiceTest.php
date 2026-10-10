<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Notification;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Account\Repository\MysqlUserRepository;
use App\Messaging\Notification\MentionReminderService;
use App\Messaging\Repository\MysqlConversationRepository;
use App\Messaging\Repository\Notice\MysqlMentionNoticeRepository;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\RecordingMailer;
use App\Tests\Scenarios\TestMailbox;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

/** #372 : un message direct non lu reçoit UNE relance après 24 h, qui dit « vous a écrit », sans contenu ni lien de désabonnement général. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class DirectReminderServiceTest extends RepositoryTestCase
{
    private MysqlMentionNoticeRepository $notices;
    private int $directId;
    /** @var array<string, User> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->notices = new MysqlMentionNoticeRepository($this->pdo);
        $users = new MysqlUserRepository($this->pdo);
        foreach (['alice', 'bob'] as $name) {
            $this->people[$name] = $users->save(new User(0, "{$name}.perso@rehearsalbox.test", 'hash', ucfirst($name), UserRole::Musicien, true, 0, null));
        }
        $this->directId = (new MysqlConversationRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make()))
            ->openDirect($this->people['alice']->id(), $this->people['bob']->id(), new \DateTimeImmutable('2026-10-05 08:00:00'))->id();
        // Alice écrit à Bob le 5 à 10:00 (UTC) ; l'e-mail « vous a écrit » part à ce moment-là.
        $this->notices->claimNotice($this->directId, $this->people['bob']->id(), $this->people['alice']->id(), new \DateTimeImmutable('2026-10-05 10:00:00'), new \DateTimeImmutable('2026-10-04 10:00:00'));
    }

    private function service(RecordingMailer $mailer, string $now): MentionReminderService
    {
        return new MentionReminderService($this->notices, TestMailbox::of($mailer), new MockClock($now), new \DateTimeZone('Europe/Paris'));
    }

    #[Test]
    public function testTheRecipientGetsOneReminderThatSaysWroteToYouAndCarriesNoContent(): void
    {
        $mailer = new RecordingMailer();

        $report = $this->service($mailer, '2026-10-06 10:30:00')->sendDue();

        self::assertSame([1, 0, 0], [$report->sent(), $report->failed(), $report->skipped()]);
        $email = $mailer->sent[0];
        self::assertSame('bob.perso@rehearsalbox.test', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('Alice', (string) $email->getSubject());
        self::assertStringContainsString('vous a écrit', (string) $email->getSubject());
        self::assertStringNotContainsString('mentionn', (string) $email->getSubject());
        foreach ([(string) $email->getTextBody(), (string) $email->getHtmlBody()] as $body) {
            self::assertStringContainsString('/messages/' . $this->directId, $body);
            self::assertStringNotContainsString('mentionn', $body);
            self::assertStringNotContainsString('/account/password', $body, 'pas de désabonnement général pour un message direct');
            self::assertStringContainsString('sourdine', $body);
        }
    }

}
