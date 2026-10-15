<?php

declare(strict_types=1);

namespace App\Tests\Assembly\View;

use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #361 : gabarit imprimable du compte rendu d'AG et sa feuille de style d'impression (page autonome, sans script ni style en ligne). */
final class AssemblyPrintTemplateTest extends TestCase
{
    private const STYLESHEET = __DIR__ . '/../../../public/assets/css/pages/assembly-print.css';

    /** @param array<string, mixed> $overrides */
    private function render(array $overrides = []): string
    {
        $minutes = array_merge([
            'title' => 'Assemblée générale 2026',
            'associationName' => 'RehearsalBox',
            'statusBanner' => null,
            'versionLine' => null,
            'opening' => 'Ouverture à 19 h.',
            'quorum' => null,
            'board' => [['label' => 'Président', 'name' => 'Alice']],
            'counts' => '5 présents, 2 représentés',
            'present' => [],
            'represented' => [],
            'excused' => [],
            'agenda' => [['number' => 1, 'title' => 'Budget', 'discussion' => null]],
            'resolutions' => [],
            'noResolution' => null,
            'moralReport' => null,
            'financialReport' => null,
            'otherBusiness' => null,
            'closing' => 'Clôture à 21 h.',
            'signatures' => [['label' => 'Président', 'name' => 'Alice']],
        ], $overrides);

        return (new PhpTemplateRenderer(__DIR__ . '/../../../templates'))->render('admin/assemblies/print', [
            'minutes' => $minutes,
            'backHref' => '/admin/assemblies/1',
        ]);
    }

    #[Test]
    public function testAnAttendeeNameIsEscapedInTheAttendanceList(): void
    {
        $html = $this->render(['present' => ['<script>alert(1)</script>']]);

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    #[Test]
    public function testTheStatusBannerAppearsOnlyWhenProvided(): void
    {
        self::assertStringNotContainsString('rb-print-banner', $this->render(['statusBanner' => null]));

        $html = $this->render(['statusBanner' => 'Projet non validé']);
        self::assertStringContainsString('rb-print-banner', $html);
        self::assertStringContainsString('Projet non validé', $html);
    }

    #[Test]
    public function testTheNoResolutionTextIsShownWhenProvided(): void
    {
        $html = $this->render(['noResolution' => 'Aucune résolution n\'a été soumise au vote.']);

        self::assertStringContainsString('Aucune résolution n&#039;a été soumise au vote.', $html);
    }

    #[Test]
    public function testALineBreakInLongTextBecomesABrElement(): void
    {
        $html = $this->render(['closing' => "Première ligne\nSeconde ligne"]);

        self::assertStringContainsString('Première ligne<br', $html);
        self::assertStringContainsString('Seconde ligne', $html);
    }

    #[Test]
    public function testTheOutputHasNoInlineScriptNorInlineStyle(): void
    {
        $html = $this->render([
            'present' => ['Bob'],
            'resolutions' => [['number' => 1, 'text' => "Oui\nNon", 'votes' => '3 pour', 'outcome' => 'Adoptée']],
            'signatures' => [['label' => 'Secrétaire', 'name' => 'Carol']],
        ]);

        self::assertStringNotContainsString('style=', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('data-print', $html);
    }

    #[Test]
    public function testThePrintStylesheetHidesControlsAndDefinesThePageMargin(): void
    {
        $css = (string) file_get_contents(self::STYLESHEET);

        self::assertStringContainsString('@media print', $css);
        self::assertStringContainsString('@page', $css);
        self::assertMatchesRegularExpression('/@media print\s*\{.*\.no-print\s*\{[^}]*display:\s*none/s', $css);
    }
}
