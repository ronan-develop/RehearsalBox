<?php

declare(strict_types=1);

namespace App\Tests\View\Css;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #202 : toute page qui contient <rb-confirm-dialog> doit charger le style de la fenêtre. Il n'était défini que dans la
 * feuille de l'administration : sur la page des messages, la confirmation s'insérait sans mise en forme et la
 * suppression paraissait ne rien faire.
 */
final class ConfirmDialogCssTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../../../templates';
    private const PUBLIC = __DIR__ . '/../../../public';

    /** @return list<string> chemins des gabarits qui posent la fenêtre (partials/confirm-dialog.php, qui contient <rb-confirm-dialog>) */
    private function pagesWithTheModal(): array
    {
        $pages = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::TEMPLATES, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'partials/confirm-dialog.php')) {
                $pages[] = $file->getPathname();
            }
        }

        return $pages;
    }

    #[Test]
    public function testTheMessagesPagesCarryTheModalAndAreCovered(): void
    {
        $names = array_map('basename', $this->pagesWithTheModal());

        self::assertContains('index.php', $names);
        self::assertContains('trash.php', $names);
        self::assertGreaterThanOrEqual(5, count($names), 'les pages admin et les pages de messagerie');
    }

    #[Test]
    public function testEveryPageWithTheModalLoadsAStylesheetThatStylesIt(): void
    {
        foreach ($this->pagesWithTheModal() as $page) {
            preg_match_all('#<link rel="stylesheet" href="(/assets/css/[^"]+)"#', (string) file_get_contents($page), $matches);
            $styled = false;
            foreach ($matches[1] as $href) {
                $styled = $styled || str_contains((string) file_get_contents(self::PUBLIC . $href), 'dialog.rb-modal');
            }
            self::assertTrue($styled, basename(dirname($page)) . '/' . basename($page) . " pose la fenêtre de confirmation mais aucune de ses feuilles de style ne définit dialog.rb-modal");
        }
    }

    #[Test]
    public function testTheModalStyleLivesInTheSharedBaseSheetOnlyOnce(): void
    {
        $base = (string) file_get_contents(self::PUBLIC . '/assets/css/base.css');
        $admin = (string) file_get_contents(self::PUBLIC . '/assets/css/pages/admin.css');

        self::assertSame(1, substr_count($base, 'dialog.rb-modal {'));
        self::assertStringNotContainsString('dialog.rb-modal {', $admin, 'pas de copie dans la feuille admin');
    }

    #[Test]
    public function testTheModalTitleAndTextAreStyled(): void
    {
        $base = (string) file_get_contents(self::PUBLIC . '/assets/css/base.css');

        self::assertMatchesRegularExpression('/\.rb-modal-title\s*\{/', $base);
        self::assertMatchesRegularExpression('/\.rb-modal-text\s*\{[^}]*overflow-wrap:\s*anywhere/s', $base);
    }

    #[Test]
    public function testThePartialIsANativeDialogWithItsTwoButtonsAndNoLeftoverOfTheOldModal(): void
    {
        $partial = (string) file_get_contents(self::TEMPLATES . '/partials/confirm-dialog.php');

        self::assertStringContainsString('<rb-confirm-dialog>', $partial);
        self::assertStringContainsString('<dialog ', $partial);
        self::assertStringContainsString('<form method="dialog"', $partial, 'fermeture native, aucune donnée envoyée');
        self::assertMatchesRegularExpression('/<button[^>]*value="cancel"[^>]*autofocus/', $partial, 'le focus initial est sur « Annuler »');
        self::assertStringContainsString('value="confirm"', $partial);
        foreach ($this->pagesWithTheModal() as $page) {
            self::assertStringNotContainsString('<rb-confirm-modal>', (string) file_get_contents($page));
        }
    }
}
