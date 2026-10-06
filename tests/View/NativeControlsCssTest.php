<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #289 : les contrôles natifs (sélecteur de date, listes) suivent le thème sombre de l'application. */
final class NativeControlsCssTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../public/assets/css/base.css');
    }

    #[Test]
    public function testTheNativeControlsAreDeclaredDarkSoThePickerPopupIsNotWhite(): void
    {
        self::assertMatchesRegularExpression('/:root\s*\{[^}]*color-scheme:\s*dark/s', $this->css());
    }

    #[Test]
    public function testTheNativeControlsUseTheAccentOfThePalette(): void
    {
        self::assertMatchesRegularExpression('/:root\s*\{[^}]*accent-color:\s*var\(--rb-accent\)/s', $this->css());
    }

    #[Test]
    public function testTheDateAndTimeInputsKeepTheirHeightAndTheIconIsReadableOnDark(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-input\[type="date"\][^{]*\{[^}]*min-height:/s', $css);
        self::assertMatchesRegularExpression('/::-webkit-calendar-picker-indicator\s*\{[^}]*cursor:\s*pointer/s', $css);
    }

    #[Test]
    public function testTheSelectsAreDrawnLikeTheOtherFieldsWithAChevronOfTheirOwn(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-select\s*\{[^}]*appearance:\s*none/s', $css);
        self::assertMatchesRegularExpression('/\.rb-select\s*\{[^}]*background-image:\s*url\("data:image\/svg\+xml/s', $css);
        self::assertMatchesRegularExpression('/\.rb-select\s*\{[^}]*padding-right:/s', $css);
        self::assertMatchesRegularExpression('/\.rb-select option\s*\{[^}]*background:\s*var\(--rb-surface\)/s', $css, 'les lignes de la liste gardent le fond sombre');
    }

    #[Test]
    public function testADateFieldNeverOverflowsItsContainerOnIosAndKeepsItsHeightWhenEmpty(): void
    {
        $css = $this->css();

        preg_match('/\.rb-input\[type="date"\][^{]*\{([^}]*)\}/s', $css, $rule);
        $body = $rule[1] ?? '';

        self::assertMatchesRegularExpression('/-webkit-appearance:\s*none/', $body, 'iOS ignore la largeur d\'un champ date natif');
        self::assertMatchesRegularExpression('/appearance:\s*none/', $body);
        self::assertMatchesRegularExpression('/min-width:\s*0/', $body, 'sans largeur minimale intrinsèque');
        self::assertMatchesRegularExpression('/max-width:\s*100%/', $body);
        self::assertMatchesRegularExpression('/min-height:/', $body, 'un champ vide s\'écrase sinon sur iOS');
    }
}
