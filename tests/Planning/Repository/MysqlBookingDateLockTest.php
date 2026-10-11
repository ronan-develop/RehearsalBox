<?php

declare(strict_types=1);

namespace App\Tests\Planning\Repository;

use App\Planning\Repository\MysqlBookingDateLock;
use App\Tests\Database\TestDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #263 : le verrou par date sérialise les réservations d'un même jour, jamais celles d'un autre jour. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class MysqlBookingDateLockTest extends TestCase
{
    #[Test]
    public function testTwoBookingsOfTheSameDayWaitForEachOtherButNotAnotherDay(): void
    {
        $first = new MysqlBookingDateLock(TestDatabase::connection());
        $second = new MysqlBookingDateLock(TestDatabase::connection()); // une AUTRE connexion : une autre réservation simultanée
        $day = new \DateTimeImmutable('2026-10-07');

        self::assertTrue($first->acquire($day));
        try {
            self::assertFalse($second->acquire($day, 0), 'même jour : il doit attendre');
            self::assertTrue($second->acquire($day->modify('+2 days'), 0), 'un autre jour n\'est jamais gêné');
            $second->release($day->modify('+2 days'));
        } finally {
            $first->release($day);
        }
        self::assertTrue($second->acquire($day, 0), 'libéré : le second passe');
        $second->release($day);
    }

    #[Test]
    public function testTwoRequestsOfTheSameGroupWaitForEachOtherWhateverTheirDates(): void
    {
        $first = new MysqlBookingDateLock(TestDatabase::connection());
        $second = new MysqlBookingDateLock(TestDatabase::connection());

        self::assertTrue($first->acquireGroup(7));
        try {
            self::assertFalse($second->acquireGroup(7, 0), 'même groupe : il doit attendre, quelle que soit la date');
            self::assertTrue($second->acquireGroup(8, 0), 'un autre groupe n\'est jamais gêné');
            $second->releaseGroup(8);
            self::assertTrue($second->acquire(new \DateTimeImmutable('2026-10-07'), 0), 'le verrou du groupe n\'est pas celui d\'un jour');
            $second->release(new \DateTimeImmutable('2026-10-07'));
        } finally {
            $first->releaseGroup(7);
        }
        self::assertTrue($second->acquireGroup(7, 0), 'libéré : le second passe');
        $second->releaseGroup(7);
    }
}
