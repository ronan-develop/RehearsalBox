<?php

declare(strict_types=1);

namespace App\Tests\Planning\Presenter;

use App\Planning\Presenter\BookingPrefill;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #457 : « Réserver le local » s'ouvre rempli avec le créneau d'un autre groupe ; tout ce qui n'est pas fiable est ignoré. */
final class BookingPrefillTest extends TestCase
{
    private const TODAY = '2026-10-12';

    private function prefill(array $query): ?array
    {
        return BookingPrefill::from($query, new \DateTimeImmutable(self::TODAY));
    }

    #[Test]
    public function testAValidSlotIsKeptAsIs(): void
    {
        self::assertSame(['date' => '2026-10-14', 'start' => '18:30', 'end' => '22:45'], $this->prefill(['date' => '2026-10-14', 'start' => '18:30', 'end' => '22:45']));
    }

    #[Test]
    public function testTodayAndTheLastAllowedDayAreAccepted(): void
    {
        self::assertNotNull($this->prefill(['date' => '2026-10-12', 'start' => '10:00', 'end' => '11:00']));
        self::assertNotNull($this->prefill(['date' => '2026-11-11', 'start' => '10:00', 'end' => '11:00']), '30 jours devant');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function untrustworthy(): array
    {
        return [
            'rien' => [[]],
            'sans fin' => [['date' => '2026-10-14', 'start' => '18:30']],
            'jour passé' => [['date' => '2026-10-11', 'start' => '18:30', 'end' => '19:00']],
            'trop loin' => [['date' => '2026-11-12', 'start' => '18:30', 'end' => '19:00']],
            'date inexistante' => [['date' => '2026-02-31', 'start' => '18:30', 'end' => '19:00']],
            'date mal formée' => [['date' => '14/10/2026', 'start' => '18:30', 'end' => '19:00']],
            'minutes hors quart d\'heure' => [['date' => '2026-10-14', 'start' => '18:20', 'end' => '19:00']],
            'fin avant le début' => [['date' => '2026-10-14', 'start' => '19:00', 'end' => '18:30']],
            'fin = début' => [['date' => '2026-10-14', 'start' => '19:00', 'end' => '19:00']],
            'fin après 23:30' => [['date' => '2026-10-14', 'start' => '22:00', 'end' => '23:45']],
            'tableau' => [['date' => ['2026-10-14'], 'start' => '18:30', 'end' => '19:00']],
            'injection' => [['date' => '2026-10-14"><script>', 'start' => '18:30', 'end' => '19:00']],
        ];
    }

    /** @param array<string, mixed> $query */
    #[Test]
    #[DataProvider('untrustworthy')]
    public function testAnythingUntrustworthyIsIgnored(array $query): void
    {
        self::assertNull($this->prefill($query));
    }
}
