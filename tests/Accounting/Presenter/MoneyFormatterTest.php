<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Presenter;

use App\Accounting\Presenter\MoneyFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #362 : des centimes entiers affichés à la française, sans jamais passer par un flottant. */
final class MoneyFormatterTest extends TestCase
{
    /** @return iterable<string, array{0: int, 1: string}> */
    public static function amounts(): iterable
    {
        yield 'zéro' => [0, "0,00\u{a0}€"];
        yield 'un centime' => [1, "0,01\u{a0}€"];
        yield 'dix centimes' => [10, "0,10\u{a0}€"];
        yield 'vingt euros' => [2000, "20,00\u{a0}€"];
        yield 'loyer' => [46800, "468,00\u{a0}€"];
        yield 'milliers' => [123456, "1\u{202f}234,56\u{a0}€"];
        yield 'millions' => [123456789, "1\u{202f}234\u{202f}567,89\u{a0}€"];
        yield 'plafond' => [99999999999, "999\u{202f}999\u{202f}999,99\u{a0}€"];
        yield 'négatif' => [-1050, "−10,50\u{a0}€"];
        yield 'négatif de moins d\'un euro' => [-5, "−0,05\u{a0}€"];
    }

    #[Test]
    #[DataProvider('amounts')]
    public function testCentsAreFormattedTheFrenchWay(int $cents, string $expected): void
    {
        self::assertSame($expected, (new MoneyFormatter())->format($cents));
    }

    #[Test]
    public function testASignIsShownOnRequestForReceiptsAndExpenses(): void
    {
        $formatter = new MoneyFormatter();

        self::assertSame("+20,00\u{a0}€", $formatter->format(2000, true));
        self::assertSame("−20,00\u{a0}€", $formatter->format(-2000, true));
        self::assertSame("0,00\u{a0}€", $formatter->format(0, true), 'zéro sans signe');
    }
}
