<?php

declare(strict_types=1);

namespace App\Tests\Metrics\Report\Security;

use App\Metrics\MetricEventType;
use App\Metrics\Report\Security\AnomalyDetector;
use App\Metrics\Report\Security\AnomalyKind;
use App\Metrics\Report\Security\ScannerPaths;
use App\Metrics\Report\Security\SecurityEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AnomalyDetectorTest extends TestCase
{
    private const BOT = 'aaaaaaaaaaaaaaaa';
    private const OTHER = 'bbbbbbbbbbbbbbbb';

    private function event(MetricEventType $type, string $ip, string $time, string $route = '/x'): SecurityEvent
    {
        return new SecurityEvent($type, $route, $ip, new \DateTimeImmutable('2026-10-07 ' . $time, new \DateTimeZone('UTC')));
    }

    /** @return list<SecurityEvent> */
    private function many(MetricEventType $type, string $ip, int $count, int $everySeconds, string $route = '/x'): array
    {
        $events = [];
        for ($i = 0; $i < $count; ++$i) {
            $events[] = $this->event($type, $ip, gmdate('H:i:s', 10 * 3600 + $i * $everySeconds), $route);
        }

        return $events;
    }

    #[Test]
    public function testNothingIsReportedForOrdinaryTraffic(): void
    {
        $events = [
            $this->event(MetricEventType::LoginFailed, self::BOT, '10:00:00'),
            $this->event(MetricEventType::NotFound, self::OTHER, '10:01:00', '/ancienne-page'),
            $this->event(MetricEventType::AccessDenied, self::OTHER, '10:02:00'),
        ];

        self::assertSame([], (new AnomalyDetector())->detect($events));
    }

    #[Test]
    public function testScannerPathsRepeatedByOneFingerprintAreAScanner(): void
    {
        $events = [
            $this->event(MetricEventType::NotFound, self::BOT, '10:00:00', '/.env'),
            $this->event(MetricEventType::NotFound, self::BOT, '10:00:05', '/wp-login.php'),
            $this->event(MetricEventType::NotFound, self::BOT, '10:00:09', '/phpmyadmin/'),
            $this->event(MetricEventType::NotFound, self::OTHER, '10:00:10', '/.env'),
        ];

        $anomalies = (new AnomalyDetector())->detect($events);

        self::assertCount(1, $anomalies);
        self::assertSame(AnomalyKind::Scanner, $anomalies[0]->kind);
        self::assertSame('aaaaaaaa', $anomalies[0]->fingerprint, 'empreinte tronquée à 8 caractères');
        self::assertSame(3, $anomalies[0]->count);
    }

    #[Test]
    public function testAnOrdinary404IsNotAScannerHit(): void
    {
        $events = $this->many(MetricEventType::NotFound, self::BOT, 10, 1, '/page-supprimee');

        self::assertSame([], array_filter((new AnomalyDetector())->detect($events), static fn ($a): bool => $a->kind === AnomalyKind::Scanner));
    }

    #[Test]
    public function testManySecurityEventsInAFewMinutesAreABurst(): void
    {
        $events = $this->many(MetricEventType::AccessDenied, self::BOT, 30, 5);

        $anomalies = (new AnomalyDetector())->detect($events);

        self::assertSame([AnomalyKind::Burst], array_map(static fn ($a): AnomalyKind => $a->kind, $anomalies));
        self::assertSame(30, $anomalies[0]->count);
    }

    #[Test]
    public function testTheSameVolumeSpreadOverHoursIsNotABurst(): void
    {
        $events = $this->many(MetricEventType::AccessDenied, self::BOT, 30, 600);

        self::assertSame([], (new AnomalyDetector())->detect($events));
    }

    #[Test]
    public function testRepeatedLoginFailuresFromOneFingerprintAreCredentialStuffing(): void
    {
        $events = $this->many(MetricEventType::LoginFailed, self::BOT, 10, 120);

        $anomalies = (new AnomalyDetector())->detect($events);

        self::assertSame([AnomalyKind::CredentialStuffing], array_map(static fn ($a): AnomalyKind => $a->kind, $anomalies));
    }

    #[Test]
    public function testFailuresSpreadAcrossFingerprintsDoNotAddUp(): void
    {
        $events = [...$this->many(MetricEventType::LoginFailed, self::BOT, 6, 60), ...$this->many(MetricEventType::LoginFailed, self::OTHER, 6, 60)];

        self::assertSame([], (new AnomalyDetector())->detect($events));
    }

    #[Test]
    public function testEventsWithoutFingerprintAreGroupedAndShownWithoutAnyAddress(): void
    {
        $events = array_map(static fn (SecurityEvent $e): SecurityEvent => new SecurityEvent($e->type, $e->route, null, $e->at), $this->many(MetricEventType::LoginFailed, self::BOT, 10, 60));

        $anomalies = (new AnomalyDetector())->detect($events);

        self::assertSame('—', $anomalies[0]->fingerprint);
    }

    #[Test]
    public function testThresholdsAreConfigurableAndTheMostRecentAnomalyComesFirst(): void
    {
        $old = $this->many(MetricEventType::LoginFailed, self::BOT, 3, 10);
        $recent = array_map(fn (SecurityEvent $e): SecurityEvent => $this->event($e->type, self::OTHER, '15' . substr($e->at->format(':i:s'), 0, 6)), $old);

        $anomalies = (new AnomalyDetector(stuffingFailures: 3))->detect([...$old, ...$recent]);

        self::assertSame(['bbbbbbbb', 'aaaaaaaa'], array_map(static fn ($a): string => $a->fingerprint, $anomalies));
    }

    #[Test]
    public function testScannerPathsAreRecognisedWhateverTheCase(): void
    {
        foreach (['/.env', '/WP-Login.php', '/phpMyAdmin/index.php', '/backup.sql', '/.git/config'] as $path) {
            self::assertTrue(ScannerPaths::matches($path), $path);
        }
        foreach (['/', '/login', '/api/conversations/12', '/assets/js/app.js'] as $path) {
            self::assertFalse(ScannerPaths::matches($path), $path);
        }
    }
}
