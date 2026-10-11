<?php

declare(strict_types=1);

namespace App\Tests\Metrics\Collection;

use App\Metrics\Collection\IpPseudonymizer;
use App\Metrics\MetricEventType;
use App\Metrics\Collection\MetricsRecorder;
use App\Tests\Doubles\InMemoryMetricsRepository;
use App\Tests\Doubles\RecordingLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class MetricsRecorderTest extends TestCase
{
    private InMemoryMetricsRepository $repository;
    private MockClock $clock;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->repository = new InMemoryMetricsRepository();
        $this->clock = new MockClock('2026-10-07 10:20:30 UTC');
        $this->logger = new RecordingLogger();
    }

    private function recorder(string $secret = 'secret', int $maxEventsPerDay = MetricsRecorder::MAX_EVENTS_PER_DAY): MetricsRecorder
    {
        return new MetricsRecorder($this->repository, new IpPseudonymizer($secret), $this->clock, $this->logger, $maxEventsPerDay);
    }

    #[Test]
    public function testNothingIsWrittenBeforeTheFlush(): void
    {
        $recorder = $this->recorder();
        $recorder->request('/login', 200, 12, 2048);
        $recorder->event(MetricEventType::NotFound, '/.env', 404, '203.0.113.7');

        self::assertSame([], $this->repository->hourly);
        self::assertSame([], $this->repository->events);
    }

    #[Test]
    public function testManyRequestsOfTheSameRouteAndClassBecomeASingleHourlyRow(): void
    {
        $recorder = $this->recorder();
        foreach ([10, 30, 20] as $ms) {
            $recorder->request('/api/conversations/feed', 200, $ms, 2048);
        }
        $recorder->request('/api/conversations/feed', 200, 5, 4096);
        $recorder->request('/api/conversations/feed', 500, 7, 1024);

        $recorder->flush();

        self::assertCount(2, $this->repository->hourly, 'une ligne par route et classe de statut, jamais une par requête');
        self::assertSame(
            ['hour' => '2026-10-07 10:00:00', 'route' => '/api/conversations/feed', 'class' => '2xx', 'requests' => 4, 'total' => 65, 'max' => 30, 'memory' => 4096, 'buckets' => [4, 0, 0, 0, 0, 0]],
            $this->repository->hourly[0],
        );
        self::assertSame('5xx', $this->repository->hourly[1]['class']);
    }

    #[Test]
    public function testTwoHoursAreTwoRows(): void
    {
        $recorder = $this->recorder();
        $recorder->request('/login', 200, 1, 1);
        $this->clock->sleep(3600);
        $recorder->request('/login', 200, 1, 1);
        $recorder->flush();

        self::assertSame(['2026-10-07 10:00:00', '2026-10-07 11:00:00'], array_column($this->repository->hourly, 'hour'));
    }

    #[Test]
    public function testEventsKeepTheTypeTheRouteTheStatusAndOnlyAFingerprintOfTheAddress(): void
    {
        $recorder = $this->recorder();
        $recorder->event(MetricEventType::LoginFailed, '/api/auth/login', 401, '203.0.113.7');
        $recorder->flush();

        $event = $this->repository->events[0];
        self::assertSame('login_failed', $event['type']);
        self::assertSame('/api/auth/login', $event['route']);
        self::assertSame(401, $event['status']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $event['ip']);
        self::assertStringNotContainsString('203.0.113.7', json_encode($this->repository->events));
    }

    #[Test]
    public function testWithoutASecretNoAddressIsKeptAtAll(): void
    {
        $recorder = $this->recorder('');
        $recorder->event(MetricEventType::NotFound, '/x', 404, '203.0.113.7');
        $recorder->flush();

        self::assertNull($this->repository->events[0]['ip']);
    }

    #[Test]
    public function testALongRouteIsTruncatedSoAScannerCannotFillTheTable(): void
    {
        $recorder = $this->recorder();
        $recorder->event(MetricEventType::NotFound, '/' . str_repeat('a', 500), 404);
        $recorder->flush();

        self::assertSame(120, mb_strlen($this->repository->events[0]['route']));
    }

    #[Test]
    public function testAFailingDatabaseNeverReachesTheCallerAndLeavesNoDataInTheLog(): void
    {
        $this->repository->failing = true;
        $recorder = $this->recorder();
        $recorder->event(MetricEventType::NotFound, '/x', 404);

        $recorder->flush();

        self::assertStringContainsString('RuntimeException', $this->logger->text());
        self::assertStringNotContainsString('alice', $this->logger->text());
    }

    #[Test]
    public function testFlushEmptiesTheBufferSoNothingIsWrittenTwice(): void
    {
        $recorder = $this->recorder();
        $recorder->request('/login', 200, 1, 1);
        $recorder->flush();
        $recorder->flush();

        self::assertCount(1, $this->repository->hourly);
    }

    /** Une requête = un enregistreur : l'inondation vient de requêtes successives, chacune écrivant son événement. */
    private function floodFrom(string $ip, int $requests, string $route = '/.env'): void
    {
        for ($i = 0; $i < $requests; ++$i) {
            $recorder = $this->recorder();
            $recorder->request('(inconnue)', 404, 3, 1024);
            $recorder->event(MetricEventType::NotFound, $route . $i, 404, $ip);
            $recorder->flush();
        }
    }

    #[Test]
    public function testOneAddressCannotFillTheEventTableWithinAnHour(): void
    {
        $this->floodFrom('203.0.113.7', MetricsRecorder::MAX_EVENTS_PER_ADDRESS_PER_HOUR + 40);

        self::assertCount(MetricsRecorder::MAX_EVENTS_PER_ADDRESS_PER_HOUR, $this->repository->events);
        $requests = array_sum(array_column($this->repository->hourly, 'requests'));
        self::assertSame(MetricsRecorder::MAX_EVENTS_PER_ADDRESS_PER_HOUR + 40, $requests, 'le volume reste compté dans l\'agrégat horaire');
    }

    #[Test]
    public function testAnotherAddressIsNotAffectedByTheFlood(): void
    {
        $this->floodFrom('203.0.113.7', MetricsRecorder::MAX_EVENTS_PER_ADDRESS_PER_HOUR + 5);
        $this->floodFrom('198.51.100.9', 3);

        $other = array_values(array_filter($this->repository->events, fn (array $e): bool => $e['ip'] === (new IpPseudonymizer('secret'))->of('198.51.100.9')));
        self::assertCount(3, $other);
    }

    #[Test]
    public function testTheAddressBudgetStartsAgainTheNextHour(): void
    {
        $this->floodFrom('203.0.113.7', MetricsRecorder::MAX_EVENTS_PER_ADDRESS_PER_HOUR + 5);
        $this->clock->modify('+1 hour');
        $this->floodFrom('203.0.113.7', 2);

        self::assertCount(MetricsRecorder::MAX_EVENTS_PER_ADDRESS_PER_HOUR + 2, $this->repository->events);
    }

    #[Test]
    public function testTheTableHasADailyCeilingWhateverTheNumberOfAddresses(): void
    {
        // Une adresse différente par requête : seul le plafond global peut les arrêter.
        for ($i = 0; $i < 60; ++$i) {
            $recorder = $this->recorder(maxEventsPerDay: 50);
            $recorder->event(MetricEventType::NotFound, '/x' . $i, 404, '10.' . intdiv($i, 65025) . '.' . intdiv($i % 65025, 255) . '.' . ($i % 255 + 1));
            $recorder->flush();
        }

        self::assertCount(50, $this->repository->events);
    }

    #[Test]
    public function testTheDefaultDailyCeilingIsBoundedForASharedHost(): void
    {
        self::assertLessThanOrEqual(50000, MetricsRecorder::MAX_EVENTS_PER_DAY);
    }

    #[Test]
    public function testAnEventWithoutAddressIsStillSubjectToTheDailyCeiling(): void
    {
        for ($i = 0; $i < 25; ++$i) {
            $recorder = $this->recorder(maxEventsPerDay: 20);
            $recorder->event(MetricEventType::NotFound, '/y' . $i, 404, '');
            $recorder->flush();
        }

        self::assertCount(20, $this->repository->events);
    }
}
