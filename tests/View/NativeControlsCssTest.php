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
}
