<?php

declare(strict_types=1);

namespace App\Tests\Presenter;

use App\Presenter\MentionText;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MentionTextTest extends TestCase
{
    #[Test]
    public function testTextWithoutMentionIsASingleSegment(): void
    {
        self::assertSame([['text' => 'Bonjour', 'mention' => false, 'me' => false]], MentionText::segments('Bonjour', [], 1));
        self::assertSame([], MentionText::segments('', [], 1));
    }

    #[Test]
    public function testValidatedMentionsAreSplitOutAndTheViewerIsFlagged(): void
    {
        $segments = MentionText::segments('Salut @Denis et @Bob !', [7 => '@Denis', 8 => '@Bob'], 8);

        self::assertSame([
            ['text' => 'Salut ', 'mention' => false, 'me' => false],
            ['text' => '@Denis', 'mention' => true, 'me' => false],
            ['text' => ' et ', 'mention' => false, 'me' => false],
            ['text' => '@Bob', 'mention' => true, 'me' => true],
            ['text' => ' !', 'mention' => false, 'me' => false],
        ], $segments);
    }

    #[Test]
    public function testAnAtSignThatIsNotAValidatedMentionStaysPlainText(): void
    {
        $segments = MentionText::segments('Écris à @Carole ou @Denis', [7 => '@Denis'], 1);

        self::assertSame('Écris à @Carole ou ', $segments[0]['text']);
        self::assertFalse($segments[0]['mention']);
        self::assertTrue($segments[1]['mention']);
    }

    #[Test]
    public function testAMentionIsNotMatchedInsideALongerName(): void
    {
        $segments = MentionText::segments('Salut @Denise', [7 => '@Denis'], 1);

        self::assertSame([['text' => 'Salut @Denise', 'mention' => false, 'me' => false]], $segments);
    }

    #[Test]
    public function testTheLongestLabelWinsAndRegexCharactersAreLiteral(): void
    {
        $segments = MentionText::segments('@Denis Martin et @A.B(c)', [1 => '@Denis', 2 => '@Denis Martin', 3 => '@A.B(c)'], 9);

        self::assertSame(['@Denis Martin', '@A.B(c)'], array_column(array_filter($segments, static fn ($s) => $s['mention']), 'text'));
    }

    #[Test]
    public function testHtmlInTheTextIsKeptAsDataForTheTemplateToEscape(): void
    {
        $segments = MentionText::segments('<b>@Denis</b>', [7 => '@Denis'], 1);

        self::assertSame('<b>', $segments[0]['text']);
        self::assertSame('</b>', $segments[2]['text']);
    }
}
