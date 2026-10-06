<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #249 : sur mobile le logo occupe la rangée du bas ; un nom de groupe très long ne doit jamais venir le recouvrir. */
final class DashboardHeaderCssTest extends TestCase
{
    #[Test]
    public function testALongGroupNameIsTruncatedInsteadOfCoveringTheLogoOnPhones(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../public/assets/css/pages/dashboard.css');
        $start = strpos($css, '.rb-dashboard-header-user > span:first-child');
        self::assertNotFalse($start, 'règle du nom de groupe dans l\'en-tête');
        $rule = substr($css, $start, strpos($css, '}', $start) - $start);

        self::assertStringContainsString('text-overflow: ellipsis', $rule);
        self::assertStringContainsString('max-width:', $rule);
        self::assertStringContainsString('white-space: nowrap', $rule);
    }
}
