<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #202 : toute page qui contient <rb-confirm-modal> doit charger le style de la fenêtre. Il n'était défini que dans la
 * feuille de l'administration : sur la page des messages, la confirmation s'insérait sans mise en forme et la
 * suppression paraissait ne rien faire.
 */
final class ConfirmModalCssTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../../templates';
    private const PUBLIC = __DIR__ . '/../../public';

    /** @return list<string> chemins des gabarits qui posent la balise <rb-confirm-modal> */
    private function pagesWithTheModal(): array
    {
        $pages = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::TEMPLATES, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), '<rb-confirm-modal>')) {
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
                $styled = $styled || str_contains((string) file_get_contents(self::PUBLIC . $href), '.rb-modal-backdrop');
            }
            self::assertTrue($styled, basename(dirname($page)) . '/' . basename($page) . " contient <rb-confirm-modal> mais aucune de ses feuilles de style ne définit .rb-modal-backdrop");
        }
    }

    #[Test]
    public function testTheModalStyleLivesInTheSharedBaseSheetOnlyOnce(): void
    {
        $base = (string) file_get_contents(self::PUBLIC . '/assets/css/base.css');
        $admin = (string) file_get_contents(self::PUBLIC . '/assets/css/pages/admin.css');

        self::assertSame(1, substr_count($base, '.rb-modal-backdrop {'));
        self::assertStringNotContainsString('.rb-modal-backdrop {', $admin, 'pas de copie dans la feuille admin');
    }
}
