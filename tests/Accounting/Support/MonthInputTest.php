<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Support;

use App\Accounting\Support\MonthInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Un mois AAAA-MM n'est lu qu'à la lettre : le premier jour du mois, sinon null. */
final class MonthInputTest extends TestCase
{
    #[Test]
    public function testAValidMonthGivesItsFirstDay(): void
    {
        self::assertSame('2025-11-01', MonthInput::parse('2025-11')?->format('Y-m-d'));
        self::assertSame('1900-01-01', MonthInput::parse('1900-01')?->format('Y-m-d'));
        self::assertSame('2999-12-01', MonthInput::parse('2999-12')?->format('Y-m-d'));
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function invalid(): iterable
    {
        foreach (['', '2025', '202511', '2025-1', '2025-13', '2025-00', '2025-11-01', ' 2025-11', "2025-11\n", '2025/11', 'novembre', '1899-12', '3000-01', 'abcd-ef'] as $text) {
            yield "« {$text} »" => [$text];
        }
        yield 'null' => [null];
        yield 'entier' => [202511];
        yield 'tableau' => [['2025-11']];
        yield 'booléen' => [true];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function testAnythingElseIsRefused(mixed $value): void
    {
        self::assertNull(MonthInput::parse($value));
    }
}
