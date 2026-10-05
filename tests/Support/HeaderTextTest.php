<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\HeaderText;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HeaderTextTest extends TestCase
{
    #[Test]
    public function testControlAndFormattingCharactersBecomeASingleSpace(): void
    {
        self::assertSame('Alpha Bcc: x@y.test', HeaderText::oneLine("Alpha\r\nBcc: x@y.test"));
        self::assertSame('a b', HeaderText::oneLine("a\u{202E}\u{2028}b"));
        self::assertSame('Alpha', HeaderText::oneLine("  Alpha \x00 "));
    }

    #[Test]
    public function testTheTextIsCutToAReasonableLength(): void
    {
        self::assertSame(100, mb_strlen(HeaderText::oneLine(str_repeat('é', 500))));
        self::assertSame('abc', HeaderText::oneLine('abcdef', 3));
    }
}
