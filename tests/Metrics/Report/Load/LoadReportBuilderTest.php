<?php

declare(strict_types=1);

namespace App\Tests\Metrics\Report\Load;

use App\Metrics\Report\HealthStatus;
use App\Metrics\Report\Load\DegradationDetector;
use App\Metrics\Report\Load\LoadReport;
use App\Metrics\Report\Load\LoadReportBuilder;
use App\Metrics\Report\MetricsPeriod;
use App\Metrics\Report\Thresholds;
use App\Tests\Doubles\FakeMetricsReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class LoadReportBuilderTest extends TestCase
{
    private FakeMetricsReader $reader;

    protected function setUp(): void
    {
        $this->reader = new FakeMetricsReader();
    }

    private function build(MetricsPeriod $period = MetricsPeriod::Day): LoadReport
    {
        return (new LoadReportBuilder($this->reader, new DegradationDetector(), new Thresholds(), new MockClock('2026-10-07 12:30:00 UTC'), new \DateTimeZone('Europe/Paris')))->build($period);
    }

    /** @return array<string, \App\Metrics\Report\StatusCard> */
    private function cards(): array
    {
        $cards = [];
        foreach ($this->build()->cards as $card) {
            $cards[$card->label] = $card;
        }

        return $cards;
    }

    #[Test]
    public function testAnEmptySiteHasNoMeasureAndAnUnknownVerdict(): void
    {
        $cards = $this->cards();

        self::assertSame('—', $cards['Temps de réponse médian']->value);
        self::assertSame('—', $cards['Pic de mémoire PHP']->value);
        self::assertSame(HealthStatus::Unknown, $cards['Verdict de dégradation']->status);
        self::assertCount(4, $this->build()->charts);
    }

    #[Test]
    public function testRateMedianNinetyFifthAndMemoryComeFromTheHourlyHistograms(): void
    {
        $this->reader->load = [
            '2026-10-07 10:00:00' => ['requests' => 600, 'errors' => 0, 'durationMs' => 30000, 'memoryKb' => 4096, 'buckets' => [500, 60, 20, 10, 5, 5]],
            '2026-10-07 11:00:00' => ['requests' => 120, 'errors' => 0, 'durationMs' => 6000, 'memoryKb' => 8192, 'buckets' => [100, 10, 5, 3, 1, 1]],
        ];

        $cards = $this->cards();

        self::assertSame('10,0', $cards['Requêtes par minute (pic)']->value, '600 requêtes en une heure : 10 par minute');
        self::assertSame('≤ 50 ms', $cards['Temps de réponse médian']->value);
        self::assertSame('≤ 250 ms', $cards['95e centile']->value, '684e requête sur 720 : tranche ≤ 250 ms');
        self::assertSame('8,0 Mo', $cards['Pic de mémoire PHP']->value);
    }

    #[Test]
    public function testTheOpenEndedBucketIsShownAsMoreThanOneSecond(): void
    {
        $this->reader->load = ['2026-10-07 10:00:00' => ['requests' => 10, 'errors' => 0, 'durationMs' => 50000, 'memoryKb' => 1, 'buckets' => [0, 0, 0, 0, 0, 10]]];

        self::assertSame('> 1000 ms', $this->cards()['95e centile']->value);
    }

    #[Test]
    public function testRoutesAreRankedWithTheirTimeShareAndThePollingIsHighlighted(): void
    {
        $this->reader->routes = [
            ['route' => '/api/conversations/{id}/updates', 'requests' => 9000, 'durationMs' => 270000, 'maxMs' => 400],
            ['route' => '/login', 'requests' => 100, 'durationMs' => 30000, 'maxMs' => 900],
        ];

        $report = $this->build();

        self::assertTrue($report->routes[0]['polling']);
        self::assertFalse($report->routes[1]['polling']);
        self::assertSame(30, $report->routes[0]['averageMs']);
        self::assertSame(90, $report->routes[0]['timeShare']);
        self::assertSame(10, $report->routes[1]['timeShare']);
        self::assertSame('90 % du temps', $this->cards()['Polling de la messagerie']->value);
    }

    #[Test]
    public function testTheVerdictUsesTheSevenDayBaselineNotTheChosenPeriod(): void
    {
        for ($i = 0; $i < 40; ++$i) {
            $hour = (new \DateTimeImmutable('2026-10-07 11:00:00', new \DateTimeZone('UTC')))->modify('-' . $i . ' hours')->format('Y-m-d H:00:00');
            $slow = $i < 3;
            $this->reader->load[$hour] = ['requests' => 100, 'errors' => 0, 'durationMs' => 100 * ($slow ? 90 : 30), 'memoryKb' => 1, 'buckets' => [100, 0, 0, 0, 0, 0]];
        }
        ksort($this->reader->load);

        $verdict = $this->build(MetricsPeriod::Day)->degradation;

        self::assertSame(HealthStatus::Critical, $verdict->status);
        self::assertSame('2026-09-30 09:30:00', $this->reader->requestedSince?->format('Y-m-d H:i:s'), 'la base remonte de 7 jours (+ 3 h), indépendamment de la période affichée');
    }
}
