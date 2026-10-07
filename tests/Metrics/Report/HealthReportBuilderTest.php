<?php

declare(strict_types=1);

namespace App\Tests\Metrics\Report;

use App\Metrics\HealthSnapshot;
use App\Metrics\MetricEventType;
use App\Metrics\Report\HealthReportBuilder;
use App\Metrics\Report\HealthStatus;
use App\Metrics\Report\MetricsPeriod;
use App\Metrics\Report\Thresholds;
use App\Tests\Doubles\FakeMetricsReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class HealthReportBuilderTest extends TestCase
{
    private FakeMetricsReader $reader;

    protected function setUp(): void
    {
        $this->reader = new FakeMetricsReader();
    }

    private function builder(): HealthReportBuilder
    {
        // 07/10/2026 12:30 UTC = 14:30 à Paris (heure d'été).
        return new HealthReportBuilder($this->reader, new Thresholds(), new MockClock('2026-10-07 12:30:00 UTC'), new \DateTimeZone('Europe/Paris'));
    }

    /** @return array<string, \App\Metrics\Report\StatusCard> */
    private function cards(MetricsPeriod $period = MetricsPeriod::Day): array
    {
        $cards = [];
        foreach ($this->builder()->build($period)->cards as $card) {
            $cards[$card->label] = $card;
        }

        return $cards;
    }

    #[Test]
    public function testAQuietSiteHasNoDataAndNothingIsGreenByDefault(): void
    {
        $cards = $this->cards();

        self::assertSame('—', $cards['Disponibilité']->value);
        self::assertSame(HealthStatus::Unknown, $cards['Disponibilité']->status);
        self::assertSame(HealthStatus::Unknown, $cards['Cron des relances']->status);
        self::assertSame(HealthStatus::Unknown, $cards['Dernière sauvegarde']->status);
    }

    #[Test]
    public function testAvailabilityErrorsAndResponseTimeComeFromTheHourlyCounters(): void
    {
        $this->reader->requests = [
            '2026-10-07 10:00:00' => ['requests' => 900, 'errors' => 0, 'durationMs' => 18000],
            '2026-10-07 11:00:00' => ['requests' => 100, 'errors' => 20, 'durationMs' => 12000],
        ];

        $cards = $this->cards();

        self::assertSame('98,00 %', $cards['Disponibilité']->value);
        self::assertSame(HealthStatus::Warning, $cards['Disponibilité']->status);
        self::assertSame('20', $cards['Erreurs serveur (5xx)']->value);
        self::assertSame(HealthStatus::Critical, $cards['Erreurs serveur (5xx)']->status);
        self::assertSame('30 ms', $cards['Temps de réponse moyen']->value);
    }

    #[Test]
    public function testMailsAreCountedByTypeAndFailuresTurnTheCardRed(): void
    {
        $this->reader->events = [
            '2026-10-07 10:00:00' => [MetricEventType::MailSent->value => 4, MetricEventType::MailFailed->value => 5],
        ];

        $card = $this->cards()['E-mails en échec'];

        self::assertSame('5', $card->value);
        self::assertSame(HealthStatus::Critical, $card->status);
        self::assertStringContainsString('4 envoyé', $card->hint);
    }

    #[Test]
    public function testTheSnapshotFeedsCronBackupDiskDatabaseAndVersion(): void
    {
        $this->reader->snapshot = new HealthSnapshot(
            new \DateTimeImmutable('2026-10-07 12:00:00 UTC'),
            diskFreeBytes: 200 * 1024 * 1024,
            dbSizeBytes: 3 * 1024 * 1024,
            backupAgeHours: 60,
            lastCronAt: new \DateTimeImmutable('2026-10-07 12:00:00 UTC'),
            releaseMarker: '20261007-abc',
            phpVersion: '8.4.1',
            load1m: null,
        );

        $cards = $this->cards();

        self::assertSame('il y a 30 min', $cards['Cron des relances']->value);
        self::assertSame(HealthStatus::Ok, $cards['Cron des relances']->status);
        self::assertSame(HealthStatus::Critical, $cards['Dernière sauvegarde']->status);
        self::assertSame(HealthStatus::Critical, $cards['Espace disque libre']->status, '200 Mo libres : sous le seuil rouge');
        self::assertSame('3,0 Mo', $cards['Taille de la base']->value);
        self::assertNull($cards['Taille de la base']->status, 'une simple information n\'a pas d\'état');
        self::assertSame('20261007-abc', $cards['Version servie']->value);
        self::assertStringContainsString('relevé du 07/10 à 14:00', $cards['Cron des relances']->hint . $cards['Dernière sauvegarde']->hint);
    }

    #[Test]
    public function testTwentyFourHoursMakeTwentyFourLocalHourPointsEndingNow(): void
    {
        $this->reader->requests = ['2026-10-07 12:00:00' => ['requests' => 7, 'errors' => 0, 'durationMs' => 70]];

        $report = $this->builder()->build(MetricsPeriod::Day);

        self::assertCount(5, $report->charts);
        self::assertSame(24, substr_count($report->charts[0], '<tr><th scope="row">'));
        self::assertStringContainsString('<th scope="row">14 h</th><td>7</td>', $report->charts[0], '12:00 UTC est 14 h à Paris, la dernière heure');
        self::assertSame('2026-10-06 13:00:00', $this->reader->requestedSince?->format('Y-m-d H:i:s'), 'début de la fenêtre, en UTC');
    }

    #[Test]
    public function testThirtyDaysMakeThirtyLocalDayPoints(): void
    {
        $this->reader->requests = [
            '2026-10-06 23:30:00' => ['requests' => 5, 'errors' => 0, 'durationMs' => 50],
            '2026-10-07 09:00:00' => ['requests' => 3, 'errors' => 0, 'durationMs' => 30],
        ];

        $html = $this->builder()->build(MetricsPeriod::Month)->charts[0];

        self::assertSame(30, substr_count($html, '<tr><th scope="row">'));
        self::assertStringContainsString('<th scope="row">07/10</th><td>8</td>', $html, '23:30 UTC du 06 est déjà le 07 à Paris : jour local');
    }

    #[Test]
    public function testRowsOutsideTheWindowAreIgnored(): void
    {
        $this->reader->requests = ['2020-01-01 00:00:00' => ['requests' => 999, 'errors' => 999, 'durationMs' => 1]];

        self::assertSame('—', $this->cards()['Disponibilité']->value);
    }
}
