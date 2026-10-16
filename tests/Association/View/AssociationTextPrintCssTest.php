<?php

declare(strict_types=1);

namespace App\Tests\Association\View;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #488 : à l'impression, seul le texte reste (titre et corps en noir sur blanc) ; menu, boutons et mention de modification disparaissent. */
final class AssociationTextPrintCssTest extends TestCase
{
    private function printBlock(): string
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../public/assets/css/pages/association-texts.css');
        self::assertMatchesRegularExpression('/@media print\s*\{/', $css);
        $start = (int) strpos($css, '@media print');

        return substr($css, $start);
    }

    #[Test]
    public function testThePrintSheetHidesEverythingButTheText(): void
    {
        $print = $this->printBlock();

        foreach (['.rb-bottom-nav', '.rb-back-link', '.rb-text-view-actions', '.rb-text-meta'] as $selector) {
            self::assertStringContainsString($selector, $print, "{$selector} masqué à l'impression");
        }
        self::assertMatchesRegularExpression('/display:\s*none/', $print);
    }

    #[Test]
    public function testThePrintSheetIsBlackOnWhiteWithoutPrintStampNorBrowserHeaderRoom(): void
    {
        $print = $this->printBlock();
        $css = (string) file_get_contents(__DIR__ . '/../../../public/assets/css/pages/association-texts.css');

        self::assertMatchesRegularExpression('/background:\s*#fff/', $print);
        self::assertMatchesRegularExpression('/color:\s*#000/', $print);
        self::assertStringNotContainsString('print-stamp', $css);
        self::assertMatchesRegularExpression('/@page\s*\{[^}]*margin:\s*0/', $print, 'sans marge de page, pas d\'adresse ni de numéro imprimés par le navigateur');
        self::assertMatchesRegularExpression('/\.rb-admin-page\s*\{[^}]*padding:\s*2cm/', $print, 'la marge est rendue par le contenu');
    }
}
