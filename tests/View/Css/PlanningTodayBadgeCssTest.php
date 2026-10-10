<?php

declare(strict_types=1);

namespace App\Tests\View\Css;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #271 : sur ordinateur, les cartes du jour courant portent un badge « Aujourd'hui » ; sur mobile, c'est déjà le titre du jour. */
final class PlanningTodayBadgeCssTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/assets/css/pages/dashboard.css');
    }

    #[Test]
    public function testTheTodayBadgeStaysInTheFlowAboveTheGroupNameSoItNeverCoversIt(): void
    {
        $css = $this->css();
        $start = strpos($css, "\n.rb-planning-card-today {");
        self::assertNotFalse($start);
        $rule = substr($css, $start, strpos($css, '}', $start) - $start);

        self::assertStringNotContainsString('position: absolute', $rule, 'plus de superposition avec le nom du groupe');
        self::assertStringContainsString('width: fit-content', $rule);
    }

    #[Test]
    public function testTheBadgeIsTiltedAndBlendedIntoThePaperAndTheTornEdgeIsUntouched(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-planning-card-today\s*\{[^}]*transform:\s*rotate\(-4deg\)[^}]*mix-blend-mode:\s*multiply/s', $css, 'posé de travers, fondu dans le papier');
        self::assertDoesNotMatchRegularExpression('/\.rb-planning-card\[data-today\]\s*\{/', $css, 'aucune règle sur la carte elle-même : le bord papier déchiré (#268) n\'est pas touché');
    }

    #[Test]
    public function testTheBadgeTakesItsLookFromTheSharedBadgeClassNotFromDeclarationsThatWouldBeOverridden(): void
    {
        $css = $this->css();
        $start = strpos($css, "\n.rb-planning-card-today {");
        $rule = substr($css, $start, strpos($css, '}', $start) - $start);

        foreach (['background', 'border', 'color:', 'font-size', 'font-weight', 'padding', 'margin', 'display'] as $overridden) {
            self::assertStringNotContainsString($overridden, $rule, "« $overridden » vient de .rb-badge (déclaré plus bas : une copie ici serait sans effet)");
        }
    }

    #[Test]
    public function testOnMobileTheBadgeIsHiddenBecauseTheDayHeadingAlreadySaysIt(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/@media \(max-width: 767\.98px\)\s*\{[^@]*\.rb-planning-card-today\s*\{[^}]*display:\s*none/s', $css);
    }
}
