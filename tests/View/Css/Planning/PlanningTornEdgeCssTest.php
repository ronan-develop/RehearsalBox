<?php

declare(strict_types=1);

namespace App\Tests\View\Css\Planning;

use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #268 : le bord « papier déchiré » des cartes du planning est le MÊME filtre TornPaper qu'avant (bruit, relief, grain), mais il est déclaré
 * dans le HTML par le serveur (six filtres, six graines fixes) et appliqué par CSS (`:nth-child(6n + k)`) : il est donc présent dès le
 * premier affichage, sans attendre le JavaScript, sans repli anguleux ni saut, y compris pour les cartes ajoutées plus tard par le script.
 */
final class PlanningTornEdgeCssTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../../';

    private function css(): string
    {
        return (string) file_get_contents(self::ROOT . 'public/assets/css/pages/dashboard.css');
    }

    private function filtersHtml(): string
    {
        return (new PhpTemplateRenderer(self::ROOT . 'templates'))->render('partials/tornpaper-filters');
    }

    #[Test]
    public function testTheServerDeclaresSixDistinctFiltersWithTheTornPaperSettings(): void
    {
        $html = $this->filtersHtml();

        preg_match_all('/<filter id="tornpaper-([1-6])"/', $html, $ids);
        self::assertSame(['1', '2', '3', '4', '5', '6'], $ids[1]);
        preg_match_all('/seed="(\d+)"/', $html, $seeds);
        self::assertCount(6, array_unique($seeds[1]), 'six graines différentes : les cartes sont variées');
        self::assertSame(6, substr_count($html, 'baseFrequency="0.045"'), 'bord déchiré (réglage d\'origine)');
        self::assertSame(6, substr_count($html, '<feDisplacementMap in="inset" in2="edge_noise" scale="9"'), 'déplacement d\'origine');
        self::assertSame(6, substr_count($html, '<feComposite in="SourceGraphic" in2="edge" operator="in"'), 'le contenu reste net : seul le bord est déplacé');
        self::assertStringNotContainsString('feDiffuseLighting', $html, 'le relief (partie coûteuse) est une image pré-calculée, pas un calcul du navigateur');
        self::assertStringContainsString('aria-hidden="true"', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('style=', $html, 'la politique de sécurité interdit le style en ligne dans les éléments : la mise en forme est dans la feuille de style');
    }

    #[Test]
    public function testThePaperReliefIsAPrecomputedTileableImageFromTheGenerator(): void
    {
        self::assertFileExists(self::ROOT . 'public/assets/img/paper-grain.png');
        self::assertLessThan(120_000, (int) filesize(self::ROOT . 'public/assets/img/paper-grain.png'), 'une petite tuile');
        self::assertSame([256, 256], array_slice((array) getimagesize(self::ROOT . 'public/assets/img/paper-grain.png'), 0, 2));
        self::assertMatchesRegularExpression('/background-image:\s*url\("\/assets\/img\/paper-grain\.png"\);\s*background-size:\s*256px 256px;\s*background-blend-mode:\s*multiply/', $this->css());
        self::assertFileExists(self::ROOT . 'tools/paper-grain.mjs', 'l\'image se régénère');
    }

    #[Test]
    public function testTheDashboardIncludesTheFiltersSoTheyExistBeforeAnyScriptRuns(): void
    {
        self::assertStringContainsString("partials/tornpaper-filters.php", (string) file_get_contents(self::ROOT . 'templates/dashboard/index.php'));
    }

    #[Test]
    public function testEachCardGetsOneOfTheSixFiltersByCssAloneOnDesktopOnly(): void
    {
        $css = $this->css();
        preg_match_all('/\.rb-planning-card:nth-child\(6n\s*\+\s*([1-6])\)\s*\{\s*filter:\s*url\(#tornpaper-\1\);\s*\}/', $css, $rules);
        self::assertSame(['1', '2', '3', '4', '5', '6'], $rules[1]);

        $first = strpos($css, '.rb-planning-card:nth-child(6n + 1)');
        $mediaStart = (int) strrpos(substr($css, 0, $first), '@media');
        $segment = substr($css, $mediaStart, $first - $mediaStart);
        self::assertStringStartsWith('@media (min-width: 768px)', $segment, 'sur ordinateur seulement : la liste mobile reste à plat');
        self::assertSame(1, substr_count($segment, '{') - substr_count($segment, '}'));
        self::assertMatchesRegularExpression('/\.rb-planning-card\s*\{[^}]*clip-path:\s*none/s', $css, 'mobile : aucune découpe');
    }

    #[Test]
    public function testNoAngularFallbackNorScriptIsLeft(): void
    {
        $css = $this->css();

        self::assertStringNotContainsString('polygon(', $css, 'plus de repli anguleux qui apparaîtrait avant le filtre');
        self::assertStringNotContainsString('[style*="filter"]', $css);
        self::assertFileDoesNotExist(self::ROOT . 'public/assets/js/ui/tornpaper.js');
        self::assertFileDoesNotExist(self::ROOT . 'public/assets/js/ui/tornpaper-init.js');
        foreach (['public/assets/js/app.js', 'public/assets/js/planning/dashboard/exceptional-planning.js'] as $script) {
            self::assertStringNotContainsStringIgnoringCase('tornpaper', (string) file_get_contents(self::ROOT . $script), $script);
        }
    }
}
