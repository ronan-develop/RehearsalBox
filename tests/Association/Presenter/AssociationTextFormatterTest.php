<?php

declare(strict_types=1);

namespace App\Tests\Association\Presenter;

use App\Association\Presenter\AssociationTextFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #490 : « # » titre, « ## » sous-titre ; le reste est du texte. Chaque ligne est échappée AVANT d'être enveloppée : aucun HTML saisi n'est interprété. */
final class AssociationTextFormatterTest extends TestCase
{
    #[Test]
    public function testHeadingsAndSubheadingsGetTheirOwnTagsAndTextStaysInParagraphs(): void
    {
        $html = (new AssociationTextFormatter())->html("# Statuts\nArticle 1\nsuite\n\n## Article 2\nTexte");

        self::assertSame(
            '<h2 class="rb-text-heading">Statuts</h2><p>Article 1<br>suite</p><h3 class="rb-text-subheading">Article 2</h3><p>Texte</p>',
            $html,
        );
    }

    #[Test]
    public function testBlankLinesSeparateParagraphsAndAreNeverRepeated(): void
    {
        self::assertSame('<p>a</p><p>b</p>', (new AssociationTextFormatter())->html("a\n\n\n\nb"));
        self::assertSame('', (new AssociationTextFormatter())->html(''));
        self::assertSame('', (new AssociationTextFormatter())->html("\n\n"));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function notHeadings(): iterable
    {
        yield 'dièse collé au mot' => ['#Titre', '<p>#Titre</p>'];
        yield 'dièse au milieu' => ['a # b', '<p>a # b</p>'];
        yield 'dièse seul' => ['#', '<p>#</p>'];
        yield 'dièse et espace seuls' => ['# ', '<p>#</p>'];
        yield 'trois dièses' => ['### x', '<p>### x</p>'];
        yield 'espace avant' => [' # x', '<p># x</p>'];
    }

    #[Test]
    #[DataProvider('notHeadings')]
    public function testOnlyAWellFormedMarkerAtTheStartOfALineMakesAHeading(string $line, string $expected): void
    {
        self::assertSame($expected, (new AssociationTextFormatter())->html($line));
    }

    #[Test]
    public function testEnteredHtmlIsEscapedInHeadingsAndParagraphsAlike(): void
    {
        $html = (new AssociationTextFormatter())->html("# <script>x</script>\n## <b onclick=\"a\">y</b>\n<img src=x onerror=alert(1)>");

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<b ', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    #[Test]
    public function testWindowsLineEndingsAreTreatedLikeNewlines(): void
    {
        self::assertSame('<h2 class="rb-text-heading">T</h2><p>a<br>b</p>', (new AssociationTextFormatter())->html("# T\r\na\r\nb"));
    }
}
