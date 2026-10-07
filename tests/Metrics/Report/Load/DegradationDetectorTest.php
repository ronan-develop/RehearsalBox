<?php

declare(strict_types=1);

namespace App\Tests\Metrics\Report\Load;

use App\Metrics\Report\HealthStatus;
use App\Metrics\Report\Load\DegradationDetector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DegradationDetectorTest extends TestCase
{
    /**
     * @param list<array{int, int, int}> $series [requêtes, erreurs, durée moyenne en ms] par heure, la plus ancienne d'abord
     *
     * @return array<string, array{requests: int, errors: int, durationMs: int}>
     */
    private function hours(array $series): array
    {
        $hours = [];
        $start = new \DateTimeImmutable('2026-10-01 00:00:00', new \DateTimeZone('UTC'));
        foreach ($series as $i => [$requests, $errors, $average]) {
            $hours[$start->modify('+' . $i . ' hours')->format('Y-m-d H:00:00')] = ['requests' => $requests, 'errors' => $errors, 'durationMs' => $requests * $average];
        }

        return $hours;
    }

    /** @return list<array{int, int, int}> */
    private function steady(int $count, int $requests = 100, int $errors = 0, int $average = 30): array
    {
        return array_fill(0, $count, [$requests, $errors, $average]);
    }

    #[Test]
    public function testASteadySiteIsNormal(): void
    {
        $verdict = (new DegradationDetector())->assess($this->hours($this->steady(30)));

        self::assertSame(HealthStatus::Ok, $verdict->status);
        self::assertStringContainsString('Stable', $verdict->messages[0]);
    }

    #[Test]
    public function testResponseTimeThatDoublesAtEqualVolumeIsCritical(): void
    {
        $verdict = (new DegradationDetector())->assess($this->hours([...$this->steady(30), ...$this->steady(3, 100, 0, 70)]));

        self::assertSame(HealthStatus::Critical, $verdict->status);
        self::assertStringContainsString('fois la base', $verdict->messages[0]);
    }

    #[Test]
    public function testASlowdownOfOneAndAHalfIsOnlyAWarning(): void
    {
        $verdict = (new DegradationDetector())->assess($this->hours([...$this->steady(30), ...$this->steady(3, 100, 0, 50)]));

        self::assertSame(HealthStatus::Warning, $verdict->status);
    }

    #[Test]
    public function testABusyEveningIsComparedWithOtherBusyEveningsNotWithTheCalmNight(): void
    {
        // 24 heures calmes (10 requêtes à 20 ms) puis 10 heures chargées (500 à 80 ms) et 3 heures chargées identiques : normal.
        $series = [...$this->steady(24, 10, 0, 20), ...$this->steady(10, 500, 0, 80), ...$this->steady(3, 500, 0, 80)];

        self::assertSame(HealthStatus::Ok, (new DegradationDetector())->assess($this->hours($series))->status, 'plus lent que la nuit, mais pas plus lent qu\'une soirée comparable');
    }

    #[Test]
    public function testATinAbsoluteIncreaseIsNotADegradation(): void
    {
        $verdict = (new DegradationDetector())->assess($this->hours([...$this->steady(30, 100, 0, 3), ...$this->steady(3, 100, 0, 8)]));

        self::assertSame(HealthStatus::Ok, $verdict->status, '3 ms à 8 ms : ×2,7 mais +5 ms seulement');
    }

    #[Test]
    public function testServerErrorsWithoutMoreTrafficAreCritical(): void
    {
        $verdict = (new DegradationDetector())->assess($this->hours([...$this->steady(30), [100, 4, 30], [100, 5, 30], [100, 3, 30]]));

        self::assertSame(HealthStatus::Critical, $verdict->status);
        self::assertStringContainsString('sans hausse de trafic', $verdict->messages[0]);
    }

    #[Test]
    public function testAFewIsolatedErrorsStayNormal(): void
    {
        $verdict = (new DegradationDetector())->assess($this->hours([...$this->steady(30), [100, 1, 30], [100, 0, 30], [100, 0, 30]]));

        self::assertSame(HealthStatus::Ok, $verdict->status);
    }

    #[Test]
    public function testNotEnoughComparableHistoryIsUnknownNeverNormal(): void
    {
        $verdict = (new DegradationDetector())->assess($this->hours([...$this->steady(4), ...$this->steady(3, 100, 0, 500)]));

        self::assertSame(HealthStatus::Unknown, $verdict->status);
        self::assertStringContainsString('Pas assez d\'historique', $verdict->messages[0]);
    }

    #[Test]
    public function testNoRecentTrafficIsUnknown(): void
    {
        self::assertSame(HealthStatus::Unknown, (new DegradationDetector())->assess([])->status);
        self::assertSame(HealthStatus::Unknown, (new DegradationDetector())->assess($this->hours($this->steady(10, 0)))->status);
    }
}
