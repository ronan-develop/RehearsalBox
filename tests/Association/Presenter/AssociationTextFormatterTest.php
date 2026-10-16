<?php

declare(strict_types=1);

namespace App\Tests\Association\Presenter;

use App\Association\Presenter\AssociationTextFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #490, #492 : « ## » sous-titre (le titre du texte a son propre champ), « - » élément de liste ; le reste est du texte. Chaque ligne est échappée AVANT d'être enveloppée : aucun HTML saisi n'est interprété. */
final class AssociationTextFormatterTest extends TestCase
{
    #[Test]
    public function testSubheadingsGetTheirOwnTagAndTextStaysInParagraphs(): void
    {
        $html = (new AssociationTextFormatter())->html("## Statuts\nArticle 1\nsuite\n\n## Article 2\nTexte");

        self::assertSame(
            '<h2 class="rb-text-subheading">Statuts</h2><p>Article 1<br>suite</p><h2 class="rb-text-subheading">Article 2</h2><p>Texte</p>',
            $html,
        );
    }

    #[Test]
    public function testConsecutiveDashLinesFormOneBulletList(): void
    {
        $html = (new AssociationTextFormatter())->html("L'association se compose de :\n- Membres d'honneur.\n- Membres bienfaiteurs.\n- Membres actifs.\n\nSuite");

        self::assertSame(
            '<p>L&#039;association se compose de :</p><ul class="rb-text-list"><li>Membres d&#039;honneur.</li><li>Membres bienfaiteurs.</li><li>Membres actifs.</li></ul><p>Suite</p>',
            $html,
        );
    }

    #[Test]
    public function testAListEndsAtABlankLineASubtitleOrPlainText(): void
    {
        $formatter = new AssociationTextFormatter();

        self::assertSame('<ul class="rb-text-list"><li>a</li></ul><ul class="rb-text-list"><li>b</li></ul>', $formatter->html("- a\n\n- b"));
        self::assertSame('<ul class="rb-text-list"><li>a</li></ul><h2 class="rb-text-subheading">T</h2><ul class="rb-text-list"><li>b</li></ul>', $formatter->html("- a\n## T\n- b"));
        self::assertSame('<ul class="rb-text-list"><li>a</li></ul><p>texte</p>', $formatter->html("- a\ntexte"));
        self::assertSame('<p>texte</p><ul class="rb-text-list"><li>a</li></ul>', $formatter->html("texte\n- a"));
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
        yield 'un seul dièse : du texte' => ['# Titre', '<p># Titre</p>'];
        yield 'deux dièses collés au mot' => ['##Titre', '<p>##Titre</p>'];
        yield 'dièse au milieu' => ['a # b', '<p>a # b</p>'];
        yield 'dièses seuls' => ['##', '<p>##</p>'];
        yield 'dièses et espace seuls' => ['## ', '<p>##</p>'];
        yield 'tiret collé au mot' => ['-x', '<p>-x</p>'];
        yield 'tiret seul' => ['-', '<p>-</p>'];
        yield 'tiret et espace seuls' => ['- ', '<p>-</p>'];
        yield 'tiret au milieu' => ['a - b', '<p>a - b</p>'];
        yield 'trois dièses' => ['### x', '<p>### x</p>'];
        yield 'espace avant' => [' ## x', '<p>## x</p>'];
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
        $html = (new AssociationTextFormatter())->html("## <script>x</script>\n## <b onclick=\"a\">y</b>\n- <img src=x onerror=alert(1)>");

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<b ', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    #[Test]
    public function testWindowsLineEndingsAreTreatedLikeNewlines(): void
    {
        self::assertSame('<h2 class="rb-text-subheading">T</h2><p>a<br>b</p>', (new AssociationTextFormatter())->html("## T\r\na\r\nb"));
    }
}
