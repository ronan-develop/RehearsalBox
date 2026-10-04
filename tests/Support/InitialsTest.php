<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Initials;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class InitialsTest extends TestCase
{
    #[Test]
    public function testInitialsFromTwoWordsTakesFirstLetterOfEachWord(): void
    {
        self::assertSame('JD', Initials::from('Jean Dupont'));
    }

    #[Test]
    public function testInitialsFromSingleWordTakesFirstTwoLetters(): void
    {
        self::assertSame('JE', Initials::from('Jean'));
    }

    #[Test]
    public function testInitialsFromMoreThanTwoWordsUsesOnlyTheFirstTwo(): void
    {
        self::assertSame('JP', Initials::from('Jean Paul Dupont'));
    }

    #[Test]
    public function testInitialsAreUppercased(): void
    {
        self::assertSame('JD', Initials::from('jean dupont'));
    }

    #[Test]
    public function testInitialsFromNameWithExtraWhitespaceIgnoresExtraSeparators(): void
    {
        self::assertSame('JD', Initials::from('  Jean   Dupont  '));
    }
}
