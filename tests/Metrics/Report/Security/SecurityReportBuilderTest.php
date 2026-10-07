<?php

declare(strict_types=1);

namespace App\Tests\Metrics\Report\Security;

use App\Metrics\MetricEventType;
use App\Metrics\Report\HealthStatus;
use App\Metrics\Report\MetricsPeriod;
use App\Metrics\Report\Security\AnomalyDetector;
use App\Metrics\Report\Security\SecurityEvent;
use App\Metrics\Report\Security\SecurityReportBuilder;
use App\Metrics\Report\Thresholds;
use App\Tests\Doubles\FakeMetricsReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SecurityReportBuilderTest extends TestCase
{
    private FakeMetricsReader $reader;

    protected function setUp(): void
    {
        $this->reader = new FakeMetricsReader();
    }

    private function build(MetricsPeriod $period = MetricsPeriod::Day): \App\Metrics\Report\Security\SecurityReport
    {
        return (new SecurityReportBuilder($this->reader, new AnomalyDetector(), new Thresholds(), new MockClock('2026-10-07 12:30:00 UTC'), new \DateTimeZone('Europe/Paris')))->build($period);
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
    public function testAQuietPeriodIsAllZerosAndGreen(): void
    {
        $cards = $this->cards();

        self::assertSame('0', $cards['Échecs de connexion']->value);
        self::assertSame(HealthStatus::Ok, $cards['Échecs de connexion']->status);
        self::assertSame([], $this->build()->anomalies);
        self::assertCount(4, $this->build()->charts);
    }

    #[Test]
    public function testCountersSumTheHourlyEventsAndTurnOrangeThenRed(): void
    {
        $this->reader->events = [
            '2026-10-07 09:00:00' => [MetricEventType::LoginFailed->value => 15, MetricEventType::RateLimited->value => 2],
            '2026-10-07 10:00:00' => [MetricEventType::LoginFailed->value => 10, MetricEventType::PasswordResetRequested->value => 4, MetricEventType::NotFound->value => 7],
            '2020-01-01 00:00:00' => [MetricEventType::LoginFailed->value => 999],
        ];

        $cards = $this->cards();

        self::assertSame('25', $cards['Échecs de connexion']->value);
        self::assertSame(HealthStatus::Warning, $cards['Échecs de connexion']->status);
        self::assertSame(HealthStatus::Warning, $cards['Limites de débit atteintes']->status);
        self::assertSame('4', $cards['Mots de passe oubliés demandés']->value);
        self::assertNull($cards['Mots de passe oubliés demandés']->status, 'une information n\'a pas d\'état');
        self::assertSame('7', $cards['Pages introuvables']->value);
    }

    #[Test]
    public function testScannerHitsAreCountedAndAnomaliesComeFromTheDetector(): void
    {
        $at = new \DateTimeImmutable('2026-10-07 10:00:00', new \DateTimeZone('UTC'));
        $this->reader->securityEvents = [
            new SecurityEvent(MetricEventType::NotFound, '/.env', 'aaaaaaaaaaaaaaaa', $at),
            new SecurityEvent(MetricEventType::NotFound, '/wp-login.php', 'aaaaaaaaaaaaaaaa', $at),
            new SecurityEvent(MetricEventType::NotFound, '/phpmyadmin', 'aaaaaaaaaaaaaaaa', $at),
            new SecurityEvent(MetricEventType::NotFound, '/vieille-page', 'bbbbbbbbbbbbbbbb', $at),
        ];

        $report = $this->build();

        self::assertCount(1, $report->anomalies);
        self::assertStringContainsString('3 sur des chemins de balayage', $this->cards()['Pages introuvables']->hint);
    }
}
