<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Support;

use App\Accounting\Support\EuroText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Centimes entiers → euros à la virgule, sans flottant. */
final class EuroTextTest extends TestCase
{
    /** @return iterable<string, array{0: int, 1: string}> */
    public static function cases(): iterable
    {
        yield 'zéro' => [0, '0,00'];
        yield 'un centime' => [1, '0,01'];
        yield 'dix centimes' => [10, '0,10'];
        yield 'un euro' => [100, '1,00'];
        yield 'loyer' => [49800, '498,00'];
        yield 'milliers sans séparateur' => [123450, '1234,50'];
        yield 'négatif' => [-1250, '-12,50'];
        yield 'plafond' => [99999999999, '999999999,99'];
    }

    #[Test]
    #[DataProvider('cases')]
    public function testCentsBecomeEuros(int $cents, string $expected): void
    {
        self::assertSame($expected, EuroText::plain($cents));
    }
}
