<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #246 : la liste des messages et la corbeille sont deux écrans d'une même messagerie. La classe du <body> ne doit pas entrer en
 * collision avec la zone remplaçable du polling (display: contents ne peint pas de fond : page blanche, texte clair invisible).
 */
final class MessagesPagesDesignTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../public/assets/css/pages/messages.css');
    }

    private function template(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../templates/messages/' . $name . '.php');
    }

    /** @return list<array{string}> */
    public static function pages(): array
    {
        return [['index'], ['trash']];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function testThePageBodyHasItsOwnClassDistinctFromTheReplaceableBodyZone(string $page): void
    {
        $template = $this->template($page);

        self::assertStringContainsString('<body class="rb-chat-page">', $template);
        self::assertStringNotContainsString('<body class="rb-chat-body">', $template);
    }

    #[Test]
    public function testThePageClassPaintsTheDarkBackgroundAndIsNeverDisplayContents(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-chat-page\s*\{[^}]*background:\s*var\(--rb-bg\)/s', $css);
        self::assertDoesNotMatchRegularExpression('/\.rb-chat-page\s*\{[^}]*display:\s*contents/s', $css);
        // La zone remplaçable du polling garde sa règle propre.
        self::assertMatchesRegularExpression('/\.rb-chat-body\s*\{[^}]*display:\s*contents/s', $css);
    }

    #[Test]
    public function testTheTrashReusesTheMessagingHeaderAndButtons(): void
    {
        $trash = $this->template('trash');

        self::assertStringContainsString('rb-chat-sidebar-head', $trash, 'même en-tête que la liste des messages');
        self::assertStringContainsString('rb-chat-icon-link', $trash, 'même flèche de retour');
        self::assertStringContainsString('class="rb-btn"', $trash);
        self::assertStringContainsString('rb-btn rb-btn-danger', $trash);
    }

    /** @return list<array{string}> */
    public static function backLinkTemplates(): array
    {
        return [['_sidebar'], ['_thread-header'], ['trash']];
    }

    #[Test]
    #[DataProvider('backLinkTemplates')]
    public function testTheBackArrowIsTheSharedSvgIconNotATextCharacter(string $name): void
    {
        $template = $this->template($name);

        self::assertStringNotContainsString('←', $template, 'plus de caractère texte, dont le rendu dépend de la police');
        self::assertStringContainsString("_icon-back.php", $template, 'une seule définition de l\'icône, réutilisée');
        self::assertStringContainsString('rb-chat-icon-link--back', $template, 'le lien de retour a sa variante en pastille');
    }

    #[Test]
    public function testTheBackLinkIsALightButtonInFullTextColourWithAnAccentHover(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-chat-icon-link--back\s*\{[^}]*color:\s*var\(--rb-text\)[^}]*background:\s*var\(--rb-surface\)/s', $css, 'texte plein sur un fond légèrement relevé, sans bordure ni ombre');
        self::assertMatchesRegularExpression('/\.rb-chat-icon-link--back\s*\{[^}]*border-radius:\s*var\(--rb-radius-md\)/s', $css);
        self::assertMatchesRegularExpression('/\.rb-chat-icon-link--back:hover\s*\{[^}]*color:\s*var\(--rb-accent\)/s', $css, 'le survol garde l\'accent, comme les autres boutons');
    }

    #[Test]
    public function testTheBackIconIsADecorativeStrokeSvgLikeTheOtherIcons(): void
    {
        $icon = $this->template('_icon-back');

        self::assertStringContainsString('<svg', $icon);
        self::assertStringContainsString('stroke="currentColor"', $icon, 'prend la couleur du texte et l\'état de survol');
        self::assertStringContainsString('stroke-width="2.5"', $icon);
        self::assertStringContainsString('aria-hidden="true"', $icon, 'le lien porte déjà son aria-label');
    }

    #[Test]
    public function testTheTrashScrollsInsideItsOwnColumnBecauseThePageBodyDoesNot(): void
    {
        $css = $this->css();
        $start = strpos($css, "\n.rb-trash {");
        self::assertNotFalse($start);
        $rule = substr($css, $start, strpos($css, '}', $start) - $start);

        self::assertStringContainsString('overflow-y: auto', $rule);
        self::assertStringContainsString('background: var(--rb-bg-2)', $rule, 'même fond que la colonne des conversations');
    }
}
