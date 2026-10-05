<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #169 : points de mise en page de la messagerie qui ont déjà cassé à l'écran ou qui protègent l'affichage. */
final class ChatCssTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../public/assets/css/pages/messages.css');
    }

    #[Test]
    public function testMobileShowsTheListOrTheThreadNeverBoth(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-chat\[data-view="list"\] \.rb-chat-main,\s*\.rb-chat\[data-view="thread"\] \.rb-chat-sidebar\s*\{[^}]*display:\s*none/', $css);
    }

    #[Test]
    public function testDesktopShowsTheListAndTheThreadSideBySide(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/@media \(min-width: 900px\)\s*\{[^@]*grid-template-columns:\s*minmax\(280px, 360px\)/s', $css);
        self::assertMatchesRegularExpression('/@media \(min-width: 900px\)\s*\{[^@]*\.rb-chat \.rb-chat-back\s*\{[^}]*display:\s*none/s', $css, 'le retour mobile disparaît sur grand écran (spécificité supérieure à .rb-chat-icon-link, déclarée plus bas)');
    }

    #[Test]
    public function testHiddenBadgesAndBlocksStayHiddenDespiteTheirDisplayRules(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-chat-archives \.rb-badge\[hidden\]/', $css);
        self::assertMatchesRegularExpression('/\.rb-messages-link \.rb-badge\[hidden\]\s*\{[^}]*display:\s*none/', $css);
        self::assertMatchesRegularExpression('/\.rb-chat-thread\[hidden\]/', $css);
    }

    #[Test]
    public function testTheDeleteButtonAndTheTrashBlocksStayHiddenWhenAsked(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-chat-delete\[hidden\]\s*\{[^}]*display:\s*none/', $css);
        self::assertMatchesRegularExpression('/\.rb-chat-delete\s*\{[^}]*min-height:\s*44px/s', $css, 'zone tactile suffisante');
        self::assertMatchesRegularExpression('/\.rb-trash \[hidden\]\s*\{[^}]*display:\s*none/', $css);
    }

    #[Test]
    public function testMentionsAreHighlightedInTheFeedAndMarkedInTheList(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-chat-mention--me\s*\{[^}]*background/s', $css);
        self::assertMatchesRegularExpression('/\.rb-chat-message--mentioned \.rb-chat-bubble\s*\{[^}]*outline/s', $css, 'le surlignage ne dépend pas de la seule couleur');
        self::assertMatchesRegularExpression('/\.rb-chat-item-mention\s*\{[^}]*position:\s*absolute/s', $css);
    }

    #[Test]
    public function testTheMentionListFloatsAboveTheComposerAndStaysHiddenWhenAsked(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-chat-mention-list\s*\{[^}]*position:\s*absolute[^}]*bottom:\s*100%/s', $css);
        self::assertMatchesRegularExpression('/\.rb-chat-mention-list\[hidden\],\s*\.rb-chat-mention-notice\[hidden\]\s*\{[^}]*display:\s*none/s', $css);
        self::assertMatchesRegularExpression('/\.rb-chat-mention-option\s*\{[^}]*min-height:\s*44px/s', $css, 'zone tactile suffisante');
    }

    #[Test]
    public function testMessageTextKeepsLineBreaksAndNeverOverflows(): void
    {
        self::assertMatchesRegularExpression('/\.rb-chat-text\s*\{[^}]*white-space:\s*pre-wrap[^}]*overflow-wrap:\s*anywhere/s', $this->css());
    }

    #[Test]
    public function testTheThreadScrollsInsideItselfAndTheComposerRespectsTheSafeArea(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-chat-messages\s*\{[^}]*overflow-y:\s*auto/s', $css);
        self::assertMatchesRegularExpression('/\.rb-chat-form\s*\{[^}]*env\(safe-area-inset-bottom\)/s', $css);
        self::assertMatchesRegularExpression('/height:\s*100dvh/', $css, 'hauteur dynamique du viewport mobile (barre d\'adresse)');
    }

    #[Test]
    public function testTheComposerInheritsTheSiteFont(): void
    {
        self::assertMatchesRegularExpression('/\.rb-chat-form textarea\s*\{[^}]*font:\s*inherit/s', $this->css());
    }

    #[Test]
    public function testTheSenderBadgeUsesTheGroupColourWithAFallback(): void
    {
        self::assertMatchesRegularExpression('/\.rb-chat-avatar\s*\{[^}]*var\(--group-color, var\(--rb-accent-2\)\)/s', $this->css());
    }
}
