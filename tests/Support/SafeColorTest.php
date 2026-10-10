<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\SafeColor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SafeColorTest extends TestCase
{
    #[Test]
    public function testAcceptsOnlyExactSixDigitHexColours(): void
    {
        self::assertSame('#aa00FF', SafeColor::from('#aa00FF'));
        self::assertSame('#000000', SafeColor::from('#000000'));
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function invalidProvider(): iterable
    {
        yield 'nom' => ['red'];
        yield 'trois chiffres' => ['#fff'];
        yield 'sans dièse' => ['aa00ff'];
        yield 'injection CSS' => ['#aa0000; background:url(x)'];
        yield 'injection HTML' => ['#aa0000"><script>'];
        yield 'retour à la ligne final' => ["#aa0000\n"];
        yield 'null' => [null];
        yield 'vide' => [''];
    }

    #[Test]
    #[DataProvider('invalidProvider')]
    public function testRefusesAnythingElseSoAStoredColourCanNeverInjectStyleOrMarkup(mixed $value): void
    {
        self::assertNull(SafeColor::from($value));
    }

    #[Test]
    public function testTheDefaultColourIsValidAndIsTheOrangeMoyenOfTheSelectorGrid(): void
    {
        self::assertSame(SafeColor::DEFAULT, SafeColor::from(SafeColor::DEFAULT));
        self::assertSame('#cb824d', SafeColor::DEFAULT, 'même valeur que DEFAULT_COLOR de color-palette.js (test JS)');
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function moreInvalidProvider(): iterable
    {
        yield 'espace devant' => [' #aa00ff'];
        yield 'octet nul' => ["#aa00ff\0"];
        yield 'huit chiffres' => ['#aa00ff80'];
        yield 'chiffres arabes-indiens' => ["#\u{0661}\u{0662}\u{0663}\u{0664}\u{0665}\u{0666}"];
        yield 'tableau' => [['#aa00ff']];
        yield 'nombre' => [123456];
        yield 'très longue' => ['#' . str_repeat('a', 100000)];
        yield 'fonction CSS' => ['rgb(1,2,3)'];
    }

    #[Test]
    #[DataProvider('moreInvalidProvider')]
    public function testRefusesMoreExoticInputs(mixed $value): void
    {
        self::assertNull(SafeColor::from($value));
    }
}
