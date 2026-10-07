<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Notification;

use App\Messaging\Notification\ReminderRun;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #238 : ce que les deux relances (groupes, mentions) partagent : la fenêtre d'âge, la plage de jour et les compteurs du bilan. */
final class ReminderRunTest extends TestCase
{
    private function at(string $at): ReminderRun
    {
        return new ReminderRun(new \DateTimeImmutable($at, new \DateTimeZone('UTC')), new \DateTimeZone('Europe/Paris'));
    }

    #[Test]
    public function testTheAgeWindowIsFromTwentyFourHoursToSevenDaysBeforeNow(): void
    {
        $run = $this->at('2026-10-07 10:00:00');

        self::assertSame('2026-10-06 10:00:00', $run->notBefore()->format('Y-m-d H:i:s'), 'pas de relance avant 24 h');
        self::assertSame('2026-09-30 10:00:00', $run->notAfter()->format('Y-m-d H:i:s'), 'plus de relance au-delà de 7 jours');
        self::assertSame('2026-10-07 10:00:00', $run->now()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function testReminderOnlyGoOutInTheLocalDaytime(): void
    {
        self::assertTrue($this->at('2026-10-07 10:00:00')->inDaytime(), '12 h à Paris');
        self::assertFalse($this->at('2026-10-07 01:00:00')->inDaytime(), '3 h à Paris');
    }

    #[Test]
    public function testTheReportCountsWhatHappened(): void
    {
        $run = $this->at('2026-10-07 10:00:00');
        $run->markSent();
        $run->markSent();
        $run->markFailed();
        $run->markSkipped();

        $report = $run->report();

        self::assertSame(2, $report->sent());
        self::assertSame(1, $report->failed());
        self::assertSame(1, $report->skipped());
        self::assertFalse($report->outsideWindow());
    }

    #[Test]
    public function testOutsideTheDaytimeTheReportSaysNothingWentOut(): void
    {
        $report = $this->at('2026-10-07 01:00:00')->outsideWindowReport();

        self::assertTrue($report->outsideWindow());
        self::assertSame(0, $report->sent());
    }
}
