<?php

declare(strict_types=1);

namespace App\Tests\Metrics\Report;

use App\Metrics\Collection\MysqlMetricsRepository;
use App\Metrics\HealthSnapshot;
use App\Metrics\MetricEventType;
use App\Metrics\Report\MysqlMetricsReader;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class MysqlMetricsReaderTest extends RepositoryTestCase
{
    private function at(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }

    #[Test]
    public function testRequestsAreSummedPerHourAcrossRoutesWithTheirServerErrors(): void
    {
        $writer = new MysqlMetricsRepository($this->pdo);
        $writer->addHourly($this->at('2026-10-07 10:00:00'), '/a', '2xx', 10, 100, 20, 1);
        $writer->addHourly($this->at('2026-10-07 10:00:00'), '/b', '2xx', 5, 50, 20, 1);
        $writer->addHourly($this->at('2026-10-07 10:00:00'), '/b', '5xx', 2, 20, 20, 1);
        $writer->addHourly($this->at('2026-10-06 10:00:00'), '/a', '2xx', 99, 1, 1, 1);

        $rows = (new MysqlMetricsReader($this->pdo))->requestsByHour($this->at('2026-10-07 00:00:00'));

        self::assertSame(['2026-10-07 10:00:00' => ['requests' => 17, 'errors' => 2, 'durationMs' => 170]], $rows);
    }

    #[Test]
    public function testEventsAreCountedPerHourAndTypeAndOnlyForTheRequestedTypes(): void
    {
        $writer = new MysqlMetricsRepository($this->pdo);
        $writer->addEvent(MetricEventType::MailSent, 'mail', null, null, $this->at('2026-10-07 10:05:00'));
        $writer->addEvent(MetricEventType::MailSent, 'mail', null, null, $this->at('2026-10-07 10:55:00'));
        $writer->addEvent(MetricEventType::MailFailed, 'mail', null, null, $this->at('2026-10-07 11:01:00'));
        $writer->addEvent(MetricEventType::NotFound, '/x', 404, null, $this->at('2026-10-07 10:10:00'));

        $rows = (new MysqlMetricsReader($this->pdo))->eventsByHour($this->at('2026-10-07 00:00:00'), [MetricEventType::MailSent, MetricEventType::MailFailed]);

        self::assertSame([
            '2026-10-07 10:00:00' => ['mail_sent' => 2],
            '2026-10-07 11:00:00' => ['mail_failed' => 1],
        ], $rows);
        self::assertSame([], (new MysqlMetricsReader($this->pdo))->eventsByHour($this->at('2026-10-07 00:00:00'), []));
    }

    #[Test]
    public function testTheLatestSnapshotIsReadBackWithItsNullableFields(): void
    {
        $writer = new MysqlMetricsRepository($this->pdo);
        $reader = new MysqlMetricsReader($this->pdo);
        self::assertNull($reader->latestSnapshot());

        $writer->saveSnapshot(new HealthSnapshot($this->at('2026-10-07 09:00:00'), 1, 2, null, null, null, '8.4.0', null));
        $writer->saveSnapshot(new HealthSnapshot($this->at('2026-10-07 10:00:00'), 1000, 2000, 5, $this->at('2026-10-07 09:59:00'), 'rel-1', '8.4.1', 0.5));

        $snapshot = $reader->latestSnapshot();

        self::assertSame('2026-10-07 10:00:00', $snapshot?->takenAt->format('Y-m-d H:i:s'));
        self::assertSame(1000, $snapshot->diskFreeBytes);
        self::assertSame(5, $snapshot->backupAgeHours);
        self::assertSame('rel-1', $snapshot->releaseMarker);
        self::assertSame(0.5, $snapshot->load1m);
    }

    #[Test]
    public function testSecurityEventsKeepOnlySecurityTypesNewestFirstAndBounded(): void
    {
        $writer = new MysqlMetricsRepository($this->pdo);
        $writer->addEvent(MetricEventType::NotFound, '/.env', 404, 'aaaaaaaaaaaaaaaa', $this->at('2026-10-07 10:00:00'));
        $writer->addEvent(MetricEventType::LoginFailed, '/api/auth/login', 401, null, $this->at('2026-10-07 11:00:00'));
        $writer->addEvent(MetricEventType::MailSent, 'mail', null, null, $this->at('2026-10-07 12:00:00'));
        $writer->addEvent(MetricEventType::ServerError, '/x', 500, null, $this->at('2026-10-07 12:30:00'));

        $reader = new MysqlMetricsReader($this->pdo);
        $events = $reader->securityEvents($this->at('2026-10-07 00:00:00'));

        self::assertSame(['login_failed', 'not_found'], array_map(static fn ($e): string => $e->type->value, $events));
        self::assertSame('aaaaaaaaaaaaaaaa', $events[1]->ipHash);
        self::assertNull($events[0]->ipHash);
        self::assertCount(1, $reader->securityEvents($this->at('2026-10-07 00:00:00'), 1));
    }

    #[Test]
    public function testLoadByHourSumsRoutesAndKeepsTheMemoryPeakAndTheHistogram(): void
    {
        $writer = new MysqlMetricsRepository($this->pdo);
        $writer->addHourly($this->at('2026-10-07 10:00:00'), '/a', '2xx', 10, 100, 20, 2048, [8, 2, 0, 0, 0, 0]);
        $writer->addHourly($this->at('2026-10-07 10:00:00'), '/b', '2xx', 5, 50, 20, 4096, [1, 1, 1, 1, 1, 0]);
        $writer->addHourly($this->at('2026-10-07 10:00:00'), '/b', '5xx', 2, 20, 20, 1024, [0, 0, 0, 0, 0, 2]);

        $rows = (new MysqlMetricsReader($this->pdo))->loadByHour($this->at('2026-10-07 00:00:00'));

        self::assertSame(
            ['2026-10-07 10:00:00' => ['requests' => 17, 'errors' => 2, 'durationMs' => 170, 'memoryKb' => 4096, 'buckets' => [9, 3, 1, 1, 1, 2]]],
            $rows,
        );
    }

    #[Test]
    public function testHistogramsAddUpAcrossFlushes(): void
    {
        $writer = new MysqlMetricsRepository($this->pdo);
        $writer->addHourly($this->at('2026-10-07 10:00:00'), '/a', '2xx', 2, 10, 5, 1, [2, 0, 0, 0, 0, 0]);
        $writer->addHourly($this->at('2026-10-07 10:00:00'), '/a', '2xx', 1, 600, 600, 1, [0, 0, 0, 1, 0, 0]);

        $rows = (new MysqlMetricsReader($this->pdo))->loadByHour($this->at('2026-10-07 00:00:00'));

        self::assertSame([2, 0, 0, 1, 0, 0], $rows['2026-10-07 10:00:00']['buckets']);
    }

    #[Test]
    public function testRouteTotalsRankByRequestsAndAreBounded(): void
    {
        $writer = new MysqlMetricsRepository($this->pdo);
        $writer->addHourly($this->at('2026-10-07 10:00:00'), '/rare', '2xx', 1, 10, 10, 1);
        $writer->addHourly($this->at('2026-10-07 10:00:00'), '/chaude', '2xx', 50, 500, 40, 1);
        $writer->addHourly($this->at('2026-10-07 11:00:00'), '/chaude', '5xx', 5, 100, 90, 1);

        $reader = new MysqlMetricsReader($this->pdo);

        self::assertSame(
            [['route' => '/chaude', 'requests' => 55, 'durationMs' => 600, 'maxMs' => 90], ['route' => '/rare', 'requests' => 1, 'durationMs' => 10, 'maxMs' => 10]],
            $reader->routeTotals($this->at('2026-10-07 00:00:00')),
        );
        self::assertCount(1, $reader->routeTotals($this->at('2026-10-07 00:00:00'), 1));
    }
}
