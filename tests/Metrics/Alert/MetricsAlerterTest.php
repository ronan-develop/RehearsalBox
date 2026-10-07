<?php

declare(strict_types=1);

namespace App\Tests\Metrics\Alert;

use App\Metrics\Alert\AlertEvaluator;
use App\Metrics\Alert\AlertRepositoryInterface;
use App\Metrics\Alert\AlertType;
use App\Metrics\Alert\MetricsAlerter;
use App\Metrics\HealthSnapshot;
use App\Metrics\Report\Load\DegradationDetector;
use App\Metrics\Report\Security\AnomalyDetector;
use App\Metrics\Report\Thresholds;
use App\Mail\Mailbox;
use App\Tests\Doubles\FailingMailer;
use App\Tests\Doubles\FakeMetricsReader;
use App\Tests\Doubles\RecordingLogger;
use App\Tests\Doubles\RecordingMailer;
use App\Tests\Scenarios\TestMailbox;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\MailerInterface;

final class MetricsAlerterTest extends TestCase
{
    private FakeMetricsReader $reader;
    private MockClock $clock;
    private RecordingMailer $mailer;

    /** @var array<string, \DateTimeImmutable> */
    private array $sentAt = [];

    protected function setUp(): void
    {
        $this->reader = new FakeMetricsReader();
        $this->clock = new MockClock('2026-10-07 12:30:00 UTC');
        $this->mailer = new RecordingMailer();
        // Sauvegarde vieille de 60 h et disque presque plein : deux alertes rouges.
        $this->reader->snapshot = new HealthSnapshot(new \DateTimeImmutable('2026-10-07 12:00:00 UTC'), 100 * 1024 * 1024, 1, 60, null, null, '8.4', null);
    }

    private function alerter(?MailerInterface $mailer = null, string $recipient = 'owner@rehearsalbox.test', ?RecordingLogger $logger = null): MetricsAlerter
    {
        $repository = new class ($this->sentAt) implements AlertRepositoryInterface {
            /** @param array<string, \DateTimeImmutable> $sentAt */
            public function __construct(private array &$sentAt)
            {
            }

            public function lastSentAt(AlertType $type): ?\DateTimeImmutable
            {
                return $this->sentAt[$type->value] ?? null;
            }

            public function markSent(AlertType $type, \DateTimeImmutable $at): void
            {
                $this->sentAt[$type->value] = $at;
            }
        };
        $mailbox = $logger === null ? TestMailbox::of($mailer ?? $this->mailer) : new Mailbox($mailer ?? $this->mailer, TestMailbox::FROM, TestMailbox::BASE_URL, logger: $logger);

        return new MetricsAlerter(
            new AlertEvaluator($this->reader, new Thresholds(), new DegradationDetector(), new AnomalyDetector(), $this->clock),
            $repository,
            $mailbox,
            $this->clock,
            $recipient,
            12,
        );
    }

    #[Test]
    public function testSeveralAlertsMakeASingleSummaryMailToTheOwner(): void
    {
        $sent = $this->alerter()->run();

        self::assertSame(2, $sent);
        self::assertCount(1, $this->mailer->sent, 'un résumé, jamais une rafale');
        $mail = $this->mailer->sent[0];
        self::assertSame('owner@rehearsalbox.test', $mail->getTo()[0]->getAddress());
        self::assertStringContainsString('Alertes de mesures (2)', (string) $mail->getSubject());
        self::assertStringContainsString('sauvegarde', (string) $mail->getTextBody());
        self::assertStringContainsString('disque', (string) $mail->getTextBody());
        self::assertStringContainsString('https://rehearsalbox.example/admin/metrics', (string) $mail->getTextBody());
        self::assertStringContainsString('aucune donnée personnelle', (string) $mail->getTextBody());
    }

    #[Test]
    public function testTheSameAlertIsNotRepeatedBeforeTheMinimumGap(): void
    {
        $this->alerter()->run();
        $this->clock->sleep(3600 * 11);
        self::assertSame(0, $this->alerter()->run(), '11 h après : trop tôt');
        self::assertCount(1, $this->mailer->sent);

        $this->clock->sleep(3600 * 2);
        self::assertSame(2, $this->alerter()->run(), '13 h après : de nouveau due');
        self::assertCount(2, $this->mailer->sent);
    }

    #[Test]
    public function testOnlyTheAlertsThatAreDueAreSentAgain(): void
    {
        $this->alerter()->run();
        $this->clock->sleep(3600 * 13);
        $this->reader->snapshot = new HealthSnapshot(new \DateTimeImmutable('2026-10-08 01:00:00 UTC'), 5000 * 1024 * 1024, 1, 60, null, null, '8.4', null);

        self::assertSame(1, $this->alerter()->run(), 'le disque est revenu à la normale, seule la sauvegarde reste');
        self::assertStringContainsString('Alerte de mesures', (string) $this->mailer->sent[1]->getSubject());
        self::assertStringNotContainsString('(', (string) $this->mailer->sent[1]->getSubject());
    }

    #[Test]
    public function testNothingIsSentWithoutARecipientOrWithoutAlert(): void
    {
        self::assertSame(0, $this->alerter(recipient: '')->run());
        $this->reader->snapshot = null;
        self::assertSame(0, $this->alerter()->run());
        self::assertSame([], $this->mailer->sent);
    }

    #[Test]
    public function testAMailFailureNeverBreaksAndLeavesTheAlertsDueForTheNextRun(): void
    {
        $logger = new RecordingLogger();

        self::assertSame(0, $this->alerter(new FailingMailer(), logger: $logger)->run());
        self::assertSame([], $this->sentAt, 'rien n\'est marqué envoyé');
        self::assertStringContainsString('envoi impossible', $logger->text());
        self::assertStringNotContainsString('owner@', $logger->text());

        self::assertSame(2, $this->alerter()->run(), 'la collecte suivante réessaie');
    }
}
