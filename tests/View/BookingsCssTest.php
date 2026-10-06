<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #263 : mise en forme de la page « Réserver le local ». */
final class BookingsCssTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../public/assets/css/pages/bookings.css');
    }

    #[Test]
    public function testTheCustomElementsAreBlocksLaidOutInAColumnWithBreathingRoom(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/rb-booking-form,\s*rb-booking-item\s*\{[^}]*display:\s*block/s', $css, 'un élément personnalisé est « inline » par défaut');
        self::assertMatchesRegularExpression('/\.rb-booking-form\s*\{[^}]*padding:\s*var\(--rb-space-/s', $css);
        self::assertMatchesRegularExpression('/\.rb-bookings-page\s*\{[^}]*max-width:/s', $css);
        self::assertMatchesRegularExpression('/\.rb-booking-items\s*\{[^}]*display:\s*grid[^}]*gap:\s*var\(--rb-space-/s', $css);
    }

    #[Test]
    public function testTheTouchTargetsAreAtLeastFortyFourPixelsAndTheMainButtonIsFullWidth(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-booking-form \.rb-input\s*\{[^}]*min-height:\s*44px/s', $css);
        self::assertMatchesRegularExpression('/\.rb-booking-submit\s*\{[^}]*width:\s*100%[^}]*min-height:\s*44px/s', $css);
    }

    #[Test]
    public function testNothingEverHidesBehindTheFixedBottomMenuWhenFocusedOrScrolledTo(): void
    {
        self::assertMatchesRegularExpression('/\.rb-booking-form \.rb-input,[^{]*\.rb-booking-submit[^{]*\{[^}]*scroll-margin-bottom:\s*\d+px/s', $this->css(), 'le menu fixe du bas ne doit pas masquer un champ ou le bouton (focus clavier, défilement)');
    }

    #[Test]
    public function testTheTimesSitSideBySideAndTheHiddenListsStayHidden(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-booking-form-times\s*\{[^}]*display:\s*grid[^}]*grid-template-columns:\s*1fr 1fr/s', $css);
        self::assertMatchesRegularExpression('/\.rb-booking-plan\[hidden\],\s*\.rb-booking-result\[hidden\]\s*\{[^}]*display:\s*none/s', $css, 'display: grid/flex ne doit pas écraser [hidden]');
    }

    #[Test]
    public function testNothingIsConveyedByColourAloneTheStateIsAlwaysWritten(): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../templates/bookings/index.php');

        self::assertStringContainsString('statusLabel', $template, 'l\'état est un texte, la couleur ne fait que le souligner');
        self::assertMatchesRegularExpression('/\.rb-booking-plan-line--request\s*\{[^}]*border-left:/s', $this->css(), 'un trait en plus de la couleur');
    }

    #[Test]
    public function testTheTimePickerLaysOutHoursColonMinutesInOneRowAndIsABlockElement(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/rb-time-picker\s*\{[^}]*display:\s*flex[^}]*align-items:\s*center/s', $css, 'un élément personnalisé est « inline » par défaut');
        self::assertMatchesRegularExpression('/\.rb-time-picker \.rb-select\s*\{[^}]*flex:\s*1[^}]*min-width:\s*0/s', $css, 'les deux listes se partagent la largeur sans déborder');
    }

    #[Test]
    public function testTheTwoPickersFitTheCardOnAPhoneAndLeaveRoomForTheDigits(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-booking-form-times\s*>\s*\.rb-field\s*\{[^}]*min-width:\s*0/s', $css, 'une colonne de grille ne doit pas dépasser la carte');
        self::assertMatchesRegularExpression('/\.rb-time-picker \.rb-select\s*\{[^}]*padding:\s*0\s+1\.75rem\s+0\s+0\.75rem/s', $css, 'peu de marge : les chiffres doivent rester visibles dans une liste étroite');
    }

    #[Test]
    public function testOnANarrowPhoneTheStartAndEndPickersAreStackedSoNoDigitIsClipped(): void
    {
        self::assertMatchesRegularExpression('/@media\s*\(max-width:\s*\d+px\)\s*\{[^@]*\.rb-booking-form-times\s*\{[^}]*grid-template-columns:\s*1fr\s*;/s', $this->css());
    }
}
