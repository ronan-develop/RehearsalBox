<?php

declare(strict_types=1);

namespace App\Tests\View\Css\Planning;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #268 : le bord « papier déchiré » des cartes du planning est PRÉ-CALCULÉ en CSS (quelques variantes de `clip-path: polygon()` générées hors
 * ligne par tools/torn-edges.php) : présent dès le premier affichage, sans saut, sans filtre SVG recalculé par carte ni JavaScript.
 */
final class PlanningTornEdgeCssTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../../';

    private function css(): string
    {
        return (string) file_get_contents(self::ROOT . 'public/assets/css/pages/dashboard.css');
    }

    /** @return array<int, string> numéro de variante => liste de points du polygone */
    private function variants(): array
    {
        preg_match_all('/\.rb-planning-card:nth-child\(6n\s*\+\s*([1-6])\)\s*\{\s*clip-path:\s*polygon\(([^)]*)\)/s', $this->css(), $matches, PREG_SET_ORDER);
        $variants = [];
        foreach ($matches as $match) {
            $variants[(int) $match[1]] = $match[2];
        }

        return $variants;
    }

    #[Test]
    public function testSixDistinctVariantsAreAssignedByCssAlone(): void
    {
        $variants = $this->variants();

        $numbers = array_keys($variants);
        sort($numbers);
        self::assertSame([1, 2, 3, 4, 5, 6], $numbers);
        self::assertCount(6, array_unique(array_map(static fn (string $p): string => preg_replace('/\s+/', '', $p), $variants)), 'six découpes différentes : les cartes sont variées');
    }

    #[Test]
    public function testEachVariantIsFinelyCutAndNeverLeavesTheBox(): void
    {
        foreach ($this->variants() as $number => $polygon) {
            self::assertDoesNotMatchRegularExpression('/,\s*\z/', $polygon, "variante $number : une virgule finale rendrait le polygone invalide, donc ignoré");
            self::assertDoesNotMatchRegularExpression('/,\s*,/', $polygon, "variante $number : pas de point vide");
            preg_match_all('/(-?\d+(?:\.\d+)?)%\s+(-?\d+(?:\.\d+)?)%/', $polygon, $points, PREG_SET_ORDER);

            self::assertGreaterThanOrEqual(64, count($points), "variante $number : un bord assez fin pour paraître déchiré");
            foreach ($points as $point) {
                self::assertGreaterThanOrEqual(0.0, (float) $point[1], "variante $number : x dans la carte");
                self::assertLessThanOrEqual(100.0, (float) $point[1], "variante $number : x dans la carte");
                self::assertGreaterThanOrEqual(0.0, (float) $point[2], "variante $number : y dans la carte");
                self::assertLessThanOrEqual(100.0, (float) $point[2], "variante $number : y dans la carte");
            }
        }
    }

    #[Test]
    public function testTheVariantsOnlyApplyOnDesktopSoTheMobileListStaysFlat(): void
    {
        $css = $this->css();
        $firstVariant = strpos($css, '.rb-planning-card:nth-child(6n + 1)');
        self::assertNotFalse($firstVariant);

        $mediaStart = (int) strrpos(substr($css, 0, $firstVariant), '@media');
        $segment = substr($css, $mediaStart, $firstVariant - $mediaStart);
        self::assertStringStartsWith('@media (min-width: 768px)', $segment, 'les variantes sont dans le bloc ordinateur');
        self::assertSame(1, substr_count($segment, '{') - substr_count($segment, '}'), 'et ce bloc est encore ouvert à la première variante');
        self::assertMatchesRegularExpression('/\.rb-planning-card\s*\{[^}]*clip-path:\s*none/s', $css, 'sur mobile, la carte reste sans découpe');
    }

    #[Test]
    public function testNoScriptNorSvgFilterIsNeededAnymore(): void
    {
        self::assertStringNotContainsString('[style*="filter"]', $this->css());
        self::assertFileDoesNotExist(self::ROOT . 'public/assets/js/ui/tornpaper.js');
        self::assertFileDoesNotExist(self::ROOT . 'public/assets/js/ui/tornpaper-init.js');
        foreach (['public/assets/js/app.js', 'public/assets/js/planning/dashboard/exceptional-planning.js'] as $script) {
            self::assertStringNotContainsStringIgnoringCase('tornpaper', (string) file_get_contents(self::ROOT . $script), $script . ' n\'appelle plus l\'effet');
        }
    }

    #[Test]
    public function testTheVariantsComeFromTheGeneratorAndTheCssSaysSo(): void
    {
        self::assertFileExists(self::ROOT . 'tools/torn-edges.php');
        self::assertStringContainsString('tools/torn-edges.php', $this->css(), 'le CSS renvoie à son générateur');
    }
}
