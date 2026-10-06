<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\MysqlBookingDateLock;
use App\Tests\TestDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #263 : le verrou par date sérialise les réservations d'un même jour, jamais celles d'un autre jour. */
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
}
