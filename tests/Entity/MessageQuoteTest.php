<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\MessageQuote;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MessageQuoteTest extends TestCase
{
    #[Test]
    public function testItKeepsWhatIsNeededToShowTheQuote(): void
    {
        $quote = new MessageQuote(12, 'Bob', 'Jeudi à 20h ?');

        self::assertSame(12, $quote->messageId());
        self::assertSame('Bob', $quote->authorName());
        self::assertSame('Jeudi à 20h ?', $quote->excerpt());
    }

    #[Test]
    public function testALongTextIsCutAtAHundredCharactersWithAnEllipsis(): void
    {
        $quote = new MessageQuote(1, 'Bob', str_repeat('a', 100) . 'SUITE');

        self::assertSame(str_repeat('a', 100) . '…', $quote->excerpt());
        self::assertSame('abc', (new MessageQuote(1, 'Bob', 'abc'))->excerpt(), 'un texte court reste intact');
    }

    #[Test]
    public function testTheCutNeverSplitsAMultibyteCharacter(): void
    {
        $excerpt = (new MessageQuote(1, 'Bob', str_repeat('é', 150)))->excerpt();

        self::assertSame(str_repeat('é', 100) . '…', $excerpt);
        self::assertTrue(mb_check_encoding($excerpt, 'UTF-8'));
    }

    #[Test]
    public function testLineBreaksAndRunsOfSpacesBecomeSingleSpaces(): void
    {
        $quote = new MessageQuote(1, 'Bob', "Salut\n\n  tout   le monde\r\n");

        self::assertSame('Salut tout le monde', $quote->excerpt());
    }
}
