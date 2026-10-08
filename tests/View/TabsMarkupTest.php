<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #328 : chaque <rb-tabs> est un balisage ARIA complet et autonome ; l'ancien câblage par sélecteurs globaux n'existe plus. */
final class TabsMarkupTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../../templates';
    private const JS = __DIR__ . '/../../public/assets/js';

    /** @return list<array{string, string}> [gabarit, contenu d'un <rb-tabs>] */
    private function blocks(): array
    {
        $blocks = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::TEMPLATES, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $html = (string) file_get_contents($file->getPathname());
            if (preg_match_all('#<rb-tabs\b[^>]*>(.*?)</rb-tabs>#s', $html, $matches) > 0) {
                foreach ($matches[1] as $block) {
                    $blocks[] = [basename(dirname($file->getPathname())) . '/' . $file->getFilename(), $block];
                }
            }
        }

        return $blocks;
    }

    #[Test]
    public function testTheDashboardHasItsTwoTabSets(): void
    {
        self::assertCount(2, $this->blocks(), 'onglets du planning et onglets des demandes de créneau');
    }

    #[Test]
    public function testEveryTabControlsAPanelOfTheSameBlockAndExactlyOneIsSelected(): void
    {
        foreach ($this->blocks() as [$name, $block]) {
            preg_match_all('#<button[^>]*role="tab"[^>]*>#', $block, $tabs);
            self::assertGreaterThanOrEqual(2, count($tabs[0]), $name);
            self::assertSame(1, preg_match_all('/aria-selected="true"/', implode('', $tabs[0])), $name . ' : un seul onglet sélectionné au départ');

            foreach ($tabs[0] as $tab) {
                self::assertMatchesRegularExpression('/\bid="[^"]+"/', $tab, $name . ' : un onglet sans id');
                self::assertMatchesRegularExpression('/\bdata-tab="[^"]+"/', $tab, $name . ' : un onglet sans nom');
                self::assertSame(1, preg_match('/aria-controls="([^"]+)"/', $tab, $controls), $name . ' : un onglet sans aria-controls');
                self::assertMatchesRegularExpression('/id="' . preg_quote($controls[1], '/') . '"[^>]*role="tabpanel"|role="tabpanel"[^>]*id="' . preg_quote($controls[1], '/') . '"/', $block, $name . ' : panneau « ' . $controls[1] . ' » introuvable dans le même <rb-tabs>');
            }
        }
    }

    #[Test]
    public function testEveryPanelNamesItsTab(): void
    {
        foreach ($this->blocks() as [$name, $block]) {
            preg_match_all('#<(?:section|div)[^>]*role="tabpanel"[^>]*>#', $block, $panels);
            foreach ($panels[0] as $panel) {
                self::assertSame(1, preg_match('/aria-labelledby="([^"]+)"/', $panel, $labelled), $name . ' : un panneau sans aria-labelledby');
                self::assertStringContainsString('id="' . $labelled[1] . '"', $block, $name . ' : onglet « ' . $labelled[1] . ' » introuvable');
            }
        }
    }

    #[Test]
    public function testTheOldGlobalSelectorWiringIsGone(): void
    {
        $dashboard = (string) file_get_contents(self::TEMPLATES . '/dashboard/index.php');
        foreach (['data-planning-tabs', 'data-planning-tab=', 'data-planning-panel', 'data-tab-target', 'is-active'] as $old) {
            self::assertStringNotContainsString($old, $dashboard, $old);
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::JS, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'js') {
                self::assertStringNotContainsString('initExceptionTabs', (string) file_get_contents($file->getPathname()), $file->getFilename());
                self::assertStringNotContainsString('initPlanningTabs', (string) file_get_contents($file->getPathname()), $file->getFilename());
            }
        }
    }
}
