<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #252 : sur bureau, la timeline des 7 jours tient toujours dans la largeur de l'écran (cartes fluides plafonnées). Le carrousel
 * des créneaux exceptionnels et le mobile (liste) ne changent pas.
 */
final class PlanningFluidTimelineCssTest extends TestCase
{
    private const DESKTOP_QUERY = '@media (min-width: 960px)';

    private string $css;

    protected function setUp(): void
    {
        $this->css = (string) file_get_contents(__DIR__ . '/../../public/assets/css/pages/dashboard.css');
    }

    /** Corps du bloc @media bureau consacré à la timeline fluide (accolades imbriquées gérées). */
    private function desktopBlock(): string
    {
        $start = strpos($this->css, self::DESKTOP_QUERY . ' { /* timeline fluide');
        self::assertNotFalse($start, 'bloc bureau de la timeline fluide introuvable');
        $open = strpos($this->css, '{', $start);
        $depth = 0;
        for ($i = $open, $len = strlen($this->css); $i < $len; $i++) {
            if ($this->css[$i] === '{') {
                $depth++;
            } elseif ($this->css[$i] === '}' && --$depth === 0) {
                return substr($this->css, $open + 1, $i - $open - 1);
            }
        }
        self::fail('bloc non fermé');
    }

    private function rule(string $css, string $selector): string
    {
        $start = strpos($css, $selector . ' {');
        self::assertNotFalse($start, "règle {$selector} introuvable");

        return substr($css, $start, strpos($css, '}', $start) - $start);
    }

    #[Test]
    public function testTheSevenDaysShareTheWidthInOneRowCappedAtTheCardWidth(): void
    {
        $track = $this->rule($this->desktopBlock(), '.rb-planning-slider:not(.rb-planning-slider--exceptional) .rb-planning-track');

        self::assertStringContainsString('display: grid', $track);
        self::assertStringContainsString('grid-auto-flow: column', $track);
        self::assertStringContainsString('grid-auto-columns: minmax(0, 204px)', $track, 'les colonnes rétrécissent, jamais au-delà de 204 px');
        self::assertStringContainsString('width: 100%', $track);
        self::assertStringContainsString('justify-content: center', $track);
    }

    #[Test]
    public function testTheFluidCardsDropTheirFixedWidthAndScaleTheirText(): void
    {
        $card = $this->rule($this->desktopBlock(), '.rb-planning-slider:not(.rb-planning-slider--exceptional) .rb-planning-card');

        self::assertStringContainsString('width: auto', $card);
        self::assertStringContainsString('min-width: 0', $card);

        $block = $this->desktopBlock();
        self::assertMatchesRegularExpression('/\.rb-planning-card-group\s*\{[^}]*font-size:\s*clamp\(/s', $block, 'texte proportionnel à la largeur');
        self::assertMatchesRegularExpression('/\.rb-planning-card-time\s*\{[^}]*font-size:\s*clamp\(/s', $block);
    }

    #[Test]
    public function testTheExceptionalCarouselKeepsItsFixedWidthCardsAndManualScrolling(): void
    {
        // Le sélecteur n'apparaît que sous la forme :not(.rb-planning-slider--exceptional) : seule la timeline fixe est concernée.
        self::assertDoesNotMatchRegularExpression('/(?<!:not\()\.rb-planning-slider--exceptional/', $this->desktopBlock());
    }

    #[Test]
    public function testNoFluidRuleLeaksOutsideTheDesktopQuery(): void
    {
        $outside = str_replace($this->desktopBlock(), '', $this->css);

        self::assertStringNotContainsString('grid-auto-columns: minmax(0, 204px)', $outside, 'mobile et tablette inchangés');
    }
}
