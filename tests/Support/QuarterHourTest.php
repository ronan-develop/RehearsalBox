<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\QuarterHour;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #263 : les heures des demandes et des réservations tombent sur le quart d'heure (donc aussi sur la demi-heure). */
final class QuarterHourTest extends TestCase
{
    /** @return list<array{string, bool}> */
    public static function times(): array
    {
        return [
            ['18:00:00', true], ['18:15:00', true], ['18:30:00', true], ['18:45', true], ['00:00:00', true],
            ['18:10:00', false], ['18:20:00', false], ['18:30:30', false], ['18:07', false],
            ['24:00:00', false], ['18:60:00', false], ['', false], ['abc', false], ['18h30', false], ['1830', false],
        ];
    }

    #[Test]
    #[DataProvider('times')]
    public function testOnlyRealHoursOnAQuarterAreAccepted(string $time, bool $expected): void
    {
        self::assertSame($expected, QuarterHour::isAligned($time));
    }

    #[Test]
    public function testItNormalisesToTheDatabaseFormat(): void
    {
        self::assertSame('18:30:00', QuarterHour::normalise('18:30'));
        self::assertSame('18:30:00', QuarterHour::normalise('18:30:00'));
    }
}
