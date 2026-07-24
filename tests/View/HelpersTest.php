<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\Entity\Enum\Weekday;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/View/helpers.php';

final class HelpersTest extends TestCase
{
    public function testFormatTimeStripsSecondsFromHhMmSs(): void
    {
        self::assertSame('18:00', formatTime('18:00:00'));
    }

    public function testFormatTimeHandlesMaxCeiling(): void
    {
        self::assertSame('23:30', formatTime('23:30:00'));
    }

    public function testFormatTimeIsIdempotentOnAlreadyShortFormat(): void
    {
        self::assertSame('18:00', formatTime('18:00'));
    }

    /** @return array<string, array{Weekday, string}> */
    public static function weekdayProvider(): array
    {
        return [
            'lundi' => [Weekday::Monday, 'Lundi'],
            'mardi' => [Weekday::Tuesday, 'Mardi'],
            'mercredi' => [Weekday::Wednesday, 'Mercredi'],
            'jeudi' => [Weekday::Thursday, 'Jeudi'],
            'vendredi' => [Weekday::Friday, 'Vendredi'],
            'samedi' => [Weekday::Saturday, 'Samedi'],
            'dimanche' => [Weekday::Sunday, 'Dimanche'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('weekdayProvider')]
    public function testFormatWeekdayTranslatesToFrench(Weekday $weekday, string $expected): void
    {
        self::assertSame($expected, formatWeekday($weekday));
    }
}
