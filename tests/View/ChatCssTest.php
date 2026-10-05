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
    public function testSwipeRevealsTheDeleteActionOnMobileAndDesktopKeepsAColumnOnTheRight(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-chat-item\s*\{[^}]*touch-action:\s*pan-y/s', $css, 'le défilement vertical reste possible');
        self::assertMatchesRegularExpression('/\.rb-chat-item--open\s*\{[^}]*--swipe-x:\s*-88px/s', $css, 'même largeur que ACTION_WIDTH du script');
        self::assertMatchesRegularExpression('/\.rb-chat-item-delete\s*\{[^}]*position:\s*absolute/s', $css, 'mobile : sous la ligne, révélé par le glissement');
        self::assertMatchesRegularExpression('/@media \(min-width: 900px\)\s*\{[^@]*\.rb-chat-item-delete\s*\{[^}]*position:\s*static[^}]*width:\s*44px/s', $css, 'ordinateur : colonne de 44 px à droite');
        self::assertMatchesRegularExpression('/:has\(\.rb-chat-item-delete:focus-visible\)/', $css, 'le clavier révèle le bouton');
    }

    #[Test]
    public function testTheSwipeWidthMatchesBetweenTheScriptAndTheStylesheet(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../public/assets/js/chat/swipe.js');

        self::assertMatchesRegularExpression('/ACTION_WIDTH = 88;/', $script);
        self::assertStringContainsString('--swipe-x: -88px', $this->css());
    }

    #[Test]
    public function testTheFeedIsAnchoredAtTheBottomByCssAlone(): void
    {
        self::assertMatchesRegularExpression('/\.rb-chat-messages\s*\{[^}]*display:\s*flex;[^}]*flex-direction:\s*column-reverse/s', $this->css(), 'ouverture sur le dernier message sans flash ni JS');
    }

    #[Test]
    public function testTheNewMessagesIndicatorFloatsAboveTheStatusLineAndStaysHiddenWhenAsked(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-chat-new-anchor\s*\{[^}]*position:\s*relative[^}]*height:\s*0/s', $css);
        self::assertMatchesRegularExpression('/\.rb-chat-new-messages\s*\{[^}]*position:\s*absolute[^}]*min-height:\s*44px/s', $css, 'flotte, zone tactile suffisante');
        self::assertMatchesRegularExpression('/\.rb-chat-new-messages\[hidden\]\s*\{[^}]*display:\s*none/', $css);
    }

    #[Test]
    public function testPageTransitionsAreNativeAndRespectReducedMotion(): void
    {
        self::assertMatchesRegularExpression('/@media \(prefers-reduced-motion: no-preference\)\s*\{\s*@view-transition\s*\{\s*navigation:\s*auto;/s', $this->css(), 'fondu natif entre pages, aucun script, désactivé pour qui réduit les animations');
    }

    #[Test]
    public function testEditingHasAHoverButtonAHiddenBannerAndATransparentBodyWrapper(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-chat-body\s*\{[^}]*display:\s*contents/s', $css, 'le conteneur remplaçable ne change pas la mise en page');
        self::assertMatchesRegularExpression('/\.rb-chat-message:hover \.rb-chat-edit,\s*\.rb-chat-edit:focus-visible\s*\{[^}]*opacity:\s*1/s', $css, 'survol ou clavier');
        self::assertMatchesRegularExpression('/@media \(hover: none\)\s*\{[^@]*\.rb-chat-message\[data-editable\]\s*\{[^}]*touch-action:\s*pan-y[^}]*transform:\s*translateX\(var\(--swipe-x/s', $css, 'tactile : la bulle suit le doigt, le défilement vertical reste possible');
        self::assertMatchesRegularExpression('/@media \(hover: none\)\s*\{[^@]*\.rb-chat-edit\s*\{[^}]*position:\s*absolute[^}]*left:\s*calc\(100% \+ 8px\)/s', $css, 'le crayon est révélé par le glissement');
        self::assertMatchesRegularExpression('/\.rb-chat-editing\[hidden\]\s*\{[^}]*display:\s*none/', $css);
        self::assertMatchesRegularExpression('/\.rb-chat-editing-cancel\s*\{[^}]*min-height:\s*44px/s', $css, 'zone tactile suffisante');
    }

    #[Test]
    public function testTheTextOfMyEditableBubblesStaysSelectableAndTheFeedNeverScrollsSideways(): void
    {
        $css = $this->css();

        // #212 : plus d'appui long, donc plus de conflit avec la sélection de texte d'iOS : copier reste possible.
        self::assertStringNotContainsString('-webkit-touch-callout', $css);
        self::assertMatchesRegularExpression('/\.rb-chat-messages\s*\{[^}]*overflow-x:\s*hidden/s', $css, 'le crayon attend hors écran');
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
