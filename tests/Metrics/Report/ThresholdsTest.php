<?php

declare(strict_types=1);

namespace App\Tests\Metrics\Report;

use App\Metrics\Report\HealthStatus;
use App\Metrics\Report\MetricsPeriod;
use App\Metrics\Report\Thresholds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ThresholdsTest extends TestCase
{
    #[Test]
    public function testAHigherIsWorseMetricTurnsOrangeThenRedAtItsThresholds(): void
    {
        $thresholds = new Thresholds(['errors_5xx' => [2, 10]]);

        self::assertSame(HealthStatus::Ok, $thresholds->status('errors_5xx', 1, true));
        self::assertSame(HealthStatus::Warning, $thresholds->status('errors_5xx', 2, true));
        self::assertSame(HealthStatus::Critical, $thresholds->status('errors_5xx', 10, true));
    }

    #[Test]
    public function testALowerIsWorseMetricTurnsOrangeThenRedWhenItFallsBelow(): void
    {
        $thresholds = new Thresholds();

        self::assertSame(HealthStatus::Ok, $thresholds->status('availability_percent', 99.9, false));
        self::assertSame(HealthStatus::Warning, $thresholds->status('availability_percent', 99.0, false));
        self::assertSame(HealthStatus::Critical, $thresholds->status('availability_percent', 90.0, false));
    }

    #[Test]
    public function testAMissingValueIsUnknownNeverGreen(): void
    {
        self::assertSame(HealthStatus::Unknown, (new Thresholds())->status('backup_hours', null, true));
    }

    #[Test]
    public function testMalformedOrUnknownOverridesAreIgnored(): void
    {
        $thresholds = new Thresholds(['errors_5xx' => 'oups', 'response_ms' => [1], 'inconnu' => [1, 2]]);

        self::assertSame(HealthStatus::Ok, $thresholds->status('errors_5xx', 0, true));
        self::assertSame(HealthStatus::Warning, $thresholds->status('errors_5xx', 1, true), 'seuil par défaut conservé');
        self::assertSame(HealthStatus::Unknown, $thresholds->status('inconnu', 5, true));
    }

    #[Test]
    public function testThePeriodComesFromTheQueryAndFallsBackToTwentyFourHours(): void
    {
        self::assertSame(MetricsPeriod::Week, MetricsPeriod::fromQuery('7j'));
        self::assertSame(MetricsPeriod::Month, MetricsPeriod::fromQuery('30j'));
        self::assertSame(MetricsPeriod::Day, MetricsPeriod::fromQuery('999'));
        self::assertSame(MetricsPeriod::Day, MetricsPeriod::fromQuery(['x']));
        self::assertSame(MetricsPeriod::Day, MetricsPeriod::fromQuery(null));
    }
}
