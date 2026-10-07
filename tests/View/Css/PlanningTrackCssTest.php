<?php

declare(strict_types=1);

namespace App\Tests\View\Css;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #201 : les créneaux fixes et occasionnels ne défilent plus tout seuls ; le carrousel de bureau se parcourt à la main. */
final class PlanningTrackCssTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/assets/css/pages/dashboard.css');
    }

    #[Test]
    public function testThePlanningNeverScrollsByItself(): void
    {
        $css = $this->css();

        self::assertStringNotContainsString('@keyframes rb-planning-scroll', $css);
        self::assertStringNotContainsString('rb-planning-track--auto', $css);
        self::assertStringNotContainsString('rb-planning-loop-copy', $css);
        self::assertStringNotContainsString('rb-planning-scroll', $css);
    }

    #[Test]
    public function testTheDesktopCarouselIsScrolledByHandWithSnapping(): void
    {
        $css = $this->css();
        $start = strpos($css, "\n.rb-planning-slider {");
        self::assertNotFalse($start);
        $rule = substr($css, $start, strpos($css, '}', $start) - $start);

        self::assertStringContainsString('overflow-x: auto', $rule);
        self::assertStringContainsString('scroll-snap-type: x', $rule);
    }
}
