<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #149 : la piste de la timeline est son propre calque de compositeur (cartes à filtre SVG rastérisées une fois). */
final class PlanningTrackCssTest extends TestCase
{
    #[Test]
    public function testTrackIsPromotedToItsOwnCompositorLayer(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../public/assets/css/pages/dashboard.css');
        $start = strpos($css, '.rb-planning-track {');
        self::assertNotFalse($start);
        $rule = substr($css, $start, strpos($css, '}', $start) - $start);

        self::assertStringContainsString('will-change: transform', $rule);
    }

    #[Test]
    public function testAutoScrollIsACompositorCssAnimationLoopingOnHalfTheTrack(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../public/assets/css/pages/dashboard.css');

        self::assertStringContainsString('@keyframes rb-planning-scroll', $css);
        self::assertMatchesRegularExpression('/@keyframes rb-planning-scroll\s*\{[^}]*translate3d\(0[^}]*\}[^}]*translate3d\(-50%/s', $css, 'De 0 à -50 % : la piste dupliquée boucle sans saut.');
        self::assertMatchesRegularExpression('/\.rb-planning-track--auto\s*\{[^}]*animation:\s*rb-planning-scroll\s+var\(--rb-planning-duration[^}]*linear\s+infinite/s', $css);
        self::assertMatchesRegularExpression('/\.rb-planning-track--paused\s*\{[^}]*animation-play-state:\s*paused/s', $css);
    }
}
