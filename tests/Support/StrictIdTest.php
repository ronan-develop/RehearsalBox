<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\StrictId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StrictIdTest extends TestCase
{
    /** @return iterable<string, array{0: mixed, 1: int}> */
    public static function validProvider(): iterable
    {
        yield 'entier' => [7, 7];
        yield 'chaîne numérique' => ['42', 42];
        yield 'dix chiffres' => ['2147483647', 2147483647];
    }

    #[Test]
    #[DataProvider('validProvider')]
    public function testAcceptsPositiveIntegersAndPlainDigitStrings(mixed $value, int $expected): void
    {
        self::assertSame($expected, StrictId::from($value));
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function invalidProvider(): iterable
    {
        yield 'zéro' => [0];
        yield 'négatif' => [-1];
        yield 'zéro en chaîne' => ['0'];
        yield 'zéros en tête' => ['007'];
        yield 'décimal' => ['1.5'];
        yield 'injection' => ['1 OR 1=1'];
        yield 'espace' => [' 5'];
        yield 'trop long' => ['99999999999'];
        yield 'octet nul' => ["5\x00"];
        yield 'tableau' => [[5]];
        yield 'null' => [null];
        yield 'booléen' => [true];
        yield 'flottant' => [5.0];
    }

    #[Test]
    #[DataProvider('invalidProvider')]
    public function testRefusesAnythingElse(mixed $value): void
    {
        self::assertNull(StrictId::from($value));
    }
}
