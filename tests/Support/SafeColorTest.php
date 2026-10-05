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
}
