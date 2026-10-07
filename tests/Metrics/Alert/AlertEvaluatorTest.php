<?php

declare(strict_types=1);

namespace App\Tests\Metrics\Alert;

use App\Metrics\Alert\AlertEvaluator;
use App\Metrics\Alert\AlertType;
use App\Metrics\HealthSnapshot;
use App\Metrics\MetricEventType;
use App\Metrics\Report\Load\DegradationDetector;
use App\Metrics\Report\Security\AnomalyDetector;
use App\Metrics\Report\Security\SecurityEvent;
use App\Metrics\Report\Thresholds;
use App\Tests\Doubles\FakeMetricsReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class AlertEvaluatorTest extends TestCase
{
    private FakeMetricsReader $reader;

    protected function setUp(): void
    {
        $this->reader = new FakeMetricsReader();
    }

    /** @return list<AlertType> */
    private function types(): array
    {
        $evaluator = new AlertEvaluator($this->reader, new Thresholds(), new DegradationDetector(), new AnomalyDetector(), new MockClock('2026-10-07 12:30:00 UTC'));

        return array_map(static fn ($a): AlertType => $a->type, $evaluator->evaluate());
    }

    private function snapshot(?int $backupHours = 5, ?int $diskMb = 5000, ?string $cronAt = '2026-10-07 12:00:00'): HealthSnapshot
    {
        return new HealthSnapshot(
            new \DateTimeImmutable('2026-10-07 12:00:00 UTC'),
            $diskMb === null ? null : $diskMb * 1024 * 1024,
            1000,
            $backupHours,
            $cronAt === null ? null : new \DateTimeImmutable($cronAt, new \DateTimeZone('UTC')),
            null,
            '8.4',
            null,
        );
    }

    #[Test]
    public function testAHealthySiteRaisesNothing(): void
    {
        $this->reader->snapshot = $this->snapshot();

        self::assertSame([], $this->types());
    }

    #[Test]
    public function testAnEmptyDatabaseRaisesNothing(): void
    {
        self::assertSame([], $this->types());
    }

    #[Test]
    public function testASilentCronAnOldBackupAndALowDiskEachRaiseTheirAlert(): void
    {
        $this->reader->snapshot = $this->snapshot(backupHours: 60, diskMb: 100, cronAt: '2026-10-07 07:00:00');

        self::assertSame([AlertType::CronSilent, AlertType::BackupOld, AlertType::DiskLow], $this->types());
    }

    #[Test]
    public function testOrangeIsNotAnAlertOnlyRedIs(): void
    {
        $this->reader->snapshot = $this->snapshot(backupHours: 40, diskMb: 500, cronAt: '2026-10-07 10:40:00');

        self::assertSame([], $this->types(), 'orange sur la page, mais personne n\'est réveillé');
    }

    #[Test]
    public function testAMissingMeasureIsNeverAnAlert(): void
    {
        $this->reader->snapshot = $this->snapshot(backupHours: null, diskMb: null, cronAt: null);

        self::assertSame([], $this->types());
    }

    #[Test]
    public function testASpikeOfServerErrorsOnTheLastHourIsAnAlert(): void
    {
        $this->reader->load = ['2026-10-07 12:00:00' => ['requests' => 100, 'errors' => 12, 'durationMs' => 3000, 'memoryKb' => 1, 'buckets' => [100, 0, 0, 0, 0, 0]]];

        self::assertContains(AlertType::ServerErrors, $this->types());
    }

    #[Test]
    public function testABurstOrRepeatedLoginFailuresIsAnAlertButAScannerAloneIsNot(): void
    {
        $at = new \DateTimeImmutable('2026-10-07 12:10:00', new \DateTimeZone('UTC'));
        $this->reader->securityEvents = array_map(static fn (int $i): SecurityEvent => new SecurityEvent(MetricEventType::NotFound, '/.env', 'aaaaaaaaaaaaaaaa', $at->modify('+' . $i . ' seconds')), range(0, 4));
        self::assertSame([], $this->types(), 'un scanner est visible sur la page, pas réveillant');

        $this->reader->securityEvents = array_map(static fn (int $i): SecurityEvent => new SecurityEvent(MetricEventType::LoginFailed, '/api/auth/login', 'aaaaaaaaaaaaaaaa', $at->modify('+' . $i * 30 . ' seconds')), range(0, 11));
        self::assertSame([AlertType::Attack], $this->types());
    }

    #[Test]
    public function testTheMessagesCarryNeitherAnAddressNorAFingerprint(): void
    {
        $at = new \DateTimeImmutable('2026-10-07 12:10:00', new \DateTimeZone('UTC'));
        $this->reader->securityEvents = array_map(static fn (int $i): SecurityEvent => new SecurityEvent(MetricEventType::LoginFailed, '/api/auth/login', 'cafebabecafebabe', $at->modify('+' . $i * 30 . ' seconds')), range(0, 11));
        $this->reader->snapshot = $this->snapshot(backupHours: 60);

        $evaluator = new AlertEvaluator($this->reader, new Thresholds(), new DegradationDetector(), new AnomalyDetector(), new MockClock('2026-10-07 12:30:00 UTC'));
        $text = implode(' ', array_map(static fn ($a): string => $a->message, $evaluator->evaluate()));

        self::assertStringNotContainsString('cafebabe', $text);
        self::assertStringNotContainsString('@', $text);
    }
}
