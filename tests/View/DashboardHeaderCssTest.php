<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #249 : sur mobile le nom du groupe passe devant le logo et dispose de beaucoup de place ; seul un nom très long est tronqué. */
final class DashboardHeaderCssTest extends TestCase
{
    #[Test]
    public function testAVeryLongGroupNameIsTruncatedAndStaysReadableOverTheLogoOnPhones(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../public/assets/css/pages/dashboard.css');
        $start = strpos($css, '.rb-dashboard-header-user > span:first-child');
        self::assertNotFalse($start, 'règle du nom de groupe dans l\'en-tête');
        $rule = substr($css, $start, strpos($css, '}', $start) - $start);

        self::assertStringContainsString('text-overflow: ellipsis', $rule);
        self::assertStringContainsString('max-width:', $rule);
        self::assertStringContainsString('white-space: nowrap', $rule);
        self::assertStringContainsString('text-shadow', $rule, 'halo sombre : lisible quand le nom croise le logo');
        preg_match('/max-width:\s*(\d+)vw/', $rule, $width);
        self::assertGreaterThanOrEqual(50, (int) ($width[1] ?? 0), 'beaucoup de place pour le nom du groupe');
    }

    /** @return string corps de la règle (premier bloc après $selector, début de ligne) */
    private function rule(string $css, string $selector): string
    {
        $start = strpos($css, "\n" . $selector . ' {');
        self::assertNotFalse($start, "règle {$selector} introuvable");

        return substr($css, $start, strpos($css, '}', $start) - $start);
    }

    #[Test]
    public function testTheGroupNameAndAvatarAreDrawnInFrontOfTheLogo(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../public/assets/css/pages/dashboard.css');

        preg_match('/z-index:\s*(\d+)/', $this->rule($css, '.rb-page-bg-text'), $logo);
        preg_match('/z-index:\s*(\d+)/', $this->rule($css, '.rb-dashboard-header-user'), $user);

        self::assertNotEmpty($logo, 'z-index du logo');
        self::assertNotEmpty($user, 'z-index du nom et de l\'avatar');
        self::assertGreaterThan((int) $logo[1], (int) $user[1], 'le nom du groupe passe devant le logo');
        self::assertStringContainsString('position: relative', $this->rule($css, '.rb-dashboard-header-user'));
    }

    #[Test]
    public function testTheHeaderDoesNotIsolateItsStackingContextOtherwiseNothingInsideCouldOvertakeTheLogo(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../public/assets/css/pages/dashboard.css');

        self::assertStringContainsString('isolation: auto', $this->rule($css, '.rb-dashboard-header'));
    }
}
