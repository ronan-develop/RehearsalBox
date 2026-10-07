<?php

declare(strict_types=1);

namespace App\Tests\Metrics;

use App\Metrics\DurationHistogram;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DurationHistogramTest extends TestCase
{
    #[Test]
    public function testEachDurationFallsInItsBucketOnTheBounds(): void
    {
        self::assertSame(0, DurationHistogram::bucketOf(0));
        self::assertSame(0, DurationHistogram::bucketOf(50));
        self::assertSame(1, DurationHistogram::bucketOf(51));
        self::assertSame(2, DurationHistogram::bucketOf(250));
        self::assertSame(4, DurationHistogram::bucketOf(1000));
        self::assertSame(5, DurationHistogram::bucketOf(1001));
        self::assertSame(5, DurationHistogram::bucketOf(60000));
    }

    #[Test]
    public function testPercentilesAreTheUpperBoundOfTheBucketHoldingThem(): void
    {
        $counts = [60, 20, 10, 5, 3, 2]; // 100 requêtes

        self::assertSame(50, DurationHistogram::percentile($counts, 0.5));
        self::assertSame(500, DurationHistogram::percentile($counts, 0.95));
        self::assertSame(DurationHistogram::OPEN_ENDED, DurationHistogram::percentile($counts, 0.99));
    }

    #[Test]
    public function testWithoutAnyMeasureThereIsNoPercentile(): void
    {
        self::assertNull(DurationHistogram::percentile([0, 0, 0, 0, 0, 0], 0.5));
    }

    #[Test]
    public function testASingleRequestIsItsOwnMedianAndNinetyFifthPercentile(): void
    {
        self::assertSame(250, DurationHistogram::percentile([0, 0, 1, 0, 0, 0], 0.5));
        self::assertSame(250, DurationHistogram::percentile([0, 0, 1, 0, 0, 0], 0.95));
    }
}
