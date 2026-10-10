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
        self::assertStringContainsString('rb-back-link', $trash, 'même flèche de retour');
        self::assertStringContainsString('class="rb-btn"', $trash);
        self::assertStringContainsString('rb-btn rb-btn-danger', $trash);
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
