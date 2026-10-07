<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #263 : mise en forme de la page « Réservations » et de la pastille du menu administrateur. */
final class AdminBookingsCssTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../public/assets/css/pages/admin.css') . (string) file_get_contents(__DIR__ . '/../../public/assets/css/base.css');
    }

    #[Test]
    public function testTheCustomElementIsABlockLaidOutInAColumnWithBreathingRoom(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/rb-booking-card\s*\{[^}]*display:\s*block/s', $css, 'un élément personnalisé est « inline » par défaut');
        self::assertMatchesRegularExpression('/\.rb-booking-list\s*\{[^}]*display:\s*(grid|flex)[^}]*gap:\s*var\(--rb-space-/s', $css);
        self::assertMatchesRegularExpression('/\.rb-booking-card-actions\s*\{[^}]*display:\s*flex[^}]*gap:/s', $css);
    }

    #[Test]
    public function testTheRefusalPanelIsHiddenByTheHiddenAttributeAndNeverForcedOpen(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-booking-card-refusal\[hidden\]\s*\{[^}]*display:\s*none/s', $css, 'display: grid/flex ne doit pas écraser [hidden]');
    }

    #[Test]
    public function testTheMenuBadgeSitsNextToTheLabelAndStaysHiddenWhenEmpty(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-bottom-nav-badge\s*\{[^}]*margin-left:/s', $css);
        self::assertMatchesRegularExpression('/\.rb-badge\[hidden\]\s*\{[^}]*display:\s*none/s', $css, 'règle globale du badge masqué (#322)');
    }
}
