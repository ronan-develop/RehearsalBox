<?php

declare(strict_types=1);

namespace App\Tests\View;

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
        $this->css = (string) file_get_contents(__DIR__ . '/../../public/assets/css/pages/dashboard.css');
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
    public function testThereIsNoPulseAnimationAnywhere(): void
    {
        self::assertStringNotContainsString('@keyframes', $this->css);
        self::assertStringNotContainsString('rb-page-bg-text-flame', $this->css);
        self::assertStringNotContainsString('animation: rb-page-bg-text', $this->css);
    }
}
