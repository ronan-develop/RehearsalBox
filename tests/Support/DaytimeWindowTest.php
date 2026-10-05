<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\DaytimeWindow;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DaytimeWindowTest extends TestCase
{
    #[Test]
    public function testTheWindowIsNineToTwentyLocalTimeAndNotUtc(): void
    {
        $paris = new \DateTimeZone('Europe/Paris');

        // 07:59 UTC = 09:59 à Paris (heure d'été) : ouvert ; 18:00 UTC = 20:00 à Paris : fermé.
        self::assertTrue(DaytimeWindow::contains(new \DateTimeImmutable('2026-10-06 07:59:00', new \DateTimeZone('UTC')), $paris));
        self::assertFalse(DaytimeWindow::contains(new \DateTimeImmutable('2026-10-06 18:00:00', new \DateTimeZone('UTC')), $paris));
        self::assertFalse(DaytimeWindow::contains(new \DateTimeImmutable('2026-10-06 06:59:00', new \DateTimeZone('UTC')), $paris), '08:59 à Paris : trop tôt');
        self::assertTrue(DaytimeWindow::contains(new \DateTimeImmutable('2026-10-06 07:00:00', new \DateTimeZone('UTC')), $paris), '09:00 à Paris : ouvert');
        self::assertTrue(DaytimeWindow::contains(new \DateTimeImmutable('2026-10-06 17:59:00', new \DateTimeZone('UTC')), $paris), '19:59 à Paris : ouvert');
    }
}
