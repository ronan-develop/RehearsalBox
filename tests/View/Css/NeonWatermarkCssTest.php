<?php

declare(strict_types=1);

namespace App\Tests\View\Css;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #141 : le néon du watermark est statique partout (mobile et desktop) : plus
 * aucune animation de pulsation.
 */
final class NeonWatermarkCssTest extends TestCase
{
    private string $css;

    protected function setUp(): void
    {
        $this->css = (string) file_get_contents(__DIR__ . '/../../../public/assets/css/pages/dashboard.css');
    }

    /** Corps du premier bloc `{ … }` qui suit $selector (accolades imbriquées gérées). */
    private function block(string $css, string $selector): string
    {
        $start = strpos($css, $selector);
        self::assertNotFalse($start, "{$selector} introuvable");
        $open = strpos($css, '{', $start);
        $depth = 0;
        for ($i = $open, $len = strlen($css); $i < $len; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}' && --$depth === 0) {
                return substr($css, $open + 1, $i - $open - 1);
            }
        }
        self::fail("Bloc non fermé pour {$selector}");
    }

    /** CSS sans les at-rules (@media, @keyframes) de premier niveau : il ne reste que les règles de base (mobile). */
    private function baseRules(): string
    {
        $out = '';
        $depth = 0;
        $skipping = false;
        for ($i = 0, $len = strlen($this->css); $i < $len; $i++) {
            $char = $this->css[$i];
            if ($depth === 0 && $char === '@') {
                $skipping = true;
            }
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0 && $skipping) {
                    $skipping = false;
                    continue;
                }
            }
            if (!$skipping) {
                $out .= $char;
            }
        }

        return $out;
    }

    #[Test]
    public function testNeonKeepsItsStaticGlow(): void
    {
        $neon = $this->block($this->baseRules(), '.rb-page-bg-text--neon');

        self::assertStringContainsString('text-shadow', $neon, 'Le néon statique garde sa lueur.');
        self::assertStringContainsString('-webkit-text-stroke', $neon);
        self::assertStringNotContainsString('animation', $neon);
    }

    #[Test]
    public function testNeonStaysDiscreetSoTheTextStaysReadableAndScrollSmooth(): void
    {
        $neon = $this->block($this->baseRules(), '.rb-page-bg-text--neon');

        preg_match('/text-shadow:(.*?);/s', $neon, $shadow);
        self::assertNotEmpty($shadow, 'text-shadow attendu');
        $layers = array_filter(array_map('trim', explode(',', preg_replace('/\([^)]*\)/', '', $shadow[1]) ?? '')));
        self::assertLessThanOrEqual(2, count($layers), 'Au plus deux couches de lueur : coût de rendu et lisibilité.');

        preg_match_all('/0 0 (\d+)px/', $shadow[1], $blurs);
        self::assertNotEmpty($blurs[1]);
        self::assertLessThanOrEqual(10, max(array_map('intval', $blurs[1])), 'Halo court : un halo large est coûteux et éblouissant.');

        preg_match('/-webkit-text-stroke:\s*([\d.]+)px/', $neon, $stroke);
        self::assertLessThanOrEqual(1.5, (float) ($stroke[1] ?? 99), 'Contour fin.');
    }

    #[Test]
    public function testGlowLayerIsDrivenByOpacityNotByRepaintingTheShadow(): void
    {
        $neon = $this->block($this->baseRules(), '.rb-page-bg-text--neon');
        $glow = $this->block($this->baseRules(), '.rb-page-bg-text::after');

        // Couche de lueur forte, dont seule l'opacité varie avec --wm-glow (compositeur).
        self::assertStringContainsString('var(--wm-glow', $glow);
        self::assertMatchesRegularExpression('/opacity:\s*calc\(var\(--wm-glow[^;]*\*\s*0?\.[1-9]/', $glow, 'Intensité maximale atténuée (pas de jaune à 100 %).');
        self::assertStringContainsString('text-shadow', $glow);
        self::assertStringNotContainsString('animation', $glow);
        self::assertStringNotContainsString('transition: text-shadow', $glow);
        // Couche de compositeur propre : l'opacité varie sans repeindre le texte ni son ombre (#143).
        self::assertStringContainsString('will-change: opacity', $glow);

        preg_match_all('/0 0 (\d+)px/', $glow, $glowBlurs);
        preg_match_all('/0 0 (\d+)px/', $neon, $neonBlurs);
        self::assertGreaterThan(max(array_map('intval', $neonBlurs[1])), max(array_map('intval', $glowBlurs[1])), 'La couche de lueur est plus brillante que le néon discret.');
        self::assertLessThanOrEqual(16, max(array_map('intval', $glowBlurs[1])), 'Halo contenu : il ne doit pas baver sur le texte.');
    }

    #[Test]
    public function testGlowLayerKeepsTheRedInsideTheLetters(): void
    {
        $glow = $this->block($this->baseRules(), '.rb-page-bg-text::after');

        // Un texte transparent laisserait l'ombre jaune recouvrir l'intérieur des lettres.
        self::assertStringNotContainsString('color: transparent', $glow);
        self::assertMatchesRegularExpression('/color:\s*(var\(--rb-neon-red\)|color-mix\([^;]*var\(--rb-neon-red\)[^;]*\))/', $glow, 'Rouge pétant (jeton --rb-neon-red) à l\'intérieur des lettres.');
        // Un soupçon de blanc seulement : le cœur reste franchement rouge.
        if (preg_match('/color-mix\([^;]*?(\d+)%[^;]*white/', $glow, $mix) === 1) {
            self::assertGreaterThanOrEqual(75, (int) $mix[1], 'Au plus 25 % de blanc : rouge pétant, pas rose.');
        }
    }

    #[Test]
    public function testReducedMotionKeepsTheWatermarkInsideTheHeaderFrame(): void
    {
        // Mouvement réduit : pas d'animation, mais le décalage de départ (dans le
        // cadre du header) doit s'appliquer : le transform n'est plus supprimé (#145).
        // Le bloc peut ne plus exister ; s'il existe, il ne touche pas au transform du watermark.
        if (str_contains($this->css, '@media (prefers-reduced-motion: reduce)')) {
            $reduced = $this->block($this->css, '@media (prefers-reduced-motion: reduce)');
            self::assertStringNotContainsString('transform: none', $reduced);
            self::assertStringNotContainsString('animation', $reduced);
        }
        self::assertStringNotContainsString('.rb-page-bg-text {' . "\n" . '    transform: none', $this->css);
    }

    #[Test]
    public function testTheLogoFollowsTheScrollDirectlyWithNoSmoothingTransition(): void
    {
        // La migration vers la barre du haut est pilotée par le scroll (#201) : une transition de transform la ferait traîner derrière le doigt.
        $logo = $this->block($this->baseRules(), '.rb-page-bg-text {');
        self::assertStringNotContainsString('transition: transform', $logo);
        self::assertStringNotContainsString('--smooth', $this->css);
        self::assertStringContainsString('position: fixed', $logo, 'fixe : il migre à l\'écran');
        self::assertMatchesRegularExpression('/z-index:\s*(\d+)/', $logo, 'au-dessus du panneau de l\'en-tête, qui le ternirait sinon');
        self::assertStringContainsString('visibility: hidden', $logo, 'invisible tant que le JavaScript ne l\'a pas placé');
        self::assertStringContainsString('.rb-page-bg-text--placed', $this->css);
    }

    #[Test]
    public function testThereIsNoPulseAnimationAnywhere(): void
    {
        self::assertStringNotContainsString('@keyframes rb-page-bg', $this->css, 'Aucune animation du watermark (la timeline a la sienne : rb-planning-scroll).');
        self::assertStringNotContainsString('rb-page-bg-text-flame', $this->css);
        self::assertStringNotContainsString('animation: rb-page-bg-text', $this->css);
    }
}
