<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #334 (suite de #256) : un seul bouton « page précédente », chevron SVG, pour toute l'application — plus de « ← » en texte. */
final class BackLinkTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    private function read(string $path): string
    {
        return (string) file_get_contents(self::ROOT . $path);
    }

    /** @return list<array{string}> */
    public static function pagesWithABackLink(): array
    {
        return [
            ['templates/messages/_sidebar.php'],
            ['templates/messages/_thread-header.php'],
            ['templates/messages/trash.php'],
            ['templates/group-space/index.php'],
            ['templates/bookings/index.php'],
        ];
    }

    #[Test]
    #[DataProvider('pagesWithABackLink')]
    public function testEveryBackLinkUsesTheSharedIconAndButtonClass(string $template): void
    {
        $html = $this->read($template);

        self::assertStringContainsString('partials/icon-back.php', $html, 'une seule définition de l\'icône, réutilisée');
        self::assertStringContainsString('rb-back-link', $html, 'même bouton léger partout');
        self::assertMatchesRegularExpression('/<a [^\n]*rb-back-link[^\n]*aria-label="[^\n]*Retour/', $html, 'le lien porte son aria-label explicite');
    }

    #[Test]
    public function testNoTemplateKeepsATextArrowAsABackLink(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . 'templates', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $content = (string) file_get_contents($file->getPathname());
            if (str_contains($content, '←') || str_contains($content, '&larr;')) {
                $offenders[] = substr($file->getPathname(), strlen(self::ROOT));
            }
        }

        self::assertSame([], $offenders, 'plus de caractère texte, dont le rendu dépend de la police');
    }

    #[Test]
    public function testTheIconIsADecorativeStrokeSvgLikeTheOtherIcons(): void
    {
        $icon = $this->read('templates/partials/icon-back.php');

        self::assertStringContainsString('<svg', $icon);
        self::assertStringContainsString('stroke="currentColor"', $icon, 'prend la couleur du texte et l\'état de survol');
        self::assertStringContainsString('stroke-width="2.5"', $icon);
        self::assertStringContainsString('aria-hidden="true"', $icon, 'le lien porte déjà son aria-label');
        self::assertFileDoesNotExist(self::ROOT . 'templates/messages/_icon-back.php', 'l\'ancienne copie, propre à la messagerie, disparaît');
    }

    #[Test]
    public function testTheBackLinkIsALightButtonInFullTextColourWithAnAccentHover(): void
    {
        $css = $this->read('public/assets/css/base.css');

        self::assertMatchesRegularExpression('/\.rb-back-link\s*\{[^}]*min-width:\s*44px[^}]*min-height:\s*44px/s', $css, 'zone tactile d\'au moins 44 px');
        self::assertMatchesRegularExpression('/\.rb-back-link\s*\{[^}]*color:\s*var\(--rb-text\)[^}]*background:\s*var\(--rb-surface\)/s', $css, 'texte plein sur un fond légèrement relevé, sans bordure ni ombre');
        self::assertMatchesRegularExpression('/\.rb-back-link\s*\{[^}]*border-radius:\s*var\(--rb-radius-md\)/s', $css);
        self::assertMatchesRegularExpression('/\.rb-back-link:hover\s*\{[^}]*color:\s*var\(--rb-accent\)/s', $css, 'le survol garde l\'accent, comme les autres boutons');
        self::assertMatchesRegularExpression('/\.rb-back-link\[hidden\]\s*\{[^}]*display:\s*none/s', $css, 'l\'attribut hidden n\'est pas écrasé par display');
    }

    #[Test]
    public function testTheOldPerPageBackStylesAreGone(): void
    {
        foreach (['public/assets/css/pages/messages.css' => 'rb-chat-icon-link', 'public/assets/css/pages/group-space.css' => 'rb-group-space-back', 'public/assets/css/pages/bookings.css' => 'rb-bookings-back'] as $file => $oldClass) {
            self::assertStringNotContainsString($oldClass, $this->read($file), "$file : un seul style de retour, dans base.css");
        }
    }
}
